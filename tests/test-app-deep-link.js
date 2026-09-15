/**
 * ?site={id} deep link (blue-plaque QR → app instead of the website) and the
 * localStorage hand-off that carries the unlock target across the Paystack
 * payment redirect.
 *
 * Run with:  node tests/test-app-deep-link.js
 *
 * Same extraction approach as tests/test-app-browser.js: the real
 * functions are lifted out of app/index.html by name and run against a fake
 * DOM/localStorage/fetch, so this cannot drift from the app. Orchestration
 * (handleUrlToken, openPendingSiteIfReady) is extracted and run for real;
 * only its leaf DOM/network actions (openDetailWithHistory, showAppNotice,
 * showTokenModal, fetch) are stubbed — the same split test-app-browser.js
 * uses for redeemVoucherRemote/markVoucherUsedLocal/renderPartners.
 *
 * What this cannot prove: splash-skip timing, real browser history/back
 * behaviour, or the Leaflet/geolocation-dependent parts of openDetail() —
 * those need the live-browser pass (see the deploy checklist).
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const APP = process.env.CHA_APP_HTML || path.join(__dirname, '..', 'app', 'index.html');
const src = fs.readFileSync(APP, 'utf8');

// ─── Assertions ──────────────────────────────────────────────────────────
let pass = 0;
let fail = 0;

function group(name) { console.log('\n\x1b[1m' + name + '\x1b[0m'); }

function ok(cond, label) {
  if (cond) { pass++; console.log('  \x1b[32m✓\x1b[0m ' + label); }
  else { fail++; console.log('  \x1b[31m✗ ' + label + '\x1b[0m'); }
}

function eq(expected, actual, label) {
  const good = expected === actual;
  ok(good, label);
  if (!good) {
    console.log('      expected: ' + JSON.stringify(expected));
    console.log('      actual:   ' + JSON.stringify(actual));
  }
}

// ─── Extraction ──────────────────────────────────────────────────────────
function extractFunction(name) {
  const start = src.indexOf('function ' + name + '(');
  if (start === -1) {
    throw new Error(
      'Could not find function ' + name + '() in app/index.html — it was ' +
      'renamed or removed. Update this test rather than deleting the case.'
    );
  }
  let i = src.indexOf('{', start);
  let depth = 0;
  for (; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}') {
      depth--;
      if (depth === 0) return src.slice(start, i + 1);
    }
  }
  throw new Error('Unbalanced braces extracting ' + name + '()');
}

// ─── Fake localStorage (real Storage semantics, in-memory) ────────────────
function makeLocalStorage() {
  const store = {};
  return {
    getItem: function (k) { return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null; },
    setItem: function (k, v) { store[k] = String(v); },
    removeItem: function (k) { delete store[k]; },
    _dump: function () { return store; }
  };
}

// ─── Fake DOM ──────────────────────────────────────────────────────────
function makeEl(id) {
  const classes = {};
  return {
    id: id, textContent: '', style: {}, dataset: {}, appendChild: function () {},
    classList: {
      add: function (c) { classes[c] = true; },
      remove: function (c) { delete classes[c]; },
      contains: function (c) { return !!classes[c]; }
    }
  };
}

/** Pull a single `var NAME = ...;` line verbatim from app source — used for
 * small constants a test needs alongside an extracted function, without
 * hand-duplicating (and risking drift from) the real value. */
function extractVarLine(name) {
  const m = src.match(new RegExp('var ' + name + ' = [^;]*;'));
  if (!m) throw new Error('Could not find var ' + name + ' in app/index.html');
  return m[0];
}

let ctx;
let els;
let localStorage;
let openDetailCalls;
let appNoticeCalls;
let showTokenModalCalls;
let hideTokenModalCalls;
let showUnlockSuccessCalls;
let replaceStateCalls;
let replaceStateStates; // the `state` argument of each real (top-level) history.replaceState() call, in order
let verifyQueue;  // responses for the verify-token fetch, one per call
let contentQueue; // responses for the cha/v1/content fetch, one per call — pending() lets a test hold it open to control ordering against verifyQueue

function pending() {
  let resolveFn;
  const promise = new Promise(function (r) { resolveFn = r; });
  return { promise: promise, resolve: resolveFn };
}

