# Clarens/CHA Heritage Trail — Herbou op GRHS-argitektuur — Ontwikkelingsplan

> Lewende beplanningsdokument. **Status: v0.8 · 24 Augustus 2026 — vervang v0.7.**
> **Opdatering in v0.8:** die GitHub-PAT-item uit v0.7 is opgelos — commit `4744cfd` staan nou op `main` (onafhanklik op GitHub bevestig). Twee ander dinge is met dieselfde oudit aan die lig gekom: (1) die `/paystack-webhook` waarna die repo se README verwys, is nie 'n GRHS-oorblyfsel nie — dit is doelbewus op 22 Aug bygevoeg (commit `0a9258d`) as 'n vangnet vir kopers wat wel betaal het maar nooit na die app teruggekeer het nie; §2 se "geen webhook nodig nie"-grondreël is hiermee verfyn. (2) 'n regte, nuwe oop item: `CACHE_NAME` in `app/sw.js` is **nie** gebump vir óf die Donate-modal-commit (`9d06f07`) óf die token-fix-commit (`4744cfd`) nie — bevestig deur albei commits se diffs op GitHub na te gaan (elkeen raak net `app/index.html`). Sien §8b — dit beteken reeds-geïnstalleerde PWA-toestelle dalk steeds nie die Donate-opdatering óf die token-fix kry nie, al is albei op die bediener lewend.

> **Rigtingverandering (v0.2):** nie meer 'n klein Paystack-lapwerk op CHA se bestaande kodebasis nie. Ons **vurk GRHS se reeds-verharde kodebasis** (app + WordPress-inprop) as CHA se nuwe fondament, trek Clarens se 31 terreine en handelsmerk daarin in, en swap Stitch → Paystack binne-in daardie fondament. GRHS se eie lewende installasie (graaffreinetheritage.co.za) word nie aangeraak nie — ons vurk net die kode, wat Danie se eie IE is.

---

## 1. Waarom hierdie rigtingverandering (bevestig deur 'n regte oudit)

Terwyl ek GRHS se kodebasis ondersoek het, het ek 'n dokument raakgeloop wat reeds presies hierdie vergelyking gedoen het: `content/clarens-payment-audit.md` in die `gr-heritage-trail`-repo — geskryf om die GRHS-bou in te lig, nie vir hierdie plan nie, maar dit bevestig elke punt wat Danie genoem het:

- **Tokens.** CHA se koop-token (`CHT-XXXX-XXXX`) word steeds van 'n formule afgelei met 'n **publieke geheim wat in die app-bundel versend word** (`TOKEN_SECRET = 'CHA2026'`) — direk sigbaar en formaat-vervalsbaar in die blaaier. GRHS se tokens (`GHT-XXXX-XXXX-XXXX-XXXX`) is **volledig ondeursigtig, kripto-lukraak gegenereer, en bediener-uitgereik** — die app ken géén geheim of hash-formule nie; elke geldigheidstoets is 'n databasis-opsoek.
- **Admin/promo-ontsluiting.** CHA se oorspronklike stelsel het admin-/promo-tokens **heeltemal kliënt-kant** ontsluit, geen bediener-oproep nodig nie (`verifyAndStore()` se kortsluitpad). 'n Latere "Phase A"-lapwerk (`access-tokens.php`) het dit reeds gedeeltelik reggemaak vir CHA, maar die koop-token-pad bly op die ou skema. GRHS is **deurgaans bediener-gesaghebbend** vir al drie tipes, van die begin af.
- **Onbevestigde webhook — 'n lewende risiko.** CHA se ou `/stitch-webhook` het **glad geen handtekening geverifieer nie** (`permission_callback => __return_true`, die "webhook secret"-instelling het bestaan maar is nooit gebruik nie). Enigiemand wat 'n Stitch-`payment_id` kon raai, kon 'n aankoop as betaal vervals. GRHS verifieer Svix-handtekeninge met herhaal-beskerming. **Danie se besluit:** dit word reggestel as deel van hierdie herbou — Paystack se direkte Verify Transaction-API het hierdie spesifieke kwesbaarheid oorbodig gemaak (§2, §4). *(v0.8: sien §2 — CHA het intussen wél 'n eie, doelbewus bygevoegde Paystack-webhook, maar as 'n bykomende vangnet, nie as die primêre bevestigingspad nie, en glad nie die ou onbevestigde Stitch-weergawe se kwesbaarheid nie.)*
- **Terrein-inhoud.** CHA se 31 terreine was 'n **hardgekodeerde `SITES`-skikking in `index.html`** — verander 'n terrein het 'n kode-wysiging en herontplooiing beteken. GRHS se terreine woon volledig in WordPress (`site`-CPT + taksonomie), met 'n bewese spreadsheet-invoerder — presies die "maklik verander"-eienskap wat CHA kortgekom het. **Opgelos** — sien §5.
- **Ander GRHS-funksies wat CHA glad nie gehad het nie:** e-pos-afleweringstaat-opsporing, tarief-beperking op `/verify-token`, geen-kas-headers op auth-roetes, Voucher Redemption Metrics-kieserskerm, geheime in `.env` pleks van die databasis — alles oorgeneem via die fork.

