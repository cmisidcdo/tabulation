/*
 * Service worker for the Tabulation System.
 *
 * Caches ONLY static files (vendor libraries, css, js, images, Livewire's script) so the
 * app loads fast and can be installed. Pages, Livewire updates and the API are never
 * cached: they are user-specific and must always be live. Scores entered while the
 * connection is down are kept by public/js/score-outbox.js, not here.
 *
 * Bump VERSION to drop every cached file on the next visit.
 */
const VERSION = 'v1';
const STATIC_CACHE = 'tabulation-static-' + VERSION;
const OFFLINE_URL = new URL('offline.html', self.registration.scope).href;
const SCOPE_PATH = new URL(self.registration.scope).pathname; // e.g. "/" or "/tabulation/"

// Paths (relative to the app root) that are safe to cache
const STATIC_PREFIXES = ['vendor/', 'css/', 'js/', 'img/', 'build/', 'fonts/'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.add(new Request(OFFLINE_URL, { cache: 'reload' })))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => k.startsWith('tabulation-') && k !== STATIC_CACHE).map((k) => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

function isStaticAsset(url) {
    if (url.origin !== self.location.origin) return false;
    if (url.pathname.endsWith('/livewire/livewire.js') || url.pathname.endsWith('/livewire/livewire.min.js')) return true;
    if (!url.pathname.startsWith(SCOPE_PATH)) return false;
    const rel = url.pathname.slice(SCOPE_PATH.length);
    return STATIC_PREFIXES.some((p) => rel.startsWith(p));
}

// Serve from cache right away, refresh the cached copy in the background.
// Edited css/js therefore shows up on the next page load without bumping VERSION.
async function staleWhileRevalidate(event) {
    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(event.request);
    const network = fetch(event.request)
        .then((res) => {
            if (res && res.ok && res.type === 'basic') cache.put(event.request, res.clone());
            return res;
        })
        .catch(() => cached);
    if (cached) {
        event.waitUntil(network);
        return cached;
    }
    return network;
}

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return; // Livewire updates, logins, uploads: straight to the network

    const url = new URL(req.url);

    if (isStaticAsset(url)) {
        event.respondWith(staleWhileRevalidate(event));
        return;
    }

    // Page loads always go to the network; when it is down, explain instead of the browser error page.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(() => caches.match(OFFLINE_URL).then((r) => r || Response.error()))
        );
    }
    // Everything else (API, report downloads, LED polling): default browser behaviour.
});
