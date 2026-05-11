<?php
/**
 * Service Worker - Generato dinamicamente
 * PAManager - Comune
 */

require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/javascript');
header('Service-Worker-Allowed: /');

$baseUrl = PUBLIC_URL;
?>
/**
 * Service Worker - PAManager
 * Gestisce caching e funzionalità offline per PWA
 */

const CACHE_NAME = 'pamanager-v1';
const STATIC_CACHE = 'pamanager-static-v1';
const DYNAMIC_CACHE = 'pamanager-dynamic-v1';
const BASE_URL = '<?= $baseUrl ?>';

// Risorse statiche da cachare immediatamente
const STATIC_ASSETS = [
    BASE_URL + '/',
    BASE_URL + '/assets/css/style.css',
    BASE_URL + '/assets/css/admin.css',
    BASE_URL + '/assets/js/app.js',
    BASE_URL + '/assets/js/admin.js',
    BASE_URL + '/manifest.json.php'
];

// Pagine da cachare per offline
const OFFLINE_PAGES = [
    BASE_URL + '/employee/',
    BASE_URL + '/employee/documents.php',
    BASE_URL + '/employee/communications.php'
];

// Pagina offline fallback
const OFFLINE_PAGE = BASE_URL + '/offline.html';

/**
 * Evento Install - Cache risorse statiche
 */
self.addEventListener('install', (event) => {
    console.log('[SW] Installing Service Worker...');

    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => {
                console.log('[SW] Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => {
                return self.skipWaiting();
            })
            .catch((error) => {
                console.error('[SW] Error caching static assets:', error);
            })
    );
});

/**
 * Evento Activate - Pulizia vecchie cache
 */
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating Service Worker...');

    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((cacheName) => {
                        if (cacheName !== STATIC_CACHE && cacheName !== DYNAMIC_CACHE) {
                            console.log('[SW] Deleting old cache:', cacheName);
                            return caches.delete(cacheName);
                        }
                    })
                );
            })
            .then(() => {
                return self.clients.claim();
            })
    );
});

/**
 * Evento Fetch - Strategia di caching
 */
self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET') {
        return;
    }

    if (!url.protocol.startsWith('http')) {
        return;
    }

    if (url.pathname.includes('/api/')) {
        event.respondWith(networkFirst(request));
        return;
    }

    if (url.pathname.includes('/auth/')) {
        event.respondWith(networkFirst(request));
        return;
    }

    if (isStaticAsset(url.pathname)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    if (request.headers.get('accept').includes('text/html')) {
        event.respondWith(networkFirstWithOffline(request));
        return;
    }

    event.respondWith(staleWhileRevalidate(request));
});

function isStaticAsset(pathname) {
    const staticExtensions = ['.css', '.js', '.png', '.jpg', '.jpeg', '.svg', '.woff', '.woff2'];
    return staticExtensions.some(ext => pathname.endsWith(ext));
}

async function cacheFirst(request) {
    const cachedResponse = await caches.match(request);

    if (cachedResponse) {
        return cachedResponse;
    }

    try {
        const networkResponse = await fetch(request);

        if (networkResponse.ok) {
            const cache = await caches.open(STATIC_CACHE);
            cache.put(request, networkResponse.clone());
        }

        return networkResponse;
    } catch (error) {
        console.error('[SW] Cache First failed:', error);
        return new Response('Risorsa non disponibile', { status: 503 });
    }
}

async function networkFirst(request) {
    try {
        const networkResponse = await fetch(request);

        if (networkResponse.ok) {
            const cache = await caches.open(DYNAMIC_CACHE);
            cache.put(request, networkResponse.clone());
        }

        return networkResponse;
    } catch (error) {
        const cachedResponse = await caches.match(request);

        if (cachedResponse) {
            return cachedResponse;
        }

        return new Response('Servizio non disponibile', { status: 503 });
    }
}

async function networkFirstWithOffline(request) {
    try {
        const networkResponse = await fetch(request);

        if (networkResponse.ok) {
            const cache = await caches.open(DYNAMIC_CACHE);
            cache.put(request, networkResponse.clone());
        }

        return networkResponse;
    } catch (error) {
        const cachedResponse = await caches.match(request);

        if (cachedResponse) {
            return cachedResponse;
        }

        const offlineResponse = await caches.match(OFFLINE_PAGE);

        if (offlineResponse) {
            return offlineResponse;
        }

        return new Response(`
            <!DOCTYPE html>
            <html lang="it">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Offline - PAManager</title>
                <style>
                    body { font-family: system-ui, sans-serif; text-align: center; padding: 50px; }
                    h1 { color: #1a365d; }
                </style>
            </head>
            <body>
                <h1>Sei offline</h1>
                <p>Questa pagina non è disponibile senza connessione internet.</p>
                <button onclick="location.reload()">Riprova</button>
            </body>
            </html>
        `, {
            headers: { 'Content-Type': 'text/html' }
        });
    }
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(DYNAMIC_CACHE);
    const cachedResponse = await cache.match(request);

    const fetchPromise = fetch(request)
        .then((networkResponse) => {
            if (networkResponse.ok) {
                cache.put(request, networkResponse.clone());
            }
            return networkResponse;
        })
        .catch(() => null);

    return cachedResponse || fetchPromise;
}

/**
 * Evento Push - Notifiche push
 */
self.addEventListener('push', (event) => {
    console.log('[SW] Push received');

    let data = { title: 'Notifica', body: 'Nuova notifica' };

    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data.body = event.data.text();
        }
    }

    const options = {
        body: data.body,
        icon: BASE_URL + '/assets/images/icon-192.png',
        badge: BASE_URL + '/assets/images/badge-72.png',
        vibrate: [100, 50, 100],
        data: {
            url: data.url || BASE_URL + '/employee/communications.php'
        },
        actions: [
            { action: 'open', title: 'Apri' },
            { action: 'close', title: 'Chiudi' }
        ]
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

/**
 * Evento Notification Click
 */
self.addEventListener('notificationclick', (event) => {
    console.log('[SW] Notification clicked');

    event.notification.close();

    if (event.action === 'close') {
        return;
    }

    const url = event.notification.data.url || BASE_URL + '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                for (const client of clientList) {
                    if (client.url.includes(BASE_URL) && 'focus' in client) {
                        client.navigate(url);
                        return client.focus();
                    }
                }
                if (clients.openWindow) {
                    return clients.openWindow(url);
                }
            })
    );
});

/**
 * Evento Sync - Background sync
 */
self.addEventListener('sync', (event) => {
    console.log('[SW] Background sync:', event.tag);

    if (event.tag === 'sync-communications') {
        event.waitUntil(syncCommunications());
    }
});

async function syncCommunications() {
    try {
        const response = await fetch(BASE_URL + '/api/communications.php');
        if (response.ok) {
            const data = await response.json();
            const cache = await caches.open(DYNAMIC_CACHE);
            await cache.put(BASE_URL + '/api/communications.php', new Response(JSON.stringify(data)));
        }
    } catch (error) {
        console.error('[SW] Sync failed:', error);
    }
}
