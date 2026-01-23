/* ==========================================================
   sw.js - Service Worker v2 (Robustecido)
   ========================================================== */
const CACHE_NAME = 'millalemu-v2-fix'; // CAMBIO: Versión nueva para forzar actualización
const URLS_TO_CACHE = [
    './',
    './index.php',
    './style_visor.css',
    './script_visor.js',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    'https://api.tiles.mapbox.com/mapbox.js/plugins/leaflet-omnivore/v0.3.1/leaflet-omnivore.min.js'
    // Quitamos el audio de Google de aquí para evitar errores de CORS que bloqueen la instalación
];

// 1. INSTALACIÓN
self.addEventListener('install', event => {
    self.skipWaiting(); // Forzar activación inmediata
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            console.log('SW: Cacheando App Shell v2');
            return cache.addAll(URLS_TO_CACHE);
        })
    );
});

// 2. ACTIVACIÓN (Limpieza profunda)
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('SW: Borrando caché antigua', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    self.clients.claim(); // Tomar control de inmediato
});

// 3. INTERCEPTOR DE RED
self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;
    event.respondWith(
        caches.match(event.request).then(cachedResponse => {
            if (cachedResponse) return cachedResponse;
            return fetch(event.request);
        })
    );
});

// 4. DESCARGA DE ZONAS (Con manejo de errores)
self.addEventListener('message', event => {
    if (event.data.action === 'CACHE_NEW_ZONE') {
        const urls = event.data.urls;
        
        event.waitUntil(
            caches.open(CACHE_NAME).then(async cache => {
                // Intentamos cachear uno por uno para que un error no detenga todo
                const promesas = urls.map(async url => {
                    try {
                        const response = await fetch(url);
                        if (!response.ok) throw new Error('Network response not ok');
                        return cache.put(url, response);
                    } catch (error) {
                        console.warn('Fallo al cachear:', url, error);
                        // No lanzamos error para continuar con los otros archivos
                    }
                });
                
                await Promise.all(promesas);

                // Avisamos al cliente que terminamos (aunque alguno haya fallado)
                self.clients.matchAll().then(clients => {
                    clients.forEach(client => client.postMessage({
                        action: 'ZONE_CACHED_OK',
                        zoneId: event.data.zoneId
                    }));
                });
            })
        );
    }
});