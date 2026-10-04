/* sw.js – site service worker (sitedeliveries.site) */

/** Change when you deploy to nudge updates */
const SW_VERSION = 'v1.0.0';

/** Optional tiny cache for your app shell/icons (safe to leave empty) */
const PRECACHE = [
  '/icon.php?f=icon-192.png',
  '/icon.php?f=icon-96.png'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open('precache-' + SW_VERSION).then(c => c.addAll(PRECACHE)).catch(()=>{})
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(self.clients.claim());
});

/**
 * PUSH: expects JSON like:
 * { title, body, icon, badge, url, tag, requireInteraction }
 *
 * If no payload or malformed -> shows a generic message.
 */
self.addEventListener('push', event => {
  let data = {};
  try {
    if (event.data) data = event.data.json();
  } catch (e) {
    // Some push services may send text; try parse manually
    try { data = JSON.parse(event.data.text()); } catch(_) {}
  }

  const title = data.title || 'Site update';
  const options = {
    body: data.body || 'You have a new notification.',
    icon: data.icon || '/icon.php?f=icon-192.png',
    badge: data.badge || '/icon.php?f=icon-96.png',
    tag: data.tag || undefined,
    requireInteraction: !!data.requireInteraction,
    data: {
      url: data.url || '/',
      _ts: Date.now()
    }
  };

  event.waitUntil(
    self.registration.showNotification(title, options)
  );
});

/**
 * When user clicks a notification: focus an existing tab for this origin,
 * or open a new one to the target URL.
 */
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const to = (event.notification.data && event.notification.data.url) ? event.notification.data.url : '/';

  event.waitUntil((async () => {
    const allClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    // Try to focus a tab on the same origin (and same path if already open)
    for (const client of allClients) {
      try {
        const url = new URL(client.url);
        if (url.origin === self.location.origin) {
          await client.focus();
          // If it’s not already at "to", navigate it
          if (url.pathname + url.search + url.hash !== to) {
            client.postMessage({ action: 'navigate', to });
          }
          return;
        }
      } catch(_) {}
    }
    // No client to focus -> open a new one
    await self.clients.openWindow(to);
  })());
});

/** Pass-through fetch (you can extend with caches if you wish) */
self.addEventListener('fetch', () => { /* no-op */ });
