# Clarens/CHA Heritage Trail — Herbou op GRHS-argitektuur — Ontwikkelingsplan

> Lewende beplanningsdokument. **Status: v0.6 · 24 Augustus 2026 — vervang v0.5.**
> **Regstelling in v0.6:** Danie het bevestig dat die Paystack-subrekening vir CHA met 'n 80/20-verdeling opgestel is, en dat Paystack suksesvol in die **Sandbox** getoets is. Die **finale lewendige betaling-toets (regte geld, regte transaksie) is egter nog nie gedoen nie** — dit was voorheen in v0.4/v0.5 (§4, §7, §9) as reeds voltooi aangeteken op grond van 'n vroeëre self-rapportering wat, met hierdie opdatering, nou as onakkuraat blyk. Daardie afdelings word hieronder reggestel. Sien §12 — die projek is nie meer as volledig afgesluit gemerk nie; hierdie een item staan weer oop.

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
- **24 September was die teiken — behaal, op een oorblywende item ná.** Byna al die werk is voor die teiken afgehandel (23 Aug 2026); die finale lewendige Paystack-toets (§4, §7) staan nog oop.
- **Slegs een betaalspoor: Paystack.** Stitch is volledig verwyder.
- **Geen webhook nodig vir Paystack nie.** Paystack se **Verify Transaction-API** laat direkte, sinchrone, bediener-geverifieerde bevestiging toe.
- **Bestaande betalers het nie hul tokens verloor nie.** Gemigreer, bevestig ontsluit — sien §8.

---

## 3. Wat oorgedra is (fork-plan) — voltooi

Uit `gr-heritage-trail` se `/wordpress-plugin`: alle klasse hernoem en oorgedra (`GRHS_*`→`CHA_*`, ens.), soos in v0.1–v0.5 uiteengesit. Uit `/app`: `index.html`, `sw.js`, `manifest.json` gevurk en na Clarens se handelsmerk herskin (§6).

---

## 4. Betaling: Paystack binne die GRHS-patroon — subrekening opgestel, sandbox getoets, lewendige toets nog oop

`class-cha-paystack.php` is gebou en in 'n sandbox suksesvol getoets (`/checkout` → `/verify-token`). Die Paystack-subrekening vir CHA is opgestel met 'n **80/20-verdeling** (80% aan CHA, 20% aan Acutus). Geen `/stitch-webhook`-ekwivalent was nodig nie. Tarief-beperking en geen-kas-headers op auth-roetes is ongeskonde uit GRHS se patroon oorgeneem.

**Uitstaande:** die finale toets met 'n regte, klein, lewendige Paystack-transaksie (nie sandbox nie) is nog nie gedoen nie. Dit moet gedoen word voordat die app met vertroue vir werklike betalende besoekers geadverteer word — sien §12, item 1.

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

## 7. Handmatige stappe — Danie in die Paystack-paneel — grootliks voltooi, een toets oop

1. **Subrekening opgestel.** Die Paystack-subrekening vir CHA is geskep met 'n **80/20-verdeling** (`percentage_charge: 20` — 80% aan CHA, 20% aan Acutus), soos in Paystack se eie dokumentasie bevestig. Die subrekening-kode en geheime sleutel is in `.env` gekopieer.
2. **Sandbox-toets suksesvol.** Die inprop is na 'n sandbox ontplooi en die volle vloei (`/checkout` → `/verify-token`) is suksesvol getoets. *Deur Danie bevestig (24 Aug 2026).*
3. **Finale lewendige toets — nog oop.** 'n Regte, klein Paystack-transaksie (nie sandbox nie) moet nog deur die volle vloei laat loop word voor die app met vertroue vir werklike besoekers geadverteer word. **Dit is die enigste oorblywende stap voor volle lansering-gereedheid.**
4. **`wrangler deploy`.** `trail.clarensheritage.org` het reeds oorgeskakel van die ou instandhouding-bladsy na die regte app — **onafhanklik deur Claude bevestig** via 'n lewende blaaier-besoek (sien v0.4 §7 vir volle besonderhede). Let wel: die app is dus reeds lewend en bruikbaar (insluitend die gratis terreine sonder aankoop); dit is spesifiek die *betaalde* Fase 2-ontsluiting wat nog nie met 'n regte transaksie bevestig is nie.

---

## 8. Databasis-migrasie — bestaande betalers — voltooi

`migrate-existing-payers.php` teen die regte databasis laat loop; gemigreerde token se ontsluiting bevestig.

---

## 9. Tydlyn — status (19 Aug → 24 Sept)

| Week | Fokus | Status |
|---|---|---|
| 1 (19–26 Aug) | Vurk, meganiese hernoem, Paystack-subaccount (80/20) opgestel, wetlike bladsye, sandbox→lewende-app-oorskakeling | ✅ |
| 2 (27 Aug–2 Sep) | `class-cha-paystack.php` + sandbox-toets ✅, databasis-migrasieskrip ✅ | ✅ |
| 3 (3–9 Sep) | Front-end re-skin, terrein-migrasieskrip + invoer | ✅ (vroeg voltooi) |
| 4 (10–16 Sep) | **Regte Paystack-end-tot-end-toets** ⚠️ **nog uitstaande — sien §7, item 3**; QA oor toestelle ✅ (vroeg voltooi); vennote/vouchers ingevoer ✅ | ⚠️ Grootliks voltooi, een item oop |
| 5 (17–24 Sep) | Finale QA-buffer, weergawe-opdatering, lansering | Kan eers as volledig gemerk word sodra die lewendige betaling-toets (§7.3) gedoen is |

