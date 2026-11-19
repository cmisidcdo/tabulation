/*
 * Service worker for the offline judge app (scope: /judge-app/).
 *
 * The whole app shell is precached so the app opens with no connection at all.
 * Shell files are served from the cache and refreshed in the background, so an updated
 * app.js arrives on the next launch. API calls are never cached; scores live in IndexedDB.
 *
 * Bump VERSION when releasing a new app version.
 */
const VERSION = 'v1';
const CACHE = 'judge-app-' + VERSION;
const BASE = self.registration.scope; // e.g. https://host/judge-app/

const SHELL = [
    'index.html',
    'app.js',
    'app.css',
    'manifest.json',
    '../vendor/bootstrap/css/bootstrap.min.css',
    '../vendor/bootstrap-icons/bootstrap-icons.min.css',
    '../img/pwa/icon-192.png',
    '../img/pwa/icon-512.png',
    '../img/pwa/apple-touch-icon.png',
].map((p) => new URL(p, BASE).href);

const ICONS_CSS = new URL('../vendor/bootstrap-icons/bootstrap-icons.min.css', BASE).href;
const INDEX = new URL('index.html', BASE).href;

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE);
        await cache.addAll(SHELL.map((u) => new Request(u, { cache: 'reload' })));
        // The icon font URL (with its hash) is only known from the stylesheet
        try {
            const css = await (await cache.match(ICONS_CSS)).text();
            const fonts = [...css.matchAll(/url\(["']?([^"')]+\.woff2[^"')]*)["']?\)/g)].map((m) => new URL(m[1], ICONS_CSS).href);
            if (fonts.length) await cache.addAll(fonts);
        } catch (e) { /* icons are optional */ }
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter((k) => k.startsWith('judge-app-') && k !== CACHE).map((k) => caches.delete(k)));
        await self.clients.claim();
    })());
});

const isApi = (url) => url.pathname.includes('/api/');

async function fromCacheThenRefresh(event, request) {
    const cache = await caches.open(CACHE);
    const cached = await cache.match(request, { ignoreSearch: request.mode === 'navigate' });
    const network = fetch(event.request)
        .then((res) => {
            if (res && res.ok && res.type === 'basic') cache.put(request, res.clone());
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
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin || isApi(url)) return; // API: network only

    // Opening the app (any page in scope) always serves the cached shell
    if (req.mode === 'navigate') {
        event.respondWith(fromCacheThenRefresh(event, new Request(INDEX)));
        return;
    }
    event.respondWith(fromCacheThenRefresh(event, req));
});
