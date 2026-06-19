═══ PRE-FLIGHT (doen dit EERSTE, voor enige verandering) ═══
1. Begin op die nuutste weergawe:
   git fetch origin && git reset --hard origin/main
   (Dit verhoed dat werk op 'n stale lêer gebou word — dít het al security-fixes,
    die logo, site-tellings en die betaal-URL stilweg teruggedraai.)
2. Lees index.html VOLLEDIG voor jy iets verander.

═══ NIE-ONDERHANDELBARE KONVENSIES ═══
- Een lêer (index.html). Geen eksterne afhanklikhede behalwe Google Fonts + Leaflet CDN.
- Presiese str.replace-styl wysigings. NOOIT 'n groot string-vervanging op die hele lêer nie.
- Geen data-cfasync attribuut nie. As <script data-cfasync=...> verskyn, verwyder dit.
- Leaflet CDN-script bly net voor die hoof-<script> in <body>. Moenie dit skuif nie.
- Ná ELKE verandering: onttrek die inline JS en loop `node --check`. Dit MOET slaag.
- Bevestig elke element-ID wat die JS gebruik, bestaan in die HTML.
- Lêer moet eindig op presies: </script></body></html>

═══ MOENIE BREEK NIE (huidige werk wat maklik verlore gaan) ═══
- Paywall: verifyAndStore(), isUnlocked() se cht_verified-toets, CHA_VERIFY_URL.
- Sekuriteit: safeUrl(), escapeHtml(), die CSP <meta> tag.
- Logo: var LOGO (volledige logo, splash) + var LOGO_ICON (vingerafdruk, koptekst).
- Site-tellings: .site-count-total spans + updateSiteCounts() — afgelei van SITES.length.
- WordPress-roetes wat die app aanroep (moet presies pas by die plugin):
    /checkout (POST) · /verify-token (GET) · /redeem (POST)
  Moenie hierdie name verander nie.

═══ WEERGAWE-OPDATERING (doen dit saam elke keer as die weergawenommer verander) ═══
- Wysig die vier verskynings in index.html: splash-badge, splash-footer, hdr-beta, about-paneel.
- Wysig CACHE_NAME in sw.js na 'cha-trail-v{nuwe-weergawe}'.
  Sonder hierdie stap kry geïnstalleerde gebruikers NIE die opdatering nie — die app
  dien index.html vanuit die ou kas (cache-first strategie).

═══ KLAAR-REËLS ═══
- Loop node --check, bevestig die sluitings-tag + geen data-cfasync.
- Wys 'n opsomming van die veranderde hunks.
- Commit MET 'n duidelike boodskap en push (moenie dit net "staged" los nie —
  ongecommitte werk gaan verlore as die terminaal toemaak).
