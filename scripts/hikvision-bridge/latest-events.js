require('dotenv').config({ path: require('path').join(__dirname, '.env') });
const crypto = require('crypto');
const http = require('http');
const { URL } = require('url');

const IP = process.env.DEVICE_IP;
const PORT = Number(process.env.DEVICE_PORT || 80);
const USER = process.env.DEVICE_USER || 'admin';
const PASS = process.env.DEVICE_PASSWORD || '';

function md5(s) {
  return crypto.createHash('md5').update(s).digest('hex');
}
function parseWww(header) {
  const p = {};
  if (!header) return p;
  for (const m of String(header).matchAll(/(\w+)=(?:"([^"]*)"|([^\s,]+))/g)) p[m[1]] = m[2] ?? m[3];
  return p;
}
function raw(urlStr, method, headers, body) {
  return new Promise((resolve, reject) => {
    const u = new URL(urlStr);
    const req = http.request(
      { hostname: u.hostname, port: u.port || 80, path: u.pathname + u.search, method, headers: headers || {}, timeout: 20000 },
      (res) => {
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, text: Buffer.concat(chunks).toString('utf8') }));
      },
    );
    req.on('error', reject);
    req.on('timeout', () => { req.destroy(); reject(new Error('timeout')); });
    if (body) req.write(body);
    req.end();
  });
}
let nc = 0;
async function digest(urlStr, method = 'GET', headers = {}, body) {
  const u = new URL(urlStr);
  const uri = u.pathname + u.search;
  const h = { ...headers };
  if (body != null) h['Content-Length'] = Buffer.byteLength(body);
  const first = await raw(urlStr, method, h, body);
  if (first.status !== 401) return first;
  const challenge = parseWww(first.headers['www-authenticate']);
  nc += 1;
  const ncStr = nc.toString(16).padStart(8, '0');
  const cnonce = crypto.randomBytes(8).toString('hex');
  const qop = challenge.qop ? String(challenge.qop).split(',')[0].trim() : undefined;
  const ha1 = md5(`${USER}:${challenge.realm}:${PASS}`);
  const ha2 = md5(`${method}:${uri}`);
  const resp = qop ? md5(`${ha1}:${challenge.nonce}:${ncStr}:${cnonce}:${qop}:${ha2}`) : md5(`${ha1}:${challenge.nonce}:${ha2}`);
  let auth = `Digest username="${USER}", realm="${challenge.realm}", nonce="${challenge.nonce}", uri="${uri}", response="${resp}"`;
  if (qop) auth += `, qop=${qop}, nc=${ncStr}, cnonce="${cnonce}"`;
  if (challenge.opaque) auth += `, opaque="${challenge.opaque}"`;
  return raw(urlStr, method, { ...h, Authorization: auth }, body);
}
function isoLocal(d) {
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

(async () => {
  const base = `http://${IP}:${PORT}`;
  const from = new Date(Date.now() - 2 * 3600 * 1000);
  const to = new Date();
  // First page
  let body = JSON.stringify({
    AcsEventCond: {
      searchID: 'latest',
      searchResultPosition: 0,
      maxResults: 30,
      major: 5,
      minor: 38,
      startTime: isoLocal(from),
      endTime: isoLocal(to),
    },
  });
  let res = await digest(`${base}/ISAPI/AccessControl/AcsEvent?format=json`, 'POST', { 'Content-Type': 'application/json' }, body);
  let j = JSON.parse(res.text);
  const total = j.AcsEvent?.totalMatches || 0;
  console.log('totalMatches', total, 'num', j.AcsEvent?.numOfMatches);
  // Last page
  const pos = Math.max(0, total - 10);
  body = JSON.stringify({
    AcsEventCond: {
      searchID: 'latest2',
      searchResultPosition: pos,
      maxResults: 30,
      major: 5,
      minor: 38,
      startTime: isoLocal(from),
      endTime: isoLocal(to),
    },
  });
  res = await digest(`${base}/ISAPI/AccessControl/AcsEvent?format=json`, 'POST', { 'Content-Type': 'application/json' }, body);
  j = JSON.parse(res.text);
  const list = j.AcsEvent?.InfoList;
  const rows = !list ? [] : Array.isArray(list) ? list : [list];
  console.log('PC now', isoLocal(new Date()));
  for (const r of rows) {
    console.log({ time: r.time, emp: r.employeeNoString, serial: r.serialNo });
  }
})().catch((e) => console.error(e));
