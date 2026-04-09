/* ==========================================================
   sw.js - Service Worker v12 (Filtro Inteligente)
   ========================================================== */
const CACHE_NAME = 'millalemu-v12-smart';

const URLS_TO_CACHE = [
    './',
    './index.php',
    './menu_usuario.php',
    './style_visor.css',
    './style.css',
    './script_visor.js',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    'https://api.tiles.mapbox.com/mapbox.js/plugins/leaflet-omnivore/v0.3.1/leaflet-omnivore.min.js',
    'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-blue.png',
    'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-red.png',
    'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-orange.png',
    'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-green.png',
    'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-shadow.png'
];

self.addEventListener('install', event => {
    self.skipWaiting();
    event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(URLS_TO_CACHE).catch(()=>{})));
});

self.addEventListener('activate', event => {
    event.waitUntil(caches.keys().then(keys => Promise.all(
        keys.map(key => { if (key !== CACHE_NAME) return caches.delete(key); })
    )));
    self.clients.claim();
});

// FUNCIÓN CLAVE: Le quita la basura a la URL para que el caché no se confunda
function limpiarURL(urlStr) {
    const urlObj = new URL(urlStr, self.location.origin);
    urlObj.searchParams.delete('nocache');
    urlObj.searchParams.delete('t');
    return urlObj.toString();
}

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;

    const cleanUrl = limpiarURL(event.request.url);

    event.respondWith(
        fetch(event.request)
            .then(networkResponse => {
                if (networkResponse && (networkResponse.status === 200 || networkResponse.status === 0)) {
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then(cache => {
                        // Guardamos usando el nombre LIMPIO
                        cache.put(cleanUrl, responseClone);
                    });
                }
                return networkResponse;
            })
            .catch(() => {
                // OFFLINE: Buscamos en la mochila usando el nombre LIMPIO
                return caches.match(cleanUrl, { ignoreSearch: false }).then(cachedRes => {
                    if (cachedRes) return cachedRes;

                    if (event.request.mode === 'navigate') {
                        const navUrl = new URL(event.request.url);
                        return caches.match(navUrl.pathname, { ignoreSearch: true }).then(res => {
                            return res || caches.match('./index.php', { ignoreSearch: true });
                        });
                    }
                    return new Response("Offline", { status: 404 });
                });
            })
    );
});

self.addEventListener('message', event => {
    if (event.data.action === 'CACHE_NEW_ZONE') {
        event.waitUntil(
            caches.open(CACHE_NAME).then(async cache => {
                const promesas = event.data.urls.map(url => {
                    const urlFresca = url + (url.includes('?') ? '&' : '?') + 't=' + Date.now();
                    return fetch(urlFresca).then(res => {
                        if (res.ok) {
                            // Se guarda con la ruta original limpia, sin el timestamp
                            return cache.put(url, res);
                        }
                    }).catch(e => console.error("Fallo al cachear offline:", url));
                });
                await Promise.all(promesas);
                self.clients.matchAll().then(cl => cl.forEach(c => c.postMessage({ 
                    action: 'ZONE_CACHED_OK', zoneId: event.data.zoneId 
                })));
            })
        );
    }
});