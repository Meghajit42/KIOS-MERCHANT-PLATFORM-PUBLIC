const CACHE_NAME = 'kios-demo-static-v5';
const STATIC_ASSETS = [
    '/assets/app.css',
    '/assets/app.js',
    '/assets/display.css',
    '/assets/display.js',
    '/assets/brand/gmb-favicon-192.png',
    '/assets/brand-product-placeholder.svg',
    '/assets/brand-product-placeholder.svg',
    '/assets/brand-product-placeholder.svg',
    '/assets/brand-product-placeholder.svg',
    '/assets/food/beverage.svg',
    '/assets/food/chaat.svg',
    '/assets/food/dosa.svg',
    '/assets/food/momo.svg',
    '/assets/food/snack.svg',
    '/assets/food/sweet.svg',
    '/assets/food/thali.svg',
    '/manifest.webmanifest',
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))));
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin || !STATIC_ASSETS.includes(url.pathname)) return;
    event.respondWith((async () => {
        try {
            const response = await fetch(request);
            if (response.ok) {
                const cache = await caches.open(CACHE_NAME);
                await cache.put(request, response.clone());
            }
            return response;
        } catch {
            return await caches.match(request) || Response.error();
        }
    })());
});
