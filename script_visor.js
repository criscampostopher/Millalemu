/* ==========================================================
  Lógica del Visor: Mapas, GPS, Alertas Inteligentes (v9.0)
  - Soporte Real para MultiPolygons complejos (Aplanamiento)
  - Paleta de Colores Exacta (Negro, Gris, Rojos, Naranjas)
  - Escudos Invisibles y Offline
   ========================================================== */

let map, userMarker, accuracyCircle, watchId;
let layerFondo, layerManuales;
let marcadoresPeligro = []; 
let capasActivas = {}; 
let ultimaPosicion = null;
let lastSoundTime = 0; 

// --- VARIABLES DE SEGURIDAD ---
let seguridadGeneralActiva = true;
let seguridadPendienteActiva = true;

// --- VARIABLES PENDIENTES ---
var poligonosPendiente = []; 
let pendienteActiva = null;
let timerAlerta = null;
let timerEspera = null;
let asistidoConfirmado = false;

// --- PALETA DE COLORES DE RIESGO (Normalizados) ---
const COLORES_ALTO_RIESGO = [
    "RGB(0,0,0)",    // Negro intenso
    "RGB(168,0,0)",  // Rojo intenso
    "RGB(255,0,0)",  // Rojo claro
    "RGB(52,52,52)"  // Gris
];

const COLORES_MEDIO_RIESGO = [
    "RGB(255,85,0)", // Naranja
    "RGB(230,152,0)" // Naranjo claro
];

// --- 1. INICIALIZAR MAPA ---
function initMap() {
    map = L.map('map', { zoomControl: false }).setView([-35.4, -72.0], 9);
    L.control.zoom({ position: 'bottomright' }).addTo(map);

    const satelite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { 
        attribution: 'Tiles &copy; Esri', maxZoom: 19 
    }).addTo(map);
    
    const calles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { 
        attribution: '&copy; OpenStreetMap', maxZoom: 19 
    });

    layerFondo = L.featureGroup().addTo(map);    
    layerManuales = L.layerGroup().addTo(map);    // Alertas Generales (SOS, Agua, Veg, Actas)
    layerPendientes = L.layerGroup().addTo(map);  // Solo Pendientes

    // Agregamos los DOS checkboxes al menú de Leaflet
    L.control.layers(
        { "Satélite": satelite, "Mapa": calles }, 
        { 
            "Alertas Generales": layerManuales,  // Checkbox 1
            "Alertas Pendientes": layerPendientes // Checkbox 2
        }
    ).addTo(map);

    renderizarMenuCapas();
    cargarDatosDeAlertas(); 
    iniciarRastreoGPS();
    setupUIEvents();
    setupServiceWorkerListener();
    
    if (typeof LISTA_MAPAS !== 'undefined') {
        const capaGeneral = LISTA_MAPAS.find(m => m.id_mapa == 1);
        if (capaGeneral) cargarCapaVisual(capaGeneral);
    }

    // --- BUCLE DE SEGURIDAD (1s) ---
    setInterval(() => { 
        if(ultimaPosicion) {
            // Escudo 1: General
            if (seguridadGeneralActiva) checkPeligros(ultimaPosicion[0], ultimaPosicion[1]);
            else {
                const div = document.getElementById('alertas');
                if(div && !pendienteActiva) div.style.display = 'none';
            }
            // Escudo 2: Pendientes
            if (seguridadPendienteActiva) checkPendientes(ultimaPosicion[0], ultimaPosicion[1]);
        } 
    }, 1000); 
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
                    <i id="chevron-${idZona}" class="fas ${chevronClass}" style="margin-right:10px; font-size:0.8rem;"></i>
                    <b>${zona.nombre}</b>
                </div>
                <div class="zone-actions" onclick="event.stopPropagation()">
                    <span id="offline-badge-${idZona}" class="offline-badge"></span>
                    <button class="btn-download" onclick="descargarZona(${idZona})" title="Descargar Offline" style="background:none; border:none; color:#3498db; cursor:pointer;">
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

// --- 3. GESTIÓN VISUAL Y COLORES ---

function rgbToHex(rgbStr) {
    if (!rgbStr) return null;
    const match = rgbStr.match(/\d+/g);
    if (!match || match.length < 3) return rgbStr; 
    const r = parseInt(match[0]), g = parseInt(match[1]), b = parseInt(match[2]);
    return "#" + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1).toUpperCase();
}

function normalizarColor(color) {
    if(!color) return "";
    return color.replace(/\s/g, '').toUpperCase(); 
}