**Interessante bevestiging:** GRHS se eie invoerder-kode (`class-grhs-importer.php`) bevat reeds 'n TODO-kommentaar wat hierdie presiese stap vooruitskou: *"TODO(step 4, app fork): replace these placeholders with the accent classes, marker colours and glyphs the forked Clarens app actually uses…"* — die argitektuur is letterlik gebou met die oog op 'n toekomstige Clarens-vurk.

---

## 2. Grondreëls

- **GRHS se lewende installasie (graaffreinetheritage.co.za) word nie aangeraak nie.** Ons vurk net die **kode** uit die private `gr-heritage-trail`-repo (Danie se eie IE, bevestig — geen eienaarskapkwessie nie) na 'n nuwe kodebasis vir CHA. Niks hier raak GRHS se databasis, instellings, of lewende gebruikers nie.
- **REST-naamruimte en localStorage-voorvoegsel bly `cha/v1` / `cht_`** (Danie se besluit) — nie 'n vars naamruimte soos GRHS s'n nie. Dit hou reeds-geïnstalleerde toestelle se plaaslike tokens bruikbaar, en pas by CHA se bestaande WordPress-installasie by `clarensheritage.org`.
- **24 September was die teiken — behaal, op een oorblywende item ná.** Byna al die werk is voor die teiken afgehandel (23–24 Aug 2026); die finale lewendige Paystack-toets (§4, §7) staan nog oop.
- **Slegs een betaalspoor: Paystack.** Stitch is volledig verwyder.
- **Paystack se Verify Transaction-API bly die primêre, sinchrone bevestigingspad — geen webhook was nodig om dít te laat werk nie.** *(v0.8, verfyn)* Op 22 Aug is 'n `/paystack-webhook`-endpoint wél bygevoeg (commit `0a9258d`, saam met 'n `mark_paid()`-wedloop-fix en 'n admin-herverifikasie-funksie) — nie omdat die sinchrone pad onbetroubaar is nie, maar as 'n **bykomende vangnet** vir die geval waar 'n koper wel betaal het maar nooit na die app teruggekeer het om `/verify-token` self te ontlok nie. Die twee bestaan naas mekaar; die repo se README se beskrywing was dus korrek en nie 'n teenstrydigheid met hierdie plan nie — hierdie plan het bloot nog nie die 22 Aug-toevoeging weerspieël nie.
- **Bestaande betalers het nie hul tokens verloor nie.** Gemigreer, en met 'n regte ou token lewend getoets — sien §8, §8a. **Waarskuwing (§8b, v0.8):** dié bevestiging is self met 'n toestel gedoen wat die nuutste `sw.js` reeds gehad het (of die app vars geïnstalleer het) — dit bewys nié outomaties dat 'n reeds-geïnstalleerde toestel wat nooit sedertdien opgedateer het, dieselfde ervaring sal hê nie.

