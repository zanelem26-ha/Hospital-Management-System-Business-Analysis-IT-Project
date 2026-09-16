/**
 * HMS Service Worker — Offline Asset Cache
 * Responsible for caching static assets and PHP pages for offline use, and providing a fallback page when the user is offline.
 * 
 * Strategy:
 *  - Static assets (CSS/JS): cache-first (serve instantly, update in background)
 *  - PHP pages: network-first (fresh data when online, cached fallback when not)
 *  - POST requests: never intercepted (handled by sync_manager.js + IndexedDB)
 *  - Unknown offline requests: serve /HMS/offline.html, then inline fallback
 */

'use strict';

// Cache version and offline shell page
// Remember to update version if static assets change (style.css, main.js, sync_manager.js, session_timeout.js, offline.html)
const CACHE   = 'hms-cache-v23'; // v2: version 23 - clean
const OFFLINE = '/HMS/offline.html';

// Assets to pre-cache on install (offline shell) and store in the cache for offline use.
// These are the minimum required to display the offline page.
const PRECACHE = [
    '/HMS/offline.html',
    '/HMS/css/style.css',
    '/HMS/js/main.js',
    '/HMS/js/sync_manager.js',
    '/HMS/js/session_timeout.js',
];

// Endpoints that must always reach the real server - online-first (auth/write/connectivity probe)
const NETWORK_FIRST = [
    '/HMS/pages/sync_receive.php',
    '/HMS/pages/login.php',
    '/HMS/pages/logout.php',
    '/HMS/pages/ping.php',
    '/HMS/pages/staff_shifts.php',
];

// Inline fallback returned when neither the cached page nor offline.html can be found.
// This should never happen in practice — offline.html is pre-cached on install.
const FALLBACK_HTML =
    '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
    + '<meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<title>Offline — HMS</title>'
    + '<style>body{font-family:system-ui;text-align:center;padding:4rem;background:#f0f4f8}'
    + 'h2{color:#1a3a5c}p{color:#607d8b}a{color:#00acc1;font-weight:600}</style></head>'
    + '<body><h2>&#9679; No internet connection detected</h2>'
    + '<p>HMS cannot be reached. Check your connection and '
    + '<a href="">retry</a> when back online.</p></body></html>';

// Install: pre-cache shell assets using allSettled so one miss never blocks install
self.addEventListener('install', (e) => {
    e.waitUntil((async () => {
        const cache = await caches.open(CACHE);
        await Promise.allSettled(PRECACHE.map(url => cache.add(url)));
        await self.skipWaiting();
    })());
});

// Activate: clear old caches, then claim all open clients immediately
self.addEventListener('activate', (e) => {
    e.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)));
        await self.clients.claim();
    })());
});

// Fetch
self.addEventListener('fetch', (e) => {
    const req = e.request;

    // Only handle same-origin GET requests
    if (req.method !== 'GET') return;
    if (!req.url.startsWith(self.location.origin)) return;

    const path = new URL(req.url).pathname;

    // Auth / sync / probe: always hit the network; return 503 JSON when offline
    if (NETWORK_FIRST.some(p => path.startsWith(p))) {
        e.respondWith(
            fetch(req).catch(() =>
                new Response(JSON.stringify({ error: 'offline' }), {
                    status:  503,
                    headers: { 'Content-Type': 'application/json' },
                })
            )
        );
        return;
    }

    // PHP pages: network-first -> cached page -> offline shell -> inline fallback
    if (path.endsWith('.php') || path === '/HMS/' || path === '/HMS') {
        e.respondWith((async () => {
            try {
                const res = await fetch(req);
                // Clone synchronously before return — calling res.clone() inside
                // a .then() fires after the body is handed to the browser and throws
                if (res.ok) {
                    const clone = res.clone();
                    caches.open(CACHE).then(c => c.put(req, clone));
                }
                return res;
            } catch (_) {
                // Network unavailable — work through fallback chain

                // 1. Exact page from cache (ignoreVary avoids Vary-header mismatches
                //    between prefetch requests and navigate-mode requests)
                const cached = await caches.match(req, { ignoreVary: true });
                if (cached) return cached;

                // 2. Pre-cached offline shell
                const offlinePage = await caches.match(OFFLINE, { ignoreVary: true });
                if (offlinePage) return offlinePage;

                // 3. Absolute inline fallback — guarantees a valid Response is always returned
                return new Response(FALLBACK_HTML, {
                    status:  503,
                    headers: { 'Content-Type': 'text/html; charset=utf-8' },
                });
            }
        })());
        return;
    }

    // Static assets: cache-first with background revalidation
    e.respondWith((async () => {
        const cached = await caches.match(req, { ignoreVary: true });
        if (cached) {
            // Update in background without blocking the response
            fetch(req).then(fresh => {
                if (fresh && fresh.ok) caches.open(CACHE).then(c => c.put(req, fresh));
            }).catch(() => {});
            return cached;
        }
        try {
            const res = await fetch(req);
            if (res.ok) { const clone = res.clone(); caches.open(CACHE).then(c => c.put(req, clone)); }
            return res;
        } catch (_) {
            const offlinePage = await caches.match(OFFLINE, { ignoreVary: true });
            return offlinePage || new Response(FALLBACK_HTML, {
                status:  503,
                headers: { 'Content-Type': 'text/html; charset=utf-8' },
            });
        }
    })());
});
