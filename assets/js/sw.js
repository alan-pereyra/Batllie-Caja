/**
 * Service Worker para Caja POS & Control de Pedidos
 * Estrategia: Network-First (Red primero) para garantizar actualización en tiempo real
 * y evitar cualquier interferencia con transacciones, stock o llamadas AJAX de WooCommerce.
 */

const CACHE_NAME = 'caja-pos-sw-v2';

// 1. Instalación: forzar activación inmediata
self.addEventListener('install', (event) => {
    self.skipWaiting();
});

// 2. Activación: tomar control de todos los clientes sin recargar
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((name) => {
                    if (name !== CACHE_NAME) {
                        return caches.delete(name);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// 3. Peticiones de red (Fetch): Network First
self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Solo procesar peticiones GET
    if (req.method !== 'GET') {
        return;
    }

    const url = new URL(req.url);

    // No interceptar peticiones de autenticación, AJAX o APIs de WordPress / WooCommerce
    if (
        url.pathname.includes('admin-ajax.php') ||
        url.search.includes('action=') ||
        url.pathname.includes('/wp-json/') ||
        url.pathname.includes('wc-ajax=') ||
        url.search.includes('batllie_sync')
    ) {
        return;
    }

    // Estrategia Network First: Siempre consultar la red en vivo
    event.respondWith(
        fetch(req)
            .then((networkResponse) => {
                return networkResponse;
            })
            .catch(() => {
                // Si no hay red, intentar responder desde caché si existe
                return caches.match(req);
            })
    );
});