---

## 3. Wat oorgedra is (fork-plan) — voltooi

Uit `gr-heritage-trail` se `/wordpress-plugin`: alle klasse hernoem en oorgedra (`GRHS_*`→`CHA_*`, ens.), soos in v0.1–v0.7 uiteengesit. Uit `/app`: `index.html`, `sw.js`, `manifest.json` gevurk en na Clarens se handelsmerk herskin (§6).

---

## 4. Betaling: Paystack binne die GRHS-patroon — subrekening opgestel, sandbox getoets, lewendige toets nog oop

`class-cha-paystack.php` is gebou en in 'n sandbox suksesvol getoets (`/checkout` → `/verify-token`). Die Paystack-subrekening vir CHA is opgestel met 'n **80/20-verdeling** (80% aan CHA, 20% aan Acutus). Tarief-beperking en geen-kas-headers op auth-roetes is ongeskonde uit GRHS se patroon oorgeneem. Sedert 22 Aug (commit `0a9258d`) is 'n `/paystack-webhook`-vangnet bygevoeg naas die sinchrone `/verify-token`-pad — sien §2.

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
- **Donate-paneel (`btnDonate`/`donateModal`) herstel en uitgebrei.** Aanvanklik met 'n doelbewuste plekhouer (geen regte bankbesonderhede gecommit nie). Op Danie se versoek gekombineer tot: (a) regte EFT-bankbesonderhede (Standard Bank, rek. 10 23 335 098 3, takkode 051001) én (b) 'n "Donate via card (Stitch)"-knoppie na Clarens se eie Stitch Express-betaalskakel. Onafhanklik geverifieer op die lewende werf — albei vertoon en werk korrek (commit `9d06f07`). **Let wel (§8b, v0.8):** hierdie commit het nie `CACHE_NAME` gebump nie.
- **Voucher/vennoot-lys ingevoer.** 4 vennote (Bibliophile, Firkin Pub & Grill, Highland Coffee Roastery, Post House Restaurant) via die `partner`-CPT ingevoer en onafhanklik bevestig op die Vouchers-oortjie, met korrekte terrein-vergrendeling.
- **Wetlike bladsye:** Privacy Policy, Terms of Service, en Refund and Cancellation Policy lewend gepubliseer op `clarensheritage.org`. Sien `CHA_Legal_Docs_Adaptation_v1.md`.
- **Opsionele opruiming (buite oorspronklike skopus, op Danie se versoek gedoen):** GRHS-spesifieke "Kathy"/"The Makery"-verwysings in vyf agterkant-PHP-lêers vervang met generiese rolle ("the site admin"/"the web editor"), insluitend die Site ID-admin-veld se waarskuwingsteks. Onafhanklik geverifieer (commit `b190899`, skoon diff, net die 5 verwagte lêers — raak nie `/app` nie, dus is `CACHE_NAME` hier nie ter sprake nie).

---

## 7. Handmatige stappe — Danie in die Paystack-paneel — grootliks voltooi, een toets oop

1. **Subrekening opgestel.** Die Paystack-subrekening vir CHA is geskep met 'n **80/20-verdeling** (`percentage_charge: 20` — 80% aan CHA, 20% aan Acutus), soos in Paystack se eie dokumentasie bevestig. Die subrekening-kode en geheime sleutel is in `.env` gekopieer.
2. **Sandbox-toets suksesvol.** Die inprop is na 'n sandbox ontplooi en die volle vloei (`/checkout` → `/verify-token`) is suksesvol getoets. *Deur Danie bevestig (24 Aug 2026).*
3. **Finale lewendige toets — nog oop.** 'n Regte, klein Paystack-transaksie (nie sandbox nie) moet nog deur die volle vloei laat loop word voor die app met vertroue vir werklike besoekers geadverteer word. **Dit is die enigste tegniese stap wat oorbly voor lansering-gereedheid.**
4. **`wrangler deploy`.** `trail.clarensheritage.org` het reeds oorgeskakel van die ou instandhouding-bladsy na die regte app — **onafhanklik deur Claude bevestig** via 'n lewende blaaier-besoek. Let wel: die app is dus reeds lewend en bruikbaar (insluitend die gratis terreine sonder aankoop); dit is spesifiek die *betaalde* Fase 2-ontsluiting wat nog nie met 'n regte transaksie bevestig is nie.

