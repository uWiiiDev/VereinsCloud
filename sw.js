const CACHE_NAME = 'vereinscloud-shell-v9';
const SHELL_ASSETS = [
    'assets/css/app.css',
    'assets/js/app.js',
];

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS)).catch(() => {})
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// Network-first for static assets; never cache HTML/PHP endpoints
self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET' || req.mode === 'navigate') return;
    const url = new URL(req.url);
    if (!url.pathname.endsWith('.css') && !url.pathname.endsWith('.js')) {
        return;
    }
    event.respondWith(
        fetch(req, { cache: 'no-cache' })
            .then((res) => {
                if (res && res.status === 200) {
                    const clone = res.clone();
                    caches.open(CACHE_NAME).then((c) => c.put(req, clone)).catch(() => {});
                }
                return res;
            })
            .catch(() => caches.match(req))
    );
});
