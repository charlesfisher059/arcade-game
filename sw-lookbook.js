// /home2/asqrtyte/public_html/sw-lookbook.js
const CACHE_NAME = 'dod-lookbook-v3';
const OFFLINE_URL = '/offline.html';

// Assets to cache immediately
const PRECACHE_ASSETS = [
  '/lookbook.php',
  '/css/master.css',
  '/manifest.json',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/images/logo.png'
];

// Install event - precache critical assets
self.addEventListener('install', event => {
  console.log('[ServiceWorker] Install');
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('[ServiceWorker] Caching app shell');
        return cache.addAll(PRECACHE_ASSETS);
      })
      .then(() => self.skipWaiting())
  );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
  console.log('[ServiceWorker] Activate');
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            console.log('[ServiceWorker] Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch event - network-first with cache fallback
self.addEventListener('fetch', event => {
  // Skip non-GET requests and chrome-extension
  if (event.request.method !== 'GET' || 
      event.request.url.startsWith('chrome-extension://')) {
    return;
  }

  // Skip lookbook API calls (they need fresh data)
  if (event.request.url.includes('?ajax=1') || 
      event.request.url.includes('?batch=1')) {
    return fetch(event.request);
  }

  // For HTML pages, try network first
  if (event.request.headers.get('accept').includes('text/html')) {
    event.respondWith(
      fetch(event.request)
        .then(response => {
          // Cache the fresh version
          const responseClone = response.clone();
          caches.open(CACHE_NAME)
            .then(cache => cache.put(event.request, responseClone));
          return response;
        })
        .catch(() => {
          // Offline fallback
          return caches.match(event.request)
            .then(cachedResponse => cachedResponse || caches.match(OFFLINE_URL));
        })
    );
    return;
  }

  // For static assets, cache-first
  event.respondWith(
    caches.match(event.request)
      .then(cachedResponse => {
        if (cachedResponse) {
          // Update cache in background
          fetch(event.request)
            .then(response => {
              const responseClone = response.clone();
              caches.open(CACHE_NAME)
                .then(cache => cache.put(event.request, responseClone));
            });
          return cachedResponse;
        }
        return fetch(event.request)
          .then(response => {
            // Don't cache if not successful
            if (!response || response.status !== 200 || response.type !== 'basic') {
              return response;
            }
            const responseClone = response.clone();
            caches.open(CACHE_NAME)
              .then(cache => cache.put(event.request, responseClone));
            return response;
          });
      })
  );
});

// Background sync for analytics
self.addEventListener('sync', event => {
  if (event.tag === 'sync-analytics') {
    console.log('[ServiceWorker] Background sync for analytics');
    event.waitUntil(syncAnalytics());
  }
});

async function syncAnalytics() {
  // Implementation for offline analytics sync
  // You can extend this later
}

// Push notifications (optional)
self.addEventListener('push', event => {
  const options = {
    body: event.data ? event.data.text() : 'New update available!',
    icon: '/icons/icon-192.png',
    badge: '/icons/badge-72.png',
    vibrate: [100, 50, 100],
    data: {
      dateOfArrival: Date.now(),
      primaryKey: 1
    }
  };
  
  event.waitUntil(
    self.registration.showNotification('DOD Visual Database', options)
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(
    clients.openWindow('/lookbook.php')
  );
});