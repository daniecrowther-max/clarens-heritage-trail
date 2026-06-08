# Clarens Heritage Trail PWA

Live: **https://trail.clarensheritage.org**  
Hosted on Cloudflare Workers Static Assets.

---

## What it is

A Progressive Web App (PWA) that guides visitors through 32 heritage sites in Clarens, Free State. Features include site stories and Quick Facts, a photo gallery, a Heritage Passport stamp system, an interactive GPS map, Partner Vouchers, and a Donate panel for the Clarens Heritage Association (CHA).

Works fully offline after first load. Installable on iOS and Android via "Add to Home Screen".

---

## Architecture

### Files served
| File | Purpose |
|---|---|
| `index.html` | Entire app shell — HTML, CSS, and JS in one file |
| `sw.js` | Service worker — caches shell offline, network-first for `content.json` |
| `manifest.json` | PWA manifest (name, icons, theme colour) |
| `icon-192x192.png` / `icon-512x512.png` | PWA icons |
| `content.json` | Remote content feed (sites + vouchers) |
| `photos/` | WebP images served as static assets |

### `index.html` internals
- **`SITES` array** — 32 baked-in heritage site objects (id, name, year, address, icon, accent colour, dot colour, map URL, facts[], story[]).
- **Photos** — served as external WebP files from the `photos/` directory and loaded by URL at display time. No base64 blobs.
- **`PARTNERS` array** — voucher partner objects with offer details, conditions, and logo URLs.
- **CDN dependencies** — Leaflet.js and Google Fonts (`Cormorant Garamond`, `Jost`) loaded via CDN. Leaflet's `<script>` tag must remain immediately before the main `<script>` in `<body>` — do not move it to `<head>`.
- **No build step** — edit and deploy directly.

---

## Content feed — weekly sync

### How it works
`content.json` (same origin, no CORS) is the remote content feed. The app checks it at most once every 7 days:

1. On app launch, any **stored feed** in `localStorage.contentFeed` is merged into `SITES` before the first render — returning users see new content immediately, even offline.
2. If `navigator.onLine` and 7+ days have passed since `localStorage.lastFeedCheck`, the app fetches `/content.json?v=<timestamp>` (query string bypasses stale cache).
3. On success: feed stored in `localStorage.contentFeed`, timestamp updated, live `SITES` re-merged, all views re-rendered. If the feed `version` integer is higher than the stored one, a subtle dismissible banner appears.
4. On failure (offline or server error): silently falls back to stored feed, or baked-in defaults. The app never breaks offline.

### `content.json` schema
```json
{
  "version": 1,
  "updated": "YYYY-MM-DD",
  "sites": [
    {
      "id": "unique-kebab-id",
      "bp": false,
      "name": "Site Name",
      "year": 1905,
      "address": "Street address",
      "icon": "&#127968;",
      "ac": "ac-olive",
      "dot": "#4a5240",
      "map": "https://maps.google.com/?q=...",
      "facts": [{"l": "Label", "v": "Value"}],
      "story": ["Paragraph one.", "Paragraph two."],
      "photo": "https://example.com/image.webp"
    }
  ],
  "vouchers": []
}
```

### Merge semantics
- Feed sites are merged into baked-in `SITES` by `id`. Feed wins on collision — allows updating baked-in content without rebuilding `index.html`. New ids are appended.
- Increment `"version"` when you want the in-app "New content" banner to fire.

### Service worker — network-first for `content.json`
`sw.js` checks the request pathname. `/content.json` is always fetched network-first; the response is cached under the path-only key so the offline fallback can find it. All other app-shell files are served cache-first.

---

## Token system

Three tiers of access token, all validated client-side via a djb2 hash keyed on `TOKEN_SECRET`:

| Prefix | Format | Expiry |
|---|---|---|
| `CHT-` | `CHT-XXXX-XXXX` | None |
| `CHA-PROMO-` | `CHA-PROMO-YYMMDDXXXX` | 90 days from embedded date |
| `CHA-ADMIN-` | `CHA-ADMIN-XXXXHHHH` | None |

Tokens are stored in `localStorage.cht_token`. The Stitch payment flow redirects back with `?token=CHT-...` or `?reference=CHA-CHT-...`; `handleUrlToken()` picks these up on app init.

---

## How to update content

### Adding/updating a site — no `index.html` redeploy needed
1. Add or edit an entry in `sites[]` in `content.json`.
2. Increment `"version"` by 1 so users see the update banner.
3. Deploy `content.json` only (see Deployment).
4. Existing users pick it up within 7 days; new visitors see it immediately.

### Changing app code or UI
Edit `index.html` or `sw.js`, bump the shell version strings (see Versioning), and redeploy.

---

## Deployment

The project uses **Cloudflare Workers Static Assets** via `wrangler.jsonc`.

### Deploy command
```bash
CLOUDFLARE_API_TOKEN=<your-token> npx wrangler deploy
```

### `.assetsignore`
Excludes `.git/`, `.wrangler/`, and `node_modules/` from the Cloudflare asset store. Do not remove — without it, `.git/` internals are publicly reachable.

### Keep secrets out of the repo
Never commit API tokens, PATs, or credentials. The deploy token for the marketing-pages worker lives in `~/cha-deploy/deploy.sh` outside this repo — keep it there.

---

## Versioning convention

Two version numbers move independently:

| Version | Location | Bump when |
|---|---|---|
| **Shell** (e.g. `v2.4`) | `sw.js` `CACHE_NAME` + four UI badges in `index.html` | Any code or UI change |
| **Feed** (integer, e.g. `1`) | `content.json` `"version"` | Any time you want users to see the "New content" banner |

### Shell release — all five locations must move together
1. `sw.js` → `const CACHE_NAME = 'cha-trail-vX.Y';`
2. `index.html` → splash badge (`.splash-beta`)
3. `index.html` → splash footer
4. `index.html` → header badge (`.hdr-beta`)
5. `index.html` → About tab `<h4>Version X.Y</h4>`

The `CACHE_NAME` bump forces already-installed PWA clients to discard their cached shell and fetch the new `index.html`. Without it, returning users keep the old version indefinitely.

---

## Changelog

| Version | Date | Notes |
|---|---|---|
| v2.4 | 2026-06-08 | CACHE_NAME bump to force cache refresh on installed clients |
| v2.3 | 2026-06-08 | Weekly content sync (`content.json` feed, network-first SW), `.assetsignore` security lockdown |
| v2.2 | 2026 | 32 sites, external WebP photos, Partner Vouchers, Stitch payment integration |
| v2.x | 2025–2026 | Additional heritage sites, Blue Plaque sites |
| v1.0 | 2025 | Initial release — Heritage Passport, offline PWA |
