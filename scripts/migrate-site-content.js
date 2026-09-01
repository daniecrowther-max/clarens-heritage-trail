#!/usr/bin/env node
/**
 * One-off migration (brief §5.1): parse Clarens's real SITES array out of
 * `index.html` as it stood at commit a8627de in the clarens-heritage-trail
 * repo, and emit a .csv matching class-cha-importer.php's column map
 * (CHA_Importer::COLUMNS) so it can be uploaded via WP Admin → Heritage
 * Sites → Import.
 *
 * Usage:
 *   node scripts/migrate-site-content.js [--repo <path>] [--ref <sha>] [--out <file>]
 *
 * Defaults: --repo ../clarens-heritage-trail (sibling checkout), --ref a8627de,
 * --out site-content-import.csv (repo root).
 *
 * IMPORTANT — known content-model gap (flagged, not silently papered over):
 * Clarens's real `cat` values are 'Heritage Site', 'Blue Plaque Site',
 * 'Cultural Heritage', 'Natural Heritage'. CHA_Importer::map_category() only
 * recognises the substrings 'plaque' / 'building' / 'monument' / 'person',
 * matching the GRHS taxonomy terms (Blue Plaques, Buildings, Monuments,
 * People) seeded by CHA_Taxonomy::seed_terms(). Only 'Blue Plaque Site' will
 * map automatically (via 'plaque'); 'Heritage Site', 'Cultural Heritage' and
 * 'Natural Heritage' will import with NO category set and a warning, until
 * Danie decides how Clarens's own categories should map — either by adding
 * a `cha_import_category_map` filter, or by extending CHA_Taxonomy's term
 * list to Clarens's actual set. This script exports the raw Category text
 * unchanged so that decision isn't made for him by a lossy guess here.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const vm = require('vm');

function parseArgs(argv) {
  const args = { repo: '../clarens-heritage-trail', ref: 'a8627de', out: 'site-content-import.csv' };
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--repo') args.repo = argv[++i];
    else if (argv[i] === '--ref') args.ref = argv[++i];
    else if (argv[i] === '--out') args.out = argv[++i];
  }
  return args;
}

function readSourceHtml(repoPath, ref) {
  const resolved = path.resolve(__dirname, '..', repoPath);
  return execFileSync('git', ['show', `${ref}:index.html`], {
    cwd: resolved,
    maxBuffer: 20 * 1024 * 1024,
    encoding: 'utf8',
  });
}

/** Extract a top-level `var NAME = [ ... ];` array literal as source text. */
function extractArrayLiteral(html, varName) {
  const marker = `var ${varName} = [`;
  const start = html.indexOf(marker);
  if (start === -1) {
    throw new Error(`Could not find "${marker}" in the source file.`);
  }
  const openBracket = html.indexOf('[', start);
  let depth = 0;
  let i = openBracket;
  let inString = null; // "'" or '"' while inside a string literal
  for (; i < html.length; i++) {
    const c = html[i];
    if (inString) {
      if (c === '\\') { i++; continue; } // skip escaped char
      if (c === inString) inString = null;
      continue;
    }
    if (c === "'" || c === '"') { inString = c; continue; }
    if (c === '[') depth++;
    else if (c === ']') {
      depth--;
      if (depth === 0) { i++; break; }
    }
  }
  return html.slice(openBracket, i);
}

/** Evaluate an array-literal source string as JS (trusted local source, not user input). */
function evalArrayLiteral(src) {
  return vm.runInNewContext(`(${src})`, {}, { timeout: 5000 });
}

