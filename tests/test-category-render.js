/**
 * A category's colour reaches a style attribute, so it must be
 * validated, and an uncategorised site must look uncategorised.
 *
 * Run with:  node tests/test-category-render.js
 *
 * Same extraction approach as tests/test-app-deep-link.js: the real functions
 * are lifted out of app/index.html and run against a fake DOM.
 *
 * Two things are being protected here.
 *
 * The first is a new injection surface. Category colours arrive on the feed and
 * are interpolated into `style="background:…"` — a place escapeHtml() cannot help,
 * because escaping the quotes would still leave the value inside a style
 * context. safeColour() is the guard, and the existing escaping suite does not
 * cover renderTrail() at all, so without this file the card renderer would go
 * untested against hostile feed data.
 *
 * The second is the whole point of the change: a site whose category is missing
 * or unknown must render as visibly Uncategorised, not as the generic
 * "Heritage Site" badge that would hide vocabulary drift.
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
    else if (src[i] === '}') { depth--; if (depth === 0) return src.slice(start, i + 1); }
  }
  throw new Error('Unbalanced braces extracting ' + name + '()');
}

function makeCtx(extra) {
  const els = {};
  function el(id) {
    if (!els[id]) {
      els[id] = {
        id: id, innerHTML: '', textContent: '', style: {}, dataset: {},
        querySelectorAll: () => [], querySelector: () => null,
        addEventListener: () => {}
      };
    }
    return els[id];
  }
  const ctx = Object.assign({
    els: els,
    document: {
      getElementById: el,
      querySelectorAll: () => [],
      createElement: () => ({ innerHTML: '', querySelector: () => ({ addEventListener: () => {} }) })
    },
    console: console
  }, extra || {});
  vm.createContext(ctx);
  vm.runInContext(extractFunction('escapeHtml'), ctx);
  vm.runInContext(extractFunction('safeToken'), ctx);
  vm.runInContext(extractFunction('safeColour'), ctx);
  vm.runInContext(extractFunction('categoryFor'), ctx);
  return ctx;
}

// Payloads aimed specifically at the style attribute and the badge text.
const XSS = '<img src=x onerror=alert(1)>';
const STYLE_BREAKOUT = '#fff"><script>alert(1)</script><span style="';
const CSS_URL = 'url(javascript:alert(1))';

/**
 * Same invariant as tests/test-app-deep-link.js: check for the RAW payload,
 * not for a generic /on\w+=/ pattern. Once escaped the payload still reads
 * "...onerror=alert(1)..." as inert text, so matching that is a false positive.
 * What must not appear is the payload verbatim, an unescaped <script, or a
 * javascript: URL that reached an attribute.
 */
function hasExecutableMarkup(html) {
  return html.indexOf(XSS) !== -1 ||
         html.indexOf(STYLE_BREAKOUT) !== -1 ||
         /<script/i.test(html) ||
         /javascript:/i.test(html);
}

// ─── safeColour ──────────────────────────────────────────────────────────
group('safeColour(): only a literal six-digit hex survives');

let ctx = makeCtx();

eq('#266328', ctx.safeColour('#266328', '#000000'), 'a valid lower-case hex passes');
eq('#D48326', ctx.safeColour('#D48326', '#000000'), 'upper case passes');
eq('#000000', ctx.safeColour('#fff', '#000000'), 'three-digit shorthand is refused');
eq('#000000', ctx.safeColour('red', '#000000'), 'a colour keyword is refused');
eq('#000000', ctx.safeColour('#12345g', '#000000'), 'a non-hex digit is refused');
eq('#000000', ctx.safeColour(STYLE_BREAKOUT, '#000000'), 'an attribute breakout is refused');
eq('#000000', ctx.safeColour(CSS_URL, '#000000'), 'a css url() payload is refused');
eq('#000000', ctx.safeColour('', '#000000'), 'empty is refused');
eq('#000000', ctx.safeColour(null, '#000000'), 'null is refused');
eq('#000000', ctx.safeColour(0x266328, '#000000'), 'a number is refused, not coerced');

// ─── categoryFor ─────────────────────────────────────────────────────────
group('categoryFor(): unknown categories are visibly unknown');