function resetEnv(sites) {
  els = { tokenMsg: makeEl('tokenMsg'), tokenModal: makeEl('tokenModal'), app: makeEl('app') };
  localStorage = makeLocalStorage();
  openDetailCalls = [];
  appNoticeCalls = [];
  showTokenModalCalls = [];
  hideTokenModalCalls = 0;
  showUnlockSuccessCalls = [];
  replaceStateCalls = [];
  replaceStateStates = [];
  verifyQueue = [];
  contentQueue = [];

  ctx = {
    console: console,
    URLSearchParams: URLSearchParams,
    URL: URL,
    Date: Date,
    JSON: JSON,
    localStorage: localStorage,
    navigator: { onLine: true },
    document: {
      title: 'Clarens Heritage Trail',
      getElementById: function (id) {
        if (!els[id]) els[id] = makeEl(id);
        return els[id];
      },
      createElement: function () { return makeEl('appNotice'); }
    },
    window: {
      location: { pathname: '/', search: '', href: 'https://app.example.test/' },
      history: { replaceState: function (state, title, url) { replaceStateCalls.push(url); } }
    },
    history: {
      state: null,
      replaceState: function (state, title, url) {
        this.state = state;
        replaceStateStates.push(state);
        replaceStateCalls.push(url);
      }
    },
    CONFIG: { lsPrefix: 'cht_' },
    SITES: sites || [],
    FREE_SITES: [],
    _siteToOpenOnceLoaded: '',
    _sitesFeedSettled: false,
    PARTNERS: [],
    CATEGORIES: {},
    currentPriceCents: 10000,
    updatePriceCopy: function () {},
    renderPartners: function () {},
    refreshVoucherStatus: function () {},
    updateSiteCounts: function () {},
    renderTrail: function () {},
    renderPassport: function () {},
    renderMap: function () {},
    // Leaf actions stubbed exactly like test-app-browser.js stubs
    // redeemVoucherRemote/markVoucherUsedLocal/renderPartners — orchestration
    // above them (handleUrlToken, mergeRemoteSites, openPendingSiteIfReady) is
    // the real code.
    openDetailWithHistory: function (id) { openDetailCalls.push(id); },
    showAppNotice: function (msg, isError) { appNoticeCalls.push({ msg: msg, isError: isError }); },
    showTokenModal: function (pendingId) { showTokenModalCalls.push(pendingId); },
    hideTokenModal: function () { hideTokenModalCalls++; },
    showUnlockSuccess: function (label) { showUnlockSuccessCalls.push(label); },
    cfTrack: function () {},
    setTimeout: setTimeout,
    clearTimeout: clearTimeout,
    // Dispatches by URL so the verify-token and content-feed fetches — the
    // two independent requests whose relative ordering caused the real race
    // this suite is guarding against — can be resolved in either order.
    fetch: function (url) {
      const isContent = String(url).indexOf('/content') !== -1;
      const queue = isContent ? contentQueue : verifyQueue;
      const next = queue.shift();
      if (!next) return Promise.reject(new Error('no ' + (isContent ? 'content' : 'verify-token') + ' fetch response queued'));
      const settle = function () {
        return { status: next.status || 200, json: function () { return Promise.resolve(next.json); } };
      };
      return next.promise ? next.promise.then(settle) : Promise.resolve(settle());
    }
  };
  vm.createContext(ctx);

  vm.runInContext(extractFunction('paymentReturnParamsPresent'), ctx);
  vm.runInContext(extractFunction('normalizeSiteId'), ctx);
  vm.runInContext(extractFunction('computeDeepLinkSiteId'), ctx);
  vm.runInContext(extractFunction('storePendingUnlockSite'), ctx);
  vm.runInContext(extractFunction('clearPendingUnlockSite'), ctx);
  vm.runInContext(extractFunction('consumePendingUnlockSite'), ctx);
  vm.runInContext(extractFunction('stripSiteParamFromUrl'), ctx);
  vm.runInContext(extractFunction('openPendingSiteIfReady'), ctx);
  vm.runInContext(extractFunction('looksLikeToken'), ctx);
  vm.runInContext(extractFunction('labelFor'), ctx);
  vm.runInContext(extractFunction('verifyAndStore'), ctx);
  vm.runInContext(extractFunction('handleUrlToken'), ctx);
  vm.runInContext('var CHA_API_BASE = "https://example.test/wp-json";', ctx);
  vm.runInContext(extractFunction('mergeRemoteSites'), ctx);
  vm.runInContext('var PENDING_UNLOCK_KEY = CONFIG.lsPrefix + "pendingUnlockSite"; var PENDING_UNLOCK_MAX_AGE_MS = 2*60*60*1000; var CHA_VERIFY_URL = "https://example.test/wp-json/cha/v1/verify-token";', ctx);
  return ctx;
}

