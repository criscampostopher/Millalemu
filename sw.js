/* ==========================================================
   sw.js - Service Worker v5 (Estrategia Híbrida Inteligente)
   ========================================================== */
const CACHE_NAME = 'millalemu-v5-hybrid'; // Cambiamos versión para limpiar lo anterior
const URLS_TO_CACHE = [
    './index.php',
    './style_visor.css',
    './style.css',
    './script_visor.js',
    // Librerías externas
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    'https://api.tiles.mapbox.com/mapbox.js/plugins/leaflet-omnivore/v0.3.1/leaflet-omnivore.min.js'
];

// 1. INSTALACIÓN
self.addEventListener('install', event => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(async cache => {
            console.log('SW: Instalando recursos base...');
            const promesas = URLS_TO_CACHE.map(async url => {
                try {
                    const response = await fetch(url);
                    if (response.ok) return cache.put(url, response);
                } catch (e) { console.warn('No se pudo cachear:', url); }
            });
            return Promise.all(promesas);
        })
    );
});

// 2. ACTIVACIÓN (Limpieza automática de cachés viejas)
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => Promise.all(
            keys.map(key => {
                if (key !== CACHE_NAME) return caches.delete(key);
            })
        ))
    );
    self.clients.claim();
});

// 3. INTERCEPTOR INTELIGENTE (EL CAMBIO CLAVE)
self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;

    // A) ESTRATEGIA "NETWORK FIRST" (Internet Primero)
    // Solo para el archivo principal HTML (index.php).
    // Esto asegura que si tienes internet, SIEMPRE veas la versión real (Admin/Worker).
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then(networkResponse => {
                    // Si Internet funciona, devolvemos la página fresca...
                    // ... Y actualizamos la caché en segundo plano para el futuro.
                    return caches.open(CACHE_NAME).then(cache => {
                        cache.put(event.request, networkResponse.clone());
                        return networkResponse;
                    });
                })
                .catch(() => {
                    // Si Internet falla, usamos la copia guardada (Modo Offline)
                    return caches.match(event.request).then(cachedRes => {
                        if (cachedRes) return cachedRes;
                        // Salvavidas: si la URL exacta falló, devolvemos el index.php genérico
                        return caches.match('./index.php');
                    });
                })
        );
        return;
    }

    // B) ESTRATEGIA "CACHE FIRST" (Caché Primero)
    // Para imágenes, scripts, mapas y estilos.
    // Aquí preferimos velocidad y no gastar datos.
    event.respondWith(
        (async () => {
            try {
                // Buscamos en caché
                const cachedResponse = await caches.match(event.request, { ignoreSearch: true });
                if (cachedResponse) return cachedResponse;
                
                // Si no está, descargamos de internet
                return await fetch(event.request);
            } catch (error) {
                // Si falla todo (Offline y sin caché), no devolvemos nada o un error 404 silencioso
                return new Response("Offline", { status: 404, statusText: "Offline" });
            }
        })()
    );
});

// 4. DESCARGA DE ZONAS (Igual que antes)
self.addEventListener('message', event => {
    if (event.data.action === 'CACHE_NEW_ZONE') {
        const urls = event.data.urls;
        event.waitUntil(
            caches.open(CACHE_NAME).then(async cache => {
                const promesas = urls.map(async url => {
                    try {
                        const urlFresca = url + (url.includes('?') ? '&' : '?') + 't=' + Date.now();
                        const response = await fetch(urlFresca);
                        if (response.ok) return cache.put(url, response);
                    } catch (e) { console.error('Error descarga zona:', url); }
                });
                await Promise.all(promesas);
                self.clients.matchAll().then(clients => {
                    clients.forEach(c => c.postMessage({ action: 'ZONE_CACHED_OK', zoneId: event.data.zoneId }));
                });
            })
        );
    }
});