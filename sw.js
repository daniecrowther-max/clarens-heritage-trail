// Clarens Heritage Trail — Service Worker
// Version: 1.0 — update this string to force cache refresh on new releases
const CACHE_NAME = 'cha-trail-v2.5.3';

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
self.addEventListener('fetch', function(e) {
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
