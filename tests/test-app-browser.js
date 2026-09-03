#!/usr/bin/env node
/**
 * Audit items 1 and 5, plus unlock-price coverage, in a real browser.
 *
 * Drives headless Chrome over the DevTools protocol against
 * tests/mock-api-server.js, which serves the real app/index.html with its API
 * pointed at a stub that can produce every rejection the live server can, a
 * stored-XSS variant of the content feed, and a feed with no config, or none
 * at all.
 *
 * Item 1: for each rejection, assert the voucher screen is NOT shown, the
 *         local "used" flag is NOT written, and an error is.
 * Item 5: feed every WordPress-sourced field a script payload and assert
 *         nothing executes and the payload renders as text.
 * Unlock price: the feed's config.unlockPriceCents overrides the CONFIG
 *         fallback, and the fallback survives a feed that fails outright or
 *         omits config.
 *
 * Usage:  node tests/test-app-browser.js
 *
 * @package cha-tests
 */

const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

// --only=item1 | --only=item5 runs a single section. Useful for a control run
// against a pre-fix checkout of the app, where the other section's fixtures
// (e.g. the voucherErrMsg panel) do not exist yet.
const ONLY = (process.argv.find((a) => a.startsWith('--only=')) || '').split('=')[1] || '';

const PORT = 8791;
const ORIGIN = `http://localhost:${PORT}`;
const CDP_PORT = 9333;

let passed = 0;
let failed = 0;

function group(name) {
  console.log(`\n${name}\n${'-'.repeat(name.length)}`);
}
function ok(cond, what) {
  if (cond) { passed++; console.log(`  PASS  ${what}`); }
  else { failed++; console.log(`  FAIL  ${what}`); }
}
function is(actual, expected, what) {
  const c = JSON.stringify(actual) === JSON.stringify(expected);
  ok(c, c ? what : `${what} (got ${JSON.stringify(actual)}, want ${JSON.stringify(expected)})`);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function chromeBinary() {
  for (const c of ['/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser']) {
    if (fs.existsSync(c)) return c;
  }
  throw new Error('No Chrome/Chromium binary found');
}

/* ---- a very small CDP client ------------------------------------------ */

class Page {
  constructor(ws) {
    this.ws = ws;
    this.id = 0;
    this.pending = new Map();
    ws.addEventListener('message', (ev) => {
      const msg = JSON.parse(ev.data);
      if (msg.id && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        msg.error ? reject(new Error(JSON.stringify(msg.error))) : resolve(msg.result);
      }
    });
  }

  send(method, params = {}) {
    const id = ++this.id;
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  /** Evaluate an expression in the page and return its JSON value. */
  async evalIn(expr) {
    const r = await this.send('Runtime.evaluate', {
      expression: `(function(){ ${expr} })()`,
      returnByValue: true,
      awaitPromise: true,
    });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || 'eval threw');
    return r.result.value;
  }

  async goto(url, opts) {
    const waitForFeed = !opts || opts.waitForFeed !== false;
    await this.send('Page.navigate', { url });

    // The content feed is only fetched once the splash is dismissed, so the
    // "Begin Your Journey" button is part of getting the app into a testable
    // state — not an incidental click.
    for (let i = 0; i < 120; i++) {
      await sleep(100);
      try {
        const started = await this.evalIn(`
          // Wait for the app script itself, not just the markup: the button
          // exists in the HTML long before its click listener is attached.
          if (typeof mergeRemoteSites !== 'function') return false;
          var b = document.getElementById('btnStart');
          if (!b) return false;
          if (!window.__started) { window.__started = true; b.click(); }
          return true;
        `);
        if (started) break;
      } catch (e) { /* still navigating */ }
    }

    // Some scenarios (e.g. the content feed's connection being dropped)
    // deliberately leave PARTNERS/SITES empty forever — waiting on them
    // below would just time out, so those callers skip this part.
    if (!waitForFeed) return;

    for (let i = 0; i < 150; i++) {
      await sleep(100);
      try {
        const ready = await this.evalIn('return typeof PARTNERS !== "undefined" && PARTNERS.length > 0 && typeof SITES !== "undefined" && SITES.length > 0;');
        if (ready) return;
      } catch (e) { /* still navigating */ }
    }
    const diag = await this.evalIn(`
      return { url: location.href,
               partners: (typeof PARTNERS === 'undefined') ? 'undefined' : PARTNERS.length,
               sites: (typeof SITES === 'undefined') ? 'undefined' : SITES.length,
               hasStart: !!document.getElementById('btnStart') };
    `).catch(() => 'eval failed');
    throw new Error('app never became ready at ' + url + ' — ' + JSON.stringify(diag));
  }
}

async function connect() {
  const res = await fetch(`http://127.0.0.1:${CDP_PORT}/json/new?about:blank`, { method: 'PUT' });
  const target = await res.json();
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r, j) => { ws.addEventListener('open', r); ws.addEventListener('error', j); });
  const page = new Page(ws);
  await page.send('Page.enable');
  await page.send('Runtime.enable');
  return page;
}