---

## 10. Oop vrae

1. **Front-end-ontplooiing:** bestaande Cloudflare Workers-opstel behou — **opgelos**.
2. **Huidige prys:** R99 (9900c) — **opgelos**.
3. **Ou WordPress-inprop:** `cha-trail-admin` afgetrek ná rugsteun en bevestigde migrasie — **opgelos**.
4. **Volledige Claude Code-bouspesifikasie:** geskryf (`CHA_GRHS_Fork_Build_Brief_ClaudeCode_v2.md`, `_v3_FINAL.md`, `CHA_Paystack_Build_Brief_ClaudeCode_v1.md`) — **opgelos**.
5. **Front-end re-skin, terrein-migrasie, QA, vennote/vouchers** — **almal opgelos**, sien §5, §6, §9.
6. **Nuut (24 Aug 2026): finale lewendige Paystack-transaksie-toets** — **nog oop**, sien §4, §7.

---

## 11. Veranderingslog

- **v0.6 (24 Aug 2026)** — **Regstelling.** Danie het bevestig: die Paystack-subrekening (80/20-verdeling) is opgestel, en Paystack is suksesvol in die **Sandbox** getoets — maar die finale **lewendige** betaling-toets is nog nie gedoen nie. Dit korrigeer v0.4/v0.5 se vroeëre aanname (gebaseer op 'n self-rapportering) dat 'n regte lewendige transaksie reeds afgehandel was. §4, §7, §9, §10 en §12 opgedateer om dit akkuraat te weerspieël; die projek is nie meer volledig as afgesluit gemerk nie.
- **v0.5 (23 Aug 2026)** — Projek (voorlopig) afgesluit gemerk. Front-end re-skin, terrein-inhoud-migrasie, voucher/vennoot-invoer, Donate-modal (EFT + Stitch Express-skakel), QA oor toestelle, en opsionele GRHS-verwysing-opruiming almal voltooi en waar moontlik onafhanklik geverifieer.
- **v0.4 (21 Aug 2026)** — Paystack het CHA se Heritage Trail-rekening geaktiveer; vier oorskakelingstappe deur Danie gerapporteer (sandbox-toets, databasis-migrasie, ou-inprop-aftrek, `wrangler deploy`) — met v0.6 nou bevestig dat die "regte transaksie"-deel van item 1 eintlik 'n sandbox-toets was, nie 'n lewendige toets nie. Die `wrangler deploy`-oorskakeling self is wel onafhanklik bevestig.
- **v0.3 (21 Aug 2026)** — Status-opdatering, wag vir Paystack-aktivering; prys-vraag opgelos; wetlike bladsye se publikasie aangeteken.
- **v0.2 (19 Aug 2026)** — Rigting verander na "vurk GRHS se argitektuur"; webhook-kwesbaarheid geïdentifiseer.
- **v0.1 (19 Aug 2026)** — Eerste weergawe (minimale Paystack-omskakeling op bestaande CHA-kode). Vervang deur v0.2.

---

## 12. Projekstatus (24 Aug 2026)

Byna al die items op die oorspronklike uitstaande-lys is afgehandel en waar binne hierdie sessie se bereik moontlik, onafhanklik op die lewende werf geverifieer. **Een item is met hierdie opdatering weer oopgemaak:**

1. **Finale lewendige Paystack-transaksie-toets (nuut heropen, 24 Aug 2026).** 'n Regte, klein transaksie moet deur die volle `/checkout` → `/verify-token`-vloei laat loop word, buite die sandbox, voordat die betaalde ontsluitingsfunksie met vertroue vir werklike besoekers geadverteer word. Dit is nou die enigste item wat volle lansering-gereedheid keer.
2. **Plugin-zip herbou + heroplaai.** Die GRHS-verwysing-opruiming (§6, commit `b190899`) het net die repo se bronkode gewysig. Die Site ID-admin-veld se opgedateerde waarskuwingsteks word eers op die lewende WordPress-admin-skerm sigbaar sodra die plugin-zip volgens `DEPLOY.md` herbou en opgelaai word — kosmeties, kan met 'n volgende gerieflike geleentheid saamgevoeg word.
3. ~~**Danie se eie 5-minuut-toestel-toets.**~~ **Opgelos (23 Aug 2026):** Danie het die kontrolelys uit `CHA_QA_Report_Week4.md` §5 op sy eie foon deurgeloop — alles werk.
4. **GRHS-handoff (aparte projek).** Die "Donate via card"-patroon wat vir CHA gebou is (EFT + Stitch Express-skakel) is as 'n handoff-item vir GRHS geloglê (`GRHS_Handoff_Donate_Link.md` in hierdie projek) — 'n toekomstige stap op 'n ander projek, nie 'n oorblywende CHA-item nie.

Die res van die CHA Heritage Trail-projek is volledig, getoets — insluitend op 'n regte foon — en lewend op `trail.clarensheritage.org`. Item 1 hierbo is die enigste stap wat oorbly voor die app met vertroue vir werklike betalende besoekers geadverteer kan word.
