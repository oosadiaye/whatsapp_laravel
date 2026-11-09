// BlastIQ service worker. It exists so the browser offers "Install app" and so a dropped
// connection shows a friendly page. It deliberately caches NO pages, API calls or
// Livewire responses: the inbox, calls and email are live, per-user data and must never
// be served stale or to the wrong person on a shared device.
const CACHE = 'bq-shell-v1';
const OFFLINE_URL = '/offline.html';
const SHELL = [OFFLINE_URL, '/icons/icon-192.png', '/icons/icon-512.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Only page navigations get a fallback, and only when the network fails.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
    }
    // Everything else goes straight to the network, untouched.
});