function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

// ─────────────────────────────────────────────────────────────────────────
group('normalizeSiteId — trims and lowercases, matching sanitize_title(trim())');

resetEnv();
eq('supply-store', ctx.normalizeSiteId('SUPPLY-STORE'), 'upper case normalises');
eq('supply-store', ctx.normalizeSiteId('  supply-store  '), 'stray whitespace trimmed');
eq('supply-store', ctx.normalizeSiteId('Supply-Store'), 'mixed case normalises');
eq('', ctx.normalizeSiteId(''), 'empty string stays empty');
eq('', ctx.normalizeSiteId(null), 'non-string input is safe, not a throw');

// ─────────────────────────────────────────────────────────────────────────
group('computeDeepLinkSiteId — the ?site= parse, and the Paystack-return guard');

resetEnv();
eq('supply-store', ctx.computeDeepLinkSiteId('?site=SUPPLY-STORE'), 'plain deep link normalises');
eq('', ctx.computeDeepLinkSiteId(''), 'no query string → no deep link');
eq('', ctx.computeDeepLinkSiteId('?foo=bar'), 'unrelated params → no deep link');
eq('', ctx.computeDeepLinkSiteId('?site=supply-store&token=ABC'), 'a Paystack token alongside ?site= backs off entirely');
eq('', ctx.computeDeepLinkSiteId('?site=supply-store&trxref=CHA-XYZ&reference=CHA-XYZ'), 'the Paystack trxref/reference pair alongside ?site= backs off entirely');
eq('', ctx.computeDeepLinkSiteId('?site=supply-store&status=failed'), 'a Paystack status alongside ?site= backs off entirely');
eq('', ctx.computeDeepLinkSiteId('?site=supply-store&reference=CHA-XYZ'), 'a Paystack reference alongside ?site= backs off entirely');

// ─────────────────────────────────────────────────────────────────────────
group('Pending-unlock localStorage round trip — store, consume, clear');

resetEnv();
ctx.storePendingUnlockSite('president-square');
eq('president-square', ctx.consumePendingUnlockSite(), 'a fresh stored id is returned');
eq(null, localStorage.getItem('cht_pendingUnlockSite'), 'consuming removes it — single use');
eq('', ctx.consumePendingUnlockSite(), 'nothing left to consume the second time');

resetEnv();
eq('', ctx.consumePendingUnlockSite(), 'nothing stored → empty, not a throw');

resetEnv();
ctx.storePendingUnlockSite('die-spens');
localStorage.setItem('cht_pendingUnlockSite', JSON.stringify({ id: 'die-spens', ts: Date.now() - 3 * 60 * 60 * 1000 }));
eq('', ctx.consumePendingUnlockSite(), 'a stale (>2h) id is ignored');
eq(null, localStorage.getItem('cht_pendingUnlockSite'), 'the stale entry is still cleared, not left behind');

resetEnv();
ctx.storePendingUnlockSite('old-library');
ctx.clearPendingUnlockSite();
eq('', ctx.consumePendingUnlockSite(), 'clearPendingUnlockSite() removes it outright — the cancelled/failed-return path');

// ─────────────────────────────────────────────────────────────────────────
group('openPendingSiteIfReady — the shared deep-link / post-unlock opener');

resetEnv([{ id: 'supply-store', name: 'Supply Store' }]);
ctx._sitesFeedSettled = true;
ctx._siteToOpenOnceLoaded = 'supply-store';
ctx.openPendingSiteIfReady();
eq(1, openDetailCalls.length, 'a found site opens exactly once');
eq('supply-store', openDetailCalls[0], 'opens the right id');
eq(0, appNoticeCalls.length, 'no notice when found');
// The base entry underneath the detail must be tagged {tab:'trail'} before
// opening — closeDetail()'s history.back() path (the X button, and the
// deep-link-only "Explore the Full Trail" button) removes 'open' before
// popstate fires, so its e.state.tab fallback needs this to land on
// 'trail' rather than the generic switchTab('map') default. Tried relying
// on call-order instead (an explicit switchTab('trail') right after
// closeDetail()) and it lost a real race — history.back() dispatches
// popstate asynchronously, so that call ran and was then overwritten once
// popstate actually fired. This replaceState-based tagging has no such race.
eq(true, replaceStateStates.length >= 1 && replaceStateStates[0] && replaceStateStates[0].tab === 'trail', 'the base entry is tagged {tab:\'trail\'} before the detail opens');

