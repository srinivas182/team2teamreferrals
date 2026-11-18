/*
 * Team to Team service worker (Sprint 53b).
 *
 * - Push notifications: shows them and opens the right page when tapped.
 * - Works offline in the member area: pages you've opened recently are kept on this device (network first, so you
 *   always see the latest when online), and anything else shows a friendly offline page. Public pages and the admin
 *   are never kept; signing out deletes everything kept.
 * - Updates: a new version waits until the page asks it to take over, so nobody loses what they're typing.
 *
 * The page registers this file as /sw.js?v=<build version>, so every deploy installs a fresh copy.
 */
const VERSION = new URL(self.location.href).searchParams.get('v') || 'dev';
const SHELL = `t2t-shell-${VERSION}`; // the offline page and its icon
const ASSETS = 't2t-assets'; // built files: their names change when their content does, so they're safe to keep
const PAGES = 't2t-pages'; // member pages opened recently
const MAX_PAGES = 30;
const OFFLINE = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(SHELL).then((c) => c.addAll([OFFLINE, '/brand/icon-192.png'])));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();
            await Promise.all(keys.filter((k) => k.startsWith('t2t-shell-') && k !== SHELL).map((k) => caches.delete(k)));
            await self.clients.claim();
        })(),
    );
});

self.addEventListener('message', (event) => {
    if (event.data === 'skip-waiting') self.skipWaiting();
    if (event.data === 'forget-pages') event.waitUntil(caches.delete(PAGES));
});

/** Member pages only: never public pages (they're cached at the edge), the admin, sign-in, files or downloads. */
const keepable = (url) =>
    url.origin === self.location.origin &&
    (url.pathname === '/app' || url.pathname.startsWith('/app/')) &&
    !/\.(pdf|csv|ics|svg|zip|png|jpg)$/.test(url.pathname) &&
    !url.pathname.startsWith('/app/billing');

const pageKey = (request, url) => `${url.pathname}${url.search}${request.headers.get('X-Inertia') ? '#inertia' : ''}`;

async function trim() {
    const cache = await caches.open(PAGES);
    const keys = await cache.keys();
    await Promise.all(keys.slice(0, Math.max(0, keys.length - MAX_PAGES)).map((k) => cache.delete(k)));
}

async function networkFirst(request, url) {
    const key = pageKey(request, url);
    try {
        const response = await fetch(request);
        // Signed out (sent to the sign-in page): nothing kept should outlive the session.
        if (response.redirected && new URL(response.url).pathname.startsWith('/login')) {
            await caches.delete(PAGES);
        } else if (response.ok && response.type === 'basic') {
            const cache = await caches.open(PAGES);
            await cache.put(key, response.clone());
            trim();
        }
        return response;
    } catch {
        const kept = await caches.open(PAGES).then((c) => c.match(key));
        if (kept) {
            const headers = new Headers(kept.headers);
            headers.set('X-T2T-Offline', '1');
            return new Response(kept.body, { status: kept.status, statusText: kept.statusText, headers });
        }
        if (request.mode === 'navigate') return (await caches.match(OFFLINE)) || Response.error();
        throw new Error('offline');
    }
}

async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) return cached;
    const response = await fetch(request);
    if (response.ok) (await caches.open(ASSETS)).put(request, response.clone());
    return response;
}

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;
    if (request.method !== 'GET') {
        // Signing out forgets every page kept on this device.
        if (url.pathname === '/logout') event.waitUntil(caches.delete(PAGES));
        return;
    }
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request));
        return;
    }
    if (keepable(url) && (request.mode === 'navigate' || request.headers.get('X-Inertia'))) {
        event.respondWith(networkFirst(request, url));
        return;
    }
    if (request.mode === 'navigate' && !url.pathname.startsWith('/admin')) {
        // Other pages go straight to the network; only when that fails is the offline page shown.
        event.respondWith(fetch(request).catch(async () => (await caches.match(OFFLINE)) || Response.error()));
    }
});

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
