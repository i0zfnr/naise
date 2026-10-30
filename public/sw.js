const CACHE = 'naise-v5';
const CORE = ['/', '/manifest.webmanifest?v=5', '/icons/naise-512.png?v=5', '/icons/naise-192.png?v=5', '/icons/apple-touch-icon.png?v=5', '/icons/favicon-32.png?v=5'];
const privatePath = path => /^\/(api|admin|staff|manage|shift|auth|demo|logout)(\/|$)/.test(path);

self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(CORE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
    event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('naise-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET' || url.origin !== self.location.origin || privatePath(url.pathname)) {
        return;
    }
    if (event.request.mode === 'navigate') {
        event.respondWith(fetch(event.request).catch(() => caches.match('/')));
        return;
    }
    event.respondWith(caches.match(event.request).then(hit => hit || fetch(event.request).then(response => {
        if (response.ok && !response.redirected && !response.headers.get('Cache-Control')?.includes('no-store')) {
            event.waitUntil(caches.open(CACHE).then(cache => cache.put(event.request, response.clone())));
        }
        return response;
    })));
});
