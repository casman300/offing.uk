/*
 * Offing push service worker (root scope).
 *
 * Shows birthday reminder notifications and opens the right page when one is
 * tapped. Deliberately tiny and dependency-free: it's served as a static file
 * (hosting has no Node), registered from Settings → Reminders, and has no
 * fetch handler, so it never touches page loads.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { title: 'Offing', body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'Offing', {
            body: data.body || '',
            icon: '/icons/icon-192.png',
            badge: '/icons/badge-96.png',
            tag: data.tag || undefined,
            data: { url: data.url || '/people' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/people', self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            for (const client of windows) {
                if (client.url.startsWith(self.location.origin) && 'focus' in client) {
                    return client.focus().then((c) => (c && 'navigate' in c ? c.navigate(url) : c));
                }
            }
            return self.clients.openWindow(url);
        }),
    );
});