---

## 8. Databasis-migrasie — bestaande betalers — voltooi én empiries bevestig

`migrate-existing-payers.php` teen die regte databasis laat loop. Sien §8a — 'n onafhanklike toets op 24 Aug 2026 het dit bykomend bevestig: 'n regte, reeds-bestaande ou-formaat token het na die fix hieronder korrek ontsluit, wat beteken die gemigreerde ry werklik in die produksie-databasis bestaan en korrek as "paid" gemerk is.

---

## 8a. Fout gevind en reggestel: ou-formaat tokens is deur die app self geblokkeer (24 Aug 2026)

Danie het probeer om 'n regte, reeds-bestaande token (in die ou vorm `CHT-XXXX-XXXX`, van vóór hierdie herbou) in die app in te tik, en kry "That doesn't look like a valid token." — voor die bediener ooit gevra is.

**Grondoorsaak:** `app/index.html` se `looksLikeToken()` — 'n kliënt-kant voor-toets wat bedoel was as blote "instant UX", nooit as gesag nie (soos die kode se eie kommentaar sê) — het vereis dat 'n token minstens 16 karakters ná `CHT-` het. Die nuwe formaat (`CHT-XXXX-XXXX-XXXX-XXXX`) het 19; die ou, voor-herbou-formaat (`CHT-XXXX-XXXX`) het net 9. Elke gemigreerde ou-token is dus deur die app self verwerp, sonder dat die bediener — wat, soos ontwerp, heeltemal formaat-onafhanklik is (`resolve_token()` soek net die presiese string in die DB) — ooit gevra is. Dit het direk die §2/§8-belofte ("bestaande betalers verloor nie hul tokens nie") in die praktyk gebreek.

**Regstelling:** die regex is verslap na minstens 6 karakters, sodat beide formate deur die voor-toets kom en die bediener — soos ontwerp — die regte, gesaghebbende besluit maak.

**Ontplooiing (24 Aug 2026):**
- Commit `4744cfd` — "Fix token format pre-check rejecting legacy CHT-XXXX-XXXX tokens from migrated payers", aanvanklik op 'n plaaslike tak (`claude/token-format-precheck-fix-2511f5`) gemaak.
- `wrangler deploy` het geslaag — die fix is lewend op `trail.clarensheritage.org` (onafhanklik bevestig deur die lewende bladsy se bronkode na te gaan: die regex wys `{6,}`).
- **Danie het self met 'n regte ou token getoets ná die deploy: dit werk.** Dit bevestig twee dinge gelyktydig — die front-end-fix werk, ÉN die onderliggende databasis-migrasie (§8) het werklik korrek teen die produksie-databasis geloop.
- **Opgelos (24 Aug 2026, v0.8):** commit `4744cfd` is na GitHub se `main` gestoot nadat Danie sy fine-grained PAT se "Contents: Read and write"-toegang vir hierdie repo bevestig het. **Onafhanklik op GitHub nagegaan** (commit-geskiedenis op `main` gelees, nie net op Danie se woord vertrou nie) — `main` se jongste commit is nou `4744cfd`. Repo en lewende ontplooiing is weer in sink.

---

## 8b. Nuwe oop item: `CACHE_NAME` nie gebump vir vandag se twee front-end-wysigings nie (24 Aug 2026)

Terwyl die GitHub-push (§8a) nagegaan is, is albei vandag/gister se front-end-commits se diffs ook op GitHub bekyk:

- **`9d06f07`** (Donate-modal: EFT + Stitch-skakel) — raak **net** `app/index.html`.
- **`4744cfd`** (token-formaat-fix) — raak **net** `app/index.html`.