function gestionarCapa(input, mapaDatos, groupName) {
    if (input.checked) {
        if (input.type === 'radio') {
            document.querySelectorAll(`input[name="${groupName}"]`).forEach(sibling => { 
                if (sibling !== input && sibling.value) removerCapaVisual(sibling.value); 
            });
        }
        cargarCapaVisual(mapaDatos);
    } else {
        removerCapaVisual(mapaDatos.id_mapa);
    }
}

function removerCapaVisual(id) {
    if (capasActivas[id]) {
        if (capasActivas[id].type !== 'logic_only') {
            try { layerFondo.removeLayer(capasActivas[id]); } catch(e) {}
        }
        delete capasActivas[id];
        if (typeof actualizarAlertasVisibles === 'function') actualizarAlertasVisibles();
        // NOTA: No borramos poligonosPendiente para mantener los "Escudos Invisibles"
    }
}

// --- FUNCIÓN AUXILIAR: APLANAR GEOMETRÍA ---
// Convierte MultiPolygons complejos en una lista plana de polígonos simples
function aplanarGeometria(geometry) {
    let listaPoligonos = [];
    
    if (geometry.type === 'Polygon') {
        listaPoligonos.push(geometry.coordinates);
    } 
    else if (geometry.type === 'MultiPolygon') {
        // MultiPolygon es un array de Polygons. Los extraemos uno a uno.
        geometry.coordinates.forEach(polyCoords => {
            listaPoligonos.push(polyCoords);
        });
    }
    
    return listaPoligonos;
}

function cargarCapaVisual(mapaDatos) {
    const id = mapaDatos.id_mapa;
    if (capasActivas[id]) return; 

    const ruta = mapaDatos.ruta_archivo;
    if (!ruta || ruta === 'manual') {
        capasActivas[id] = { type: 'logic_only' };
        if (typeof actualizarAlertasVisibles === 'function') actualizarAlertasVisibles();
        return; 
    }

    const extension = ruta.split('.').pop().toLowerCase();
    
    // --- KML (Estilos) ---
    if (extension === 'kml') {
        fetch(ruta).then(res => res.text()).then(kmlText => {
            const styles = {};
            const regexStyle = /<Style[\s\S]*?id=["']([^"']+)["'][\s\S]*?<PolyStyle[\s\S]*?<color>([0-9A-Fa-f]{8})<\/color>/gi;
            let match;
            while ((match = regexStyle.exec(kmlText)) !== null) {
                const styleId = match[1]; const kmlColor = match[2];    
                let hex = '#E0A9E0'; 
                if (kmlColor.length === 8) {
                    const bb = kmlColor.substr(2, 2), gg = kmlColor.substr(4, 2), rr = kmlColor.substr(6, 2);
                    hex = `#${rr}${gg}${bb}`;
                }
                styles[styleId] = hex; styles['#'+styleId] = hex;
            }

            const layer = omnivore.kml.parse(kmlText, null, L.geoJSON(null, {
                style: feature => {
                    let colorFinal = styles[feature.properties.styleUrl] || feature.properties.fill || '#E0A9E0';
                    return { fillColor: colorFinal, color: colorFinal, weight: 1, fillOpacity: 0.6 };
                },
                onEachFeature: (f, l) => {
                    if (mapaDatos.categoria.toLowerCase().includes('pendiente')) {
                        // KML es más complejo de aplanar, asumimos simple por ahora
                        // (KMLs exportados de GlobalMapper suelen ser Polygons simples)
                        let rawGeoms = aplanarGeometria(f.geometry);
                        rawGeoms.forEach(coords => {
                            poligonosPendiente.push({ 
                                id: Math.random().toString(36).substr(2, 9),
                                coords: coords, 
                                colorRaw: 'KML_GENERIC' // Difícil extraer color raw de KML en JS puro
                            });
                        });
                    }
                    l.bindPopup(`<b>${mapaDatos.nombre_mapa}</b>`);
                }
            }));
            agregarCapaAlMapa(layer, id);
        }).catch(e => console.error("Error KML:", e));
    } 
    
    // --- GEOJSON (Procesamiento Inteligente) ---
    else if (extension === 'geojson' || extension === 'json') {
        fetch(ruta).then(r => r.json()).then(data => {
            const layer = L.geoJSON(data, {
                style: function(feature) {
                    let props = feature.properties;
                    let rawFill = props.FILL_COLOR || props.Fill_Color || props.fill_color;
                    let fillColorFinal = rawFill ? rgbToHex(rawFill) : null;
                    let rawBorder = props.BORDER_COLOR || props.Border_Color || props.border_color;
                    let borderColorFinal = rawBorder ? rgbToHex(rawBorder) : "#000000";

                    if (!fillColorFinal) {
                        fillColorFinal = "#E0A9E0"; // Default
                        borderColorFinal = fillColorFinal;
                    }
                    return { fillColor: fillColorFinal, color: borderColorFinal, weight: 1, fillOpacity: 0.6 };
                },
                onEachFeature: (f, l) => {
                    let props = f.properties;
                    l.bindPopup(`<b>${mapaDatos.nombre_mapa}</b><br>Glosa: ${props.GLOSALEGAD || ''}`);

                    // LÓGICA SEGURIDAD PENDIENTES
                    if (mapaDatos.categoria.toLowerCase().includes('pendiente')) {
                        let colorStr = props.FILL_COLOR || props.Fill_Color || props.fill_color;
                        let colorNormalizado = normalizarColor(colorStr);
                        
                        // APLANAMOS LA GEOMETRÍA
                        // Esto convierte 1 Feature MultiPolygon en N polígonos simples
                        let listaCoords = aplanarGeometria(f.geometry);
                        
                        listaCoords.forEach(coords => {
                            poligonosPendiente.push({
                                id: Math.random().toString(36).substr(2, 9),
                                coords: coords, // Coordenadas [ [long, lat], [long, lat]... ]
                                colorRaw: colorNormalizado
                            });
                        });
                    }
                }
            });
            agregarCapaAlMapa(layer, id);
        }).catch(e => console.error("Error GeoJSON:", e));
    }

    else if (extension === 'kmz') {
        const layer = omnivore.kml(ruta, null, L.geoJSON(null, {
             style: { color: '#E0A9E0', fillColor: '#E0A9E0', fillOpacity: 0.5 },
             onEachFeature: (f, l) => { l.bindPopup(`<b>${mapaDatos.nombre_mapa}</b>`); }
        })).on('ready', function() { agregarCapaAlMapa(layer, id); });
    }
}