/* ---- scenario driver --------------------------------------------------- */

const UNLOCK = `
  localStorage.clear();
  localStorage.setItem('cht_token', 'CHT-TEST-0001-0002-0003');
  localStorage.setItem('cht_verified', '1');
  localStorage.setItem('cht_type', 'purchase');
`;

/**
 * Load a scenario, unlock the app, and run the voucher confirm flow.
 *
 * @param {Page}   page
 * @param {string} scenario Mock-server scenario name.
 * @returns {object} What the voucher modal ended up showing.
 */
async function runRedeem(page, scenario) {
  // Prime localStorage on the origin, then reload so the app boots unlocked.
  await page.goto(`${ORIGIN}/?scenario=${scenario}`);
  await page.evalIn(UNLOCK + 'return true;');
  await page.goto(`${ORIGIN}/?scenario=${scenario}`);

  await page.evalIn(`
    window.__consoleErrors = [];
    showVoucher('mock-partner');
    document.getElementById('voucherRevealBtn').click();
    document.getElementById('voucherConfirmBtn').click();
    return true;
  `);

  // The redeem XHR has a 10s timeout; wait past it for the 'hang' scenario.
  const deadline = scenario === 'hang' ? 130 : 40;
  for (let i = 0; i < deadline; i++) {
    await sleep(100);
    const settled = await page.evalIn(`
      var btn = document.getElementById('voucherConfirmBtn');
      return !btn.disabled ||
             document.getElementById('voucherUsedMsg').style.display === 'block';
    `);
    if (settled) break;
  }

  return page.evalIn(`
    var vis = function(id) { return document.getElementById(id).style.display !== 'none'; };
    return {
      voucherShown:  vis('voucherActiveSection'),
      usedShown:     vis('voucherUsedMsg'),
      errorShown:    vis('voucherErrMsg'),
      errorText:     document.getElementById('voucherErrMsg').textContent.trim(),
      usedText:      document.getElementById('voucherUsedMsg').textContent.trim(),
      localFlag:     localStorage.getItem('cht_voucher_mock-partner') !== null,
      confirmEnabled: !document.getElementById('voucherConfirmBtn').disabled
    };
  `);
}

/**
 * Read what the buyer actually sees for the unlock price: the text content
 * of every .unlock-price span (the buyPhaseBtn button copy and the buyModal
 * price line — both hidden behind display:none but still in the DOM, so no
 * modal needs to be opened to read them).
 *
 * @param {Page} page
 * @returns {string[]}
 */
function unlockPriceText(page) {
  return page.evalIn(`
    return [].map.call(document.querySelectorAll('.unlock-price'), function(el) { return el.textContent; });
  `);
}

/**
 * Like Page.goto(), but for scenarios where the content feed deliberately
 * never resolves (e.g. a dropped connection) — PARTNERS/SITES stay empty
 * forever there, so goto()'s normal readiness wait would just time out.
 * This only waits for the splash to be dismissed.
 *
 * @param {Page}   page
 * @param {string} url
 */
async function gotoAppOnly(page, url) {
  await page.goto(url, { waitForFeed: false });
}

/* ---- main -------------------------------------------------------------- */

