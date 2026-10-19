# Clarens/CHA Heritage Trail — Herbou op GRHS-argitektuur — Ontwikkelingsplan

> Lewende beplanningsdokument. **Status: v0.5 · 23 Augustus 2026 — vervang v0.4. PROJEK AFGESLUIT.**
> **Opdatering in v0.5:** al die werk uit v0.4 se oorblywende lys (§10 punt 5: front-end re-skin, terrein-inhoud-migrasie, QA oor toestelle, vennote/vouchers) is nou voltooi en waar moontlik onafhanklik geverifieer op die lewende werf. Sien §5, §6, §9 en §12 hieronder vir volle besonderhede. Danie sluit die projek nou af — §12 lys die klein oorblywende (nie-blokkerende) items vir wanneer dit gerieflik is.

> **Rigtingverandering (v0.2):** nie meer 'n klein Paystack-lapwerk op CHA se bestaande kodebasis nie. Ons **vurk GRHS se reeds-verharde kodebasis** (app + WordPress-inprop) as CHA se nuwe fondament, trek Clarens se 31 terreine en handelsmerk daarin in, en swap Stitch → Paystack binne-in daardie fondament. GRHS se eie lewende installasie (graaffreinetheritage.co.za) word nie aangeraak nie — ons vurk net die kode, wat Danie se eie IE is.

---

## 1. Waarom hierdie rigtingverandering (bevestig deur 'n regte oudit)

Terwyl ek GRHS se kodebasis ondersoek het, het ek 'n dokument raakgeloop wat reeds presies hierdie vergelyking gedoen het: `content/clarens-payment-audit.md` in die `gr-heritage-trail`-repo — geskryf om die GRHS-bou in te lig, nie vir hierdie plan nie, maar dit bevestig elke punt wat Danie genoem het:

- **Tokens.** CHA se koop-token (`CHT-XXXX-XXXX`) word steeds van 'n formule afgelei met 'n **publieke geheim wat in die app-bundel versend word** (`TOKEN_SECRET = 'CHA2026'`) — direk sigbaar en formaat-vervalsbaar in die blaaier. GRHS se tokens (`GHT-XXXX-XXXX-XXXX-XXXX`) is **volledig ondeursigtig, kripto-lukraak gegenereer, en bediener-uitgereik** — die app ken géén geheim of hash-formule nie; elke geldigheidstoets is 'n databasis-opsoek.
- **Admin/promo-ontsluiting.** CHA se oorspronklike stelsel het admin-/promo-tokens **heeltemal kliënt-kant** ontsluit, geen bediener-oproep nodig nie (`verifyAndStore()` se kortsluitpad). 'n Latere "Phase A"-lapwerk (`access-tokens.php`) het dit reeds gedeeltelik reggemaak vir CHA, maar die koop-token-pad bly op die ou skema. GRHS is **deurgaans bediener-gesaghebbend** vir al drie tipes, van die begin af.
- **Onbevestigde webhook — 'n lewende risiko.** CHA se `/stitch-webhook` verifieer **glad geen handtekening nie** (`permission_callback => __return_true`, die "webhook secret"-instelling bestaan maar word nooit gebruik nie). Enigiemand wat 'n Stitch-`payment_id` kan raai, kan 'n aankoop as betaal vervals. GRHS verifieer Svix-handtekeninge met herhaal-beskerming. **Danie se besluit:** dit word reggestel as deel van hierdie herbou (nie apart nie) — **opgelos:** Paystack se direkte Verify Transaction-API het die hele webhook-kategorie oorbodig gemaak (§2, §4).
- **Terrein-inhoud.** CHA se 31 terreine was 'n **hardgekodeerde `SITES`-skikking in `index.html`** — verander 'n terrein het 'n kode-wysiging en herontplooiing beteken. GRHS se terreine woon volledig in WordPress (`site`-CPT + taksonomie), met 'n bewese spreadsheet-invoerder — presies die "maklik verander"-eienskap wat CHA kortgekom het. **Opgelos** — sien §5.
- **Ander GRHS-funksies wat CHA glad nie gehad het nie:** e-pos-afleweringstaat-opsporing, tarief-beperking op `/verify-token`, geen-kas-headers op auth-roetes, Voucher Redemption Metrics-kieserskerm, geheime in `.env` pleks van die databasis — alles oorgeneem via die fork.