resetEnv([{ id: 'supply-store', name: 'Supply Store' }]);
ctx._sitesFeedSettled = true;
ctx._siteToOpenOnceLoaded = 'gr-999';
ctx.openPendingSiteIfReady();
eq(0, openDetailCalls.length, 'an unknown/pending id never opens a detail');
eq(1, appNoticeCalls.length, 'shows the app-wide notice instead');
eq(true, appNoticeCalls[0].isError, 'the notice is styled as the error variant');

resetEnv([{ id: 'supply-store', name: 'Supply Store' }]);
ctx._sitesFeedSettled = true;
ctx._siteToOpenOnceLoaded = 'supply-store';
ctx.openPendingSiteIfReady();
ctx.openPendingSiteIfReady(); // called again, e.g. from a second mergeRemoteSites-style settle
eq(1, openDetailCalls.length, 'consumed exactly once — a second call is a no-op');

resetEnv([]);
ctx._sitesFeedSettled = true;
ctx._siteToOpenOnceLoaded = '';
ctx.openPendingSiteIfReady();
eq(0, openDetailCalls.length, 'no pending id at all → does nothing (the "no ?site=" case)');
eq(0, appNoticeCalls.length, 'and no notice either');

// This is the exact bug a live-browser pass caught and a purely synchronous
// unit test could not: with the id pending but the feed not yet settled,
// nothing must happen YET — treating an empty SITES as "not found" here
// would burn the one-shot id before the feed ever gets a chance to answer.
resetEnv([{ id: 'supply-store', name: 'Supply Store' }]);
ctx._sitesFeedSettled = false;
ctx._siteToOpenOnceLoaded = 'supply-store';
ctx.openPendingSiteIfReady();
eq(0, openDetailCalls.length, 'does not open yet — the feed has not settled');
eq(0, appNoticeCalls.length, 'and does not give up and show "not found" either');
eq('supply-store', ctx._siteToOpenOnceLoaded, 'the pending id survives, untouched, for a later retry');
ctx._sitesFeedSettled = true;
ctx.openPendingSiteIfReady();
eq(1, openDetailCalls.length, 'opens once the feed catches up');

// ─────────────────────────────────────────────────────────────────────────
group('openDetailWithHistory(id, deferPush) — Chrome\'s history-manipulation intervention');

// Real functions under test here: armDeferredHistoryPush, openDetailWithHistory,
// closeDetail. canAccess/showTokenModal/openDetail are stubbed leaves — this
// group is about WHEN pushState() is called relative to a user gesture, not
// access gating or rendering. A separate, dedicated context (not resetEnv()
// above) because those other tests stub openDetailWithHistory itself.

/** Pull the anonymous popstate handler's body out of app source, brace-matched,
 * the same way test-app-browser.js pulls the confirm-button click handler. */
function extractPopstateHandler() {
  const marker = "window.addEventListener('popstate', function(e) {";
  const start = src.indexOf(marker);
  if (start === -1) throw new Error('Could not find the popstate handler in app/index.html');
  const braceStart = src.indexOf('{', start + marker.length - 1);
  let depth = 0, i = braceStart;
  for (; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}') { depth--; if (depth === 0) break; }
  }
  return src.slice(braceStart, i + 1);
}

let docListeners;
let historyStack;    // [baseState, ...pushed states] — index 0 is the entry under any pushed detail-open state
let pushStateCalls;
let backCalls;
let canAccessResult;
let openDetailCalls3;
let showTokenModalCalls3;
let switchTabCalls;
let dvOpen;
let historyCtx;

