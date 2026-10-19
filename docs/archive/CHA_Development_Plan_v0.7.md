# Clarens/CHA Heritage Trail — Herbou op GRHS-argitektuur — Ontwikkelingsplan

> Lewende beplanningsdokument. **Status: v0.7 · 24 Augustus 2026 — vervang v0.6.**
> **Opdatering in v0.7:** 'n regte fout is gevind, reggemaak, en lewend ontplooi — sien §8a. Dit het terselfdertyd as 'n onafhanklike bevestiging gedien dat die bestaande-betalers-migrasie (§8) werklik teen die regte databasis geloop het. Een nuwe klein administratiewe item is oop (§12, item 1): die fix-commit staan nog nie op GitHub se `main` nie weens 'n toegangsprobleem met Danie se PAT.

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
- **24 September was die teiken — behaal, op een oorblywende item ná.** Byna al die werk is voor die teiken afgehandel (23–24 Aug 2026); die finale lewendige Paystack-toets (§4, §7) staan nog oop.
- **Slegs een betaalspoor: Paystack.** Stitch is volledig verwyder.
- **Geen webhook nodig vir Paystack nie.** Paystack se **Verify Transaction-API** laat direkte, sinchrone, bediener-geverifieerde bevestiging toe.
- **Bestaande betalers het nie hul tokens verloor nie.** Gemigreer, en nou ook **met 'n regte ou token lewend getoets** — sien §8, §8a.

---

## 3. Wat oorgedra is (fork-plan) — voltooi

Uit `gr-heritage-trail` se `/wordpress-plugin`: alle klasse hernoem en oorgedra (`GRHS_*`→`CHA_*`, ens.), soos in v0.1–v0.6 uiteengesit. Uit `/app`: `index.html`, `sw.js`, `manifest.json` gevurk en na Clarens se handelsmerk herskin (§6).

---

## 4. Betaling: Paystack binne die GRHS-patroon — subrekening opgestel, sandbox getoets, lewendige toets nog oop

`class-cha-paystack.php` is gebou en in 'n sandbox suksesvol getoets (`/checkout` → `/verify-token`). Die Paystack-subrekening vir CHA is opgestel met 'n **80/20-verdeling** (80% aan CHA, 20% aan Acutus). Geen `/stitch-webhook`-ekwivalent was nodig nie. Tarief-beperking en geen-kas-headers op auth-roetes is ongeskonde uit GRHS se patroon oorgeneem.

**Uitstaande:** die finale toets met 'n regte, klein, lewendige Paystack-transaksie (nie sandbox nie) is nog nie gedoen nie. Dit moet gedoen word voordat die app met vertroue vir werklike betalende besoekers geadverteer word — sien §12, item 2.

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
3. **Finale lewendige toets — nog oop.** 'n Regte, klein Paystack-transaksie (nie sandbox nie) moet nog deur die volle vloei laat loop word voor die app met vertroue vir werklike besoekers geadverteer word. **Dit is die enigste tegniese stap wat oorbly voor lansering-gereedheid.**
4. **`wrangler deploy`.** `trail.clarensheritage.org` het reeds oorgeskakel van die ou instandhouding-bladsy na die regte app — **onafhanklik deur Claude bevestig** via 'n lewende blaaier-besoek (sien v0.4 §7 vir volle besonderhede). Let wel: die app is dus reeds lewend en bruikbaar (insluitend die gratis terreine sonder aankoop); dit is spesifiek die *betaalde* Fase 2-ontsluiting wat nog nie met 'n regte transaksie bevestig is nie.

---

## 8. Databasis-migrasie — bestaande betalers — voltooi én empiries bevestig

`migrate-existing-payers.php` teen die regte databasis laat loop. Sien §8a — 'n onafhanklike toets op 24 Aug 2026 het dit bykomend bevestig: 'n regte, reeds-bestaande ou-formaat token het na die fix hieronder korrek ontsluit, wat beteken die gemigreerde ry werklik in die produksie-databasis bestaan en korrek as "paid" gemerk is.

---

## 8a. Fout gevind en reggestel: ou-formaat tokens is deur die app self geblokkeer (24 Aug 2026)