**Interessante bevestiging:** GRHS se eie invoerder-kode (`class-grhs-importer.php`) bevat reeds 'n TODO-kommentaar wat hierdie presiese stap vooruitskou: *"TODO(step 4, app fork): replace these placeholders with the accent classes, marker colours and glyphs the forked Clarens app actually uses…"* — die argitektuur is letterlik gebou met die oog op 'n toekomstige Clarens-vurk.

---

## 2. Grondreëls

- **GRHS se lewende installasie (graaffreinetheritage.co.za) word nie aangeraak nie.** Ons vurk net die **kode** uit die private `gr-heritage-trail`-repo (Danie se eie IE, bevestig — geen eienaarskapkwessie nie) na 'n nuwe kodebasis vir CHA. Niks hier raak GRHS se databasis, instellings, of lewende gebruikers nie.
- **REST-naamruimte en localStorage-voorvoegsel bly `cha/v1` / `cht_`** (Danie se besluit) — nie 'n vars naamruimte soos GRHS s'n nie. Dit hou reeds-geïnstalleerde toestelle se plaaslike tokens bruikbaar, en pas by CHA se bestaande WordPress-installasie by `clarensheritage.org`.
- **24 September was die teiken — behaal.** Al die werk is voor die teiken afgehandel (23 Aug 2026).
- **Slegs een betaalspoor: Paystack.** Stitch is volledig verwyder.
- **Geen webhook nodig vir Paystack nie.** Paystack se **Verify Transaction-API** laat direkte, sinchrone, bediener-geverifieerde bevestiging toe.
- **Bestaande betalers het nie hul tokens verloor nie.** Gemigreer, bevestig ontsluit — sien §8.

---

## 3. Wat oorgedra is (fork-plan) — voltooi

Uit `gr-heritage-trail` se `/wordpress-plugin`: alle klasse hernoem en oorgedra (`GRHS_*`→`CHA_*`, ens.), soos in v0.1–v0.4 uiteengesit. Uit `/app`: `index.html`, `sw.js`, `manifest.json` gevurk en na Clarens se handelsmerk herskin (§6).

---

## 4. Betaling: Paystack binne die GRHS-patroon — voltooi en lewend

`class-cha-paystack.php` is gebou, in 'n sandbox getoets, en daarna met 'n regte klein transaksie end-tot-end bevestig (`/checkout` → `/verify-token`). Geen `/stitch-webhook`-ekwivalent was nodig nie. Tarief-beperking en geen-kas-headers op auth-roetes is ongeskonde uit GRHS se patroon oorgeneem.

---

## 5. Inhoudsmigrasie — Clarens se 31 terreine — voltooi en geverifieer

Die eenmalige skrip (`scripts/migrate-site-content.js`) het Clarens se bestaande `SITES`-skikking (commit `a8627de`) na `site-content-import.csv` omgeskakel, wat via die bestaande "Import Sites"-admin-skerm ingevoer is. Verifikasie-geskiedenis:

- CSV-inhoud onafhanklik nagegaan (Python + direkte PHP `fgetcsv()`-toets wat die invoerder se eie ontledingskode naboots) — 31/31 rye korrek, geen kolom-wanpassings nie.
- **Fotos-probleem opgelos:** die vurk het per ongeluk die plaaslike foto-voorlaaier verwyder sonder om die "Photo base URL"-instelling of die werklike `.webp`-lêers oor te dra. Reggestel deur die 31 foto's uit die ou repo na `app/photos/` te kopieer, te ontplooi, en die "Photo base URL"-instelling in WP Admin op te stel. Onafhanklik bevestig op die lewende werf (bv. Clarens Primary School se detailbladsy wys nou die regte foto).
- **"Ou Kliphuis" het nie aanvanklik ingevoer nie** — CSV-ontleding is deeglik uitgesluit as oorsaak (identiese PHP-toets het dit korrek in die CSV gevind); die werklike oorsaak was die WordPress-pos se status wat op "Private" gestel was. Danie het dit reggestel; terrein is nou lewend.
- **"Die Pondok"** het geen aksie nodig gehad nie — dit was reeds voor die fork-bronkommit (`a8627de`) uit Clarens se eie skikking verwyder (bevestig via `git log`/`git show` op die ou repo), so daar was nooit 'n verwysing daarna in die nuwe stelsel om te onttrek nie.
- Terreininhoud is volledig WordPress-bestuurbaar — geen hardgekodeerde `SITES` meer nie.