Nie een van die twee het `sw.js` se `CACHE_NAME` opgedateer nie. Die repo se eie README is hieroor uitdruklik: 'n blaaier bespeur 'n nuwe diensarbeider slegs deur `sw.js` self byte-vir-byte te vergelyk — 'n ontplooiing wat net `index.html` verander sonder om `CACHE_NAME` te bump, bereik nooit toestelle wat die app reeds geïnstalleer het nie; hulle bly die ou, gekasde bundel onbepaald voortsit. (Die vorige `sw.js`-bump was commit `9cb6fad` op 22 Aug — vóór albei hierdie wysigings.)

**Praktiese implikasie:** 'n besoeker wat die app **voor 22–24 Aug** reeds op sy foon geïnstalleer het en dit nie herlaai/verwyder-en-herinstalleer nie, sien moontlik steeds nie die Donate-opdatering nie, **en kan steeds die §8a-fout ervaar** as hy 'n ou token probeer intik — presies die scenario wat hierdie plan se §2-belofte moet voorkom. Danie se eie bevestigingstoets (§8a) bewys dus net dat die fix op die *bediener* en vir *nuwe/vars-gelaaide* kliënte werk, nie noodwendig vir elke reeds-geïnstalleerde toestel nie.

**Aanbeveling:** `CACHE_NAME` in `app/sw.js` een keer bump (bv. `cha-trail-v0.1.2` → `v0.1.3`) en `wrangler deploy` weer loop — 'n klein, veilige stap wat verseker alle reeds-geïnstalleerde toestelle die jongste `index.html` (met albei vandag se wysigings) binnekort optel. Sien §12, item 1 (nuwe prioriteit).

---

## 9. Tydlyn — status (19 Aug → 24 Sept)

| Week | Fokus | Status |
|---|---|---|
| 1 (19–26 Aug) | Vurk, meganiese hernoem, Paystack-subaccount (80/20) opgestel, wetlike bladsye, sandbox→lewende-app-oorskakeling | ✅ |
| 2 (27 Aug–2 Sep) | `class-cha-paystack.php` + sandbox-toets ✅, databasis-migrasieskrip ✅ (nou ook empiries bevestig, §8a) | ✅ |
| 3 (3–9 Sep) | Front-end re-skin, terrein-migrasieskrip + invoer | ✅ (vroeg voltooi) |
| 4 (10–16 Sep) | **Regte Paystack-end-tot-end-toets** ⚠️ **nog uitstaande — sien §7, item 3**; QA oor toestelle ✅; vennote/vouchers ingevoer ✅ | ⚠️ Grootliks voltooi, twee items oop (§7.3, §8b) |
| 5 (17–24 Sep) | Finale QA-buffer, weergawe-opdatering, lansering | Kan eers as volledig gemerk word sodra §7.3 en §8b afgehandel is |

---

## 10. Oop vrae

1. **Front-end-ontplooiing:** bestaande Cloudflare Workers-opstel behou — **opgelos**.
2. **Huidige prys:** R99 (9900c) — **opgelos**.
3. **Ou WordPress-inprop:** `cha-trail-admin` afgetrek ná rugsteun en bevestigde migrasie — **opgelos**.
4. **Volledige Claude Code-bouspesifikasie:** geskryf — **opgelos**.
5. **Front-end re-skin, terrein-migrasie, QA, vennote/vouchers** — **almal opgelos**.
6. **Finale lewendige Paystack-transaksie-toets** — **nog oop**, sien §4, §7.
7. ~~**GitHub-PAT-skryftoegang**~~ — **opgelos (24 Aug 2026, v0.8)**, sien §8a.
8. **Nuut (24 Aug 2026): `CACHE_NAME`-bump vir vandag se front-end-wysigings** — **nog oop**, sien §8b.

---

## 11. Veranderingslog