function agregarCapaAlMapa(layer, id) {
    layerFondo.addLayer(layer);
    if (!ultimaPosicion) try { map.fitBounds(layer.getBounds()); } catch(e){}
    capasActivas[id] = layer;
    if (typeof actualizarAlertasVisibles === 'function') actualizarAlertasVisibles();
}

// --- 4. GESTIÓN DE ALERTAS (BD) ---
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
                        const m = L.geoJSON(geom, { style: { color: '#FF5722', weight: 5, fillOpacity: 0.2, dashArray: '10, 10' } });
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

// --- ACTUALIZAR VISIBILIDAD (MODIFICADO) ---
function actualizarAlertasVisibles() {
    // Limpiamos ambas capas visuales
    layerManuales.clearLayers(); 
    layerPendientes.clearLayers(); 

    marcadoresPeligro.forEach(m => {
        const idMapa = m.id_mapa_asociado;
        
        // Verificamos si la capa asociada está activa (o es el mapa general id=1)
        if (capasActivas[idMapa] || idMapa == 1) {
            
            // --- CAMBIO AQUÍ: CLASIFICACIÓN ---
            // Si el nombre dice "PENDIENTE", va a la capa de Pendientes
            // Si no, va a la capa Manuales (General)
            if (m.nombre_alerta && m.nombre_alerta.toUpperCase().includes("PENDIENTE")) {
                if(m.addTo) m.addTo(layerPendientes); 
                else layerPendientes.addLayer(m);
            } else {
                if(m.addTo) m.addTo(layerManuales); 
                else {
                    layerManuales.addLayer(m);
                    // Si es un punto manual con radio, dibujamos el círculo también
                    if (m.radio_custom > 0 && m.tipo_geom === 'Point') {
                        L.circle(m.getLatLng(), { radius: m.radio_custom, color: '#e74c3c', fillColor: '#c0392b', fillOpacity: 0.3, weight: 1 }).addTo(layerManuales);
                    }
                }
            }
        }
    });
}

// --- 5. ALGORITMOS DE SEGURIDAD ---