function csvCell(value) {
  const s = value === null || value === undefined ? '' : String(value);
  if (/[",\n\r]/.test(s)) {
    return '"' + s.replace(/"/g, '""') + '"';
  }
  return s;
}

function buildFactsCell(facts) {
  if (!Array.isArray(facts)) return '';
  return facts
    .filter((f) => f && (f.l || f.v))
    .map((f) => `${f.l || ''}: ${f.v || ''}`)
    .join('; ');
}

function buildSummaryAndHistory(story) {
  const paras = Array.isArray(story) ? story.filter((p) => typeof p === 'string' && p.trim() !== '') : [];
  const summary = paras.length ? paras[0] : '';
  const history = paras.slice(1).join('\n\n');
  return { summary, history };
}

const COLUMNS = [
  'Site ID',
  'Site Name',
  'Category',
  'Year',
  'Street Address',
  'GPS Latitude',
  'GPS Longitude',
  'Short Summary',
  'Full History',
  'Key Facts',
  'Blue Plaque Text',
  'Primary Photo Filename',
  'Additional Photo Filenames',
  'Photo Credit',
  'Sources',
  'Captured By',
  'Free/Paid',
  'Notes',
];

function main() {
  const args = parseArgs(process.argv.slice(2));
  const html = readSourceHtml(args.repo, args.ref);

  const sites = evalArrayLiteral(extractArrayLiteral(html, 'SITES'));
  let freeSites = [];
  try {
    freeSites = evalArrayLiteral(extractArrayLiteral(html, 'FREE_SITES'));
  } catch (e) {
    console.warn('Warning: could not find/parse FREE_SITES — Free/Paid column will be blank.');
  }

  const rows = [COLUMNS.join(',')];
  const categoryCounts = {};
  const unmappableCategories = new Set();
  const KNOWN_KEYWORDS = ['plaque', 'building', 'monument', 'person'];

  for (const s of sites) {
    const { summary, history } = buildSummaryAndHistory(s.story);
    const cat = s.cat || '';
    categoryCounts[cat] = (categoryCounts[cat] || 0) + 1;
    if (!KNOWN_KEYWORDS.some((k) => cat.toLowerCase().includes(k))) {
      unmappableCategories.add(cat);
    }

    const notes = [];
    if (s.trail) notes.push(`trail=${s.trail}${s.trailNum ? ' #' + s.trailNum : ''}`);
    if (s.bp) notes.push('bp flag was true in source app data');

    const row = [
      s.id || '',
      s.name || '',
      cat,
      s.year || '',
      s.address || '',
      typeof s.lat === 'number' ? s.lat : '',
      typeof s.lng === 'number' ? s.lng : '',
      summary,
      history,
      buildFactsCell(s.facts),
      '', // Blue Plaque Text — no plaque-text field exists in the source app data.
      s.id ? `img-${s.id}.webp` : '', // matches the existing photos/ naming convention.
      '', // Additional Photo Filenames — not present in the source app data.
      '', // Photo Credit — not present in the source app data.
      '', // Sources — not present in the source app data.
      '', // Captured By — not present in the source app data.
      freeSites.includes(s.id) ? 'Free' : 'Paid',
      notes.join('; '),
    ];
    rows.push(row.map(csvCell).join(','));
  }

  const outPath = path.resolve(__dirname, '..', args.out);
  fs.writeFileSync(outPath, rows.join('\n') + '\n', 'utf8');

  console.log(`Wrote ${sites.length} sites to ${outPath}`);
  console.log(`Free sites: ${freeSites.length ? freeSites.join(', ') : '(none found)'}`);
  console.log('\nCategory value counts (raw, as they appear in the source app):');
  for (const [cat, count] of Object.entries(categoryCounts)) {
    console.log(`  ${count.toString().padStart(3)}  ${cat}`);
  }
  if (unmappableCategories.size) {
    console.log(
      '\nWARNING: these Category values will NOT auto-map to a CHA_Taxonomy term on import ' +
        '(CHA_Importer::map_category only recognises plaque/building/monument/person):'
    );
    for (const cat of unmappableCategories) console.log(`  - "${cat}"`);
    console.log(
      'Decide before importing: add a `cha_import_category_map` filter, or extend ' +
        "CHA_Taxonomy's seeded term list to Clarens's own category set. See this script's header comment."
    );
  }
}

main();
