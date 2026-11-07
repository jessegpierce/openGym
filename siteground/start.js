/* openGym on SiteGround (a Node.js project on a shared hosting plan).
 *
 * SiteGround runs one Node process with no nginx of its own in front, so this file does the job
 * the `web` container does in the Docker setup:
 *   - serves the built app (public/) and the exercise media (public/exercise-media/)
 *   - forwards /api/* to the openGym API (api/server.js), which it starts on an internal port
 *
 * The app is reached at https://<your-site>/<folder>/ through a small PHP file on that site
 * (siteground/PierceStrong/index.php), which passes every request here with a shared secret.
 * Requests without the secret are refused, so nobody can use this project's own address to get
 * around the PHP side.
 *
 * Settings (environment variables on the SiteGround Node.js project):
 *   PORT          port SiteGround tells the app to listen on (set by SiteGround)
 *   PROXY_SECRET  the same random string as in the PHP config.php (required)
 *   DATA_DIR      where workouts, profiles and passkeys are saved (default: ~/opengym-data)
 *   ORIGIN, RP_ID, RP_NAME, FIRST_USER_ADMIN, INVITE_ONLY ...  passed to the openGym API as-is
 */
import http from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PUBLIC = path.join(HERE, 'public');
const PUBLIC_PORT = +(process.env.PORT || 3000);
const API_PORT = +(process.env.OPENGYM_API_PORT || 3077);
const SECRET = String(process.env.PROXY_SECRET || '');

// The API reads its settings from the environment when it loads, so set them before importing.
process.env.PORT = String(API_PORT);
process.env.DATA_DIR = process.env.DATA_DIR || path.join(os.homedir(), 'opengym-data');
// Only this wrapper can reach the API, and it sets X-Forwarded-For itself (below), so the API may
// believe that header for its sign-in throttle.
process.env.TRUST_PROXY = '1';
process.env.NODE_ENV = process.env.NODE_ENV || 'production';
const DATA = process.env.DATA_DIR;
fs.mkdirSync(DATA, { recursive: true });

/* ---------- start log: answers "did my data survive the last redeploy?" ---------- */
// Every start appends a line to a file in DATA_DIR. After a redeploy, /__sg/status lists the
// earlier starts if the folder survived, and only the latest one if it was wiped.
const bootFile = path.join(DATA, 'siteground-starts.log');
const BOOT = { at: new Date().toISOString(), id: crypto.randomBytes(4).toString('hex') };
try { fs.appendFileSync(bootFile, JSON.stringify(BOOT) + '\n'); } catch (e) { console.error('cannot write', bootFile, e.message); }

await import('./api/server.js');

