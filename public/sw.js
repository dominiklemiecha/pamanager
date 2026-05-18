/**
 * Service Worker - PAManager
 * Gestisce caching offline e push notifications
 */

const CACHE_NAME = 'pamanager-v12';

// Base path dello scope del Service Worker (es. "/pamanager/public/" o "/")
// Calcolato dalla directory del file sw.js — robusto anche se self.registration non è ancora pronto.
const BASE = new URL('./', self.location.href).pathname;

const OFFLINE_URL = BASE + 'offline.html';

// Risorse da cachare per funzionamento offline
const STATIC_ASSETS = [
    BASE,
    BASE + 'assets/css/style.css',
    BASE + 'assets/images/icon.php?size=192',
    BASE + 'assets/images/icon.php?size=512',
    BASE + 'offline.html'
];

// Installazione Service Worker
self.addEventListener('install', (event) => {
    console.log('[SW] Installing...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[SW] Caching static assets');
                return cache.addAll(STATIC_ASSETS.map(url => {
                    // Aggiungi base URL se configurato
                    return url;
                })).catch(err => {
                    console.log('[SW] Cache addAll failed:', err);
                });
            })
            .then(() => self.skipWaiting())
    );
});

// Attivazione Service Worker
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating...');
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_NAME)
                    .map((name) => {
                        console.log('[SW] Deleting old cache:', name);
                        return caches.delete(name);
                    })
            );
        }).then(() => self.clients.claim())
    );
});

// Intercetta richieste di rete
self.addEventListener('fetch', (event) => {
    // Skip per richieste non GET
    if (event.request.method !== 'GET') return;

    // Skip per richieste API
    if (event.request.url.includes('/api/')) return;

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                // Clona la risposta per il cache
                if (response.status === 200) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        // Cache risorse statiche e icone dinamiche
                        if (event.request.url.match(/\.(css|js|png|jpg|jpeg|gif|svg|woff|woff2)$/) ||
                            event.request.url.includes('icon.php')) {
                            cache.put(event.request, responseClone);
                        }
                    });
                }
                return response;
            })
            .catch(() => {
                // Offline - prova dal cache
                return caches.match(event.request).then((response) => {
                    if (response) {
                        return response;
                    }
                    // Se è una pagina HTML, prova mostrare la pagina offline
                    const accept = event.request.headers.get('accept') || '';
                    if (accept.includes('text/html')) {
                        return caches.match(OFFLINE_URL).then(r => r || new Response('', { status: 504, statusText: 'Offline' }));
                    }
                    // Per asset non-HTML, restituisci una risposta vuota per evitare "Failed to convert value to 'Response'"
                    return new Response('', { status: 504, statusText: 'Offline' });
                });
            })
    );
});