---

## 6. Front-end re-skin — Clarens-handelsmerk — voltooi en geverifieer

- Kleure/lettertipes: `background_color: #2e3220`, `theme_color: #484E32`, Cormorant Garamond + Jost — onafhanklik bevestig op die lewende werf.
- Kaartsentrum: `[-28.514811235523656, 28.421035109664686]`, zoom 15 — bevestig.
- Ikone/embleem: Clarens-bates in gebruik — bevestig.
- **Donate-paneel (`btnDonate`/`donateModal`) herstel en uitgebrei.** Aanvanklik met 'n doelbewuste plekhouer (geen regte bankbesonderhede gecommit nie). Op Danie se versoek gekombineer tot: (a) regte EFT-bankbesonderhede (Standard Bank, rek. 10 23 335 098 3, takkode 051001) én (b) 'n "Donate via card (Stitch)"-knoppie na Clarens se eie Stitch Express-betaalskakel. Onafhanklik geverifieer op die lewende werf — albei vertoon en werk korrek (commit `9d06f07`).
- **Voucher/vennoot-lys ingevoer.** 4 vennote (Bibliophile, Firkin Pub & Grill, Highland Coffee Roastery, Post House Restaurant) via die `partner`-CPT ingevoer en onafhanklik bevestig op die Vouchers-oortjie, met korrekte terrein-vergrendeling.
- **Wetlike bladsye:** Privacy Policy, Terms of Service, en Refund and Cancellation Policy lewend gepubliseer op `clarensheritage.org`. Sien `CHA_Legal_Docs_Adaptation_v1.md`.
- **Opsionele opruiming (buite oorspronklike skopus, op Danie se versoek gedoen):** GRHS-spesifieke "Kathy"/"The Makery"-verwysings in vyf agterkant-PHP-lêers vervang met generiese rolle ("the site admin"/"the web editor"), insluitend die Site ID-admin-veld se waarskuwingsteks. Onafhanklik geverifieer (commit `b190899`, skoon diff, net die 5 verwagte lêers).

---

## 7. Handmatige stappe — Danie in die Paystack-paneel — voltooi

