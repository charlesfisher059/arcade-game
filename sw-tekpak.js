/**
 * sw-tekpak.js
 * Path: /home2/asqrtyte/public_html/sw-tekpak.js
 *
 * Service worker for the Tek Pak creator PWA.
 * Caches the app shell so drafts remain usable offline (localStorage).
 * Does not replace the site-wide /sw.js push worker — register this
 * only from /tek-pak (tech_pack.php).
 */
const TEK_CACHE = 'tek-pak-shell-v1';
const TEK_SHELL = [
  '/tek-pak',
  '/tech_pack.php',
  '/manifest-tekpak.json',
  '/android-chrome-192x192.png',
  '/android-chrome-512x512.png',
  '/apple-touch-icon.png',
  '/favicon-32x32.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(TEK_CACHE).then((cache) => cache.addAll(TEK_SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k.startsWith('tek-pak-') && k !== TEK_CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Never cache API save/list/load posts handled as GET?action= — those are POST.
  // Network-first for HTML app routes; cache fallback when offline.
  const isAppPage =
    url.pathname === '/tek-pak' ||
    url.pathname === '/tekpak' ||
    url.pathname === '/tech-pack' ||
    url.pathname === '/tech_pack' ||
    url.pathname === '/tech_pack.php' ||
    url.pathname.endsWith('/tek-pak') ||
    url.pathname.endsWith('/tech_pack.php');

  if (isAppPage) {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(TEK_CACHE).then((c) => c.put('/tek-pak', copy)).catch(() => {});
          return res;
        })
        .catch(() =>
          caches.match('/tek-pak').then((r) => r || caches.match('/tech_pack.php') || caches.match(req))
        )
    );
    return;
  }

  // Static assets: cache-first
  if (
    url.pathname.startsWith('/android-chrome') ||
    url.pathname.startsWith('/apple-touch') ||
    url.pathname.startsWith('/favicon') ||
    url.pathname === '/manifest-tekpak.json'
  ) {
    event.respondWith(
      caches.match(req).then((hit) => {
        if (hit) return hit;
        return fetch(req).then((res) => {
          const copy = res.clone();
          caches.open(TEK_CACHE).then((c) => c.put(req, copy)).catch(() => {});
          return res;
        });
      })
    );
  }
});
