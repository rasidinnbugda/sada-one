/* SADA One — service worker (PWA shell)
 * Strategy: network-first for pages (the panel is live data), cache fallback
 * for the static shell, and a friendly offline page when both fail. */
const CACHE = 'sada-one-v1';
const SHELL = ['./offline.html', './assets/css/app.css', './assets/js/app.js'];

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    if (e.request.method !== 'GET' || url.origin !== location.origin) return;
    // Static assets: cache-first with background refresh
    if (url.pathname.includes('/assets/')) {
        e.respondWith(
            caches.match(e.request).then((hit) => {
                const live = fetch(e.request).then((r) => {
                    if (r.ok) caches.open(CACHE).then((c) => c.put(e.request, r.clone()));
                    return r;
                }).catch(() => hit);
                return hit || live;
            })
        );
        return;
    }
    // Pages: always fresh; offline fallback
    e.respondWith(fetch(e.request).catch(() => caches.match('./offline.html')));
});
