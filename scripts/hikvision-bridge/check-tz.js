require('dotenv').config({ path: require('path').join(__dirname, '.env') });
const crypto = require('crypto');
const http = require('http');
const { URL } = require('url');
const { PrismaClient } = require('@prisma/client');

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
  const time = await digest(`${base}/ISAPI/System/time`);
  console.log('DEVICE TIME RAW:', time.text);

  const from = new Date(Date.now() - 6 * 3600 * 1000);
  const to = new Date();
  const body = JSON.stringify({
    AcsEventCond: {
      searchID: 'tz1',
      searchResultPosition: 0,
      maxResults: 10,
      major: 5,
      minor: 38,
      startTime: isoLocal(from),
      endTime: isoLocal(to),
    },
  });
  const ev = await digest(`${base}/ISAPI/AccessControl/AcsEvent?format=json`, 'POST', { 'Content-Type': 'application/json' }, body);
  console.log('PC now local:', isoLocal(new Date()), 'tzOffsetMin', new Date().getTimezoneOffset());
  console.log('EVENTS:', ev.text.slice(0, 2000));

  const db = new PrismaClient();
  const latest = await db.hrTimeCard.findMany({
    where: { deletedAt: null, fingerprintClock: { not: null } },
    orderBy: { createdAt: 'desc' },
    take: 5,
    select: { clockTime: true, cardDate: true, createdAt: true, fingerprintClock: true },
  });
  const logs = await db.hrHikvisionEventLog.findMany({ orderBy: { createdAt: 'desc' }, take: 5 });
  console.log('DB CARDS:', latest);
  console.log(
    'DB LOGS:',
    logs.map((l) => ({
      eventTime: l.eventTime,
      createdAt: l.createdAt,
      msg: l.message,
      src: l.source,
    })),
  );
  await db.$disconnect();
})().catch(async (e) => {
  console.error(e);
  process.exit(1);
});