/* ---------- helpers ---------- */
const CSP = "default-src 'self'; script-src 'self' 'sha256-xUZtZS1nWJaAyUqA7ZKgkLhjdEisnY0MiRmax16aGFg='; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' blob: https:; font-src 'self' data:; connect-src 'self' https: http:; worker-src 'self'; manifest-src 'self'; frame-src https://www.youtube-nocookie.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
const SECURITY = {
  'X-Frame-Options': 'DENY',
  'X-Content-Type-Options': 'nosniff',
  'Referrer-Policy': 'same-origin',
  'Content-Security-Policy': CSP
};
const TYPES = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8', '.webmanifest': 'application/manifest+json',
  '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.gif': 'image/gif',
  '.webp': 'image/webp', '.avif': 'image/avif', '.ico': 'image/x-icon', '.mp4': 'video/mp4', '.webm': 'video/webm',
  '.woff': 'font/woff', '.woff2': 'font/woff2', '.txt': 'text/plain; charset=utf-8', '.md': 'text/plain; charset=utf-8',
  '.wasm': 'application/wasm', '.mp3': 'audio/mpeg', '.ogg': 'audio/ogg', '.wav': 'audio/wav'
};
const safeEqual = (a, b) => {
  const x = Buffer.from(String(a)), y = Buffer.from(String(b));
  return x.length === y.length && crypto.timingSafeEqual(x, y);
};
function send(res, code, body, headers = {}) {
  res.writeHead(code, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store', ...SECURITY, ...headers });
  res.end(body);
}
function clientAddress(req) {
  // Behind the PHP pass-through (secret checked), the visitor's address arrives in X-Forwarded-For.
  const fwd = String(req.headers['x-forwarded-for'] || '').split(',').map(s => s.trim()).filter(Boolean).pop();
  return fwd || String(req.socket.remoteAddress || '').replace(/^::ffff:/, '');
}

/* ---------- /api → the openGym API ---------- */
function proxyApi(req, res) {
  const headers = { ...req.headers };
  delete headers['x-opengym-proxy'];
  headers['x-forwarded-for'] = clientAddress(req);
  headers['x-real-ip'] = headers['x-forwarded-for'];
  delete headers['cf-connecting-ip'];      // only Cloudflare may set this, and it is not in front
  headers.host = 'localhost';             // the API does not use it; never pass on a visitor's value
  delete headers['x-forwarded-host'];
  const up = http.request({ host: '127.0.0.1', port: API_PORT, method: req.method, path: req.url, headers }, r => {
    res.writeHead(r.statusCode, r.statusMessage, r.rawHeaders.length ? rawPairs(r.rawHeaders) : r.headers);
    r.pipe(res);
  });
  up.setTimeout(30 * 60000);
  up.on('error', e => {
    console.error('api proxy:', e.message);
    if (!res.headersSent) send(res, 502, 'The openGym API is not answering yet. Try again in a moment.');
    else res.destroy();
  });
  req.pipe(up);
}
// Keeps repeated headers (two Set-Cookie lines) as separate lines.
function rawPairs(raw) {
  const out = [];
  for (let i = 0; i < raw.length; i += 2) {
    if (/^(connection|keep-alive|transfer-encoding)$/i.test(raw[i])) continue;
    out.push([raw[i], raw[i + 1]]);
  }
  return out;
}

/* ---------- the built app and the exercise media ---------- */
function cacheFor(rel) {
  if (/^assets\/[^/]+-[A-Za-z0-9_-]+\.(js|css)$/.test(rel)) return 'public, max-age=31536000, immutable';
  if (/\.(js|css|json|html|webmanifest)$/i.test(rel)) return 'no-cache, must-revalidate';
  if (/\.(png|jpe?g|gif|webp|avif|ico|svg|woff2?|mp4)$/i.test(rel)) return 'public, max-age=2592000, immutable';
  return 'no-cache';
}
function serveStatic(req, res, pathname) {
  if (req.method !== 'GET' && req.method !== 'HEAD') return send(res, 405, 'method not allowed', { Allow: 'GET, HEAD' });
  let rel;
  try { rel = decodeURIComponent(pathname); } catch { return send(res, 400, 'bad request'); }
  rel = rel.replace(/^\/+/, '');
  if (rel === '' || rel.endsWith('/')) rel += 'index.html';
  const file = path.resolve(PUBLIC, rel);
  if (file !== PUBLIC && !file.startsWith(PUBLIC + path.sep)) return send(res, 400, 'bad request');
  let st;
  try { st = fs.statSync(file); } catch { st = null; }
  if (st && st.isDirectory()) {
    // "exercise-media" without a slash → the folder's index, the way a web server would
    return send(res, 301, '', { Location: './' + path.basename(file) + '/' });
  }
  if (!st) {
    // Not a file: the app routes behind the "#", so any other path goes back to its start page.
    // A relative Location keeps the visitor under the folder the PHP side serves this from.
    const depth = rel.split('/').length - 1;
    return send(res, 302, '', { Location: (depth ? '../'.repeat(depth) : './') });
  }

  const headers = {
    ...SECURITY,
    'Content-Type': TYPES[path.extname(file).toLowerCase()] || 'application/octet-stream',
    'Cache-Control': cacheFor(rel),
    'Last-Modified': st.mtime.toUTCString(),
    'Accept-Ranges': 'bytes'
  };
  if (rel.startsWith('exercise-media/')) headers['Cross-Origin-Resource-Policy'] = 'same-origin';

  const since = Date.parse(req.headers['if-modified-since'] || '');
  if (since && Math.floor(st.mtimeMs / 1000) * 1000 <= since && !req.headers.range) {
    res.writeHead(304, headers); return res.end();
  }

  // Byte ranges: iPhone Safari will not play an mp4 from a server that ignores them.
  const range = /^bytes=(\d*)-(\d*)$/.exec(String(req.headers.range || ''));
  let start = 0, end = st.size - 1, code = 200;
  if (range && (range[1] || range[2])) {
    if (range[1]) { start = +range[1]; end = range[2] ? Math.min(+range[2], st.size - 1) : st.size - 1; }
    else { start = Math.max(0, st.size - +range[2]); }
    if (start > end || start >= st.size) {
      return send(res, 416, '', { 'Content-Range': `bytes */${st.size}` });
    }
    code = 206;
    headers['Content-Range'] = `bytes ${start}-${end}/${st.size}`;
  }
  headers['Content-Length'] = end - start + 1;
  res.writeHead(code, headers);
  if (req.method === 'HEAD') return res.end();
  fs.createReadStream(file, { start, end }).on('error', () => res.destroy()).pipe(res);
}

/* ---------- status page for setup checks ---------- */
function status(res) {
  let starts = [];
  try { starts = fs.readFileSync(bootFile, 'utf8').trim().split('\n').map(l => JSON.parse(l)); } catch {}
  // Counts and yes/no only: this page is reachable by anyone who can reach the website, so it
  // names no folders and no files. SG_STATUS_DETAIL=1 adds the folder paths while setting up.
  const body = {
    ok: true,
    startsRecorded: starts.length,
    dataSurvivedARestart: starts.length > 1,
    lastStarts: starts.slice(-5).map(s => s.at),
    profileStoreExists: fs.existsSync(path.join(DATA, 'db.json')),
    appBuilt: fs.existsSync(path.join(PUBLIC, 'index.html'))
  };
  if (/^(1|true|yes|on)$/i.test(process.env.SG_STATUS_DETAIL || '')) {
    Object.assign(body, { dataDir: DATA, home: os.homedir(), cwd: process.cwd(), node: process.version, publicPort: PUBLIC_PORT });
  }
  send(res, 200, JSON.stringify(body, null, 2), { 'Content-Type': 'application/json; charset=utf-8' });
}

/* ---------- the server SiteGround talks to ---------- */
http.createServer((req, res) => {
  let url;
  try { url = new URL(req.url, 'http://x'); } catch { return send(res, 400, 'bad request'); }
  // A tiny unguarded answer so SiteGround (or you) can see the process is up.
  if (url.pathname === '/__sg/ping') return send(res, 200, 'ok');
  if (SECRET.length < 32) return send(res, 503, 'Not set up yet: PROXY_SECRET (32+ characters) is missing.');
  if (!safeEqual(req.headers['x-opengym-proxy'] || '', SECRET)) {
    return send(res, 403, 'Open this app through its address on your website, not through this project address.');
  }
  if (url.pathname === '/__sg/status') return status(res);
  if (url.pathname === '/api' || url.pathname.startsWith('/api/')) return proxyApi(req, res);
  serveStatic(req, res, url.pathname);
}).listen(PUBLIC_PORT, () => {
  console.log(`openGym for SiteGround on :${PUBLIC_PORT} (api on :${API_PORT}, data in ${DATA})`);
});
