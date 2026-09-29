/* Offline app shell: keeps the pages, styles and scripts on the device so a judge can still open
   the score sheet when the campus Wi-Fi drops. Scores typed offline are kept by score.js and sent
   when the connection is back. The API (api/…) is never cached: results always come live. */
'use strict';

const CACHE = 'coc-tab-shell-v1';
const SHELL = [
  'index.html',
  'pages/judge.html',
  'pages/score.html',
  'pages/dashboard.html',
  'assets/css/app.css',
  'assets/js/app.js',
  'assets/js/pages/login.js',
  'assets/js/pages/judge.js',
  'assets/js/pages/score.js',
  'assets/images/app-icon.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL).catch(() => { /* cache what we can */ })));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('coc-tab-shell-') && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin || url.pathname.includes('/api/') || url.pathname.includes('/install/')) return;

  // pages: the network first (always fresh), the saved copy when offline
  if (req.mode === 'navigate' || req.destination === 'document') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          if (res.ok) caches.open(CACHE).then((cache) => cache.put(req, res.clone()));
          return res;
        })
        .catch(() => caches.match(req, { ignoreSearch: true }).then((hit) => hit || caches.match('index.html', { ignoreSearch: true })))
    );
    return;
  }

  // styles, scripts, pictures: pages load them as file.js?v=<release>, so the exact URL never goes
  // stale; offline, any saved release of the file is better than nothing
  if (/\.(css|js|png|jpe?g|webp|svg|ico|woff2?)$/i.test(url.pathname)) {
    event.respondWith(
      caches.open(CACHE).then((cache) => cache.match(req).then((hit) => hit || fetch(req)
        .then((res) => {
          if (res.ok) cache.put(req, res.clone());
          return res;
        })
        .catch(() => cache.match(req, { ignoreSearch: true }))))
    );
  }
});
