/* ==========================================================
  Lógica del Visor: Mapas, GPS, Alertas y Colores KML (v7 Final)
   ========================================================== */

let map, userMarker, accuracyCircle, watchId;
let layerFondo, layerManuales;
let marcadoresPeligro = []; 
let capasActivas = {}; 
let alertasSilenciadas = false;
let ultimaPosicion = null;
let lastSoundTime = 0; 

// --- 1. INICIALIZAR MAPA ---
function initMap() {
    map = L.map('map', { zoomControl: false }).setView([-35.4, -72.0], 9);
    L.control.zoom({ position: 'bottomright' }).addTo(map);

    const satelite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { 
        attribution: 'Tiles &copy; Esri', 
        maxZoom: 19 
    }).addTo(map);
    
    const calles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { 
        attribution: '&copy; OpenStreetMap', 
        maxZoom: 19 
    });

    layerFondo = L.featureGroup().addTo(map);    
    layerManuales = L.layerGroup().addTo(map);   
    L.control.layers({ "Satélite": satelite, "Mapa": calles }, { "Alertas": layerManuales }).addTo(map);

    renderizarMenuCapas();
    cargarDatosDeAlertas(); 
    iniciarRastreoGPS();
    setupUIEvents();
    setupServiceWorkerListener();
    
    if (typeof LISTA_MAPAS !== 'undefined') {
        const capaGeneral = LISTA_MAPAS.find(m => m.id_mapa == 1);
        if (capaGeneral) cargarCapaVisual(capaGeneral);
    }

    setInterval(() => { if(ultimaPosicion) checkPeligros(ultimaPosicion[0], ultimaPosicion[1]); }, 1000); 
}

// --- 2. RENDERIZADOR DE MENÚ ---
function renderizarMenuCapas() {
    const container = document.getElementById('layers-container');
    if(!container) return;
    container.innerHTML = '';

    const zonas = {};
    if (typeof LISTA_MAPAS !== 'undefined' && Array.isArray(LISTA_MAPAS)) {
        LISTA_MAPAS.forEach(m => {
            if (!zonas[m.id_zona]) { zonas[m.id_zona] = { nombre: m.nombre_zona || 'Zona General', mapas: [] }; }
            zonas[m.id_zona].mapas.push(m);
        });
    }

    Object.keys(zonas).forEach(idZona => {
        const zona = zonas[idZona];
        const divZona = document.createElement('div');
        divZona.className = 'zone-group';

        const tieneCapaActiva = zona.mapas.some(m => (typeof MAPA_ID_ACTUAL !== 'undefined' && MAPA_ID_ACTUAL == m.id_mapa));
        const displayStyle = tieneCapaActiva ? 'block' : 'none';
        const activeClass = tieneCapaActiva ? 'active' : '';
        const chevronClass = tieneCapaActiva ? 'fa-chevron-down' : 'fa-chevron-right';

        divZona.innerHTML = `
            <div class="zone-header ${activeClass}" onclick="toggleZona('${idZona}')">
                <div style="display:flex; align-items:center;">
                    <i id="chevron-${idZona}" class="fas ${chevronClass} zone-chevron"></i>
                    <b>${zona.nombre}</b>
                </div>
                <div class="zone-actions" onclick="event.stopPropagation()">
                    <span id="offline-badge-${idZona}" class="offline-badge"></span>
                    <button class="btn-download" onclick="descargarZona(${idZona})" title="Descargar">
                        <i class="fas fa-download"></i>
                    </button>
                </div>
            </div>
        `;
        const divCapas = document.createElement('div');
        divCapas.className = 'zone-layers';
        divCapas.id = `layers-zone-${idZona}`; 
        divCapas.style.display = displayStyle;

        zona.mapas.forEach(mapa => {
            const isRadio = (mapa.es_excluyente === true || mapa.es_excluyente === "t");
            const inputType = isRadio ? 'radio' : 'checkbox';
            const nameGroup = isRadio ? `zona_${idZona}` : `mapa_${mapa.id_mapa}`;
            const isChecked = (typeof MAPA_ID_ACTUAL !== 'undefined' && MAPA_ID_ACTUAL == mapa.id_mapa) || (mapa.id_mapa == 1);

            const item = document.createElement('div');
            item.className = 'layer-item';
            item.innerHTML = `
                <label style="cursor:pointer; display:flex; align-items:center; width:100%;">
                    <input type="${inputType}" name="${nameGroup}" value="${mapa.id_mapa}" 
                           ${isChecked ? 'checked' : ''} style="margin-right:8px;">
                    <span style="flex:1;">${mapa.nombre_mapa}</span>
                    <span class="badge-cat">${mapa.categoria}</span>
                </label>
            `;
            const input = item.querySelector('input');
            input.onchange = () => gestionarCapa(input, mapa, nameGroup);
            divCapas.appendChild(item);
            if (isChecked) setTimeout(() => gestionarCapa(input, mapa, nameGroup), 100);
        });
        divZona.appendChild(divCapas);
        container.appendChild(divZona);
    });
}

