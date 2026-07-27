/**
 * service-worker.js
 * Path: /home2/asqrtyte/public_html/service-worker.js
 *
 * IMPORTANT: must be served from the site ROOT (not a subfolder) for its
 * scope to cover the whole site, including /arcade.php. Browsers restrict
 * a service worker's control to its own directory and below.
 *
 * If you already have a service worker (manifest.json is referenced in
 * arcade_app/views/game.php, which usually means a PWA setup already
 * exists) -- DO NOT upload this as a second file. Instead, merge the
 * 'push' and 'notificationclick' event listeners below into your
 * existing service worker file. Two separate service workers competing
 * for the same scope will cause one to silently lose control.
 */

self.addEventListener('push', function (event) {
  var data = { title: 'Diamonds Outta Dirt', body: 'You have a new notification.', url: '/' };
  try {
    if (event.data) data = Object.assign(data, event.data.json());
  } catch (_) {
    // Non-JSON payload -- fall back to defaults rather than fail silently.
  }

  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      icon: '/favicon-192.png',
      badge: '/favicon-96.png',
      data: { url: data.url || '/' },
      tag: data.tag || undefined
    })
  );
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var targetUrl = (event.notification.data && event.notification.data.url) || '/';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windowClients) {
      for (var i = 0; i < windowClients.length; i++) {
        var client = windowClients[i];
        if (client.url === targetUrl && 'focus' in client) return client.focus();
      }
      if (clients.openWindow) return clients.openWindow(targetUrl);
    })
  );
});