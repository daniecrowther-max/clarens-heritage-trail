// Clarens Heritage Trail — Service Worker
// Version: 0.1.0 — bump this string whenever THIS FILE's own caching/fetch
// logic or the ASSETS list changes. That's the only thing that makes a
// browser detect and install a new SW at all (it byte-compares sw.js, not
// index.html) — a deploy that only touches index.html would otherwise never
// be noticed, leaving old installs stuck forever. That's why navigations are
// network-first below: a plain content deploy reaches users on their very
// next load without depending on this file changing.
const CACHE_NAME = 'cha-trail-v0.1.2';

// Files to cache for offline use
const ASSETS = [
  '/',
  '/index.html'
];

// Install: cache core assets
self.addEventListener('install', function(e) {
  e.waitUntil(
    caches.open(CACHE_NAME).then(function(cache) {
      return cache.addAll(ASSETS);
    })
  );
  self.skipWaiting();
});

// Activate: delete old caches
self.addEventListener('activate', function(e) {
  e.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(
        keys.filter(function(key) { return key !== CACHE_NAME; })
            .map(function(key) { return caches.delete(key); })
      );
    })
  );
  self.clients.claim();
});

// Fetch: network-first for content.json, cache-first for everything else
// TODO(step 7 / PWA offline): the content feed now comes from the cha/v1/content
// REST endpoint (see CHA_API_BASE in index.html), not the static /content.json.
// This branch is inert until offline caching is repointed at the REST feed.
self.addEventListener('fetch', function(e) {
  // The WordPress REST API must never be served from the SW cache. The Cache
  // API ignores Cache-Control entirely, so the catch-all cache-first branch
  // below would happily store a no-store response and replay it forever:
  // cha/v1/content would freeze at whatever the first load returned (admin
  // edits would never reach users), and a revoked token could keep validating
  // from cache — the same class of bug the LiteSpeed no-cache fix closed
  // server-side. Matching on /wp-json/ covers the API on any host, so this
  // holds now that the app is a different origin from WordPress.
  if (e.request.url.indexOf('/wp-json/') !== -1) {
    return; // fall through to the network, untouched by the SW
  }

  // HTML navigations (page loads / PWA launches) are network-first: this is
  // the actual fix for stale content on already-installed devices. Cache-first
  // here would mean a device keeps serving whatever index.html it first ever
  // cached, indefinitely, regardless of skipWaiting/clients.claim below (those
  // only matter once a new SW is detected — see the CACHE_NAME comment).
  // Falls back to the cached shell only when genuinely offline.
  if (e.request.mode === 'navigate' || new URL(e.request.url).pathname === '/index.html') {
    e.respondWith(
      fetch(e.request).then(function(response) {
        if (response.status === 200) {
          var copy = response.clone();
          caches.open(CACHE_NAME).then(function(cache) { cache.put('/index.html', copy); });
        }
        return response;
      }).catch(function() {
        return caches.match('/index.html');
      })
    );
    return;
  }

  // content.json must always be fetched fresh so weekly sync actually gets new data
  if (new URL(e.request.url).pathname === '/content.json') {
    e.respondWith(
      fetch(e.request).then(function(response) {
        if (response.status === 200) {
          var copy = response.clone();
          // Store under path-only key so offline fallback can find it
          caches.open(CACHE_NAME).then(function(cache) { cache.put('/content.json', copy); });
        }
        return response;
      }).catch(function() {
        return caches.match('/content.json').then(function(cached) {
          return cached || new Response('{"version":0,"sites":[],"vouchers":[]}',
            {headers:{'Content-Type':'application/json'}});
        });
      })
    );
    return;
  }

  e.respondWith(
    caches.match(e.request).then(function(cached) {
      if (cached) return cached;
      return fetch(e.request).then(function(response) {
        // Cache successful GET requests
        if (e.request.method === 'GET' && response.status === 200) {
          var copy = response.clone();
          caches.open(CACHE_NAME).then(function(cache) {
            cache.put(e.request, copy);
          });
        }
        return response;
      }).catch(function() {
        // If offline and not cached, return the main app
        return caches.match('/index.html');
      });
    })
  );
});