window.toggleZona = function(idZona) {
    const layersDiv = document.getElementById(`layers-zone-${idZona}`);
    const chevron = document.getElementById(`chevron-${idZona}`);
    const header = chevron.closest('.zone-header');
    if (layersDiv.style.display === 'none') {
        layersDiv.style.display = 'block'; header.classList.add('active'); chevron.classList.replace('fa-chevron-right', 'fa-chevron-down');
    } else {
        layersDiv.style.display = 'none'; header.classList.remove('active'); chevron.classList.replace('fa-chevron-down', 'fa-chevron-right');
    }
};

// --- 3. GESTIÓN VISUAL Y COLORES KML (CORREGIDO) ---

function gestionarCapa(input, mapaDatos, groupName) {
    if (input.checked) {
        if (input.type === 'radio') {
            document.querySelectorAll(`input[name="${groupName}"]`).forEach(sibling => { 
                if (sibling !== input && sibling.value) {
                    removerCapaVisual(sibling.value); 
                }
            });
        }
        cargarCapaVisual(mapaDatos);
    } else {
        removerCapaVisual(mapaDatos.id_mapa);
    }
}

function cargarCapaVisual(mapaDatos) {
    const id = mapaDatos.id_mapa;
    if (capasActivas[id]) return; 

    if (mapaDatos.ruta_archivo === 'manual' || !mapaDatos.ruta_archivo) {
        capasActivas[id] = { type: 'logic_only' };
        actualizarAlertasVisibles();
        return; 
    }

    const ruta = mapaDatos.ruta_archivo;
    
    // --- LÓGICA KML (EXTRAER COLORES) ---
    if (ruta.toLowerCase().endsWith('.kml')) {
        fetch(ruta)
            .then(res => res.text())
            .then(kmlText => {
                // 1. DICCIONARIO DE ESTILOS MANUAL (Regex)
                const styles = {};
                const regexStyle = /<Style[\s\S]*?id=["']([^"']+)["'][\s\S]*?<color>([0-9A-Fa-f]{8})<\/color>/gi;
                let match;
                
                while ((match = regexStyle.exec(kmlText)) !== null) {
                    const styleId = match[1];     
                    const kmlColor = match[2];    
                    
                    let hex = '#FF5722';
                    if (kmlColor.length === 8) {
                        const bb = kmlColor.substr(2, 2);
                        const gg = kmlColor.substr(4, 2);
                        const rr = kmlColor.substr(6, 2);
                        hex = `#${rr}${gg}${bb}`;
                    }
                    styles['#' + styleId] = hex;
                    styles[styleId] = hex;
                }

                // 2. PARSEAR KML
                // NOTA: 'omnivore.kml.parse' es síncrono. Devuelve el layer listo.
                const layer = omnivore.kml.parse(kmlText, null, L.geoJSON(null, {
                    style: feature => {
                        const cat = (mapaDatos.categoria || '').trim().toLowerCase();
                        
                        // SI ES PENDIENTE: Usar colores originales + Transparencia
                        if (cat === 'pendiente') {
                            let colorFinal = '#FF5722'; 

                            if (feature.properties.styleUrl && styles[feature.properties.styleUrl]) {
                                colorFinal = styles[feature.properties.styleUrl];
                            }
                            else if (feature.properties.fill) {
                                let c = feature.properties.fill;
                                if (c.length === 8 && !c.startsWith('#')) {
                                     const bb = c.substr(2, 2);
                                     const gg = c.substr(4, 2);
                                     const rr = c.substr(6, 2);
                                     colorFinal = `#${rr}${gg}${bb}`;
                                } else {
                                    colorFinal = c;
                                }
                            }
                            return { fillColor: colorFinal, color: colorFinal, weight: 1, fillOpacity: 0.5 };
                        }
                        
                        // OTROS MAPAS (Morado)
                        if (feature.geometry.type.includes('Polygon')) {
                            return { fillColor: '#E0A9E0', color: '#800080', weight: 2, fillOpacity: 0.5 };
                        }
                        return { color: '#FF0000', weight: 2 };
                    },
                    onEachFeature: (f, l) => {
                        let contenido = "<b>" + mapaDatos.categoria + ":</b> " + mapaDatos.nombre_mapa;
                        if(f.properties.description) contenido += "<br>" + f.properties.description;
                        l.bindPopup(contenido);
                    }
                }));

                // 3. AGREGAR AL MAPA (SIN ESPERAR EVENTO 'READY')
                layerFondo.addLayer(layer); 
                if(!ultimaPosicion) map.fitBounds(layer.getBounds()); 
                
                capasActivas[id] = layer;
                actualizarAlertasVisibles();
            })
            .catch(e => console.error("Error KML:", e));

    } else {
        // --- LÓGICA GEOJSON ---
        fetch(ruta).then(r => r.json()).then(data => {
            const layer = L.geoJSON(data, {
                style: feature => {
                    const cat = (mapaDatos.categoria || '').trim().toLowerCase();
                    if (cat === 'pendiente') {
                         const color = feature.properties.fill || '#FF5722';
                         return { fillColor: color, color: color, weight: 1, fillOpacity: 0.5 };
                    }
                    if (feature.geometry.type.includes('Polygon')) return { fillColor: '#E0A9E0', color: '#800080', weight: 2, fillOpacity: 0.5 };
                    return { color: '#FF0000', weight: 2 };
                },
                onEachFeature: (f, l) => { 
                    l.bindPopup("<b>" + mapaDatos.categoria + ":</b> " + mapaDatos.nombre_mapa); 
                }
            });
            layerFondo.addLayer(layer);
            if(!ultimaPosicion) map.fitBounds(layer.getBounds());
            capasActivas[id] = layer;
            actualizarAlertasVisibles();
        }).catch(e => console.error(e));
    }
}