// A) MATEMÁTICA: RAY CASTING PARA POLYGONOS SIMPLES
// Nota: GeoJSON usa [lng, lat], pero Leaflet/GPS usan [lat, lng].
function isMarkerInsidePolygon(lat, lng, polygonCoords) {
    // polygonCoords es [Ring1, Ring2...], donde Ring1 es el exterior.
    const x = lng, y = lat;
    
    // 1. Check Exterior Ring (index 0)
    if (pointInRing(x, y, polygonCoords[0])) {
        // 2. Check Holes (index 1+)
        let inHole = false;
        for (let k = 1; k < polygonCoords.length; k++) {
            if (pointInRing(x, y, polygonCoords[k])) {
                inHole = true;
                break;
            }
        }
        if (!inHole) return true; // Dentro y no en agujero
    }
    return false;
}

function pointInRing(x, y, ring) {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const xi = ring[i][0], yi = ring[i][1];
        const xj = ring[j][0], yj = ring[j][1];
        const intersect = ((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
        if (intersect) inside = !inside;
    }
    return inside;
}


// B) CHEQUEO GENERAL (BD) - CORREGIDO
function checkPeligros(lat, lng) {
    // 1. Si el botón GENERAL está apagado, NO hacemos nada.
    if (typeof IS_ADMIN !== 'undefined' && IS_ADMIN) return;
    if (!seguridadGeneralActiva) {
        // Limpiamos alertas generales si se apaga el botón
        const div = document.getElementById('alertas');
        if (div && !div.innerHTML.includes("PENDIENTE")) div.style.display = 'none';
        return;
    }
    
    // Si ya hay una pendiente sonando (prioridad), no mostramos alertas generales
    if (pendienteActiva) return;

    const divAlertas = document.getElementById('alertas'); 
    let dangerDetected = false;
    let htmlAlertas = "";
    
    marcadoresPeligro.forEach(m => {
        // 2. FILTRO CLAVE: Si es Pendiente, LA IGNORAMOS (el otro botón se encarga)
        if (m.nombre_alerta && m.nombre_alerta.toUpperCase().includes("PENDIENTE")) return;

        let isDanger = false;
        
        if (m.tipo_geom === 'Point') {
            const distancia = map.distance([lat, lng], m.getLatLng());
            const radioAlerta = (m.radio_custom > 0) ? m.radio_custom : 15; 
            if(distancia <= radioAlerta) isDanger = true;
        } 
        else if (m.tipo_geom === 'Polygon') {
            let flatList = aplanarGeometria(m.rawGeoJSON);
            for(let coords of flatList) {
                if (isMarkerInsidePolygon(lat, lng, coords)) { isDanger = true; break; }
            }
        }

        if(isDanger) {
            dangerDetected = true;
            htmlAlertas += `<div class="alerta-card"><i class="fas fa-exclamation-triangle"></i><br>⚠️ ZONA DE PELIGRO<br><span style="font-size:0.9rem;">${m.nombre_alerta}</span></div>`;
        }
    });

    if (dangerDetected) {
        divAlertas.innerHTML = htmlAlertas;
        divAlertas.style.display = 'block';
        
        // SONIDO GENERAL
        const now = Date.now();
        if (now - lastSoundTime > 5000) { 
            const audio = document.getElementById('alertaAudio');
            if(audio) audio.play().catch(e => console.log("Falta click usuario"));
            lastSoundTime = now; 
        }
    } else {
        // Solo ocultamos si no hay pendiente activa
        if (!divAlertas.innerHTML.includes("PENDIENTE")) divAlertas.style.display = 'none';
    }
}

// C) CHEQUEO PENDIENTES (CORREGIDO)
function checkPendientes(lat, lng) {
    // 1. Si el botón PENDIENTE está apagado, NO hacemos nada y reseteamos.
    if (typeof IS_ADMIN !== 'undefined' && IS_ADMIN) return;
    if (!seguridadPendienteActiva) {
        if (pendienteActiva) detenerLoopAlerta();
        return;
    }
    
    // Si el usuario ya confirmó asistencia, no molestamos más.
    if (asistidoConfirmado) return;

    let alertaDetectada = null;

    for (let m of marcadoresPeligro) {
        // Solo buscamos polígonos que digan "PENDIENTE"
        if (m.tipo_geom === 'Polygon' && m.nombre_alerta && m.nombre_alerta.toUpperCase().includes('PENDIENTE')) {
            
            // Usamos SCAN RECURSIVO para encontrar los polígonos reales
            const anillos = scanCapasRecursivo(m);
            for (let anillo of anillos) {
                if (isPointInRing(lat, lng, anillo)) {
                    alertaDetectada = m;
                    break;
                }
            }
        }
        if (alertaDetectada) break;
    }

    // GESTIÓN DE ESTADO (ENTRAR / SALIR)
    if (alertaDetectada) {
        // Si acabamos de entrar (no había activa antes)
        if (!pendienteActiva) {
            pendienteActiva = alertaDetectada;
            const nivel = pendienteActiva.nombre_alerta.toUpperCase().includes("CRÍTICA") ? "ALTO" : "MEDIO";
            iniciarLoopAlerta(nivel);
        }
        // Si ya había una activa, no hacemos nada (el loop de sonido ya está corriendo)
    } else {
        // Si no detectamos nada pero había una activa -> SALIMOS
        if (pendienteActiva) {
            detenerLoopAlerta();
        }
    }
}

// Auxiliares para el escaneo (Agrégalas al final del archivo)
function scanCapasRecursivo(layer) {
    let anillos = [];
    if (layer instanceof L.Polygon) return aplanarAnillos(layer.getLatLngs());
    if (layer.eachLayer) { layer.eachLayer(l => { anillos = anillos.concat(scanCapasRecursivo(l)); }); }
    return anillos;
}

function aplanarAnillos(arr) {
    if (!Array.isArray(arr) || arr.length === 0) return [];
    if (arr[0] instanceof L.LatLng) return [arr];
    let res = [];
    arr.forEach(sub => res = res.concat(aplanarAnillos(sub)));
    return res;
}

function isPointInRing(lat, lng, ring) {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const xi = ring[i].lng, yi = ring[i].lat;
        const xj = ring[j].lng, yj = ring[j].lat;
        const intersect = ((yi > lat) !== (yj > lat)) && (lng < (xj - xi) * (lat - yi) / (yj - yi) + xi);
        if (intersect) inside = !inside;
    }
    return inside;
}