ctx = makeCtx({
  CATEGORIES: {
    'cultural-heritage': { name: 'Cultural Heritage', colour: '#8a5c2e', text: '#FFFFFF', icon: '🏛️' },
    'blue-plaque-site': { name: 'Blue Plaque Site', colour: '#1a4a7a', text: '#FFFFFF', icon: '🔵' }
  }
});

let cd = ctx.categoryFor({ id: 'supply-store', cat: 'Cultural Heritage', catSlug: 'cultural-heritage' });
eq('Cultural Heritage', cd.name, 'a known slug resolves its name');
eq('#8a5c2e', cd.colour, 'and its colour');
eq('🏛️', cd.icon, 'and its glyph');
ok(cd.hasCategory, 'and counts as categorised');

cd = ctx.categoryFor({ id: 'president-square', cat: 'Cemetery', catSlug: 'cemetery' });
ok(cd.hasCategory, 'a category the feed has no definition for is still a category, not "Uncategorised"');
eq('Cemetery', cd.name, 'and keeps its own name rather than being renamed');
eq('#8C8C8C', cd.colour, 'but renders unstyled grey, which is itself the signal something is out of step');

cd = ctx.categoryFor({ id: 'die-spens' });
ok(!cd.hasCategory, 'only a site with NO category at all is uncategorised');
eq('Uncategorised', cd.name, 'and is named Uncategorised, not "Heritage Site"');

cd = ctx.categoryFor({ id: 'old-library', catSlug: 'blue-plaque-site' });
eq('#1a4a7a', cd.colour, 'Blue Plaque Site keeps the designation blue');

cd = ctx.categoryFor({ id: 'supply-store', cat: 'Cultural Heritage', catSlug: 'cultural-heritage' });
ok(cd.defined, 'a category the feed defined is marked defined');
cd = ctx.categoryFor({ id: 'president-square', cat: 'Cemetery', catSlug: 'cemetery' });
ok(!cd.defined, 'a category with no definition is marked undefined, so the badge can say so');

ctx = makeCtx({ CATEGORIES: { 'evil': { name: XSS, colour: STYLE_BREAKOUT, text: CSS_URL, icon: XSS } } });
cd = ctx.categoryFor({ id: 'ou-slaghuis', catSlug: 'evil' });
eq('#8C8C8C', cd.colour, 'a hostile colour in the feed is replaced with the fallback');
eq('#FFFFFF', cd.text, 'a hostile text colour is replaced too');

// ─── the real card renderer ──────────────────────────────────────────────
group('renderTrail(): hostile category data cannot reach the markup');

const EVIL_SITE = { id: 'railway-building', name: XSS, address: XSS, icon: XSS, cat: XSS, catSlug: 'evil', bp: false };

ctx = makeCtx({
  SITES: [EVIL_SITE],
  visited: {},
  CONFIG: { trailGroups: [{ key: 'clarens-town', label: 'Clarens Town', tag: 'Town' }], freeSitesOrder: [] },
  CATEGORIES: { 'evil': { name: XSS, colour: STYLE_BREAKOUT, text: CSS_URL, icon: XSS } },
  PARTNERS: [],
  canAccess: () => true,
  siteYear: () => 1820,
  visitedCount: () => 0,
  renderFreeSites: () => {},
  wireLogoFallback: () => {},
  partnerLogoUrl: () => ''
});
['trailOrder', 'byOrder', 'freeOrder', 'trailKey', 'renderTrail'].forEach(function (fn) { vm.runInContext(extractFunction(fn), ctx); });
ctx.renderTrail();