Danie het probeer om 'n regte, reeds-bestaande token (in die ou vorm `CHT-XXXX-XXXX`, van vóór hierdie herbou) in die app in te tik, en kry "That doesn't look like a valid token." — voor die bediener ooit gevra is.

**Grondoorsaak:** `app/index.html` se `looksLikeToken()` — 'n kliënt-kant voor-toets wat bedoel was as blote "instant UX", nooit as gesag nie (soos die kode se eie kommentaar sê) — het vereis dat 'n token minstens 16 karakters ná `CHT-` het. Die nuwe formaat (`CHT-XXXX-XXXX-XXXX-XXXX`) het 19; die ou, voor-herbou-formaat (`CHT-XXXX-XXXX`) het net 9. Elke gemigreerde ou-token is dus deur die app self verwerp, sonder dat die bediener — wat, soos ontwerp, heeltemal formaat-onafhanklik is (`resolve_token()` soek net die presiese string in die DB) — ooit gevra is. Dit het direk die §2/§8-belofte ("bestaande betalers verloor nie hul tokens nie") in die praktyk gebreek.

**Regstelling:** die regex is verslap na minstens 6 karakters, sodat beide formate deur die voor-toets kom en die bediener — soos ontwerp — die regte, gesaghebbende besluit maak.

**Ontplooiing (24 Aug 2026):**
- Commit `4744cfd` — "Fix token format pre-check rejecting legacy CHT-XXXX-XXXX tokens from migrated payers", gemaak op 'n plaaslike tak (`claude/token-format-precheck-fix-2511f5`).
- `wrangler deploy` het geslaag — die fix is lewend op `trail.clarensheritage.org` (onafhanklik bevestig deur die lewende bladsy se bronkode na te gaan: die regex wys nou `{6,}`).
- **Danie het self met 'n regte ou token getoets ná die deploy: dit werk nou.** Dit bevestig twee dinge gelyktydig — die front-end-fix werk, ÉN die onderliggende databasis-migrasie (§8) het werklik korrek teen die produksie-databasis geloop (anders sou die bediener steeds "invalid/expired" gegee het).
- **Uitstaande administratiewe stappie:** `git push origin HEAD:main` het misluk (403 — Danie se fine-grained GitHub PAT het nie skryftoegang tot hierdie repo nie). Die fix is dus wel lewend (deploy gebeur vanaf plaaslike lêers, nie van GitHub nie), maar commit `4744cfd` staan nog net plaaslik — GitHub se `main` is tydelik nie op datum met wat regtig lewend is nie. Sien §12, item 1.

---

## 9. Tydlyn — status (19 Aug → 24 Sept)

| Week | Fokus | Status |
|---|---|---|
| 1 (19–26 Aug) | Vurk, meganiese hernoem, Paystack-subaccount (80/20) opgestel, wetlike bladsye, sandbox→lewende-app-oorskakeling | ✅ |
| 2 (27 Aug–2 Sep) | `class-cha-paystack.php` + sandbox-toets ✅, databasis-migrasieskrip ✅ (nou ook empiries bevestig, §8a) | ✅ |
| 3 (3–9 Sep) | Front-end re-skin, terrein-migrasieskrip + invoer | ✅ (vroeg voltooi) |
| 4 (10–16 Sep) | **Regte Paystack-end-tot-end-toets** ⚠️ **nog uitstaande — sien §7, item 3**; QA oor toestelle ✅; vennote/vouchers ingevoer ✅ | ⚠️ Grootliks voltooi, een item oop |
| 5 (17–24 Sep) | Finale QA-buffer, weergawe-opdatering, lansering | Kan eers as volledig gemerk word sodra die lewendige betaling-toets (§7.3) gedoen is |

---

## 10. Oop vrae

