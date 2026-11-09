/* Team to Team service worker: shows push notifications and opens the right page when tapped. */
self.addEventListener('push', (event) => {
    let data = { title: 'Team to Team', body: '', url: '/app' };
    try {
        data = { ...data, ...event.data.json() };
    } catch (e) {
        /* plain text push */
    }
    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: '/brand/icon-192.png',
            badge: '/brand/icon-192.png',
            data: { url: data.url || '/app' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/app', self.location.origin);
    if (url.origin !== self.location.origin) return;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
            for (const w of wins) {
                if (w.url.startsWith(self.location.origin) && 'focus' in w) {
                    w.navigate(url.href);
                    return w.focus();
                }
            }
            return self.clients.openWindow(url.href);
        }),
    );
});
