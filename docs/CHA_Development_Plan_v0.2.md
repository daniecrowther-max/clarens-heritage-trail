# Clarens/CHA Heritage Trail — Herbou op GRHS-argitektuur — Ontwikkelingsplan

> Lewende beplanningsdokument. **Status: v0.2 · 19 Augustus 2026 — vervang v0.1.**
> **Rigtingverandering (v0.2):** nie meer 'n klein Paystack-lapwerk op CHA se bestaande kodebasis nie. Ons **vurk GRHS se reeds-verharde kodebasis** (app + WordPress-inprop) as CHA se nuwe fondament, trek Clarens se 31 terreine en handelsmerk daarin in, en swap Stitch → Paystack binne-in daardie fondament. GRHS se eie lewende installasie (graaffreinetheritage.co.za) word nie aangeraak nie — ons vurk net die kode, wat Danie se eie IE is.

---

## 1. Waarom hierdie rigtingverandering (bevestig deur 'n regte oudit)

Terwyl ek GRHS se kodebasis ondersoek het, het ek 'n dokument raakgeloop wat reeds presies hierdie vergelyking gedoen het: `content/clarens-payment-audit.md` in die `gr-heritage-trail`-repo — geskryf om die GRHS-bou in te lig, nie vir hierdie plan nie, maar dit bevestig elke punt wat Danie genoem het:

