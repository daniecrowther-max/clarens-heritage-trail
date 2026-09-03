#!/usr/bin/env node
/**
 * Local harness for the app-side security fixes (audit items 1 and 5).
 *
 * Serves /app on http://localhost:8788 with CONFIG.apiBase rewritten to this
 * same origin, and answers cha/v1/* itself. Scenarios are driven by a query
 * string on the page URL so a browser can walk through the rejection paths
 * that are impossible to reach against the live site:
 *
 *   ?scenario=ok            redeem succeeds
 *   ?scenario=invalid       redeem answers 401 invalid_token
 *   ?scenario=sold_out      redeem answers 403 sold_out
 *   ?scenario=expired       redeem answers 403 expired
 *   ?scenario=already       redeem answers 409 already_redeemed
 *   ?scenario=servererror   redeem answers 500 with an HTML body
 *   ?scenario=hang          redeem never answers (client timeout path)
 *   ?scenario=refused       the redeem route drops the connection
 *   &xss=1                  feed fields carry stored-XSS payloads
 *   &feed=ok                cha/v1/content serves config.unlockPriceCents (default)
 *   &feed=noconfig          cha/v1/content serves sites/partners but no `config` key
 *   &feed=down              cha/v1/content drops the connection
 *
 * Usage:  node tests/mock-api-server.js [port]
 *
 * @package cha-tests
 */

const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = Number(process.argv[2] || 8788);
const APP_DIR = path.join(__dirname, '..', 'app');
const ORIGIN = `http://localhost:${PORT}`;

// The payloads the audit asks us to feed through every WordPress-sourced field.
const XSS = `"><img src=x onerror="window.__xss=(window.__xss||0)+1"><script>window.__xss=(window.__xss||0)+1</script>`;

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.webp': 'image/webp',
  '.css': 'text/css; charset=utf-8',
};

function feed(xss) {
  const t = (clean) => (xss ? clean + XSS : clean);
  return {
    config: { unlockPriceCents: 9900, currency: 'ZAR' },
    sites: [
      {
        id: 'mock-site-one',
        name: t('Mock Site One'),
        address: t('1 Test Street'),
        icon: t('🏛'),
        cat: 'Heritage Site',
        ac: xss ? 'ac-blue" onmouseover="window.__xss=1' : 'ac-blue',
        trail: 'clarens-town',
        trailNum: 1,
        lat: -28.5148,
        lng: 28.421,
        bp: false,
        free: true,
        radius: 100,
        photoCredit: t('Photo by Someone'),
        facts: [{ l: t('Year'), v: t('1912') }],
        story: [t('A paragraph of story text.')],
      },
    ],
    partners: [
      {
        id: 'mock-partner',
        wpId: 4242,
        name: t('Mock Partner'),
        type: t('Restaurant'),
        address: t('2 Test Street'),
        offer: t('10%'),
        offerLabel: t('10% Discount'),
        offerSub: t('on any main course'),
        desc: t('A description.'),
        logo: xss ? 'javascript:window.__xss=1' : '',
        lat: -28.515,
        lng: 28.4215,
        condition: 'paid',
      },
    ],
  };
}

const SCENARIOS = {
  ok: { status: 200, body: { success: true, code: 'redeemed', message: 'Voucher redeemed successfully.', partner_name: 'Mock Partner' } },
  invalid: { status: 401, body: { success: false, code: 'invalid_token', message: 'A valid trail pass is required to redeem this voucher.' } },
  sold_out: { status: 403, body: { success: false, code: 'sold_out', message: 'This voucher is sold out.' } },
  expired: { status: 403, body: { success: false, code: 'expired', message: 'This voucher has expired.' } },
  already: { status: 409, body: { success: false, code: 'already_redeemed', message: 'This voucher has already been redeemed.' } },
  servererror: { status: 500, raw: '<html><body>500 Internal Server Error</body></html>' },
  // A 200 that claims success without the server ever agreeing — the shape a
  // tampered/proxied response would take.
  liar: { status: 403, body: { success: 'true', code: 'sold_out', message: 'This voucher is sold out.' } },
};

let scenario = 'ok';
let xssOn = false;
let feedMode = 'ok'; // 'ok' | 'noconfig' | 'down' — controls cha/v1/content only, independent of `scenario`

function json(res, status, obj) {
  const body = JSON.stringify(obj);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Access-Control-Allow-Origin': '*' });
  res.end(body);
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, ORIGIN);
  const p = url.pathname;

  if (url.searchParams.has('scenario')) scenario = url.searchParams.get('scenario');
  if (url.searchParams.has('xss')) xssOn = url.searchParams.get('xss') === '1';
  if (url.searchParams.has('feed')) feedMode = url.searchParams.get('feed');

  if (req.method === 'OPTIONS') {
    res.writeHead(204, {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Headers': 'Content-Type',
      'Access-Control-Allow-Methods': 'GET,POST,OPTIONS',
    });
    return res.end();
  }

  if (p === '/cha/v1/content') {
    if (feedMode === 'down') return req.socket.destroy();
    const body = feed(xssOn);
    if (feedMode === 'noconfig') delete body.config; // sites/partners still present — only config is missing
    return json(res, 200, body);
  }

  if (p === '/cha/v1/voucher-status') return json(res, 200, {});

  if (p === '/cha/v1/redeem') {
    if (scenario === 'hang') return; // never respond
    if (scenario === 'refused') return req.socket.destroy();
    const s = SCENARIOS[scenario] || SCENARIOS.ok;
    if (s.raw) {
      res.writeHead(s.status, { 'Content-Type': 'text/html', 'Access-Control-Allow-Origin': '*' });
      return res.end(s.raw);
    }
    return json(res, s.status, s.body);
  }

  if (p === '/cha/v1/verify-token') return json(res, 200, { valid: true, type: 'purchase', paid: true });

  // Static app files, with the API base pointed at this server.
  const rel = p === '/' ? '/index.html' : p;
  const file = path.join(APP_DIR, path.normalize(rel).replace(/^(\.\.[/\\])+/, ''));
  if (!file.startsWith(APP_DIR) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
    res.writeHead(404);
    return res.end('not found');
  }

  const ext = path.extname(file);
  if (ext === '.html') {
    let html = fs.readFileSync(file, 'utf8');
    html = html.replace(/apiBase:\s*'[^']*'/, `apiBase: '${ORIGIN}'`);
    // The service worker would cache the real app across scenario switches.
    html = html.replace(/'serviceWorker' in navigator/g, 'false');
    res.writeHead(200, { 'Content-Type': MIME['.html'] });
    return res.end(html);
  }

  res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
});

server.listen(PORT, () => {
  console.log(`CHA mock API + app on ${ORIGIN}`);
  console.log(`  ${ORIGIN}/?scenario=sold_out`);
  console.log(`  ${ORIGIN}/?xss=1`);
});