function resetHistoryEnv() {
  docListeners = {};
  historyStack = [null];
  pushStateCalls = [];
  backCalls = 0;
  canAccessResult = true;
  openDetailCalls3 = [];
  showTokenModalCalls3 = [];
  switchTabCalls = [];
  dvOpen = false;

  historyCtx = {
    console: console,
    document: {
      getElementById: function (id) {
        // Only 'detail-view' is asked about .classList by anything under
        // test here (closeDetail, the popstate handler) — a single fake
        // element, backed by the shared dvOpen flag, is enough.
        const el = makeEl(id);
        el.classList = {
          contains: function () { return dvOpen; },
          add: function () { dvOpen = true; },
          remove: function () { dvOpen = false; }
        };
        return el;
      },
      addEventListener: function (type, fn) {
        (docListeners[type] = docListeners[type] || []).push(fn);
      },
      removeEventListener: function (type, fn) {
        if (docListeners[type]) docListeners[type] = docListeners[type].filter(function (f) { return f !== fn; });
      }
    },
    history: {
      pushState: function (state) { historyStack.push(state); pushStateCalls.push(state); },
      replaceState: function (state) { historyStack[historyStack.length - 1] = state; },
      back: function () {
        backCalls++;
        if (historyStack.length > 1) historyStack.pop();
        firePopstate({ state: historyStack[historyStack.length - 1] });
      }
    },
    canAccess: function () { return canAccessResult; },
    showTokenModal: function (id) { showTokenModalCalls3.push(id); },
    openDetail: function (id) {
      openDetailCalls3.push(id);
      dvOpen = true; // openDetail() ends with dv.classList.add('open') in the real code
    },
    switchTab: function (name) { switchTabCalls.push(name); }
  };
  // history.state must reflect live mutation from pushState()/replaceState(),
  // which a plain data property captured at object-literal time would not.
  Object.defineProperty(historyCtx.history, 'state', { get: function () { return historyStack[historyStack.length - 1]; } });

  vm.createContext(historyCtx);
  function firePopstate(e) { historyCtx.__popstateHandler(e); }
  historyCtx.__popstateHandler = vm.runInContext('(function(e) ' + extractPopstateHandler() + ')', historyCtx);
  vm.runInContext(extractVarLine('DEFERRED_PUSH_EVENTS'), historyCtx);
  vm.runInContext(extractFunction('armDeferredHistoryPush'), historyCtx);
  vm.runInContext(extractFunction('openDetailWithHistory'), historyCtx);
  vm.runInContext(extractFunction('closeDetail'), historyCtx);
  return historyCtx;
}

function fireDocEvent(type) {
  (docListeners[type] || []).slice().forEach(function (fn) { fn({ type: type }); });
}

function topState() { return historyStack[historyStack.length - 1]; }

resetHistoryEnv();
historyCtx.openDetailWithHistory('supply-store', true);
eq(1, openDetailCalls3.length, 'the detail opens immediately, deferred or not');
eq(0, pushStateCalls.length, 'but no history entry is pushed yet — no user gesture has happened');

// scroll/wheel/touchstart do NOT grant user activation per the HTML spec —
// a visitor who only scrolls to read must not have Back silently armed,
// since Chrome would still flag an entry pushed from one of those handlers
// as skippable. Confirmed here, not assumed.
fireDocEvent('scroll');
fireDocEvent('wheel');
eq(0, pushStateCalls.length, 'scrolling/wheeling alone never pushes the deferred entry');

fireDocEvent('pointerup');
eq(1, pushStateCalls.length, 'a real activation-granting interaction pushes the deferred entry exactly once');
eq(true, topState() && topState().detailOpen, 'the pushed state matches the immediate-push shape');

fireDocEvent('touchend');
fireDocEvent('click');
fireDocEvent('keydown');
eq(1, pushStateCalls.length, 'further interactions after the first do nothing — one-shot, listeners removed');

resetHistoryEnv();
historyCtx.openDetailWithHistory('president-square', true);
historyCtx.closeDetail(); // the close (X) button, before any interaction fired
eq(0, pushStateCalls.length, 'closing before any interaction never creates a history entry');
eq(0, backCalls, 'history.back() is not called — closeDetail() found history.state.detailOpen unset');

resetHistoryEnv();
fireDocEvent('pointerup'); // arm nothing — no deferred open exists yet; must be a no-op
eq(0, pushStateCalls.length, 'an interaction with no deferred push armed does nothing');

