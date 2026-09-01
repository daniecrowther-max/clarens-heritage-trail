# Clarens/CHA Heritage Trail — Herlansering met Paystack — Ontwikkelingsplan

> Lewende beplanningsdokument, in dieselfde gees as GRHS s'n. Status: **v0.1 · 19 Augustus 2026**
> Skopomvang volgens Danie se besluit: **minimaal — net Clarens/CHA, een tenant, Paystack in plek van Stitch.** Geen multi-tenant-argitektuur word nou gebou nie; die rigting daarheen word in §7 gedokumenteer vir later.

---

## 1. Projekopsomming

Herlanseer die Clarens Heritage Trail-app (CHA se bestaande produk) teen **24 September 2026**, betyds vir die Clarens Festival of Light, met **Paystack** as betalingsverskaffer in plek van Stitch Express. Die app is tans in 'n bewuste onderhoud-modus (hangende toestemming van al die terrein-eienaars) — dit moet herstel word na sy volle funksionaliteit, met die betaalvloei omgeskakel.

**Harde beperking:** die Graaff-Reinet Heritage Society (GRHS)-projek is 'n aparte, lopende, betalende kliëntverbintenis met sy eie 2 November-lansering en 'n eksplisiete reël in sy bouplan ("Stitch Express is the only payment rail — do not introduce any parallel payment mechanism"). **Hierdie plan raak GRHS se kode nie.** Enigiets wat tydens hierdie werk in GRHS raakgesien word wat verander moet word, word aan Danie gerapporteer en in GRHS se eie projek/sessie gedoen.

Die Clarens Festival of Lights (CFoL)-app self **gaan nie voort nie** — maar sy kodebasis is 'n werkende, geteste verwysing vir presies die soort betaal-argitektuur wat hier benodig word (sien §4).

---

## 2. Grondwaarheid — huidige status (uit die repo's, 19 Aug 2026)

### Front-end (`clarens-heritage-trail`, Cloudflare Workers Static Assets, `trail.clarensheritage.org`)
- Die **huidige HEAD** (commit `5f722d7`) is 'n opsetlike onderhoud-bladsy — nie die werklike app nie.
- Die **werklike, volledig-funksionele app** leef by commit **`a8627de`** (die commit onmiddellik vóór "Tydelik deaktiveer app — vertoon onderhoud-bladsy", commit `d905c33`). Dis die punt om van te vurk/herstel.
- Op daardie punt: **31 erfenisterreine** (onlangs verminder van 32 ná verwydering van "Die Pondok"), 5 gratis terreine (`FREE_SITES`), Partner Vouchers, Heritage Passport, GPS-kaart, Donate-paneel.
- `content.json` is tans **leeg** (bewustelik — hangende eienaars-toestemming). Dit moet gevul word voor lansering; dis 'n inhouds-/toestemmingsafhanklikheid, nie 'n tegniese een nie.

### Huidige betaalvloei (Stitch, uit die werklike `a8627de`-weergawe van `index.html`)
- Drie token-tipes, alle plaaslik gevalideer via 'n eenvoudige hash (`TOKEN_SECRET = 'CHA2026'`), met bediener-kant bevestiging as die gesag:
  - `CHT-XXXX-XXXX` — koop-token (Trail Pass)
  - `CHA-PROMO-DDDDDDXXXXXX` — 90 dae geldig
  - `CHA-ADMIN-XXXXXX` — geen vervaldatum
- Stitch Express hosted checkout; terugkeer-URL dra `?reference=CHA-CHT-XXXX-XXXX` (of legacy `?token=`); `handleUrlToken()` ontleed dit.
- `verifyAndStore()` bevestig `CHT-`-tokens teen `wp-json/cha/v1/verify-token`; `CHA_CREATE_PAYMENT_URL` = `wp-json/cha/v1/checkout`.
- Token/status in `localStorage`: `cht_token`, `cht_verified`, `cht_visited`.