// FUNCIÓN DE SONIDO Y VISUALIZACIÓN (CORREGIDA)
function iniciarLoopAlerta(nivel) {
    // Doble chequeo de seguridad
    if (!seguridadPendienteActiva || asistidoConfirmado) return;

    const colorFondo = (nivel === "ALTO") ? "#c0392b" : "#e67e22"; // Rojo o Naranja
    const titulo = (nivel === "ALTO") ? "PENDIENTE CRÍTICA" : "PENDIENTE MEDIA";
    
    const divAlertas = document.getElementById('alertas');
    divAlertas.style.display = 'block';
    
    // HTML DE LA ALERTA GRANDE
    divAlertas.innerHTML = `
        <div class="alerta-card" style="background:${colorFondo}; width: 90%; margin: 10px auto; padding: 20px;">
            <i class="fas fa-mountain" style="font-size:3rem; margin-bottom:15px; color:white;"></i><br>
            <strong style="font-size:1.5rem; color:white;">⚠️ ${titulo}</strong><br>
            <span style="font-size:1.1rem; color:white;">RIESGO DE VOLCAMIENTO</span><br><br>
            <button onclick="confirmarAsistencia()" style="padding:15px 30px; background:white; color:${colorFondo}; border:none; border-radius:50px; font-weight:bold; font-size:1.2rem; cursor:pointer; box-shadow: 0 4px 6px rgba(0,0,0,0.2);">
                <i class="fas fa-check"></i> CONFIRMAR ASISTENCIA
            </button>
        </div>
    `;

    // REPRODUCCIÓN DE SONIDO (FORZADA)
    const audio = document.getElementById('alertaAudio');
    if (audio) {
        audio.currentTime = 0; // Reiniciar audio
        audio.loop = true;     // Activar bucle
        
        // Intentar reproducir con promesa para capturar errores
        var playPromise = audio.play();
        if (playPromise !== undefined) {
            playPromise.then(_ => {
                // Reproducción iniciada correctamente
            })
            .catch(error => {
                console.log("El navegador bloqueó el audio. El usuario debe interactuar primero.");
            });
        }
    }

    // NOTA: He quitado el setTimeout que apagaba la alerta a los 15s.
    // La alerta de pendiente es CRÍTICA, no debe desaparecer sola hasta que:
    // 1. El usuario salga de la zona.
    // 2. El usuario confirme asistencia.
}

function detenerLoopAlerta() {
    pendienteActiva = null; asistidoConfirmado = false;
    clearTimeout(timerAlerta); clearTimeout(timerEspera);
    const audio = document.getElementById('alertaAudio');
    audio.pause(); audio.loop = false;
    
    const div = document.getElementById('alertas');
    if(div.innerHTML.includes('PENDIENTE')) div.style.display = 'none';
}