let html = ctx.els.sitesList.innerHTML;
ok(html.length > 0, 'the card rendered');
ok(!hasExecutableMarkup(html), 'no executable markup survived into the card');
ok(!/#fff">/.test(html), 'the style attribute was not broken out of');
ok(/background:#8C8C8C/.test(html), 'the hostile colour was replaced by the grey fallback');
ok(/1820/.test(html), 'the numeric year still renders');

group('renderTrail(): a good category renders its colour, a missing one does not');

ctx = makeCtx({
  SITES: [
    { id: 'frost-house', name: 'Ou Slaghuis', address: 'Main St', icon: '🏛️', cat: 'Cultural Heritage', catSlug: 'cultural-heritage', bp: false },
    { id: 'fischer-house', name: 'Nameless', address: 'Somewhere', icon: '', bp: false }
  ],
  visited: {},
  CONFIG: { trailGroups: [{ key: 'clarens-town', label: 'Clarens Town', tag: 'Town' }], freeSitesOrder: [] },
  CATEGORIES: { 'cultural-heritage': { name: 'Cultural Heritage', colour: '#8a5c2e', text: '#FFFFFF', icon: '🏛️' } },
  PARTNERS: [],
  canAccess: () => true,
  siteYear: () => 1812,
  visitedCount: () => 0,
  renderFreeSites: () => {},
  wireLogoFallback: () => {},
  partnerLogoUrl: () => ''
});
['trailOrder', 'byOrder', 'freeOrder', 'trailKey', 'renderTrail'].forEach(function (fn) { vm.runInContext(extractFunction(fn), ctx); });
ctx.renderTrail();
html = ctx.els.sitesList.innerHTML;

ok(/background:#8a5c2e/.test(html), 'the categorised card carries its category colour');
ok(/Cultural Heritage/.test(html), 'and its category name in the badge');
ok(/status-uncat/.test(html), 'the uncategorised card gets the uncategorised badge class');
ok(/Uncategorised/.test(html), 'and says Uncategorised in words');
ok(!/Heritage Site<\/span>/.test(html), 'and never falls back to the old generic "Heritage Site" badge');

group('renderTrail(): a category the feed cannot style is marked, not just grey');

// The realistic cause is a stale cached feed or an app deployed ahead of the
// plugin. It must not look like a plausible grey category — that is the exact
// failure mode this whole change removes.
ctx = makeCtx({
  SITES: [{ id: 'ng-kerk', name: 'Cemetery Gate', address: 'Church St', icon: '', cat: 'Cemetery', catSlug: 'cemetery', bp: false }],
  visited: {},
  CONFIG: { trailGroups: [{ key: 'clarens-town', label: 'Clarens Town', tag: 'Town' }], freeSitesOrder: [] },
  CATEGORIES: { 'cultural-heritage': { name: 'Cultural Heritage', colour: '#8a5c2e', text: '#FFFFFF', icon: '🏛️' } },
  PARTNERS: [],
  canAccess: () => true,
  siteYear: () => 1855,
  visitedCount: () => 0,
  renderFreeSites: () => {},
  wireLogoFallback: () => {},
  partnerLogoUrl: () => ''
});
['trailOrder', 'byOrder', 'freeOrder', 'trailKey', 'renderTrail'].forEach(function (fn) { vm.runInContext(extractFunction(fn), ctx); });
ctx.renderTrail();
html = ctx.els.sitesList.innerHTML;

ok(/status-undefined/.test(html), 'it carries the undefined marker class');
ok(/Cemetery/.test(html), 'it still shows its real category name');
ok(!/Uncategorised/.test(html), 'and is NOT mislabelled Uncategorised — it does have a category');
ok(/&#9888;/.test(html), 'and is flagged with a warning glyph so it does not read as a normal grey category');
ok(/\.status-undefined/.test(src), 'and the CSS rule that makes it visible exists');

group('Regression: no category name is hardcoded in the render paths');

// Comments legitimately mention the names; only code matters.
const codeOnly = src.replace(/^\s*\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, '');
ok(!/'Natural Heritage'/.test(codeOnly), "no comparison against 'Natural Heritage'");
ok(!/'Cultural Heritage'/.test(codeOnly), "no comparison against 'Cultural Heritage'");
ok(!/s\.cat === 'Blue Plaque Site'/.test(codeOnly), "no comparison against 'Blue Plaque Site'");
ok(!/safeToken\(s\.ac\)|esc\(s\.ac\)/.test(codeOnly), 'the retired ac field is no longer read');
ok(!/\.ac-blue|\.ac-olive|\.ac-gold|\.ac-mid/.test(src), 'the unreachable .ac-* rules are gone');
ok(!/\.status-nat|\.status-cul|\.badge-nat|\.badge-cul/.test(src), 'the per-category badge rules are gone');

console.log('\n' + (fail ? '\x1b[31m' : '\x1b[32m') + pass + ' passed, ' + fail + ' failed\x1b[0m');
process.exit(fail ? 1 : 0);