### Agterkant (`cht-heritage-trail` repo → plugin `cha-trail-admin`)
- Nou behoorlik in Git (was voorheen net los zip-lêers — presies dieselfde dissipline as GRHS s'n).
- REST-naamruimte `wp-json/cha/v1`: `/checkout`, `/verify-token`, `/redeem`, `/voucher-status`.
- Lêers: `stitch.php` (betaling), `access-tokens.php`, `rest-api.php`, `cpt.php`, `meta-boxes.php`, `settings.php`, `github.php`.
- Deploy-dissipline: nooit die werkboom zip nie — altyd `git archive` vanaf 'n skoon commit (soos GRHS se eie reël).

---

## 3. Wat uit CFoL geleen word — die bewese Paystack-patroon

Al gaan CFoL self nie voort nie, is sy `payment.js` presies die argitektuur wat hier nodig is:

- **`paymentGate`** (enjin-vlak) besit die "ontsluit"-status — app-toestand wat 'n verskaffer-wisseling oorleef, soos paspoort-stempels.
- **Verskaffers** (bv. `createPaystackProvider`) besit net geldbeweging: `startPayment()` / `verifyPayment()`. Watter verskaffer loop, is **data** (`config.json → payment.provider`), nooit hardgekodeerde logika nie.
- Die Paystack-fabriek laai reeds Paystack Inline v2 (`js.paystack.co/v2/inline.js`), hanteer `subaccountCode`/`splitCode` vir inkomste-verdeling — presies die meganisme wat ons oorspronklik vir CHA bespreek het — en val terug op 'n "optimistic/test mode" as geen `verifyUrl` gestel is nie (met 'n eksplisiete waarskuwing om dit voor regte lansering reg te stel).
- 'n Getoetste sandbox-sleutel (`pk_test_...`) en 'n werkende `verify-worker` (Cloudflare Worker) patroon vir bediener-kant-bevestiging bestaan reeds.

Dis nie 'n herhaling van die oorspronklike Stitch-integrasiewerk se pyn nie — dis 'n reeds-deurgewerkte patroon wat net na Clarens/CHA se konteks oorgedra hoef te word.

---

## 4. Voorgestelde tegniese benadering (minimale omvang)

1. **Herstel die werklike app** vanaf commit `a8627de` (nie die onderhoud-bladsy nie) as die werkbasis.
2. **Front-end:** vervang net die koop-vloei (Stitch checkout + verify) met 'n oorgeplaaste weergawe van CFoL se `paymentGate`/`createPaystackProvider`-patroon. PROMO- en ADMIN-tokens is nie Stitch-afhanklik nie — hulle bly ongeskonde. Net `CHA_CREATE_PAYMENT_URL`, `handleUrlToken()` se Stitch-spesifieke redirect-ontleding, en `verifyAndStore()` se `CHT-`-tak verander.
3. **Agterkant (`cha-trail-admin`):** vervang `stitch.php` met 'n nuwe `paystack.php` wat dieselfde publieke REST-vorm (`/checkout`, `/verify-token`) handhaaf — die front-end/agterkant-kontrak verander min, net die interne implementasie. Verifikasie via Paystack se Verify Transaction API, of 'n ligte Cloudflare `verify-worker` soos CFoL s'n indien jy verifikasie buite WordPress verkies (vinniger, minder WordPress-laai per betaling).
4. **Paystack-rekening:** reeds in Danie se eie naam (bevestig, geen probleem). Net 'n subaccount/split-persentasie vir CHA self moet in die Paystack-paneel opgestel word (soos CFoL se WorldVisuals-subaccount-konsep).
5. **Inhoud:** `content.json` moet gevul word met CHA se terreine voor publieke lansering — 'n aparte, nie-tegniese afhanklikheid (eienaars-toestemming), soos jy self genoem het.

---

## 5. Voorlopige tydlyn (19 Aug → 24 Sept 2026, ~5 weke)

| Week | Fokus |
|---|---|
| 1 (19–26 Aug) | Herstel werklike app-kode van `a8627de`; Paystack-subaccount vir CHA opgestel; oordra van `paymentGate`-patroon begin (sandbox) |
| 2–3 (27 Aug–9 Sep) | `paystack.php`-agterkant gebou + sandbox-getoets; front-end geïntegreer; `content.json` gevul sodra eienaars-toestemming deur is |
| 4 (10–16 Sep) | End-to-end lewendige betaal-toets (regte Paystack-transaksie, klein bedrag); QA oor toestelle/blaaiers |
| 5 (17–24 Sep) | Finale QA-buffer, weergawe-opdatering (CACHE_NAME ens.), lansering |