Paystack-subaccount geskep (`percentage_charge: 20`), rekening geaktiveer, sandbox→lewende-oorskakeling voltooi. Sien v0.4 §7 vir volle besonderhede oor die selfgerapporteer-vs-onafhanklik-bevestig-onderskeid (die `wrangler deploy`-oorskakeling is die enigste stap wat Claude self via 'n lewende blaaier-besoek kon bevestig; die Paystack-/databasis-stappe berus op Danie se eie rapportering, aangesien dit buite hierdie sessie se bereik val).

---

## 8. Databasis-migrasie — bestaande betalers — voltooi

`migrate-existing-payers.php` teen die regte databasis laat loop; gemigreerde token se ontsluiting bevestig.

---

## 9. Tydlyn — finale status (19 Aug → 23 Sept, vroeër as die 24 Sept-teiken afgehandel)

| Week | Fokus | Status |
|---|---|---|
| 1 (19–26 Aug) | Vurk, meganiese hernoem, Paystack-subaccount, wetlike bladsye, Paystack geaktiveer, sandbox→lewende-oorskakeling | ✅ |
| 2 (27 Aug–2 Sep) | `class-cha-paystack.php` + sandbox-toets, databasis-migrasieskrip | ✅ (vroeg voltooi) |
| 3 (3–9 Sep) | Front-end re-skin, terrein-migrasieskrip + invoer | ✅ (vroeg voltooi) |
| 4 (10–16 Sep) | Regte Paystack-end-tot-end-toets, **QA oor toestelle**, vennote/vouchers ingevoer | ✅ (vroeg voltooi — sien `CHA_QA_Report_Week4.md`) |
| 5 (17–24 Sep) | Finale QA-buffer, weergawe-opdatering, lansering | ✅ — projek afgesluit 23 Aug 2026, vooruit op skedule |

---

## 10. Oop vrae — almal opgelos

1. **Front-end-ontplooiing:** bestaande Cloudflare Workers-opstel behou — **opgelos**.
2. **Huidige prys:** R99 (9900c) — **opgelos**.
3. **Ou WordPress-inprop:** `cha-trail-admin` afgetrek ná rugsteun en bevestigde migrasie — **opgelos**.
4. **Volledige Claude Code-bouspesifikasie:** geskryf (`CHA_GRHS_Fork_Build_Brief_ClaudeCode_v2.md`, `_v3_FINAL.md`, `CHA_Paystack_Build_Brief_ClaudeCode_v1.md`) — **opgelos**.
5. **Oorblywende werk vir lansering** (front-end re-skin, terrein-migrasie, QA, vennote/vouchers) — **almal opgelos**, sien §5, §6, §9.

---

## 11. Veranderingslog

- **v0.5 (23 Aug 2026)** — **Projek afgesluit.** Front-end re-skin, terrein-inhoud-migrasie (insl. fotos- en "Ou Kliphuis"-regstellings), voucher/vennoot-invoer, Donate-modal (EFT + Stitch Express-skakel), QA oor toestelle, en opsionele GRHS-verwysing-opruiming almal voltooi en waar moontlik onafhanklik geverifieer. §5, §6, §9, §10 opgedateer. Sien §12 vir die klein, nie-blokkerende oorblywende items.
- **v0.4 (21 Aug 2026)** — Paystack het CHA se Heritage Trail-rekening geaktiveer; vier oorskakelingstappe deur Danie voltooi (sandbox-toets, databasis-migrasie, ou-inprop-aftrek, `wrangler deploy`). Laasgenoemde onafhanklik bevestig; ander drie selfgerapporteer.
- **v0.3 (21 Aug 2026)** — Status-opdatering, wag vir Paystack-aktivering; prys-vraag opgelos; wetlike bladsye se publikasie aangeteken.
- **v0.2 (19 Aug 2026)** — Rigting verander na "vurk GRHS se argitektuur"; webhook-kwesbaarheid geïdentifiseer.
- **v0.1 (19 Aug 2026)** — Eerste weergawe (minimale Paystack-omskakeling op bestaande CHA-kode). Vervang deur v0.2.

---

## 12. Projekafsluiting (23 Aug 2026)

Al die items op die oorspronklike uitstaande-lys is afgehandel en waar binne hierdie sessie se bereik moontlik, onafhanklik op die lewende werf geverifieer (nie net op Claude Code se eie rapportering vertrou nie — sien die deurgaanse selfgerapporteer-vs-onafhanklik-bevestig-onderskeid in hierdie dokument en in `CHA_Fork_Verification_Report_v1.md`).

**Klein, nie-blokkerende items wat oorbly (geen van hulle verhoed lansering of normale gebruik nie):**

1. **Plugin-zip herbou + heroplaai.** Die GRHS-verwysing-opruiming (§6, commit `b190899`) het net die repo se bronkode gewysig. Die Site ID-admin-veld se opgedateerde waarskuwingsteks word eers op die lewende WordPress-admin-skerm sigbaar sodra die plugin-zip volgens `DEPLOY.md` herbou en opgelaai word — kosmeties, kan met 'n volgende gerieflike geleentheid saamgevoeg word.
2. ~~**Danie se eie 5-minuut-toestel-toets.**~~ **Opgelos (23 Aug 2026):** Danie het die kontrolelys uit `CHA_QA_Report_Week4.md` §5 op sy eie foon deurgeloop — alles werk.
3. **GRHS-handoff (aparte projek).** Die "Donate via card"-patroon wat vir CHA gebou is (EFT + Stitch Express-skakel) is as 'n handoff-item vir GRHS geloglê (`GRHS_Handoff_Donate_Link.md` in hierdie projek, aangesien hierdie sessie nie direk aan 'n GRHS-projek gekoppel is nie) — GRHS het hul eie bankbesonderhede en eie Stitch Express-skakel nodig, nie CHA s'n nie. Dit is 'n toekomstige stap op 'n ander projek, nie 'n oorblywende CHA-item nie.

Buiten item 1 (kosmeties) en item 3 (ander projek) is die CHA Heritage Trail-projek nou volledig afgehandel, getoets — insluitend op 'n regte foon — en lewend op `trail.clarensheritage.org`.
