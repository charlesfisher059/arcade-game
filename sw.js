/**
 * service-worker.js
 * Path: /home2/asqrtyte/public_html/sw.js  <-- IMPORTANT, see below
 *
 * arcade.js already calls navigator.serviceWorker.register('/sw.js'), so
 * this content needs to be deployed as /sw.js at the site root, not as
 * service-worker.js -- that's just this file's name in this conversation,
 * not the path it should live at. Must be served from the site ROOT (not
 * a subfolder) for its scope to cover the whole site, including
 * /arcade.php. Browsers restrict a service worker's control to its own
 * directory and below.
 *
 * If you already have a real file at /sw.js -- DO NOT overwrite it
 * blindly. Merge the 'push' and 'notificationclick' event listeners below
 * into it instead. Two separate service workers competing for the same
 * scope will cause one to silently lose control.
 *
 * Pairs with a small addition to arcade.js's existing service worker
 * registration block -- see the 'message' listener noted there, which
 * catches the postMessage below as a deep-link fallback.
 */

self.addEventListener('push', function (event) {
  var data = { title: 'Diamonds Outta Dirt', body: 'You have a new notification.', url: '/arcade.php' };
  try {
    if (event.data) data = Object.assign(data, event.data.json());
  } catch (_) {
    // Non-JSON payload -- fall back to defaults rather than fail silently.
  }

  var options = {
    body: data.body,
    icon: '/arcade_app/assets/characters/fire_muse.png',
    badge: '/arcade_app/assets/characters/fire_muse.png',
    data: { url: data.url || '/arcade.php' }
  };
  // Only set tag/renotify when the server actually sent one. A notification
  // with no tag always stacks -- that's the safe default for anything that
  // hasn't been deliberately grouped into a category yet.
  if (data.tag) {
    options.tag = data.tag;
    options.renotify = !!data.renotify;
  }

  event.waitUntil(self.registration.showNotification(data.title, options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var targetUrl = (event.notification.data && event.notification.data.url) || '/arcade.php';
  var target = new URL(targetUrl, self.location.origin);

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windowClients) {
      for (var i = 0; i < windowClients.length; i++) {
        var client = windowClients[i];
        var clientUrl;
        try { clientUrl = new URL(client.url); } catch (_) { continue; }

        // Match on origin + path only, not the full URL. The app uses
        // #hub=missions-style hash routing, so an already-open arcade tab
        // almost never has the exact same URL as the notification target --
        // matching the full string would open a brand-new duplicate tab on
        // every single click instead of reusing the one already open.
        if (clientUrl.origin === target.origin && clientUrl.pathname === target.pathname) {
          if ('focus' in client) client.focus();
          // Actually move the existing tab to the deep-linked hash/route.
          if ('navigate' in client) {
            try { client.navigate(target.href); } catch (_) {}
          }
          // Belt-and-suspenders: some in-app hub routing only reacts to a
          // hashchange event, which client.navigate() to the same page
          // with a different hash should fire -- but this message gives
          // the page a second, explicit way to catch it if that's ever
          // unreliable in a given browser. See the listener this pairs
          // with in arcade.js's service worker registration block.
          try { client.postMessage({ type: 'dod-notification-click', url: target.href }); } catch (_) {}
          return;
        }
      }
      if (clients.openWindow) return clients.openWindow(target.href);
    })
  );
});