- **v0.8 (24 Aug 2026)** — GitHub-push (§8a) bevestig opgelos: commit `4744cfd` staan nou op `main`, onafhanklik op GitHub nagegaan. Twee bykomende bevindinge uit dieselfde oudit: (1) §2 se webhook-grondreël verfyn — `/paystack-webhook` (commit `0a9258d`, 22 Aug) is 'n doelbewuste vangnet-toevoeging naas die sinchrone `/verify-token`-pad, nie 'n GRHS-oorblyfsel of teenstrydigheid nie; (2) nuwe oop item — `CACHE_NAME` in `app/sw.js` is nie gebump vir óf die Donate-modal-commit (`9d06f07`) óf die token-fix-commit (`4744cfd`) nie, wat beteken reeds-geïnstalleerde toestelle dalk nog nie een van die twee wysigings ontvang het nie (nuwe §8b).
- **v0.7 (24 Aug 2026)** — Regte fout gevind en reggestel: 'n te-streng kliënt-kant token-formaat-toets het gemigreerde ou-formaat tokens geblokkeer voor die bediener ooit gevra is (commit `4744cfd`, lewend ontplooi via `wrangler deploy`). Danie het self bevestig dat 'n regte ou token nou korrek ontsluit — wat terselfdertyd die databasis-migrasie (§8) empiries bevestig. Nuwe oop item destyds: die fix-commit was nog nie op GitHub se `main` nie (PAT-skryftoegang-probleem).
- **v0.6 (24 Aug 2026)** — Regstelling: Paystack-subrekening (80/20) opgestel en suksesvol in Sandbox getoets, maar die finale lewendige betaling-toets was (en is steeds) nie gedoen nie — korrigeer v0.4/v0.5 se vroeëre aanname.
- **v0.5 (23 Aug 2026)** — Projek (voorlopig) afgesluit gemerk. Front-end re-skin, terrein-inhoud-migrasie, voucher/vennoot-invoer, Donate-modal, QA oor toestelle, en GRHS-verwysing-opruiming almal voltooi.
- **v0.4 (21 Aug 2026)** — Paystack het CHA se rekening geaktiveer; vier oorskakelingstappe gerapporteer.
- **v0.3 (21 Aug 2026)** — Status-opdatering, wag vir Paystack-aktivering.
- **v0.2 (19 Aug 2026)** — Rigting verander na "vurk GRHS se argitektuur".
- **v0.1 (19 Aug 2026)** — Eerste weergawe. Vervang deur v0.2.

---

## 12. Projekstatus (24 Aug 2026)

**Oorblywende items:**

1. **Nuut, prioriteit (24 Aug 2026): bump `CACHE_NAME` in `app/sw.js` en ontplooi weer.** Sonder dit is dit onseker of reeds-geïnstalleerde PWA-toestelle die Donate-opdatering óf die ou-token-fout se regstelling al ontvang het. Klein, veilige stap — sien §8b vir volle besonderhede.
2. **Finale lewendige Paystack-transaksie-toets.** 'n Regte, klein transaksie moet deur die volle `/checkout` → `/verify-token`-vloei laat loop word, buite die sandbox. Dit bly die enigste **tegniese** item wat volle lansering-gereedheid vir betalings keer.
3. **Plugin-zip herbou + heroplaai.** Kosmeties (Site ID-admin-veld-teks) — kan met 'n volgende gerieflike geleentheid saamgevoeg word.
4. ~~**Danie se eie 5-minuut-toestel-toets.**~~ **Opgelos (23 Aug 2026).**
5. ~~**Stoot commit `4744cfd` na GitHub se `main`.**~~ **Opgelos (24 Aug 2026)** — onafhanklik op GitHub bevestig.
6. **GRHS-handoff (aparte projek).** Geloglê in `GRHS_Handoff_Donate_Link.md` — 'n toekomstige stap op 'n ander projek.

Die res van die CHA Heritage Trail-projek is volledig, getoets — insluitend op 'n regte foon, en met 'n regte gemigreerde token — en lewend op `trail.clarensheritage.org`, en die repo is weer in sink met wat lewend is. Items 1 en 2 hierbo is die twee stappe wat oorbly voor die app met volle vertroue — vir élke besoeker, nie net vars installasies nie — vir werklike betalende besoekers geadverteer kan word.