resetHistoryEnv();
historyCtx.openDetailWithHistory('die-spens'); // no second argument — the normal, tap-triggered path
eq(1, openDetailCalls3.length, 'opens the detail, same as always');
eq(1, pushStateCalls.length, 'pushState() fires synchronously and immediately — unchanged from before this fix');
eq(true, topState() && topState().detailOpen, 'same state shape as the deferred path produces');
fireDocEvent('pointerup');
eq(1, pushStateCalls.length, 'no deferred listener was ever armed for this path, so a later interaction changes nothing');

resetHistoryEnv();
canAccessResult = false;
historyCtx.openDetailWithHistory('old-library', true);
eq(0, openDetailCalls3.length, 'a locked site never opens, deferred or not');
eq(0, pushStateCalls.length, 'and nothing is pushed — showTokenModal() owns this case, unchanged');
eq(1, showTokenModalCalls3.length, 'the normal locked-site prompt still fires');

// ─────────────────────────────────────────────────────────────────────────
group('closeDetail() -> popstate -> switchTab — the exact chain the live Back-button bug was in');

// Real popstate handler under test here too, wired against the same
// two-level history stack model. The X/CTA-button close path removes 'open'
// BEFORE calling history.back(), so by the time popstate fires the handler
// has already lost the "overlay still open" branch a physical Back press
// would have taken, and falls through to e.state.tab instead — this proves
// that fallback actually resolves to 'trail', not just that something was
// tagged somewhere.

resetHistoryEnv();
historyCtx.history.replaceState({ tab: 'trail' }, ''); // what openPendingSiteIfReady() does before opening
historyCtx.openDetailWithHistory('ou-slaghuis', true);
fireDocEvent('click'); // arms the deferred push (e.g. tapping "Explore the Full Trail" itself)
historyCtx.closeDetail();
eq(1, backCalls, 'closeDetail() called history.back() — a real entry existed to pop');
eq('trail', switchTabCalls.join(','), 'the popstate fallback resolves to the tagged trail tab, not the untagged map default');

resetHistoryEnv();
// Same sequence, base entry left UNTAGGED — reproduces the exact live bug
// this was written against, so a regression (e.g. dropping the replaceState
// call in openPendingSiteIfReady()) shows up here, not just in production.
historyCtx.openDetailWithHistory('railway-building', true);
fireDocEvent('click');
historyCtx.closeDetail();
eq('map', switchTabCalls.join(','), 'without the tag, the same close path falls through to the generic map default — this is the bug, reproduced deliberately to prove the tag is what fixes it');

// ─────────────────────────────────────────────────────────────────────────
group('Paystack round trip · reopens the site once BOTH the unlock and the feed have settled, in either order');

