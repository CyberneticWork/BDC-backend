/**
 * Sohan HR — Hikvision on-site bridge (ported from Solar working sync)
 *
 * Runs on an office PC that can reach the fingerprint device LAN IP.
 * Polls Hikvision ISAPI AcsEvent (HTTP Digest) and POSTs punches to HR:
 *   POST /api/hikvision/punches/{token}
 *
 * Setup:
 *   1. npm install
 *   2. Copy .env.example → .env (DEVICE_* + CLOUD_PUNCHES_URL from HR Hikvision panel)
 *   3. npm start   (or start-bridge.bat / install-autostart.bat)
 */
const path = require('path');
const fs = require('fs');
const crypto = require('crypto');
const http = require('http');
const https = require('https');
const { URL } = require('url');

function cleanSecret(value) {
  return String(value || '')
    .replace(/^\uFEFF/, '')
    .replace(/\r/g, '')
    .trim()
    .replace(/^[\u201C\u201D"']+|[\u201C\u201D"']+$/g, '');
}

function decodeEnvBuffer(buf) {
  if (buf.length >= 2 && buf[0] === 0xff && buf[1] === 0xfe) {
    return buf.slice(2).toString('utf16le');
  }
  let s = buf.toString('utf8');
  if (s.charCodeAt(0) === 0xfeff) s = s.slice(1);
  return s;
}

function parseEnvText(text) {
  const out = {};
  for (const rawLine of String(text).split(/\r?\n/)) {
    const line = rawLine.replace(/^\uFEFF/, '').trim();
    if (!line || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq < 1) continue;
    const key = line.slice(0, eq).trim();
    let val = line.slice(eq + 1).trim();
    const quoted = val.match(/^(['"])([\s\S]*)\1$/);
    out[key] = quoted ? quoted[2] : val;
  }
  return out;
}

const envPath = path.join(__dirname, '.env');
const parsedEnv = fs.existsSync(envPath)
  ? parseEnvText(decodeEnvBuffer(fs.readFileSync(envPath)))
  : {};
for (const [k, v] of Object.entries(parsedEnv)) process.env[k] = v;

const DEVICE_IP = cleanSecret(process.env.DEVICE_IP);
const DEVICE_PORT = Number(process.env.DEVICE_PORT || 80);
const DEVICE_USER = cleanSecret(process.env.DEVICE_USER || 'admin') || 'admin';
const DEVICE_PASSWORD = cleanSecret(process.env.DEVICE_PASSWORD);
let CLOUD_PUNCHES_URL = cleanSecret(process.env.CLOUD_PUNCHES_URL);
const CLOUD_BASE_URL = cleanSecret(process.env.CLOUD_BASE_URL || '').replace(/\/+$/, '').replace(/\/api$/i, '');
const SECRET = cleanSecret(process.env.HIKVISION_SECRET || '');
const POLL_SECONDS = Math.max(15, Number(process.env.POLL_SECONDS || 60));
const LOOKBACK_MINUTES = Math.max(5, Number(process.env.LOOKBACK_MINUTES || 180));
const STATE_FILE = path.join(__dirname, '.bridge-state.json');
const once = process.argv.includes('--once');

function punchesToken(urlStr) {
  const m = String(urlStr || '').match(/\/hikvision\/punches\/([^/?#]+)/i);
  return m ? m[1] : '';
}

function apiOrigin(urlStr) {
  try {
    const u = new URL(String(urlStr || '').replace(/\/+$/, ''));
    const host = u.hostname.toLowerCase().replace(/^www\./, '');
    const mapped = {
      'spmhr.cyberneticde.site': 'https://apispmhr.cyberneticde.site',
      'apispmhr.cyberneticde.site': 'https://apispmhr.cyberneticde.site',
      'jcfood.cyberneticde.site': 'https://apijcfood.cyberneticde.site',
      'apijcfood.cyberneticde.site': 'https://apijcfood.cyberneticde.site',
      'urbanhr.cyberneticde.site': 'https://apiurbanhr.cyberneticde.site',
      'apiurbanhr.cyberneticde.site': 'https://apiurbanhr.cyberneticde.site',
      'sunfohr.cyberneticde.site': 'https://apisunfohr.cyberneticde.site',
      'apisunfohr.cyberneticde.site': 'https://apisunfohr.cyberneticde.site',
      'bdchrnew.cyberneticde.site': 'https://apibdchrnew.cyberneticde.site',
      'apibdchrnew.cyberneticde.site': 'https://apibdchrnew.cyberneticde.site',
    };
    if (mapped[host]) return mapped[host];
    return u.origin;
  } catch {
    return '';
  }
}

function canonicalPunchesUrl(base, punchesUrl) {
  const origin = apiOrigin(base);
  const token = punchesToken(punchesUrl);
  if (origin && token) return `${origin}/api/hikvision/punches/${token}`;
  return punchesUrl;
}

async function refreshCloudPunchesUrl() {
  const token = punchesToken(CLOUD_PUNCHES_URL);
  if (!token) return CLOUD_PUNCHES_URL;
  const probeBase = apiOrigin(CLOUD_BASE_URL) || apiOrigin(CLOUD_PUNCHES_URL);
  if (!probeBase) return CLOUD_PUNCHES_URL;
  try {
    const res = await rawRequest(`${probeBase}/api/hikvision/cloud-base`, { method: 'GET' });
    const live = apiOrigin(res.json?.public_api_url || res.json?.data?.public_api_url);
    if (live) {
      CLOUD_PUNCHES_URL = canonicalPunchesUrl(live, CLOUD_PUNCHES_URL);
    }
  } catch (e) {
    console.warn('[bridge] cloud-base refresh skipped:', e.message || e);
  }
  if (CLOUD_BASE_URL) {
    CLOUD_PUNCHES_URL = canonicalPunchesUrl(CLOUD_BASE_URL, CLOUD_PUNCHES_URL);
  }
  return CLOUD_PUNCHES_URL;
}

function loadState() {
  try {
    return JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
  } catch {
    return { postedKeys: [] };
  }
}

function saveState(state) {
  fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
}

function md5(s) {
  return crypto.createHash('md5').update(s).digest('hex');
}

function parseWwwAuthenticate(header) {
  const raw = Array.isArray(header) ? header.join(',') : String(header || '');
  const p = {};
  for (const m of raw.matchAll(/(\w+)=(?:"([^"]*)"|([^\s,]+))/g)) {
    p[m[1]] = m[2] ?? m[3];
  }
  return p;
}

function rawRequest(urlStr, options = {}, body) {
  return new Promise((resolve, reject) => {
    const u = new URL(urlStr);
    const lib = u.protocol === 'https:' ? https : http;
    const headers = { ...(options.headers || {}) };
    if (body != null) {
      headers['Content-Length'] = Buffer.byteLength(body);
    }
    const req = lib.request(
      {
        protocol: u.protocol,
        hostname: u.hostname,
        port: u.port || (u.protocol === 'https:' ? 443 : 80),
        path: u.pathname + u.search,
        method: options.method || 'GET',
        headers,
        timeout: options.timeout || 25000,
        rejectUnauthorized: false,
      },
      (res) => {
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => {
          const text = Buffer.concat(chunks).toString('utf8');
          let json = null;
          try {
            json = JSON.parse(text);
          } catch {
            json = null;
          }
          resolve({ status: res.statusCode || 0, headers: res.headers, text, json });
        });
      }
    );
    req.on('error', reject);
    req.on('timeout', () => req.destroy(new Error('timeout')));
    if (body != null) req.write(body);
    req.end();
  });
}

async function digestRequest(method, uriPath, body, contentType = 'application/json') {
  const url = `http://${DEVICE_IP}:${DEVICE_PORT}${uriPath}`;
  const headers = {};
  if (body != null) headers['Content-Type'] = contentType;

  const first = await rawRequest(url, { method, headers }, body);
  if (first.status !== 401) return first;

  const challenge = parseWwwAuthenticate(first.headers['www-authenticate']);
  if (!challenge.realm || !challenge.nonce) {
    throw new Error('Device 401 without Digest challenge');
  }
  const nc = '00000001';
  const cnonce = crypto.randomBytes(8).toString('hex');
  const qop = challenge.qop ? String(challenge.qop).split(',')[0].trim() : undefined;
  const ha1 = md5(`${DEVICE_USER}:${challenge.realm}:${DEVICE_PASSWORD}`);
  const ha2 = md5(`${method}:${uriPath}`);
  const resp = qop
    ? md5(`${ha1}:${challenge.nonce}:${nc}:${cnonce}:${qop}:${ha2}`)
    : md5(`${ha1}:${challenge.nonce}:${ha2}`);
  let auth =
    `Digest username="${DEVICE_USER}", realm="${challenge.realm}", nonce="${challenge.nonce}", uri="${uriPath}", response="${resp}"`;
  if (qop) auth += `, qop=${qop}, nc=${nc}, cnonce="${cnonce}"`;
  if (challenge.opaque) auth += `, opaque="${challenge.opaque}"`;

  return rawRequest(url, { method, headers: { ...headers, Authorization: auth } }, body);
}

function isoLocal(d) {
  const pad = (n) => String(n).padStart(2, '0');
  const off = -d.getTimezoneOffset();
  const sign = off >= 0 ? '+' : '-';
  const abs = Math.abs(off);
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}${sign}${pad(Math.floor(abs / 60))}:${pad(abs % 60)}`;
}

function mapEvent(ev) {
  const empNo = String(ev.employeeNoString || ev.employeeNo || ev.empNo || '').trim();
  if (!empNo) return null;
  const rawTime = ev.time || ev.dateTime;
  if (!rawTime) return null;
  const at = new Date(rawTime);
  if (Number.isNaN(at.getTime())) return null;
  const pad = (n) => String(n).padStart(2, '0');
  return {
    empNo,
    date: `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}`,
    time: `${pad(at.getHours())}:${pad(at.getMinutes())}:${pad(at.getSeconds())}`,
    serialNo: ev.serialNo != null ? Number(ev.serialNo) : null,
  };
}

async function fetchDeviceEvents() {
  const queries = [
    { major: 5, minor: 38 },
    { major: 5, minor: 1 },
    { major: 5, minor: 75 },
    { major: 5, minor: 76 },
    { major: 0, minor: 0 },
  ];
  const from = new Date(Date.now() - LOOKBACK_MINUTES * 60 * 1000);
  const to = new Date(Date.now() + 15 * 60 * 1000);
  const pathApi = '/ISAPI/AccessControl/AcsEvent?format=json';
  const events = [];
  const seen = new Set();

  for (const q of queries) {
    let position = 0;
    for (let page = 0; page < 20; page += 1) {
      const body = JSON.stringify({
        AcsEventCond: {
          searchID: `${Date.now()}-${q.major}-${q.minor}-${page}`,
          searchResultPosition: position,
          maxResults: 30,
          major: q.major,
          minor: q.minor,
          startTime: isoLocal(from),
          endTime: isoLocal(to),
        },
      });
      const res = await digestRequest('POST', pathApi, body);
      if (res.status < 200 || res.status >= 300) break;
      const acs = res.json?.AcsEvent || {};
      const list = acs.InfoList;
      const rows = !list ? [] : Array.isArray(list) ? list : [list];
      for (const ev of rows) {
        const key = `${ev.serialNo ?? 'x'}|${ev.time || ev.dateTime || ''}|${ev.employeeNoString || ev.employeeNo || ''}`;
        if (seen.has(key)) continue;
        seen.add(key);
        events.push(ev);
      }
      const num = Number(acs.numOfMatches || rows.length || 0);
      const total = Number(acs.totalMatches || 0);
      if (!rows.length || position + num >= total || acs.responseStatusStrg === 'NO MATCH') break;
      position += num;
    }
  }

  events.sort((a, b) => new Date(a.time || a.dateTime || 0) - new Date(b.time || b.dateTime || 0));
  return events;
}

async function postToCloud(punches) {
  await refreshCloudPunchesUrl();
  if (!CLOUD_PUNCHES_URL) throw new Error('CLOUD_PUNCHES_URL missing');
  const headers = { 'Content-Type': 'application/json' };
  if (SECRET) headers['X-Hikvision-Secret'] = SECRET;
  const res = await rawRequest(
    CLOUD_PUNCHES_URL,
    { method: 'POST', headers },
    JSON.stringify({ punches })
  );
  if (res.status < 200 || res.status >= 300) {
    throw new Error(`Cloud POST HTTP ${res.status}: ${res.text.slice(0, 200)}`);
  }
  return res.json;
}

async function tick() {
  const state = loadState();
  const postedKeys = new Set(state.postedKeys || []);
  console.log(`[bridge] Polling ${DEVICE_IP}:${DEVICE_PORT} …`);
  const rawEvents = await fetchDeviceEvents();
  const punches = [];
  for (const ev of rawEvents) {
    const mapped = mapEvent(ev);
    if (!mapped) continue;
    const key = `${mapped.serialNo ?? 'x'}|${mapped.date}|${mapped.time}|${mapped.empNo}`;
    if (postedKeys.has(key)) continue;
    punches.push({ ...mapped, _key: key });
  }
  if (!punches.length) {
    console.log(`[bridge] No new punches (raw events: ${rawEvents.length})`);
    return;
  }
  console.log(`[bridge] Posting ${punches.length} punch(es) to ${CLOUD_PUNCHES_URL}`);
  const result = await postToCloud(punches.map(({ _key, ...p }) => p));
  for (const p of punches) postedKeys.add(p._key);
  const keys = [...postedKeys];
  saveState({ postedKeys: keys.slice(-5000), lastSyncAt: new Date().toISOString() });
  console.log(`[bridge] Cloud response:`, JSON.stringify(result?.data || result));
}

async function main() {
  if (!DEVICE_IP || !DEVICE_PASSWORD || !CLOUD_PUNCHES_URL) {
    console.error('[bridge] Set DEVICE_IP, DEVICE_PASSWORD, CLOUD_PUNCHES_URL in .env');
    process.exit(1);
  }
  console.log(`[bridge] Device ${DEVICE_IP}:${DEVICE_PORT} → ${CLOUD_PUNCHES_URL}`);
  await tick();
  if (once) return;
  setInterval(() => {
    tick().catch((e) => console.error('[bridge] tick failed:', e.message || e));
  }, POLL_SECONDS * 1000);
}

main().catch((e) => {
  console.error('[bridge] fatal:', e.message || e);
  process.exit(1);
});