function confirmarAsistencia() {
    asistidoConfirmado = true;
    clearTimeout(timerAlerta);
    const audio = document.getElementById('alertaAudio');
    audio.pause(); audio.loop = false;
    document.getElementById('alertas').style.display = 'none';
    alert("✅ Asistencia confirmada.");
}

// --- 6. EVENTOS DE INTERFAZ ---
window.toggleSeguridad = function(tipo) {
    const btn = (tipo === 'general') ? document.getElementById('btnToggleGeneral') : document.getElementById('btnTogglePendiente');
    if (tipo === 'general') {
        seguridadGeneralActiva = !seguridadGeneralActiva;
        btn.innerHTML = seguridadGeneralActiva ? "ON" : "OFF";
        btn.className = seguridadGeneralActiva ? "btn-action btn-alert-on" : "btn-action btn-alert-off";
    } else {
        seguridadPendienteActiva = !seguridadPendienteActiva;
        btn.innerHTML = seguridadPendienteActiva ? "ON" : "OFF";
        btn.className = seguridadPendienteActiva ? "btn-action btn-alert-on" : "btn-action btn-alert-off";
        if (!seguridadPendienteActiva) detenerLoopAlerta();
    }
};

window.saveMarker = function(ll, nom, desc, nivel, radio, id_destino = 0) {
    const data = { action: 'add_marker', lat: ll.lat, lng: ll.lng, nombre: nom, descripcion: desc, nivel: nivel, radio: radio, id_mapa: id_destino };
    fetch('Api/api_mapa.php', { method:'POST', body:JSON.stringify(data) }).then(r=>r.json()).then(res=>{ if(res.success) { cargarDatosDeAlertas(); alert("Guardado."); } });
};
window.borrarMarcador = function(id) { if(confirm("¿Eliminar?")) fetch('Api/api_mapa.php', { method: 'POST', body: JSON.stringify({ action: 'delete_marker', id: id }) }).then(r=>r.json()).then(res=>{ if(res.success) cargarDatosDeAlertas(); }); };
window.reportarUser = function() {
    const msg = prompt("⚠️ REPORTE SOS"); if(!msg) return;
    navigator.geolocation.getCurrentPosition(p => saveMarker({lat: p.coords.latitude, lng: p.coords.longitude}, "🚨 SOS", msg, "Critico", 20, 1), null, {enableHighAccuracy:true});
};
window.iniciarRastreoGPS = function() {
    if (!navigator.geolocation) return;
    watchId = navigator.geolocation.watchPosition(p => {
        const lat = p.coords.latitude, lng = p.coords.longitude, acc = p.coords.accuracy;
        ultimaPosicion = [lat, lng];
        if(userMarker) { userMarker.setLatLng([lat, lng]); userMarker.bindPopup(`Precisión: ${Math.round(acc)}m`); } 
        else if(map) { userMarker = L.circleMarker([lat, lng], { radius: 8, fillColor: "#3498db", color: "#fff", weight: 2, fillOpacity: 1 }).addTo(map); }
        if (accuracyCircle) { accuracyCircle.setLatLng([lat, lng]); accuracyCircle.setRadius(acc); } 
        else if(map) { accuracyCircle = L.circle([lat, lng], { radius: acc, color: '#3498db', fillOpacity: 0.15, weight: 1 }).addTo(map); }
    }, null, { enableHighAccuracy: true, maximumAge: 0, timeout: 10000 });
};
window.centrarEnUsuario = function() { if(ultimaPosicion && map) map.setView(ultimaPosicion, 16, {animate: true}); };
function setupUIEvents() {
    const form = document.getElementById('markerFormContainer');
    let currentLatLng;
    document.getElementById('cancelMarker').onclick = () => { form.style.display='none'; document.getElementById('markerForm').reset(); };
    map.on('contextmenu', function(e) {
        if (!IS_ADMIN) return; 
        currentLatLng = e.latlng;
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
window.descargarZona = function(idZona) {
    const urls = [];
    LISTA_MAPAS.forEach(m => { if (m.id_zona == idZona && m.ruta_archivo && m.ruta_archivo !== 'manual') urls.push(m.ruta_archivo); });
    if (urls.length > 0) {
        document.getElementById('loader').style.display = 'block';
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
            }
        });
    }
}





window.abrirPIV = function () {
  const id = (typeof MAPA_ID_ACTUAL !== 'undefined' && MAPA_ID_ACTUAL) ? MAPA_ID_ACTUAL : 0;
  const url = id ? `piv_formulario.php?id_mapa=${id}` : `piv_formulario.php`;
  window.open(url, '_blank');
};

initMap();