(async function () {
  // Ordering A — matches what actually broke in live-browser testing: the
  // local mock verify-token responded before the content feed did, so the
  // unlock succeeded first. openPendingSiteIfReady() must wait for the feed
  // rather than treating the still-empty SITES as "not found".
  resetEnv();
  ctx.storePendingUnlockSite('ou-slaghuis');
  ctx.window.location.search = '?token=CHT-AAAA-BBBB-CCCC-DDDD';
  verifyQueue.push({ json: { valid: true, type: 'purchase' } });
  const feedGateA = pending();
  contentQueue.push({ promise: feedGateA.promise, json: { sites: [{ id: 'ou-slaghuis', name: 'Ou Slaghuis' }] } });

  ctx.handleUrlToken();
  ctx.mergeRemoteSites(ctx.openPendingSiteIfReady);
  await sleep(10);

  eq(1, showUnlockSuccessCalls.length, 'the unlock success modal shows immediately — independent of the feed');
  eq(0, openDetailCalls.length, 'but the site does not reopen yet — the feed is still in flight');

  feedGateA.resolve();
  await sleep(10);

  eq(1, openDetailCalls.length, 'reopens as soon as the (delayed) feed catches up');
  eq('ou-slaghuis', openDetailCalls[0], 'reopens the correct site');
  eq(null, localStorage.getItem('cht_pendingUnlockSite'), 'the pending marker is gone after use');

  // Ordering B — the feed settles first, verify-token lags behind it.
  resetEnv();
  ctx.storePendingUnlockSite('railway-building');
  ctx.window.location.search = '?token=CHT-AAAA-BBBB-CCCC-EEEE';
  contentQueue.push({ json: { sites: [{ id: 'railway-building', name: 'Railway Building' }] } });
  const verifyGateB = pending();
  verifyQueue.push({ promise: verifyGateB.promise, json: { valid: true, type: 'purchase' } });

  ctx.mergeRemoteSites(ctx.openPendingSiteIfReady);
  ctx.handleUrlToken();
  await sleep(10);

  eq(0, openDetailCalls.length, 'the feed is ready, but the unlock has not been confirmed yet');

  verifyGateB.resolve();
  await sleep(10);

  eq(1, openDetailCalls.length, 'opens as soon as the (delayed) unlock confirms');
  eq('railway-building', openDetailCalls[0], 'reopens the correct site');

  // ───────────────────────────────────────────────────────────────────────
  group('Paystack round trip · a cancelled/failed return clears the key without opening anything');

  resetEnv([{ id: 'railway-building', name: 'Railway Building' }]);
  ctx.storePendingUnlockSite('railway-building');
  ctx.window.location.search = '?status=cancelled'; // not a recognised status → falls through like a plain non-return load
  ctx.handleUrlToken();
  // A genuinely unrecognised status with no token is treated as "not a Paystack
  // return at all" by the app (see handleUrlToken's own status checks) — the
  // key is left untouched for a still-in-flight separate return trip.
  eq('railway-building', JSON.parse(localStorage.getItem('cht_pendingUnlockSite')).id, 'an unrelated/unrecognised status leaves the key alone');

  resetEnv([{ id: 'railway-building', name: 'Railway Building' }]);
  ctx.storePendingUnlockSite('railway-building');
  ctx.window.location.search = '?status=failed';
  ctx.handleUrlToken();
  eq(null, localStorage.getItem('cht_pendingUnlockSite'), 'a "failed" Paystack status clears the pending key');
  eq(0, openDetailCalls.length, 'and nothing is opened');

  resetEnv([{ id: 'railway-building', name: 'Railway Building' }]);
  ctx.storePendingUnlockSite('railway-building');
  ctx.window.location.search = '?status=closed';
  ctx.handleUrlToken();
  eq(null, localStorage.getItem('cht_pendingUnlockSite'), 'a "closed" (cancelled) Paystack status clears the pending key too');

  // ───────────────────────────────────────────────────────────────────────
  group('Paystack round trip · a stale pending key is ignored even on a real, successful return');

  resetEnv([{ id: 'frost-house', name: 'Frost House' }]);
  localStorage.setItem('cht_pendingUnlockSite', JSON.stringify({ id: 'frost-house', ts: Date.now() - 3 * 60 * 60 * 1000 }));
  ctx.window.location.search = '?token=CHT-EEEE-FFFF-GGGG-HHHH';
  verifyQueue.push({ json: { valid: true, type: 'purchase' } });
  contentQueue.push({ json: { sites: [{ id: 'frost-house', name: 'Frost House' }] } });

  ctx.handleUrlToken();
  ctx.mergeRemoteSites(ctx.openPendingSiteIfReady);
  await sleep(10);

  eq(1, showUnlockSuccessCalls.length, 'the unlock itself still succeeds');
  eq(0, openDetailCalls.length, 'but the stale site is never reopened, even though the feed has it');
  eq(null, localStorage.getItem('cht_pendingUnlockSite'), 'the stale entry is cleared regardless');

  // ───────────────────────────────────────────────────────────────────────
  group('Paystack round trip · a failed verify never reopens the pending site');

  resetEnv([{ id: 'fischer-house', name: 'Fischer House' }]);
  ctx.storePendingUnlockSite('fischer-house');
  ctx.window.location.search = '?token=CHT-BAD1-BAD2-BAD3-BAD4';
  verifyQueue.push({ json: { valid: false, type: 'unknown' } });
  contentQueue.push({ json: { sites: [{ id: 'fischer-house', name: 'Fischer House' }] } });

  ctx.handleUrlToken();
  ctx.mergeRemoteSites(ctx.openPendingSiteIfReady);
  await sleep(10);

  eq(0, showUnlockSuccessCalls.length, 'no success shown');
  eq(0, openDetailCalls.length, 'no reopen on an invalid token, even though the feed has the site');

  console.log('\n' + (fail ? '\x1b[31m' : '\x1b[32m') + pass + ' passed, ' + fail + ' failed\x1b[0m');
  process.exit(fail ? 1 : 0);
})();
