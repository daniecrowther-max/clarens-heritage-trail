# Clarens/CHA Heritage Trail — Ontwikkelingsplan

> Lewende beplanningsdokument. **Status: v0.11 · 15 September 2026 — vervang v0.10.**
> **Opdatering in v0.11:** die GRHS-verbeterings sedert die vurk (19 Aug) is na CHA oorgedra. Vyf rye gebou en getoets (A, C, D, E, F), drie oorgeslaan op Danie se besluit (B, G, H). **Niks is ontplooi nie** — sien §5 vir die presiese handmatige stappe. Plugin-weergawe 0.1.0 → **0.2.0**.

---

## 1. Wat oorgedra is (Danie se keuse, 15 Sep 2026)

| Ry | Funksie | GRHS-commits | Uitkoms | Commit in CHA |
|---|---|---|---|---|
| A | Data-gedrewe kategoriemodel | `472455b`, `9b68c69`, `61478fc`, `e4ca1c3`, `44cfceb` | **Gebou.** Sien §2.1 | `30090de` |
| B | Foto-oplaai in WP Admin (WebP, R2) | `40e875e`, `9ec6104`, `82622ea` | **Oorgeslaan** — CHA het nog geen R2-emmer of -geloofsbriewe nie. Later. | — |
| C | Permanente QR-kortskakels `/s/{site_id}` | `21b0459`, `a395cdf`, `5202e8c`, `dc1a908` | **Gebou.** Sien §2.2 | `620ad54` |
| D | App-diepskakel `?site={id}`, oor die betaling behou | `0ccd0e4`, `83d5f58`, `a2c053b`, `08335cc` | **Gebou** — herdoen vir Paystack. Sien §2.3 | `a4389bc` |
| E | Besoekersbewoording "Phase 2" → produknaam | `0731257` | **Gebou** as **"Heritage Pass"** (nie GRHS se "Trail Pass" nie). Sien §2.4 | `abe1829` |
| F | `tools/build-zip.sh` + toets | `409b882` | **Gebou.** Sien §2.5 | `c618604` |
| G | Slug-herstel `gr-025..gr-030` | `0e01029`, `3e91577` | **Oorgeslaan** — GRHS-data-regstelling; CHA het nooit die probleem gehad nie. | — |
| H | Stitch-webhook id-passing + debug-hek | `2280468`, `8a0ee0c` | **Oorgeslaan** — Stitch-spesifiek. Paystack stuur een `reference`-veld; CHA se webhook is reeds idempotent (34 toetse). Geen ekwivalente gaping nie. | — |

Vooraf: die 15 Sep-dokumentwysigings is apart gecommit (`3ad2cba`). GRHS-repo se integriteit: `git fsck --no-dangling` skoon.

## 2. Ontwerpbesluite per ry

### 2.1 Ry A — kategoriemodel