function removerCapaVisual(id) {
    if (capasActivas[id]) {
        if (capasActivas[id].type !== 'logic_only') layerFondo.removeLayer(capasActivas[id]);
        delete capasActivas[id];
        actualizarAlertasVisibles();
    }
}

// --- 4. GESTIÓN DE ALERTAS ---
function cargarDatosDeAlertas() {
    fetch('Api/api_mapa.php?action=fetch_markers').then(r => r.json()).then(res => {
        if (res.success && res.markers) {
            marcadoresPeligro = []; 
            const isAdmin = res.is_admin;
            res.markers.forEach(row => {
                try {
                    if (!row.geojson) return;
                    const geom = JSON.parse(row.geojson);
                    const props = { ...row }; delete props.geojson;
                    
                    if (geom.type === 'Point') {
                        const m = L.marker([geom.coordinates[1], geom.coordinates[0]], { 
                            icon: L.icon({ iconUrl: props.icono_url, iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34] }) 
                        });
                        m.tipo_geom = 'Point';
                        m.radio_custom = parseFloat(props.radio_metros) || 0;
                        asignarDatosComunes(m, props, isAdmin);
                        marcadoresPeligro.push(m);
                    } 
                    else if (geom.type === 'Polygon' || geom.type === 'MultiPolygon') {
                        const m = L.geoJSON(geom, {
                            style: { 
                                color: '#FF5722',  
                                weight: 5,         
                                fillOpacity: 0.2,  
                                dashArray: '10, 10' 
                            }
                        });
                        m.tipo_geom = 'Polygon';
                        m.eachLayer(l => asignarDatosComunes(l, props, isAdmin));
                        m.id_mapa_asociado = props.id_mapa;
                        m.nombre_alerta = props.nombre;
                        m.rawGeoJSON = geom; 
                        marcadoresPeligro.push(m);
                    }
                } catch(e){ console.error(e); }
            });
            actualizarAlertasVisibles();
        }
    });
}

function asignarDatosComunes(layer, props, isAdmin) {
    layer.nombre_alerta = props.nombre;
    layer.id_mapa_asociado = props.id_mapa;
    layer.id_db = props.id;
    let html = `<div style='text-align:center;'><h3 style='margin:0;color:#2c3e50;font-size:1rem'>${props.nombre}</h3><p>${props.descripcion || ''}</p>`;
    if(isAdmin) html += `<button onclick="borrarMarcador(${props.id})" style="background:#e74c3c; color:white; border:none; padding:5px; cursor:pointer;">Eliminar</button>`;
    html += `</div>`;
    layer.bindPopup(html);
}

function actualizarAlertasVisibles() {
    layerManuales.clearLayers(); 
    marcadoresPeligro.forEach(m => {
        const idMapa = m.id_mapa_asociado;
        if (capasActivas[idMapa] || idMapa == 1) {
            if(m.tipo_geom === 'Polygon') {
                m.eachLayer(l => layerManuales.addLayer(l));
            } else {
                layerManuales.addLayer(m);
                if (m.radio_custom > 0 && m.tipo_geom === 'Point') {
                    L.circle(m.getLatLng(), { radius: m.radio_custom, color: '#e74c3c', fillColor: '#c0392b', fillOpacity: 0.3, weight: 1 }).addTo(layerManuales);
                }
            }
        }
    });
}

