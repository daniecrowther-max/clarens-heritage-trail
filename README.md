# CHA / Clarens Heritage Trail

> **1 Sep 2026:** hierdie repo is nou die tuiste van die Clarens Heritage
> Trail app (voorheen ontwikkel in `whitelabel-heritage-trail`). Ou inhoud
> (die oorspronklike enkel-lêer app, foto's, Cloudflare Worker-skrip) is
> geargiveer in `_archive/pre-whitelabel-2026-09-01/` -- sien die
> `README.md.MIGRATION_NOTE.md` daar vir volledige geskiedenis-verwysings.
> `whitelabel-heritage-trail` bly voorlopig die repo wat trail.clarensheritage.org
> werklik ontplooi totdat hierdie repo se ontplooiing eksplisiet oorgeskakel word.


PWA front-end + WordPress plugin for the Clarens Heritage Trail, forked from
GRHS's `grhs-heritage-trail` codebase per the build brief
(`CHA_GRHS_Fork_Build_Brief_ClaudeCode_v3_FINAL.md`). Single payment rail:
Paystack (Stitch fully removed). Purchases are confirmed both synchronously
(`/verify-token` calls Paystack's Verify Transaction API directly on the
redirect back from checkout) and via a `/paystack-webhook` endpoint, which
catches a buyer who paid but never returned to the app.

## Deployment — frontend (`/app`)

`trail.clarensheritage.org` is served by a **Cloudflare Worker** (Workers
Static Assets — `wrangler.jsonc`'s `assets.directory: "app"`), **not**
Cloudflare Pages. It's easy to assume otherwise since the repo also has a
`.pages.dev`-style history from the old GRHS project, but `wrangler pages
project list` on this account shows no Pages project for CHA at all.

**There is no git integration.** A push to `main` deploys nothing — confirmed
via `wrangler deployments list`, whose only entries are manual `Upload`
sources, never a `Git` source. The frontend goes live **only** via:

```
wrangler deploy
```

run from the repo root (no build step — it uploads `/app`'s files directly).
This is a separate action from pushing to GitHub; do both when a frontend
change needs to ship.

**Only bump `CACHE_NAME` in `app/sw.js`** (e.g. `cha-trail-v0.1.1` →
`v0.1.2`) **when `sw.js`'s own caching/fetch logic or its `ASSETS` list
changes.** A browser only detects a new service worker by byte-comparing
`sw.js` itself, but that doesn't mean every `/app` change needs a bump:
HTML navigations are network-first (see the fetch handler in `sw.js`), so a
content-only change — a new `index.html`, for example — reaches every
already-installed device on its very next load regardless of the SW
version. This was investigated and confirmed after an earlier, over-broad
version of this note caused a false alarm on the Donate-modal and
token-regex fixes (neither touched `sw.js` and neither needed a bump); see
`docs/CHA_Development_Plan_v0.10.md` §8b for the full trace.

(The WordPress plugin's deploy model is different again — manual zip upload,
covered in step 2 below.)

## Repository layout

```
/app                 # PWA front-end — Cloudflare Workers Static Assets
/wordpress-plugin    # cha/v1 REST namespace, CPTs, Paystack, importer
/scripts             # one-off migration scripts (see below)
.env.example          # copy to .env on the WordPress host, fill in real values
wrangler.jsonc         # Cloudflare Workers deploy config for /app
```

## What's built vs what needs you

Everything in `/app` and `/wordpress-plugin` is finished, renamed (`cha/v1`,
`cht_`, `CHT-` throughout — zero `grhs`/`GRHS` remnants), `php -l` clean, and
JS-syntax-checked. As of 24 Aug 2026 it is **live**: the plugin is deployed
on `clarensheritage.org`, the front-end is deployed to
`trail.clarensheritage.org`, site content and existing payers' tokens are
migrated and verified against the production database, and a Paystack
sandbox transaction has run end-to-end. The steps below are kept as the
deploy runbook — most are done (marked ✅); the one still open is the final
**live** (non-sandbox) Paystack transaction test, after which the old
plugin can be retired. See `docs/CHA_Development_Plan_v0.10.md` for the
full history.

### 1. Paystack subaccount (brief §8) — ✅ done

A subaccount for CHA has been created in the Paystack dashboard:
- `percentage_charge: 20` (80% to CHA, 20% to Acutus)
- The subaccount code and secret key are in `.env` on the live host
- A sandbox transaction has been tested successfully end-to-end before any
  live key was used

### 2. Deploy the plugin, test end-to-end in sandbox — ✅ done

**Build the deploy zip with this exact command — do not zip the
`/wordpress-plugin` folder directly.** The repo's directory is named
`wordpress-plugin/`, but the plugin is deployed on the WordPress host as
`wp-content/plugins/cha-heritage-trail/` — a different name. A zip whose
top-level folder is `wordpress-plugin/` (e.g. from `git archive` without
`--prefix`, or from zipping the folder as-is) installs as a *second,
duplicate* plugin instead of updating the live one. Build from the repo root:

```
git archive --format=zip --prefix=cha-heritage-trail/ -o cha-heritage-trail.zip HEAD:wordpress-plugin
```

(Swap `HEAD` for another ref/tree-ish if building from something other than
the current commit.) Verify before uploading:

```
unzip -Z1 cha-heritage-trail.zip | grep -v '^cha-heritage-trail/' && echo "WRONG PREFIX" || echo "ok"
```

Upload the resulting zip via WP Admin → Plugins → Add New → Upload Plugin
(it will offer to replace the existing `cha-heritage-trail` install), or
extract it over `wp-content/plugins/cha-heritage-trail/` directly. Either
way, place `.env` **above the web root** (see `.env.example` for the exact
layout and the `CHA_ENV_PATH` override).

- The plugin is activated as `cha-heritage-trail` on the live site. Its
  activation hook created `wp_cha_purchases`, `wp_cha_access_tokens`, and
  the redeem table.
- A sandbox Paystack transaction has been run through `/checkout` →
  `/verify-token` end-to-end successfully. (The **live**, non-sandbox
  transaction test is the one item still open — see the acceptance
  checklist below.)
- The zip has since been rebuilt and re-uploaded once more (24 Aug 2026, to
  ship the GRHS `Kathy`/`The Makery` reference cleanup, commit `b190899`),
  confirmed live on the plugin's admin screens.

### 3. Site content (brief §5.1) — ✅ done, imported and verified

`scripts/migrate-site-content.js` was run once against the real Clarens data
(commit `a8627de` in the `clarens-heritage-trail` repo) to produce
`site-content-import.csv` in this repo's root — 31 sites. It has since been
imported via **WP Admin → Heritage Sites → Import** and independently
verified live (31/31 rows correct, photos restored to `app/photos/`, and a
one-off "Private" post-status issue on a single site fixed). Re-import
remains idempotent (matched by Site ID) if the sheet needs a future edit.

`CHA_Taxonomy::TERMS` now seeds Clarens's own four real categories
(`Heritage Site`, `Blue Plaque Site`, `Cultural Heritage`, `Natural
Heritage`) instead of GRHS's (`Blue Plaques`/`Buildings`/`Monuments`/
`People`), and `CHA_Importer::map_category()` matches the CSV's Category
column against them directly — so every row in `site-content-import.csv`
categorises correctly on import, no filter needed.

`CHA_Importer::fill_style_defaults()` also now looks up each site's real
icon/accent-colour (`ac`/`dot`/`icon`) from a curated per-`site_id` table
(taken verbatim from `clarens-heritage-trail`@`a8627de`, the same source the
migration script used) before falling back to a category default — matching
how Clarens's real data actually works (every site has its own hand-picked
icon/colour, not one derived from its category), and using the app's real
CSS accent classes (`ac-blue`/`ac-olive`/`ac-gold`/`ac-mid`) rather than
placeholder names that didn't exist in the stylesheet.

The CSV has no Photo Credit / Sources / Captured By / Blue Plaque Text data —
none of that exists in the source app's data model, so those columns are
blank for you to fill in via WP Admin after import, or in the sheet before
re-importing (re-import is idempotent, matched by Site ID).

### 4. Existing payers (brief §5.2) — ✅ done, empirically confirmed

`scripts/migrate-existing-payers.php` copies every `paid` row from the OLD
plugin's `wp_cha_trail_purchases` into the NEW plugin's `wp_cha_purchases`.
It has been run against the live database (both plugins active
simultaneously, per the script's header instructions), and a real,
pre-existing legacy token was tested end-to-end and confirmed to unlock.

That test also surfaced a real bug: the front-end's `looksLikeToken()`
pre-check in `app/index.html` required 16+ characters after `CHT-`, which
silently rejected legacy `CHT-XXXX-XXXX` tokens (9 characters) before the
server was ever asked — even though the migration and the server-side
lookup were both correct. Fixed in commit `4744cfd` (regex relaxed to 6+
characters) and deployed via `wrangler deploy`; re-tested live and
confirmed working.

### 5. Retire the old plugin (brief §6) — still open, do this last

Strict order, each step must succeed before the next (this is irreversible
from step 3 onward):

1. New plugin fully live (promo/admin tokens and vouchers confirmed
   working; new purchases confirmed in sandbox — **the real, live
   transaction test is still the one open item, see the acceptance
   checklist below**).
2. §5.2 migration script run **and its verification test passed** — ✅ done,
   see step 4 above.
3. **Back up** the old `wp_cha_trail_purchases` and
   `wp_cha_trail_access_tokens` tables (SQL dump, stored outside the
   database) — last safety net before destruction.
4. Deactivate the old `cha-trail-admin` plugin in WordPress.
5. **Delete** the old plugin's files entirely (WP Admin → Plugins → Delete,
   or SFTP removal).
6. **Drop** the old tables (`wp_cha_trail_purchases`,
   `wp_cha_trail_access_tokens`) from the database.
7. Archive the old `cht-heritage-trail` repo (stop treating it as live code —
   don't delete its git history).

Do not do steps 4–6 before 1–3 are done.

### 6. Deploy the front-end — ✅ done

See "Deployment — frontend (`/app`)" near the top of this file for the full
deploy model (Cloudflare Worker, not Pages; manual `wrangler deploy`; no git
integration). `trail.clarensheritage.org` has already been cut over from
the old `clarens-heritage-trail` repo's maintenance page to this `/app` —
independently confirmed via a live browser visit. Free sites are already
usable by real visitors; it's specifically the paid Phase 2 unlock that
still needs the live Paystack transaction test (see below) before the app
can be advertised with full confidence.

## Acceptance checklist (brief §10)

- [x] No `grhs`/`GRHS`/`GHT-`/`ght_` remnants anywhere (verified by repo-wide
      grep sweep).
- [x] `cha/v1`, `cht_`, `CHT-` used throughout.
- [x] Tokens opaque, crypto-random, server-issued (`CHA_Tokens::generate()`,
      unchanged from the proven GRHS pattern).
- [x] Price nowhere hardcoded outside `class-cha-settings.php` (verified by
      the brief's own grep test — only false-positive millisecond timeouts
      remain elsewhere).
- [ ] `/checkout` + `/verify-token` working end-to-end with a real small
      Paystack transaction — sandbox confirmed; the **live**, non-sandbox
      transaction is the **one remaining item** before full launch
      confidence (see `docs/CHA_Development_Plan_v0.10.md` §4, §7).
- [x] Existing payers' migrated tokens unlock correctly — `scripts/migrate-existing-payers.php`
      run against the live DB and empirically confirmed with a real,
      pre-existing legacy token (§4 above; the front-end pre-check bug this
      test surfaced is fixed in commit `4744cfd`).
- [x] Site content fully WordPress-manageable, no hardcoded `SITES` (`var
      SITES = []` in `app/index.html`, populated at runtime from
      `cha/v1/content`) — imported and independently verified live, 31/31
      sites correct (§3 above).
- [x] Donate panel present and extended: real EFT banking details plus a
      "Donate via card (Stitch)" button to Clarens's own Stitch Express
      payment link, independently verified live (commit `9d06f07`).
- [ ] Old plugin + tables removed only after the §5 sequence — still open,
      do this last (the §5.2 migration prerequisite is now verified done).
- [x] Front-end still targets Cloudflare Workers Static Assets
      (`wrangler.jsonc`, not Pages) — deployed and live at
      `trail.clarensheritage.org` (§6 above).
- [x] PWA installs and works offline — confirmed via QA across real
      devices, including a real phone with a real migrated token; the
      SW/cache-versioning logic itself is unchanged from the working GRHS
      pattern.