- **Tokens.** CHA se koop-token (`CHT-XXXX-XXXX`) word steeds van 'n formule afgelei met 'n **publieke geheim wat in die app-bundel versend word** (`TOKEN_SECRET = 'CHA2026'`) — direk sigbaar en formaat-vervalsbaar in die blaaier. GRHS se tokens (`GHT-XXXX-XXXX-XXXX-XXXX`) is **volledig ondeursigtig, kripto-lukraak gegenereer, en bediener-uitgereik** — die app ken géén geheim of hash-formule nie; elke geldigheidstoets is 'n databasis-opsoek.
- **Admin/promo-ontsluiting.** CHA se oorspronklike stelsel het admin-/promo-tokens **heeltemal kliënt-kant** ontsluit, geen bediener-oproep nodig nie (`verifyAndStore()` se kortsluitpad). 'n Latere "Phase A"-lapwerk (`access-tokens.php`) het dit reeds gedeeltelik reggemaak vir CHA, maar die koop-token-pad bly op die ou skema. GRHS is **deurgaans bediener-gesaghebbend** vir al drie tipes, van die begin af.
- **Onbevestigde webhook — 'n lewende risiko.** CHA se `/stitch-webhook` verifieer **glad geen handtekening nie** (`permission_callback => __return_true`, die "webhook secret"-instelling bestaan maar word nooit gebruik nie). Enigiemand wat 'n Stitch-`payment_id` kan raai, kan 'n aankoop as betaal vervals. GRHS verifieer Svix-handtekeninge met herhaal-beskerming. **Danie se besluit:** dit word reggestel as deel van hierdie herbou (nie apart nie), gegewe die app tans in onderhoud-modus met leë inhoud is — lae blootstelling intussen.
- **Terrein-inhoud.** CHA se 31 terreine is 'n **hardgekodeerde `SITES`-skikking in `index.html`** — verander 'n terrein beteken 'n kode-wysiging en herontplooiing. GRHS se terreine woon volledig in WordPress (`site`-CPT + taksonomie), met 'n bewese spreadsheet-invoerder — presies die "maklik verander"-eienskap wat CHA kortkom.
- **Ander GRHS-funksies wat CHA glad nie het nie:** e-pos-afleweringstaat-opsporing (status/pogings/laaste-fout per token/aankoop, herstuur-vermoë), tarief-beperking op `/verify-token`, geen-kas-headers op auth-roetes (GRHS het 'n regte LiteSpeed-kas-foutfout gevind en reggestel — 'n herroepe token het steeds as geldig gevalideer vanaf 'n growwe kas), Voucher Redemption Metrics-kieserskerm, geheime in `.env` pleks van die databasis.

**Interessante bevestiging:** GRHS se eie invoerder-kode (`class-grhs-importer.php`) bevat reeds 'n TODO-kommentaar wat hierdie presiese stap vooruitskou: *"TODO(step 4, app fork): replace these placeholders with the accent classes, marker colours and glyphs the forked Clarens app actually uses…"* — die argitektuur is letterlik gebou met die oog op 'n toekomstige Clarens-vurk.

---

## 2. Grondreëls

- **GRHS se lewende installasie (graaffreinetheritage.co.za) word nie aangeraak nie.** Ons vurk net die **kode** uit die private `gr-heritage-trail`-repo (Danie se eie IE, bevestig — geen eienaarskapkwessie nie) na 'n nuwe kodebasis vir CHA. Niks hier raak GRHS se databasis, instellings, of lewende gebruikers nie.
- **REST-naamruimte en localStorage-voorvoegsel bly `cha/v1` / `cht_`** (Danie se besluit) — nie 'n vars naamruimte soos GRHS s'n nie. Dit hou reeds-geïnstalleerde toestelle se plaaslike tokens bruikbaar, en pas by CHA se bestaande WordPress-installasie by `clarensheritage.org`.
- **24 September bly die teiken.** Skopomvang en volgorde word ingerig om dit haalbaar te maak — party GRHS-verfynings (bv. volle e-pos-herprobering-outomatisering) kan ná lansering volg as hulle nie krities is nie.
- **Slegs een betaalspoor: Paystack.** Stitch word volledig verwyder (nie GRHS se `class-grhs-stitch.php` self nie — daardie lêer word as sjabloon gebruik om te vervang, presies soos die vorige plan se logika, maar nou binne die GRHS-argitektuur ingebed).
- **Geen webhook nodig vir Paystack.** Waar GRHS vir Stitch 'n Svix-handtekening-geverifieerde webhook moes bou (Stitch is inherent async), laat Paystack se **Verify Transaction-API** direkte, sinchrone, bediener-geverifieerde bevestiging toe — ons vra Paystack self, met ons eie geheime sleutel; daar's niks om te vervals nie. Dit is nie 'n afwattering van GRHS se sekuriteitspatroon nie — dit omseil die hele kategorie risiko wat die webhook-oudit-bevinding beskryf.
- **Bestaande betalers moet nie hul tokens verloor nie.** Enige reeds-betaalde ry in die huidige `wp_cha_trail_purchases`-tabel word na die nuwe tabel gemigreer (token-string ongeskonde) sodat 'n toestel met 'n reeds-gestoorde `cht_token` steeds korrek ontsluit.

---

## 3. Wat oorgedra word (fork-plan)

Uit `gr-heritage-trail` se `/wordpress-plugin`:

| GRHS-lêer | Word | CHA-weergawe |
|---|---|---|
| `class-grhs-post-types.php`, `-taxonomy.php`, `-meta.php`, `-site-meta-box.php`, `-partner-meta-box.php` | Inhoudsmodel — direk hernoem (`GRHS_*` → `CHA_*`, tabelvoorvoegsel `grhs_` → `cha_`) | Ongeskonde patroon; CHA se 31 terreine word ingevoer, nie hardgekodeer nie |
| `class-grhs-rest.php` | REST-voer, `grhs/v1` → **`cha/v1`** | Feed-vorm identies |
| `class-grhs-cors.php`, `-shortcodes.php`, `-social-meta.php` | Direk hernoem, geen logika-verandering | |
| `class-grhs-xlsx-reader.php`, `-importer.php` | Direk hernoem | Gebruik om Clarens se 31 terreine in te voer (§5) |
| `class-grhs-env.php` | Direk hernoem — **CHA skuif ook na `.env`-gebaseerde geheime** (was voorheen in `wp_options`, oudit-bevinding #7) | |
| `class-grhs-settings.php` | Direk hernoem — prys as instelling (`cha_unlock_price_cents`), verstek te bevestig (huidige CHA-prys was R99, gesprekke het R100 genoem) | |
| `class-grhs-tokens.php` | Direk hernoem — opaque tokens, voorvoegsel **`GHT-` → `CHT-`** (nie `CHA-ADMIN-`/`CHA-PROMO-` nie — eenvormige opaque formaat vir al drie tipes, soos GRHS) | |
| `class-grhs-purchases.php` | Direk hernoem | |
| `class-grhs-stitch.php` | **Vervang deur `class-cha-paystack.php`** — sien §4 | |
| `class-grhs-redeem.php`, `-admin.php`, `-voucher-metrics.php` | Direk hernoem | |

Uit `/app`: vurk `index.html`, `sw.js`, `manifest.json` — re-skin na Clarens se handelsmerk (§6).

---

## 4. Betaling: Paystack binne die GRHS-patroon

Dieselfde tegniese ontwerp as v0.1 se bouspesifikasie, nou binne `class-cha-paystack.php` ingebed i.p.v. as 'n los lapwerk op die ou `stitch.php`:

- `POST cha/v1/checkout` — bediener-gesaghebbende prys (`CHA_Settings::unlock_price_cents()`), genereer 'n opaque `CHT-`-token (`CHA_Tokens::generate()`), skep 'n Paystack **Initialize Transaction** met `reference = 'CHA-' . $token`, `subaccount` (80/20-verdeling, sien §7), `callback_url = https://trail.clarensheritage.org`. Voeg 'n pending-ry by via `CHA_Purchases::insert_pending()`.
- `GET cha/v1/verify-token` — DB eerste (`CHA_Purchases::find_by_token()`); as reeds `paid`, gee dadelik terug (geen herhaalde Paystack-oproep nie); as `pending`, roep Paystack se **Verify Transaction**-API direk op, bevestig status/bedrag/geldeenheid, werk DB by, e-pos die token (`CHA_Purchases::send_token_email()`, met afleweringstaat-opsporing wat reeds ingebou is).
- Geen `/stitch-webhook`-ekwivalent nodig nie (§2).
- Tarief-beperking, geen-kas-headers op auth-roetes: oorgeneem ongeskonde uit GRHS se patroon.

---

## 5. Inhoudsmigrasie — Clarens se 31 terreine

GRHS se invoerder verwag 'n spreadsheet (`.xlsx`/`.csv`) met hierdie kolomme: Site ID, Site Name, Category, Year, Street Address, GPS Latitude, GPS Longitude, Short Summary, Full History, Key Facts, Blue Plaque Text, Primary Photo Filename, Additional Photo Filenames, Photo Credit, Sources, Captured By, Free/Paid, Notes.

**Voorgestelde benadering:** eerder as om 31 terreine met die hand oor te tik, skryf 'n eenmalige skrip wat Clarens se bestaande `SITES`-skikking (uit die werklike app-kode, commit `a8627de`) direk ontleed en 'n CSV in presies hierdie vorm genereer — dan word die bestaande "Import Sites"-admin-skerm (reeds gebou, geen nuwe kode nodig nie) gebruik om dit een keer in te voer. Vinniger en betroubaarder as handmatige oortikwerk, en toets die invoerder self met regte data.

---

## 6. Front-end re-skin — Clarens-handelsmerk

- Kleure/lettertipes: `background_color: #2e3220`, `theme_color: #484E32`, Cormorant Garamond + Jost (uit die bestaande `manifest.json`).
- Kaartsentrum: `[-28.514811235523656, 28.421035109664686]`, zoom 15 (uit die werklike `a8627de`-app).
- Ikone/embleem: bestaande Clarens-bates hergebruik.
- **Kontroleer of die Donate-paneel ('n Clarens-spesifieke funksie — `btnDonate`/`donateModal`) in GRHS se vurk behoue gebly het.** GRHS het waarskynlik nie 'n Donate-funksie nodig gehad nie en dit kon uitgeval het tydens die oorspronklike vurk-van-Clarens — moet eksplisiet herstel word as Danie dit vir CHA wil behou.
- Voucher/vennoot-lys: Clarens se eie vennote moet via die `partner`-CPT ingevoer word (data-taak, nie kode nie).

---

## 7. Handmatige stappe — Danie in die Paystack-paneel

Ongeskonde uit v0.1: skep 'n subaccount vir CHA met `percentage_charge: 20` (gee 80% aan CHA, 20% aan Acutus — bevestig via Paystack se eie dokumentasie), kopieer die subaccount-kode en geheime sleutel in die nuwe `.env`.

---

## 8. Databasis-migrasie — bestaande betalers

Voor die ou `cha-trail-admin`-inprop gedeaktiveer word: onttrek alle `status = 'paid'`-rye uit `wp_cha_trail_purchases` (e-pos, token, bedrag, betaal-datum) en voer dit in die nuwe `wp_cha_purchases`-tabel in, token-string ongeskonde. 'n Reeds-betaalde gebruiker se toestel (met 'n gestoorde `cht_token`) sal dan steeds korrek ontsluit via die nuwe stelsel se eenvoudige DB-opsoek, ongeag die ou token se (ander) formaat.

---

## 9. Voorlopige tydlyn (19 Aug → 24 Sept, ~5 weke)

| Week | Fokus |
|---|---|
| 1 (19–26 Aug) | Vurk `/wordpress-plugin` + `/app` na nuwe CHA-kodebasis; meganiese hernoem (`GRHS_*`→`CHA_*`, `grhs_`→`cha_`, `GHT-`→`CHT-`, naamruimte→`cha/v1`); Paystack-subaccount opgestel |
| 2 (27 Aug–2 Sep) | `class-cha-paystack.php` gebou + sandbox-getoets; databasis-migrasieskrip vir bestaande betalers |
| 3 (3–9 Sep) | Front-end re-skin (kleure/fonte/kaartsentrum/Donate-paneel); terrein-migrasieskrip + invoer via bestaande "Import Sites"-skerm |
| 4 (10–16 Sep) | End-tot-end-toets: volledige aankoopvloei met regte Paystack-transaksie; QA oor toestelle; vennote/vouchers ingevoer |
| 5 (17–24 Sep) | Finale QA-buffer, weergawe-opdatering, lansering |

Die eienaars-toestemming vir terrein-inhoud (~1 week vanaf 19 Aug, per Danie) loop parallel — dit blokkeer nie die argitektuurwerk nie, net wanneer die regte (nie toets-)inhoud gepubliseer kan word.

---

## 10. Oop vrae

1. **Front-end-ontplooiing:** hou Clarens se bestaande Cloudflare Workers Static Assets-opstel (`trail.clarensheritage.org`), of skuif na GRHS se patroon (Cloudflare Pages, outomaties op push na `main`)? Ek stel voor: hou die bestaande opstel om DNS/infrastruktuur-omwenteling onder tydsdruk te vermy — kan later saamgetrek word.
2. **Huidige prys:** kode het R99 (9900c), gesprekke het R100 genoem — watter een is korrek vir die nuwe `cha_unlock_price_cents`-verstek?
3. **Ou WordPress-inprop:** word `cha-trail-admin` heeltemal gedeaktiveer/verwyder sodra die nuwe inprop lewend is, of loop hulle 'n rukkie langs mekaar (nie aanbeveel nie, gegewe REST-naamruimte-botsing as albei `cha/v1` registreer)?
4. Soos voorheen: wil jy dat ek nou 'n volledige Claude Code-bouspesifikasie skryf (soos die vorige een, maar nou vir hierdie groter herbou), sodat jy self met die kodewerk kan begin?

---

## 11. Veranderingslog

- **v0.2 (19 Aug 2026)** — Rigting verander van "Paystack-lapwerk op CHA se bestaande kode" na "vurk GRHS se argitektuur, trek Clarens-data in", ná bevestiging deur `content/clarens-payment-audit.md` en direkte lees van GRHS se token-/betaal-/inhoudsmodel-kode. Live webhook-kwesbaarheid op CHA geïdentifiseer (opgelos deur hierdie herbou, per Danie se besluit). 24 Sept-teiken bevestig steeds.
- **v0.1 (19 Aug 2026)** — Eerste weergawe (minimale Paystack-omskakeling op bestaande CHA-kode). Vervang deur v0.2.