// --- ALGORITMO: PUNTO EN POLÍGONO ---
function isMarkerInsidePolygon(lat, lng, poly) {
    let polys = [];
    if (poly.type === 'Polygon') polys.push(poly.coordinates);
    if (poly.type === 'MultiPolygon') polys = poly.coordinates;
    let inside = false;
    for (let i = 0; i < polys.length; i++) {
        let rings = polys[i];
        let polygonCoords = rings[0]; 
        let x = lng, y = lat;
        for (let k = 0, j = polygonCoords.length - 1; k < polygonCoords.length; j = k++) {
            let xi = polygonCoords[k][0], yi = polygonCoords[k][1];
            let xj = polygonCoords[j][0], yj = polygonCoords[j][1];
            let intersect = ((yi > y) != (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
            if (intersect) inside = !inside;
        }
    }
    return inside;
}

function checkPeligros(lat, lng) {
    if (IS_ADMIN) return; 
    if (alertasSilenciadas) return;
    const divAlertas = document.getElementById('alertas'); divAlertas.innerHTML = '';
    let dangerDetected = false;
    marcadoresPeligro.forEach(m => {
        let isDanger = false;
        if (m.tipo_geom === 'Point') {
            const distancia = map.distance([lat, lng], m.getLatLng());
            const radioAlerta = (m.radio_custom > 0) ? m.radio_custom : 15; 
            if(distancia <= radioAlerta) isDanger = true;
        } 
        else if (m.tipo_geom === 'Polygon') {
            if (isMarkerInsidePolygon(lat, lng, m.rawGeoJSON)) isDanger = true;
        }
        if(isDanger) {
            dangerDetected = true;
            divAlertas.innerHTML += `<div class="alerta-card"><i class="fas fa-exclamation-triangle"></i><br>⚠️ ZONA DE PELIGRO<br><span style="font-size:0.9rem;">${m.nombre_alerta}</span></div>`;
        }
    });
    divAlertas.style.display = dangerDetected ? 'block' : 'none';
    if(dangerDetected) {
        const now = Date.now();
        if (now - lastSoundTime > 5000) { 
            const audio = document.getElementById('alertaAudio');
            audio.currentTime = 0; audio.play().catch(()=>{});
            lastSoundTime = now; 
        }
    }
}

// --- 5. GESTIÓN OFFLINE ---
window.descargarZona = function(idZona) {
    const urls = [];
    LISTA_MAPAS.forEach(m => { if (m.id_zona == idZona && m.ruta_archivo && m.ruta_archivo !== 'manual') urls.push(m.ruta_archivo); });
    if (urls.length > 0) {
        document.getElementById('loader').style.display = 'block';
        setTimeout(() => { if(document.getElementById('loader').style.display === 'block') document.getElementById('loader').style.display = 'none'; }, 8000);
        if (navigator.serviceWorker && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({ action: 'CACHE_NEW_ZONE', urls: urls, zoneId: idZona });
        } else { alert("Recarga la página."); document.getElementById('loader').style.display = 'none'; }
    } else { alert("Zona vacía."); }
};
function setupServiceWorkerListener() {
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', event => {
            if(event.data.action === 'ZONE_CACHED_OK'){
                document.getElementById('loader').style.display = 'none';
                alert("✅ Zona descargada.");
                const badge = document.getElementById('offline-badge-' + event.data.zoneId);
                if(badge) badge.innerHTML = '<i class="fas fa-check-circle" style="color:#27ae60;"></i> Offline';
            }
        });
    }
}