// Gestione Push Notifications
self.addEventListener('push', (event) => {
    const timestamp = new Date().toISOString();
    console.log('[SW] Push received at', timestamp);

    // Log al server per debug - IMPORTANTE: questo ci dice se il SW riceve il push
    const logToServer = (eventType, extra = '') => {
        const url = BASE + 'api/push-log.php?event=' + eventType + '&time=' + Date.now() + extra;
        return fetch(url).catch(err => console.log('[SW] Log fetch error:', err));
    };

    // Logga immediatamente che il push è stato ricevuto
    logToServer('push_received', '&timestamp=' + encodeURIComponent(timestamp));

    let data = {
        title: 'PAManager',
        body: 'Nuova notifica',
        icon: '/assets/images/icon.php?size=192',
        url: '/'
    };

    try {
        if (event.data) {
            // Prova prima a leggere come testo per debug
            const rawText = event.data.text();
            console.log('[SW] Push raw text (first 200 chars):', rawText.substring(0, 200));
            logToServer('push_raw', '&raw=' + encodeURIComponent(rawText.substring(0, 100)));

            // Ora prova a parsare come JSON
            try {
                const payload = JSON.parse(rawText);
                console.log('[SW] Push payload parsed:', JSON.stringify(payload));
                logToServer('push_parsed', '&payload=' + encodeURIComponent(JSON.stringify(payload).substring(0, 100)));
                data = { ...data, ...payload };
            } catch (jsonErr) {
                console.log('[SW] JSON parse error, using raw text as body');
                logToServer('push_json_error', '&error=' + encodeURIComponent(jsonErr.message));
                data.body = rawText;
            }
        } else {
            console.log('[SW] Push without data');
            logToServer('push_no_data');
        }
    } catch (e) {
        console.log('[SW] Push data error:', e);
        logToServer('push_error', '&error=' + encodeURIComponent(e.message));
    }

    // Costruisci URL assoluto per l'icona (richiesto da alcuni browser, iOS Safari fallisce silenziosamente se l'icona è 404)
    // I path dal server arrivano root-relative (es. "/assets/...") ma l'app è sotto SCOPE_PATH (es. "/pamanager/public/").
    const origin = self.location.origin;
    let iconUrl;
    if (data.icon.startsWith('http')) {
        iconUrl = data.icon;
    } else if (data.icon.startsWith('/')) {
        // Strip leading slash e usa BASE come prefisso
        iconUrl = origin + BASE + data.icon.replace(/^\/+/, '');
    } else {
        iconUrl = origin + BASE + data.icon;
    }

    // Stesso trattamento per data.url (per notificationclick)
    if (data.url) {
        if (!data.url.startsWith('http') && data.url.startsWith('/')) {
            data.url = BASE + data.url.replace(/^\/+/, '');
        } else if (!data.url.startsWith('http')) {
            data.url = BASE + data.url;
        }
    }

    // Opzioni compatibili con iOS Safari
    // iOS NON supporta: actions, vibrate, badge, requireInteraction
    const options = {
        body: data.body,
        icon: iconUrl,
        data: {
            url: data.url || '/',
            dateOfArrival: Date.now()
        },
        tag: data.tag || 'pamanager-notification'
    };

    // Aggiungi opzioni solo per browser che le supportano (non iOS)
    const isIOS = /iPad|iPhone|iPod/.test(self.navigator?.userAgent || '');
    if (!isIOS) {
        options.badge = origin + BASE + 'assets/images/icon.php?size=72';
        options.vibrate = [100, 50, 100];
        options.actions = [
            { action: 'open', title: 'Apri' },
            { action: 'close', title: 'Chiudi' }
        ];
        options.requireInteraction = data.requireInteraction || false;
    }

    console.log('[SW] Showing notification:', data.title, options);

    // IMPORTANTE: iOS Web Push richiede TASSATIVAMENTE che il push event mostri una notifica visibile,
    // altrimenti revoca la subscription. Fallback a notifica minima se quella ricca fallisce.
    event.waitUntil(
        self.registration.showNotification(data.title, options)
            .then(() => {
                console.log('[SW] Notification shown successfully');
                logToServer('notification_shown');
            })
            .catch(err => {
                console.error('[SW] Notification error, fallback to minimal:', err);
                logToServer('notification_error', '&error=' + encodeURIComponent(err.message || String(err)));
                // Fallback minimo: solo title + body, niente altro
                return self.registration.showNotification(data.title || 'PAManager', {
                    body: data.body || 'Nuova notifica'
                }).catch(e2 => {
                    console.error('[SW] Even fallback notification failed:', e2);
                    logToServer('notification_fallback_error', '&error=' + encodeURIComponent(e2.message || String(e2)));
                });
            })
    );
});

// Click su notifica
self.addEventListener('notificationclick', (event) => {
    console.log('[SW] Notification clicked');

    event.notification.close();

    if (event.action === 'close') {
        return;
    }

    const urlToOpen = event.notification.data?.url || '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                // Se c'è già una finestra aperta, focalizzala
                for (const client of clientList) {
                    if (client.url.includes(self.location.origin) && 'focus' in client) {
                        client.navigate(urlToOpen);
                        return client.focus();
                    }
                }
                // Altrimenti apri nuova finestra
                if (clients.openWindow) {
                    return clients.openWindow(urlToOpen);
                }
            })
    );
});

// Chiusura notifica
self.addEventListener('notificationclose', (event) => {
    console.log('[SW] Notification closed');
});

// Gestione messaggi dalla pagina (per debug/test)
self.addEventListener('message', (event) => {
    console.log('[SW] Message received:', event.data);

    if (event.data && event.data.type === 'SKIP_WAITING') {
        console.log('[SW] Skip waiting requested');
        self.skipWaiting();
        return;
    }

    if (event.data && event.data.type === 'SIMULATE_PUSH') {
        const payload = event.data.payload || {};
        console.log('[SW] Simulating push notification');

        self.registration.showNotification(payload.title || 'Test', {
            body: payload.body || 'Notifica simulata',
            icon: self.location.origin + BASE + 'assets/images/icon.php?size=192',
            tag: 'test-simulated-' + Date.now()
        }).then(() => {
            console.log('[SW] Simulated notification shown');
        }).catch(err => {
            console.error('[SW] Simulated notification error:', err);
        });
    }

    if (event.data && event.data.type === 'GET_VERSION') {
        event.ports[0].postMessage({ version: 'pamanager-v7' });
    }
});

// Sincronizzazione in background (per invio dati quando torna online)
self.addEventListener('sync', (event) => {
    console.log('[SW] Sync event:', event.tag);

    if (event.tag === 'sync-notifications') {
        event.waitUntil(
            // Qui si potrebbero sincronizzare dati pendenti
            Promise.resolve()
        );
    }
});

console.log('[SW] Service Worker loaded');