Die eienaars-toestemming-status vir die terrein-inhoud is die grootste onbekende risiko op hierdie tydlyn — nie die Paystack-omskakeling self nie.

---

## 6. Effek op bestaande tokens/kliënte

Bestaande PROMO/ADMIN-tokens wat reeds uitgereik is, bly geldig (ongeraakte logika). Enige gebruiker met 'n reeds-ontsluitte toestel (`cht_verified` in `localStorage`) word nie geraak nie. Slegs *nuwe* koop-transaksies gaan voortaan deur Paystack.

---

## 7. Toekomstige rigting — engine-argitektuur (ná 2027, nou net gedokumenteer)

Danie het gevra dat die rigting nou reeds vasgelê word, al word dit eers later gebou:

- **Front-end-teiken:** een gedeelde "trail-engine" (app-romp, kaart, paspoort, PWA/service-worker, `paymentGate`-module) met 'n JSON/config-gedrewe tema en content-feed per tenant — soos CFoL se `config.json` + `featureFlags`-patroon reeds bewys het. Dit vervang die huidige patroon waar elke installasie (Clarens, GRHS) sy eie hardgekodeerde `SITES`-skikking en aparte `manifest.json` het.
- **Agterkant:** nog nie besluit nie. Clarens (`cha/v1`) en GRHS (`grhs/v1`) het reeds onafhanklik uiteengeloop — elk sy eie CPT-struktuur, veldlys en plugin. Voorlopige teiken-rigting (nie nou gebou nie): 'n gedeelde "trail-core"-inprop met 'n tenant-konfigurasie (naamruimte-voorvoegsel, betalingsverskaffer, veldlys) eerder as volledig aparte plugins per kliënt — maar dit word eers ná GRHS se eie lansering (2 Nov) en na 2027 ondersoek, wanneer nuwe installasies weer instroom.
- **Herbruikbare bates wat reeds bestaan en behou moet word:** GRHS se hand-oor-dokumentasiepatroon (bedryfshandleiding + opleiding-kraaldek — self reeds deur GRHS se eie plan as "wit-etiket-bate" gemerk) behoort ook vir CHA en toekomstige installasies gebou te word, eerder as elke keer van voor af.
- **CFoL-repo:** word nie verder ontwikkel nie, maar bly staan as argitektuur-verwysing (`payment.js` se provider-patroon, `config.json`/skema-gedrewe inhoudstruktuur). Die moeite werd om 'n kort `README`-notasie by daardie repo te voeg wat sê dit is gearchiveer/nie-aktief, sodat dit nie later verwar word met 'n lopende projek nie.

---

## 8. Oop vrae vir Danie

1. **Eienaars-toestemming vir CHA se terrein-inhoud** — wat is die status, en verwag jy dit binnekort? Dit bepaal of `content.json` betyds gevul kan word, of ons met plekhouerinhoud moet begin en later vervang.
2. **Paystack subaccount/split-persentasie vir CHA** — het jy reeds 'n syfer in gedagte (soos die 30% wat CFoL vir WorldVisuals SA gebruik het), of moet dit eers bepaal word?
3. **Verifikasie-benadering** — Paystack se eie Verify Transaction API direk vanaf WordPress, of 'n aparte ligte Cloudflare `verify-worker` (soos CFoL s'n)? Laasgenoemde is vinniger en ontkoppel van WordPress se laaityd, maar is nog 'n stuk infrastruktuur om te onderhou.
4. **Wil jy hê ek moet nou met die werklike kodewerk begin** (die `paymentGate`-oordrag, `paystack.php` bou), of eers 'n meer gedetailleerde tegniese spesifikasie skryf (soos GRHS se `GR_Build_Brief_ClaudeCode_v1.md`-styl) voordat enige kode geraak word?

---

## 9. Veranderingslog

- **v0.1 (19 Aug 2026)** — Eerste weergawe. Grondwaarheid vasgestel uit `clarens-heritage-trail`, `cht-heritage-trail`, en `clarens_festival_of_lights`-repo's. Skopomvang bevestig deur Danie: minimaal (net CHA/Clarens, een tenant), GRHS bly onaangeraak, engine-rigting gedokumenteer vir later.