// --- 6. EVENTOS UI Y GPS ---
function setupUIEvents() {
    const form = document.getElementById('markerFormContainer');
    let currentLatLng;
    document.getElementById('cancelMarker').onclick = () => { form.style.display='none'; document.getElementById('markerForm').reset(); };
    map.on('contextmenu', function(e) {
        if (!IS_ADMIN) return; 
        currentLatLng = e.latlng; if(navigator.vibrate) navigator.vibrate(50);
        const selector = document.getElementById('selectorMapaForm');
        if(selector) {
            selector.innerHTML = ''; 
            if(typeof LISTA_MAPAS !== 'undefined') {
                LISTA_MAPAS.forEach(m => {
                    let opt = document.createElement('option'); opt.value = m.id_mapa; opt.text = `[${m.categoria}] ${m.nombre_mapa}`;
                    if (m.id_mapa == 1) opt.selected = true; selector.appendChild(opt);
                });
            }
        }
        form.style.display = 'block';
    });
    document.getElementById('markerForm').onsubmit = (e) => {
        e.preventDefault(); 
        if (!currentLatLng) return;
        saveMarker(currentLatLng, document.getElementById('popupNombre').value, document.getElementById('popupDesc').value, document.getElementById('popupIcon').value, document.getElementById('popupRadio').value, document.getElementById('selectorMapaForm').value); 
        form.style.display='none';
    };
    const btnBorrar = document.getElementById('borrarMarcadores');
    if(btnBorrar) btnBorrar.onclick = () => { if(confirm("¿RESETEAR TODO?")) fetch('Api/api_mapa.php?action=delete_all', { method: 'POST' }).then(() => { cargarDatosDeAlertas(); alert("Reset completo."); }); };
}

window.borrarMarcador = function(id) { if(confirm("¿Eliminar?")) fetch('Api/api_mapa.php', { method: 'POST', body: JSON.stringify({ action: 'delete_marker', id: id }) }).then(r=>r.json()).then(res=>{ if(res.success) cargarDatosDeAlertas(); }); };

window.saveMarker = function(ll, nom, desc, nivel, radio, id_destino = 0) {
    const data = { action: 'add_marker', lat: ll.lat, lng: ll.lng, nombre: nom, descripcion: desc, nivel: nivel, radio: radio, id_mapa: id_destino };
    fetch('Api/api_mapa.php', { method:'POST', body:JSON.stringify(data) }).then(r=>r.json()).then(res=>{ if(res.success) { cargarDatosDeAlertas(); alert("Guardado."); } });
};

window.reportarUser = function() {
    const msg = prompt("⚠️ REPORTE SOS"); if(!msg) return;
    navigator.geolocation.getCurrentPosition(p => saveMarker({lat: p.coords.latitude, lng: p.coords.longitude}, "🚨 SOS", msg, "Critico", 20, 1), null, {enableHighAccuracy:true});
};

window.toggleAlertas = function() {
    alertasSilenciadas = !alertasSilenciadas;
    const btn = document.getElementById('btnToggleAlertas');
    if (alertasSilenciadas) { btn.className = "btn-panel btn-alert-off"; btn.innerHTML = '<i class="fas fa-bell-slash"></i> OFF'; document.getElementById('alertas').innerHTML = ''; } 
    else { btn.className = "btn-panel btn-alert-on"; btn.innerHTML = '<i class="fas fa-bell"></i> ON'; if(ultimaPosicion) checkPeligros(ultimaPosicion[0], ultimaPosicion[1]); }
};

window.iniciarRastreoGPS = function() {
    if (!navigator.geolocation) { console.warn("GPS no soportado"); return; }
    
    const opcionesGPS = {
        enableHighAccuracy: true,
        maximumAge: 0,
        timeout: 10000 
    };

    watchId = navigator.geolocation.watchPosition(p => {
        const lat = p.coords.latitude; 
        const lng = p.coords.longitude; 
        const accuracy = p.coords.accuracy; 
        ultimaPosicion = [lat, lng];

        let colorRadio = '#3498db'; 
        let advertencia = "";

        if (accuracy > 2000) { colorRadio = '#e74c3c'; advertencia = " (Mala Señal)"; } 
        else if (accuracy > 50) { colorRadio = '#f39c12'; advertencia = " (Señal Débil)"; }

        if(userMarker) {
            userMarker.setLatLng([lat, lng]);
            userMarker.bindPopup("<b>Estás aquí</b><br>Precisión: " + Math.round(accuracy) + "m" + advertencia);
        } else if(map) {
            userMarker = L.circleMarker([lat, lng], { radius: 8, fillColor: "#3498db", color: "#fff", weight: 2, fillOpacity: 1 }).addTo(map);
        }

        if (accuracyCircle) {
            accuracyCircle.setLatLng([lat, lng]);
            accuracyCircle.setRadius(accuracy);
            accuracyCircle.setStyle({ color: colorRadio, fillColor: colorRadio });
        } else if(map) {
            accuracyCircle = L.circle([lat, lng], { radius: accuracy, color: colorRadio, fillColor: colorRadio, fillOpacity: 0.15, weight: 1 }).addTo(map);
        }

        checkPeligros(lat, lng);

    }, err => { console.error("Error GPS", err); }, opcionesGPS);
};

window.centrarEnUsuario = function() { if(ultimaPosicion && map) { map.setView(ultimaPosicion, 16, {animate: true}); } };

initMap();