(async () => {
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cha-chrome-'));
  const mock = spawn(process.execPath, [path.join(__dirname, 'mock-api-server.js'), String(PORT)], { stdio: 'ignore' });
  const chrome = spawn(chromeBinary(), [
    '--headless=new',
    `--remote-debugging-port=${CDP_PORT}`,
    `--user-data-dir=${profile}`,
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-gpu',
    '--disable-dev-shm-usage',
  ], { stdio: 'ignore' });

  const cleanup = () => {
    try { chrome.kill(); } catch (e) {}
    try { mock.kill(); } catch (e) {}
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) {}
  };
  process.on('exit', cleanup);

  // Wait for both to come up.
  let page = null;
  for (let i = 0; i < 60; i++) {
    await sleep(250);
    try { page = await connect(); break; } catch (e) { /* not up yet */ }
  }
  if (!page) { console.error('could not reach headless Chrome'); cleanup(); process.exit(1); }

  try {
    /* ---------------------------------------------------------------- */
    if (ONLY !== 'item5') {
    group('Item 1 — a voucher screen requires a server success');

    const okRun = await runRedeem(page, 'ok');
    ok(okRun.usedShown && !okRun.errorShown, 'server success: the confirmed screen is shown');
    ok(okRun.localFlag, 'server success: the voucher is marked used locally');
    ok(/confirmed/i.test(okRun.usedText), 'server success: the copy confirms the redemption');

    const rejections = [
      ['invalid', '401 invalid token'],
      ['sold_out', '403 sold out'],
      ['expired', '403 expired'],
      ['servererror', '500 with an HTML body'],
      ['refused', 'a dropped connection'],
      ['liar', 'a rejection whose body fakes success:"true"'],
    ];

    for (const [scenario, label] of rejections) {
      const r = await runRedeem(page, scenario);
      ok(!r.voucherShown, `${label}: no voucher screen`);
      ok(r.errorShown, `${label}: an error is shown`);
      ok(!r.localFlag, `${label}: nothing is marked used locally`);
      ok(r.confirmEnabled, `${label}: the confirm button is usable again`);
    }

    const hang = await runRedeem(page, 'hang');
    ok(!hang.voucherShown && hang.errorShown, 'a request that never answers: no voucher screen, error shown');
    ok(!hang.localFlag, 'a request that never answers: nothing marked used locally');

    // already_redeemed is a server verdict, not a failure — the used state is
    // correct there, and it is not a voucher.
    const already = await runRedeem(page, 'already');
    ok(!already.voucherShown, '409 already redeemed: no voucher screen');
    ok(already.usedShown && /already been redeemed/i.test(already.usedText), '409 already redeemed: the used state is shown');

    // Offline: the app must refuse before it even tries.
    await page.goto(`${ORIGIN}/?scenario=ok`);
    await page.evalIn(UNLOCK + 'return true;');
    await page.goto(`${ORIGIN}/?scenario=ok`);
    const offline = await page.evalIn(`
      Object.defineProperty(navigator, 'onLine', { get: function(){ return false; }, configurable: true });
      showVoucher('mock-partner');
      document.getElementById('voucherRevealBtn').click();
      document.getElementById('voucherConfirmBtn').click();
      return {
        voucherShown: document.getElementById('voucherActiveSection').style.display !== 'none',
        errorShown:   document.getElementById('voucherErrMsg').style.display !== 'none',
        errorText:    document.getElementById('voucherErrMsg').textContent,
        localFlag:    localStorage.getItem('cht_voucher_mock-partner') !== null
      };
    `);
    ok(!offline.voucherShown, 'offline: no voucher screen');
    ok(offline.errorShown && /offline/i.test(offline.errorText), 'offline: an offline-specific error is shown');
    ok(!offline.localFlag, 'offline: nothing is marked used locally');
    }

    /* ---------------------------------------------------------------- */
    group('Unlock price — the feed overrides the CONFIG fallback');

    // The mock feed (tests/mock-api-server.js) serves config.unlockPriceCents:
    // 9900 — deliberately different from CONFIG.unlockPriceCents (10000) in
    // app/index.html — so this only passes if the app is actually reading the
    // feed's price rather than just re-displaying its own built-in fallback.
    await page.goto(`${ORIGIN}/?feed=ok`);
    is(await unlockPriceText(page), ['R99.00', 'R99.00'], 'the feed price (R99.00) is shown, not the CONFIG fallback (R100.00)');

    // The feed responds 200 with sites/partners present but no `config` key at
    // all — mergeRemoteSites() must leave the CONFIG fallback in place rather
    // than throw or blank the price out.
    await page.goto(`${ORIGIN}/?feed=noconfig`);
    is(await unlockPriceText(page), ['R100.00', 'R100.00'], 'a feed response with no config: the CONFIG fallback price is shown');

    // The feed request itself never completes (dropped connection). PARTNERS/
    // SITES never populate here, so this uses gotoAppOnly() instead of goto()
    // and only waits for the splash to be dismissed.
    await gotoAppOnly(page, `${ORIGIN}/?feed=down`);
    await sleep(1000); // let the failed fetch reject and reach mergeRemoteSites()'s .catch()
    const downPrice = await unlockPriceText(page);
    const appVisible = await page.evalIn(`return document.getElementById('app').classList.contains('visible');`);
    is(downPrice, ['R100.00', 'R100.00'], 'the feed request fails outright: the CONFIG fallback price is shown');
    ok(appVisible, 'the feed request fails outright: the app is not stuck on the splash screen');

    // feedMode is persistent server state, like `scenario` — reset it so it
    // doesn't leak into a later group that navigates without an explicit
    // &feed= param (Item 5 loads plain ?xss=1 and needs the feed to succeed).
    await fetch(`${ORIGIN}/?feed=ok`);

    /* ---------------------------------------------------------------- */
    if (ONLY !== 'item1') {
    group('Item 5 — stored XSS in WordPress feed fields');

    await page.goto(`${ORIGIN}/?xss=1`);
    await page.evalIn(UNLOCK + 'return true;');
    await page.goto(`${ORIGIN}/?xss=1`);

    // Visit every render path the payload can reach.
    await page.evalIn(`
      renderTrail();
      renderPassport();
      renderPartners();
      renderMap();
      openDetail('mock-site-one');
      switchTab('vouchers');
      return true;
    `);
    await sleep(500);

    const xss = await page.evalIn(`
      return {
        fired:   window.__xss || 0,
        injected: document.querySelectorAll('img[src="x"], img[onerror]').length,
        scripts:  document.querySelectorAll('#sitesList script, #partnersList script, #detailContent script, #stampsGrid script').length,
        siteText: (document.querySelector('#sitesList .card-name') || {}).textContent || '',
        partnerText: (document.querySelector('#partnersList .partner-card-title') || {}).textContent || '',
        detailText: (document.querySelector('#detailContent .detail-title') || {}).textContent || '',
        logoImgs: document.querySelectorAll('img.partner-logo-img').length,
        // Scoped to the containers built from feed data. The app's own static
        // markup has one hand-written onclick (the Samsung install banner),
        // which interpolates nothing and is not in scope here.
        inlineHandlers: document.querySelectorAll(
          '#sitesList [onclick],#sitesList [onerror],#sitesList [onmouseover],#sitesList [onload],' +
          '#partnersList [onclick],#partnersList [onerror],#partnersList [onmouseover],#partnersList [onload],' +
          '#detailContent [onclick],#detailContent [onerror],#detailContent [onmouseover],#detailContent [onload],' +
          '#stampsGrid [onclick],#stampsGrid [onerror],#stampsGrid [onmouseover],#stampsGrid [onload],' +
          '#freeSitesList [onclick],#freeSitesList [onerror],#freeSitesList [onmouseover],#freeSitesList [onload],' +
          '#leaflet-map [onclick],#leaflet-map [onerror],#leaflet-map [onmouseover],#leaflet-map [onload]'
        ).length
      };
    `);

    is(xss.fired, 0, 'no injected script executed');
    is(xss.injected, 0, 'no attacker <img> element was created');
    is(xss.scripts, 0, 'no <script> element was created from feed data');
    is(xss.inlineHandlers, 0, 'no inline event-handler attributes survive in feed-rendered content');
    ok(xss.siteText.includes('<script>'), 'the site name renders the payload as visible text');
    ok(xss.partnerText.includes('<script>'), 'the partner name renders the payload as visible text');
    ok(xss.detailText.includes('<script>'), 'the detail title renders the payload as visible text');
    is(xss.logoImgs, 0, 'a javascript: logo URL produces no <img> at all');

    // The map popup's "View Site" button used to be a string-built onclick.
    const popup = await page.evalIn(`
      var m = null;
      mapMarkersLayer.eachLayer(function(l){ if (!m) m = l; });
      m.openPopup();
      var btn = document.querySelector('.map-popup-btn');
      return {
        hasBtn: !!btn,
        hasOnclickAttr: btn ? btn.hasAttribute('onclick') : null,
        popupText: (document.querySelector('.map-popup-name') || {}).textContent || ''
      };
    `);
    ok(popup.hasBtn, 'the map popup still renders its View Site button');
    is(popup.hasOnclickAttr, false, 'the map popup button carries no onclick attribute');
    ok(popup.popupText.includes('<script>'), 'the map popup renders the payload as visible text');

    const opened = await page.evalIn(`
      document.querySelector('.map-popup-btn').click();
      return document.getElementById('detail-view').classList.contains('open');
    `);
    ok(opened, 'the map popup button still opens the site detail');

    // A CSS-class payload must not become an attribute or a live handler.
    const acAttr = await page.evalIn(`
      var el = document.querySelector('#sitesList .card-accent');
      return el ? el.getAttribute('class') : null;
    `);
    is(acAttr, 'card-accent ', 'a non-token `ac` value is dropped rather than written into class=');
    }
  } catch (e) {
    failed++;
    console.error('\n  ERROR ' + (e && e.stack || e));
  }

  console.log(`\n${passed} passed, ${failed} failed`);
  cleanup();
  process.exit(failed === 0 ? 0 : 1);
})();
