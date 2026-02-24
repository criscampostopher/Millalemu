/* ==========================================================
   sw.js - Service Worker v10 (Estrategia: Internet Primero)
   ========================================================== */
const CACHE_NAME = 'millalemu-v10-network-first';

// Recursos estáticos (CSS, JS, Iconos)
const URLS_TO_CACHE = [
    './style_visor.css',
    './style.css',
    './script_visor.js',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    'https://api.tiles.mapbox.com/mapbox.js/plugins/leaflet-omnivore/v0.3.1/leaflet-omnivore.min.js'
];

// 1. INSTALACIÓN
self.addEventListener('install', event => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            // Intentamos cachear, pero no detenemos si algo falla
            return cache.addAll(URLS_TO_CACHE).catch(err => console.warn(err));
        })
    );
});

// 2. ACTIVACIÓN (Limpieza profunda de versiones viejas)
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => Promise.all(
            keys.map(key => {
                if (key !== CACHE_NAME) {
                    console.log('SW: Eliminando caché vieja:', key);
                    return caches.delete(key);
                }
            })
        ))
    );
    self.clients.claim();
});

// 3. INTERCEPTOR (La corrección clave)
self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;

    // ESTRATEGIA: NETWORK FIRST (INTENTAR RED SIEMPRE)
    // Usamos esto para TODO (index.php, API mapas, etc.)
    // Así garantizamos que si hay internet, ves lo más nuevo.
    event.respondWith(
        fetch(event.request)
            .then(networkResponse => {
                // Si la respuesta es válida, la guardamos en caché (actualizamos copia)
                if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then(cache => {
                        cache.put(event.request, responseClone);
                    });
                }
                return networkResponse;
            })
            .catch(() => {
                // FALLO DE RED (OFFLINE): Usamos caché
                return caches.match(event.request, { ignoreSearch: false }).then(cachedRes => {
                    if (cachedRes) return cachedRes;

                    // Si piden el index.php y no está exacto, buscamos el genérico (ignoreSearch)
                    if (event.request.mode === 'navigate') {
                        return caches.match('./index.php', { ignoreSearch: true });
                    }
                    
                    return new Response("Offline", { status: 404 });
                });
            })
    );
});

// 4. DESCARGA MANUAL DE ZONAS (Para el botón "Descargar")
self.addEventListener('message', event => {
    if (event.data.action === 'CACHE_NEW_ZONE') {
        event.waitUntil(
            caches.open(CACHE_NAME).then(async cache => {
                const promesas = event.data.urls.map(url => {
                    // Truco timestamp para forzar descarga fresca
                    const urlFresca = url + (url.includes('?') ? '&' : '?') + 't=' + Date.now();
                    return fetch(urlFresca).then(res => {
                        if (res.ok) return cache.put(url, res);
                    });
                });
                await Promise.all(promesas);
                self.clients.matchAll().then(cl => cl.forEach(c => c.postMessage({ 
                    action: 'ZONE_CACHED_OK', zoneId: event.data.zoneId 
                })));
            })
        );
    }
});