1. **Front-end-ontplooiing:** bestaande Cloudflare Workers-opstel behou — **opgelos**.
2. **Huidige prys:** R99 (9900c) — **opgelos**.
3. **Ou WordPress-inprop:** `cha-trail-admin` afgetrek ná rugsteun en bevestigde migrasie — **opgelos**.
4. **Volledige Claude Code-bouspesifikasie:** geskryf — **opgelos**.
5. **Front-end re-skin, terrein-migrasie, QA, vennote/vouchers** — **almal opgelos**.
6. **Finale lewendige Paystack-transaksie-toets** — **nog oop**, sien §4, §7.
7. **Nuut (24 Aug 2026): GitHub-PAT-skryftoegang** — **nog oop**, sien §8a, §12 item 1.

---

## 11. Veranderingslog

- **v0.7 (24 Aug 2026)** — Regte fout gevind en reggestel: 'n te-streng kliënt-kant token-formaat-toets het gemigreerde ou-formaat tokens geblokkeer voor die bediener ooit gevra is (commit `4744cfd`, lewend ontplooi via `wrangler deploy`). Danie het self bevestig dat 'n regte ou token nou korrek ontsluit — wat terselfdertyd die databasis-migrasie (§8) empiries bevestig. Nuwe oop item: die fix-commit staan nog nie op GitHub se `main` nie (PAT-skryftoegang-probleem) — repo en lewende ontplooiing is tydelik nie in sink nie. Sien §8a, §12.
- **v0.6 (24 Aug 2026)** — Regstelling: Paystack-subrekening (80/20) opgestel en suksesvol in Sandbox getoets, maar die finale lewendige betaling-toets was (en is steeds) nie gedoen nie — korrigeer v0.4/v0.5 se vroeëre aanname.
- **v0.5 (23 Aug 2026)** — Projek (voorlopig) afgesluit gemerk. Front-end re-skin, terrein-inhoud-migrasie, voucher/vennoot-invoer, Donate-modal, QA oor toestelle, en GRHS-verwysing-opruiming almal voltooi.
- **v0.4 (21 Aug 2026)** — Paystack het CHA se rekening geaktiveer; vier oorskakelingstappe gerapporteer.
- **v0.3 (21 Aug 2026)** — Status-opdatering, wag vir Paystack-aktivering.
- **v0.2 (19 Aug 2026)** — Rigting verander na "vurk GRHS se argitektuur".
- **v0.1 (19 Aug 2026)** — Eerste weergawe. Vervang deur v0.2.

---

## 12. Projekstatus (24 Aug 2026)

**Oorblywende items:**

1. **Nuut (24 Aug 2026): stoot commit `4744cfd` na GitHub se `main`.** Die token-formaat-fix is reeds lewend (deploy gebeur vanaf plaaslike lêers), maar staan nog net op 'n plaaslike tak — nie op GitHub nie — omdat Danie se fine-grained PAT nie skryftoegang tot hierdie repo het nie. Regstel: GitHub → Settings → Developer settings → Fine-grained tokens → gee die token "Contents: Read and write" vir `whitelabel-heritage-trail` (of skep 'n nuwe token met breër omvang), dan `git push origin HEAD:main`. Klein administratiewe stap, geen produksie-impak nie (die fix werk reeds lewend), maar belangrik vir repo-higiëne.
2. **Finale lewendige Paystack-transaksie-toets.** 'n Regte, klein transaksie moet deur die volle `/checkout` → `/verify-token`-vloei laat loop word, buite die sandbox. Dit is nou die enigste **tegniese** item wat volle lansering-gereedheid keer.
3. **Plugin-zip herbou + heroplaai.** Kosmeties (Site ID-admin-veld-teks) — kan met 'n volgende gerieflike geleentheid saamgevoeg word.
4. ~~**Danie se eie 5-minuut-toestel-toets.**~~ **Opgelos (23 Aug 2026).**
5. **GRHS-handoff (aparte projek).** Geloglê in `GRHS_Handoff_Donate_Link.md` — 'n toekomstige stap op 'n ander projek.

Die res van die CHA Heritage Trail-projek is volledig, getoets — insluitend op 'n regte foon, en nou ook met 'n regte gemigreerde token — en lewend op `trail.clarensheritage.org`. Item 2 hierbo is die enigste **tegniese** stap wat oorbly voor die app met vertroue vir werklike betalende besoekers geadverteer kan word; item 1 is 'n klein administratiewe opruiming sonder produksie-impak.