- **Bron van waarheid:** die `heritage_category`-terme self. Elke term dra `cha_cat_colour` en `cha_cat_icon` as term-meta, redigeerbaar op Heritage Sites → Heritage Categories (`CHA_Category_Admin`). 'n Nuwe kategorie byvoeg in die admin is die enigste stap.
- **Palet (7 gleuwe, almal WCAG AA teen hul eie tekskleur, uit `app/index.html` se Clarens-tokens):** `#1a4a7a` plaque-blou (gereserveer vir slug `blue-plaque-site`), `#4E5530` olyf, `#c8a052` oker (donker teks), `#606e42` olyf-mid, `#8a5c2e` kultuur-bruin, `#2e3a1f` olyf-donker, `#c8d4b8` klip (donker teks). `--cha-red` is doelbewus weggelaat. Toewysing is skaarsheid-gebaseer en **gepersisteer**, nie gehash nie.
- **Saad-glyphs** (`CHA_Taxonomy::SEED_ICONS`, net geskryf waar 'n term nog geen glyph het nie): heritage-site 🏛️, blue-plaque-site 🔵, cultural-heritage 🎭, natural-heritage 🌿 — dieselfde vier wat die ou invoerder per kategorie geskryf het, dus verander niks sigbaar nie.
- **Feed-kontrak:** `cha/v1/content` kry 'n `categories`-blok (slug → name/colour/text/icon) en elke terrein kry `catSlug`. `ac` en `dot` verlaat die rekord. `icon` word by feed-bou opgelos: terrein se eie meta (HTML-entiteit gedekodeer, soos voorheen) anders die kategorie se glyph. Die feed word gespoel op `created_term`/`edited_term`/`delete_term` én wanneer 'n terrein herkategoriseer word.
- **Invoerder:** `match_category()` pas teen die geregistreerde terme (presies → genormaliseer → vaag, vaag word in rooi gerapporteer). Die gekureerde per-terrein glyph-tabel (31 terreine, HTML-entiteite) bly — dit is die enigste ding wat nog per terrein geskryf word. Geen `ac`/`dot` word meer geskryf nie.
- **Migrasie (eenmalig, `cha_category_styles_migrated`):** verwyder gestoorde `ac`/`dot`; verwyder `icon` **slegs** as dit een van die vier ou kategorie-glyphs is (rou emoji). Clarens se handgekose per-terrein-ikone is HTML-entiteite en pas nooit — hulle bly onaangeraak. Alles wat verwyder word, word eers in `cha_category_style_backup` gebêre; herstel met `wp cha category-styles restore`.
- **App:** `CATEGORIES`, `safeColour()`, `categoryFor()`; kenteken en kaart-aksent as inline `style` (geen bou-stap, terme onbekend by bou-tyd). 'n Terrein sonder kategorie lyk sigbaar **Uncategorised**; 'n kategorie sonder feed-definisie kry 'n rooi stippel-omlyning. `bp` bly die kaartspeld se tipering.
- **Webwerf:** `CHA_Category_Colours` druk `--cha-cat-colour-<slug>` / `--cha-cat-text-<slug>` / `--cha-cat-icon` en `--cha-photo` as CSS-veranderlikes op die WordPress-voorkant (die GRHS mu-plugin, nou binne die plugin). Opsioneel vir die tema om te gebruik.

### 2.2 Ry C — QR-kortskakels

- Herskryfreël `^s/([^/]+)/?$` → `cha_short_link`; opgelos via die `site_id`-meta, nooit `post_name` nie; altyd **302** met no-cache-koppe (`nocache_headers()`, `DONOTCACHEPAGE`, `litespeed_control_set_nocache`).
- Onbekende/ongepubliseerde id → die **`/trail/`-bladsy** (Clarens se bladsy-slug is `trail`, nie `heritage-trail` nie — nagegaan op die lewende webwerf) met 'n kalm nota (`.cha-plaque-note`), nooit 'n 404 nie.
- Basis-opsie `cha_short_link_base`, **slegs via WP-CLI** (`wp cha short-links base <url> | --unset | status | csv`). Met `{id}` daarin word 'n query-string-teiken gebou — die aanbevole waarde vir CHA is `https://trail.clarensheritage.org/?site={id}` (ry D). Die `allowed_redirect_hosts`-toelating word net rondom die een `wp_safe_redirect()` in `dispatch()` gevoeg en verwyder.
- Skandeer-teller: telling + laaste datum (POPIA-skoon), atomiese UPDATE. Admin: Heritage Sites → Plaque Links (leesalleen, CSV). Eenmalige herskryf-spoel `cha_short_links_rewrite_flushed` vir lêer-vervang-ontplooiings; admin-kennisgewing met "Fix now" as die roete later verdwyn.

### 2.3 Ry D — diepskakel oor Paystack

- `?site={id}` (hooflettergevoelloos, spasies gesnoei). Splash en eerste-besoek-oorlegger word oorgeslaan; die detail open sodra die feed gesettel het (`_sitesFeedSettled` — beide volgordes van verify-token vs. feed getoets).
- **Paystack-verskil:** Stitch keer terug na 'n kaal geregistreerde oorsprong; Paystack keer terug na `CHECKOUT_REDIRECT_URL` (die kaal app-oorsprong) met net sy eie `?trxref=&reference=`. Die behoud werk dus identies via **localStorage** (`cht_pendingUnlockSite`, 2 uur maks): `#buySubmit` stoor die terrein-id voor die oorhandiging; `handleUrlToken()` verbruik dit presies een keer en heropen die (nou ontsluite) terrein na 'n suksesvolle verifikasie. `paymentReturnParamsPresent()` sluit `trxref` in, sodat `?site=` nooit met 'n Paystack-terugkeer meeding nie. Die alternatief (`site` in `callback_url` inbou) is nie gedoen nie — dit sou `?site=` en `?trxref=` saam laat aankom en die wag-logika moes herontwerp word.
- Chrome se terug-knop-intervensie: die programmatiese `pushState` word uitgestel tot die eerste gebruikersinteraksie (`pointerup`/`touchend`/`click`/`keydown`). "Explore the Full Trail"-knoppie op 'n diepgeskakelde detail. `?site=` word uit die adresbalk gehaal ná gebruik.
- **Geen `sw.js`-bump nie:** slegs `index.html` het verander; HTML-navigasie is netwerk-eerste (README, v0.10 §8b).

### 2.4 Ry E — "Heritage Pass"

Alle besoekerstekste ("Phase 2", "Trail Pass") in die app, die aankoop- en promo-e-posse, die redeem-foutboodskap en die admin-etikette sê nou **Heritage Pass**. "Heritage Passport" (die stempel-funksie) is iets anders en is nie geraak nie. Die gratis-terreine-blurb tel nou uit die feed (`.free-site-count`) i.p.v. 'n hardgekodeerde 5.

### 2.5 Ry F — bou-gereedskap

`tools/build-zip.sh` bou uit **HEAD** met die letterlike voorvoegsel `cha-heritage-trail/`, inspekteer die argief (presies een topvlak-inskrywing, niks los nie) en verwyder sy uitset as dit nie klop nie. `tests/test-build-zip.php` vergelyk die zip se lêerlys met `git ls-tree`. README wys nou hierheen.

## 3. Toetse

`bash tests/run.sh` — **9 suites, 432 stellings**, 3 agtereenvolgende lopies groen (15 Sep):
checkout 19 · redeem-stock 24 · webhook 34 · build-zip 19 · short-links 57 · category-model 92 · category-render 45 · app-deep-link 69 · app-browser 73.

Struktuurkontroles op `app/index.html` (geen `data-cfasync`; Leaflet-`<script>` direk voor die hoof-`<script>`; eindig op `</script></body></html>`; `node --check`; elke `getElementById`-id bestaan) slaag. Grep-vee: geen `grhs`/`GRHS`/`ght_`/`GHT-`/`Kathy`/`Makery` in `app/`, `wordpress-plugin/`, `tests/`, `tools/`; `Stitch` net in die bestaande skenk-skakel. Ontsluitprys steeds slegs uit `class-cha-settings.php` / die feed.

## 4. Nuwe opsies, meta en opdragte

| Naam | Tipe | Doel |
|---|---|---|
| `cha_cat_colour`, `cha_cat_icon` | term-meta (heritage_category) | kategorie-kleur en -glyph |
| `cha_category_style_backup` | opsie (nie outolaai) | terugrol-rugsteun van die ac/dot-migrasie |
| `cha_category_styles_migrated` | opsie | eenmalige migrasie-wag |
| `cha_short_link_base` | opsie | kortskakel-teikenbasis (`{id}` toegelaat) |
| `cha_short_link_misses` | opsie | onbekende id's geskandeer (gekap op 50) |
| `cha_short_links_rewrite_flushed` | opsie | eenmalige herskryf-spoel-wag |
| `_cha_scan_count`, `_cha_scan_last` | post-meta (site) | skandeer-teller |
| `wp cha short-links status\|base\|csv` | WP-CLI | kortskakels bestuur |
| `wp cha category-styles status\|restore\|discard` | WP-CLI | migrasie-rugsteun bestuur |

Geen nuwe `.env`-geheime nie. Geen nuwe REST-roetes nie (die kortskakel is 'n herskryfreël, nie REST nie; hy stuur self no-cache-koppe).

## 5. Ontplooiing — handmatig, in hierdie volgorde (NIKS is nog gedoen nie)

**Volgorde maak saak: plugin eers, dan app.** Die app lees `categories`/`catSlug` uit die feed; as die app eerste gaan, wys elke terrein "Uncategorised" tot die plugin op is. Andersom is veilig (die ou app ignoreer die nuwe velde).

1. **Commit + push** `main` (ná Danie se bevestiging van hierdie verslag).
2. **Bou die zip:** `tools/build-zip.sh` → `dist/cha-heritage-trail.zip` (lêerlys en sha256 word gedruk).
3. **WP Admin → Plugins → Add New → Upload Plugin** → kies die zip → "Replace current with uploaded". Plugin-weergawe moet 0.2.0 wys.
4. **Eerste admin-laai ná die oplaai** laat die eenmalige take loop: ac/dot-migrasie (met rugsteun), saad-glyphs, kleur-persistering, herskryf-spoel. Gaan Heritage Sites → Heritage Categories na: vier terme, elk met 'n kleur en glyph. Gaan Heritage Sites → Plaque Links na: 31 rye.
5. **LiteSpeed Cache → Toolbox → Purge All**, en spoel die feed (enige terrein stoor, of wag 5 min).
6. **Toets die feed:** `curl -s https://clarensheritage.org/wp-json/cha/v1/content | jq '.categories, .sites[0].catSlug'`.
7. **Toets 'n kortskakel:** `curl -sI https://clarensheritage.org/s/supply-store` → `302`, `Location:` na die terrein se bladsy, `Cache-Control: no-store…`. As dit 404 gee: Settings → Permalinks → Save (of die "Fix now"-kennisgewing).
8. **App:** `npx wrangler deploy` van die repo-wortel. Geen `sw.js`-verandering, dus geen kas-bump nodig nie.
9. **Toets die app lewendig:** `https://trail.clarensheritage.org/?site=primary-school` (gratis terrein) open die detail direk; kenteken en aksentkleur kom van die kategorie; terug-knop werk ná 'n tik.
10. **Opsioneel, wanneer die plakkate na die app moet wys:** `wp cha short-links base "https://trail.clarensheritage.org/?site={id}"` (of later `--unset`). Toets dan `/s/supply-store` weer — dit moet nou na die app se `?site=` gaan.
11. **Terugrol as nodig:** ou zip oplaai; `wp cha category-styles restore` bring ac/dot/ikone terug (die rugsteun bly totdat `discard` uitdruklik gedoen word).

## 6. Bekende beperkings / oop punte

- Ry B (foto's) wag op 'n R2-emmer en -geloofsbriewe.
- Die per-kategorie CSS-veranderlikes (`CHA_Category_Colours`) word gedruk maar die Clarens-tema gebruik hulle nog nêrens — geen effek totdat die tema dit doen nie.
- Die kaartspeld-kleur is steeds per tipe (`bp`/heritage/partner), nie per kategorie nie — soos in GRHS, 'n bewuste nie-doelwit.
- `tests/test-app-browser.js` het Chrome én toegang tot `unpkg.com` (Leaflet) nodig — sien `KNOWN_ISSUES.md`.
