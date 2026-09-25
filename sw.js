/* SADA One — service worker (PWA shell)
 * Strategy: network-first for pages (the panel is live data), cache fallback
 * for the static shell, and a friendly offline page when both fail.
 * Only navigations and /assets/ files are intercepted — ajax.php and everything
 * else go straight to the network untouched. */
const CACHE = 'sada-one-v3'; // bump when cached shell files must be dropped (7.0 renamed every CSS class)
const SHELL = ['./offline.html', './assets/css/app.css', './assets/js/app.js'];

const fallbackResponse = () => new Response(
    '<!doctype html><meta charset="utf-8"><title>Bağlantı yok</title><body style="font-family:sans-serif;padding:40px;text-align:center">'
    + '<h2>Sunucuya ulaşılamadı</h2><p>Bağlantınızı kontrol edip sayfayı yenileyin.</p><a href="javascript:location.reload()">Yenile</a></body>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });

self.addEventListener('install', (e) => {
    // Cache the shell best-effort: one failing file must not block the worker from installing
    e.waitUntil(caches.open(CACHE).then((c) => Promise.allSettled(SHELL.map((u) => c.add(u)))).then(() => self.skipWaiting()));
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
    // Static assets: cache-first with background refresh (URLs carry ?v=version)
    if (url.pathname.includes('/assets/')) {
        e.respondWith(
            caches.match(e.request).then((hit) => {
                const live = fetch(e.request).then((r) => {
                    if (r.ok) caches.open(CACHE).then((c) => c.put(e.request, r.clone()));
                    return r;
                }).catch(() => hit || fallbackResponse());
                return hit || live;
            })
        );
        return;
    }
    // Pages: always fresh; offline fallback. Never resolve with undefined — that
    // fails the navigation outright and shows the browser's own error page.
    if (e.request.mode === 'navigate') {
        e.respondWith(fetch(e.request).catch(async () => (await caches.match('./offline.html')) || fallbackResponse()));
    }
});
