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
let idPoligonoSilenciado = null; // (Guarda la ID, no un simple true/false)


// sonido de las alertas
let audioCtx = null;
let alarmInterval = null;


function unlockAudio() {
    if (!audioCtx) {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    if (audioCtx.state === 'suspended') {
        audioCtx.resume();
    }
}

function playAlarmaSintetica() {
    unlockAudio();
    if (alarmInterval) return; // Ya está sonando
    
    const hacerBeep = () => {
        if(audioCtx.state === 'suspended') audioCtx.resume();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        
        osc.type = 'square'; // Onda cuadrada = sonido electrónico/estridente
        osc.frequency.setValueAtTime(880, audioCtx.currentTime); // Tono 1
        osc.frequency.setValueAtTime(1100, audioCtx.currentTime + 0.15); // Sube el tono rápido
        
        gain.gain.setValueAtTime(0.15, audioCtx.currentTime); // Volumen
        gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.3);
        
        osc.start(audioCtx.currentTime);
        osc.stop(audioCtx.currentTime + 0.3);
    };

    hacerBeep(); // Suena el primero inmediato
    alarmInterval = setInterval(hacerBeep, 600); // Se repite cada 600ms
}

function stopAlarmaSintetica() {
    if (alarmInterval) {
        clearInterval(alarmInterval);
        alarmInterval = null;
    }
}

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




    map = L.map('map', { zoomControl: false,rotate: true,         // <-- NUEVO: Enciende el motor de giro
        touchRotate: true,    // <-- NUEVO: Permite girar con 2 dedos en el celular
        rotateControl: {      // <-- NUEVO: Muestra una brújula
            closeOnZeroBearing: false, 
            position: 'bottomright' 
        } }).setView([-35.4, -72.0], 9);
    L.control.zoom({ position: 'bottomright' }).addTo(map);

    const satelite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { 
        attribution: 'Tiles &copy; Esri', maxZoom: 19, crossOrigin: true
    }).addTo(map);
    
    const calles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { 
        attribution: '&copy; OpenStreetMap', maxZoom: 19, crossOrigin: true
    });

    // --- PLUGIN DE CAPTURA ---
    if (L.simpleMapScreenshoter) {
        window.screenshoter = L.simpleMapScreenshoter({
            hidden: true // Oculto, lo activamos por código
        }).addTo(map);
    }

    layerFondo = L.featureGroup().addTo(map);    
    layerManuales = L.layerGroup()   // Alertas Generales (SOS, Agua, Veg, Actas)
    layerPendientes = L.layerGroup()  // Solo Pendientes

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
    
    
    // ==========================================================
    // MAGIA PIV 5.0: MOVIMIENTO LIBRE + HITBOX PERFECTO
    // ==========================================================
    let svgComicLayer = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svgComicLayer.style.position = "absolute";
    svgComicLayer.style.top = "0"; svgComicLayer.style.left = "0";
    svgComicLayer.style.width = "100%"; svgComicLayer.style.height = "100%";
    svgComicLayer.style.overflow = "visible"; 
    svgComicLayer.style.pointerEvents = "none"; 
    svgComicLayer.style.zIndex = "90"; 
    map.getPanes().popupPane.appendChild(svgComicLayer);

    let lineasActivas = [];

    map.on('popupopen', function(e) {
        let popup = e.popup;
        let marker = popup._source;
        if (!marker || !marker.getLatLng) return; 

        let wrapper = popup._container;
        
        // ¡ESTAS DOS LÍNEAS SON LA CLAVE! 
        // 1. Permite mover arriba/abajo. 2. Evita el desfase.
        wrapper.style.position = 'relative'; 
        wrapper.style.marginBottom = '0'; 
        
        let tip = wrapper.querySelector('.leaflet-popup-tip-container');
        if (tip) tip.style.display = 'none';

       // --- DETECTAR COLOR DESDE LA URL DE LA IMAGEN ---
        let colorLinea = "#e74c3c"; // Rojo por defecto
        
        // Si el marcador usa una imagen (iconUrl), leemos el nombre de la imagen
        if (marker.options && marker.options.icon && marker.options.icon.options && marker.options.icon.options.iconUrl) {
            let url = marker.options.icon.options.iconUrl.toLowerCase();
            
            if (url.includes('red') || url.includes('rojo')) colorLinea = '#e74c3c';
            else if (url.includes('orange') || url.includes('naranja')) colorLinea = '#f39c12';
            else if (url.includes('yellow') || url.includes('amarillo')) colorLinea = '#f1c40f';
            else if (url.includes('green') || url.includes('verde')) colorLinea = '#2ecc71';
            else if (url.includes('blue') || url.includes('azul')) colorLinea = '#3498db';
            else if (url.includes('purple') || url.includes('morado')) colorLinea = '#9b59b6';
            else if (url.includes('black') || url.includes('negro')) colorLinea = '#2c3e50';
            else if (url.includes('gray') || url.includes('gris')) colorLinea = '#7f8c8d';
        } 
        // Por si acaso es un polígono dibujado y no una imagen
        else if (marker.options && marker.options.fillColor) {
            colorLinea = marker.options.fillColor;
        } else if (marker.options && marker.options.color) {
            colorLinea = marker.options.color;
        }

        let path = document.createElementNS("http://www.w3.org/2000/svg", "path");
        path.setAttribute("stroke", colorLinea); 
        path.setAttribute("stroke-width", "3"); // Lo puse en 3 para que resalte más el color
        path.setAttribute("fill", "none");
        path.setAttribute("stroke-dasharray", "5,5"); 
        svgComicLayer.appendChild(path);

        let actualizarLinea = function() {
            if (!marker._icon && !marker.getLatLng) return;
            
            let origen = map.latLngToLayerPoint(marker.getLatLng());
            
            // Leemos los pixeles reales para que no se confunda si abres varios
            let svgRect = svgComicLayer.getBoundingClientRect();
            let popRect = wrapper.getBoundingClientRect();
            
            let pLeft = popRect.left - svgRect.left;
            let pTop = popRect.top - svgRect.top;
            let pRight = pLeft + popRect.width;
            let pBottom = pTop + popRect.height;
            
            let destinoX = pLeft + (popRect.width / 2);
            let destinoY = pTop + (popRect.height / 2);
            
            if (origen.y > pBottom) destinoY = pBottom; 
            else if (origen.y < pTop) destinoY = pTop;  
            
            if (origen.x > pRight) destinoX = pRight;   
            else if (origen.x < pLeft) destinoX = pLeft; 

            let d = `M ${origen.x} ${origen.y} L ${destinoX} ${destinoY}`;
            path.setAttribute("d", d);
        };

        setTimeout(actualizarLinea, 50);

        let isDragging = false;
        let startX, startY, startLeft, startTop;
        wrapper.style.cursor = 'grab';

        // --- 1. FUNCIÓN PARA MOVER (Se ejecuta solo si isDragging es true) ---
        const moverArrastre = function(e) {
            if (!isDragging) return;
            // Detectamos si es touch (dedo) o mouse
            let clientX = e.touches ? e.touches[0].clientX : e.clientX;
            let clientY = e.touches ? e.touches[0].clientY : e.clientY;
            
            let dx = clientX - startX; 
            let dy = clientY - startY;
            wrapper.style.left = (startLeft + dx) + 'px';
            wrapper.style.top = (startTop + dy) + 'px';
            actualizarLinea(); 
            if (e.type === 'touchmove') e.preventDefault(); // Evita scroll en la tablet
        };

        // --- 2. FUNCIÓN PARA SOLTAR (Apaga los sensores) ---
        const soltarArrastre = function() {
            if (isDragging) { 
                isDragging = false; 
                wrapper.style.cursor = 'grab'; 
                // ¡LA CLAVE!: Apagamos los sensores globales de la memoria
                document.removeEventListener('mousemove', moverArrastre);
                document.removeEventListener('mouseup', soltarArrastre);
                document.removeEventListener('touchmove', moverArrastre);
                document.removeEventListener('touchend', soltarArrastre);
            }
        };

        // --- 3. FUNCIÓN PARA INICIAR (Enciende los sensores temporalmente) ---
        const iniciarArrastre = function(e) {
            if (['BUTTON', 'A', 'I'].includes(e.target.tagName) || e.target.className.includes('close')) return;
            isDragging = true;
            wrapper.style.cursor = 'grabbing';
            
            let clientX = e.touches ? e.touches[0].clientX : e.clientX;
            let clientY = e.touches ? e.touches[0].clientY : e.clientY;
            
            startX = clientX; 
            startY = clientY;
            startLeft = parseFloat(wrapper.style.left || 0);
            startTop = parseFloat(wrapper.style.top || 0);
            wrapper.style.zIndex = 10000; 

            // Encendemos los sensores globales SOLO mientras arrastramos
            document.addEventListener('mousemove', moverArrastre);
            document.addEventListener('mouseup', soltarArrastre);
            document.addEventListener('touchmove', moverArrastre, { passive: false });
            document.addEventListener('touchend', soltarArrastre);
        };

        // Le asignamos el evento de inicio a la burbuja
        wrapper.addEventListener('mousedown', iniciarArrastre);
        wrapper.addEventListener('touchstart', iniciarArrastre, { passive: false });

        map.on('move', actualizarLinea);
        map.on('zoom', actualizarLinea);
        lineasActivas.push({ popup: popup, path: path, updateFn: actualizarLinea });
    });

    map.on('popupclose', function(e) {
        lineasActivas = lineasActivas.filter(item => {
            if (item.popup === e.popup) {
                if (item.path.parentNode) item.path.parentNode.removeChild(item.path);
                map.off('move', item.updateFn);
                map.off('zoom', item.updateFn);
                // Reseteamos
                item.popup._container.style.left = '0px';
                item.popup._container.style.top = '0px';
                return false;
            }
            return true;
        });
    });
    // ==========================================================
    
    
    

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
            // --- CORRECCIÓN AQUÍ: FILTRO DE ZONA 0 ---
            // Si la zona es 0 (Sistema/Oculta), saltamos este mapa y no lo mostramos en la lista
            if (m.id_zona == 0 || m.id_zona == '0') return; 
            // -----------------------------------------

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

    let extension = "";
    
    // Convertimos a minúsculas para evitar problemas de "GeoJSON" vs "geojson"
    let tipoBD = (mapaDatos.tipo_mapa || "").toLowerCase().trim();

    // 1. Prioridad: Lo que diga la Base de Datos
    if (tipoBD === 'geojson' || tipoBD === 'json') {
        extension = 'geojson';
    } 
    else if (tipoBD === 'kml' || tipoBD === 'kmz') {
        extension = 'kml';
    }
    // 2. Si es la API (api_descargar_mapa.php), SIEMPRE es GeoJSON por defecto
    else if (ruta.indexOf('api_descargar_mapa.php') !== -1) {
        console.log("Map via API detected (" + id + "): Forcing GeoJSON");
        extension = 'geojson';
    }
    // 3. Último recurso: Mirar la extensión del archivo (para uploads viejos)
    else {
        // split('?')[0] elimina parámetros extra como ?t=12345
        extension = ruta.split('.').pop().split('?')[0].toLowerCase();
    }

    console.log(`Cargando mapa ${id}: Tipo=${extension} Ruta=${ruta}`);


    
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
                style: function (feature) {
                    // ---------------------------------------------------------
                    // 1. CORRECCIÓN DE VARIABLE (mapa -> mapaDatos)
                    // ---------------------------------------------------------
                    // AQUÍ ESTABA EL ERROR: Usábamos 'mapa' que no existía.
                    var nombre = (mapaDatos.nombre_mapa || "").toLowerCase();
                    var categoria = (mapaDatos.categoria || "").toLowerCase();
                    
                    var esPendiente = nombre.includes('pendiente') || categoria.includes('pendiente');
                    var opacidadFinal = esPendiente ? 0.8 : 0.6; 

                    // ---------------------------------------------------------
                    // 2. BUSCADOR DE PROPIEDADES
                    // ---------------------------------------------------------
                    var props = feature.properties || {};
                    function getProp(clavesPosibles, valorDefecto) {
                        var keys = Object.keys(props);
                        for (var i = 0; i < keys.length; i++) {
                            var keyReal = keys[i];
                            var keyUpper = keyReal.toUpperCase();
                            for (var j = 0; j < clavesPosibles.length; j++) {
                                if (keyUpper === clavesPosibles[j]) return props[keyReal];
                            }
                        }
                        return valorDefecto;
                    }

                    // ---------------------------------------------------------
                    // 3. EXTRACCIÓN DE ESTILOS
                    // ---------------------------------------------------------
                    var colorRelleno = getProp(['FILL_COLOR', 'FILL', 'COLOR'], null);
                    var estiloRelleno = getProp(['FILL_STYLE', 'FILLSTYLE'], '');
                    var colorBorde = getProp(['BORDER_COLOR', 'BORDER_C', 'STROKE', 'EDGE_COLOR'], '#3388ff');
                    var anchoBorde = getProp(['BORDER_WIDTH', 'BORDER_W', 'WIDTH', 'STROKE-WIDTH'], 2);
                    var estiloBorde = getProp(['BORDER_STYLE', 'STYLE'], '');

                    if (estiloBorde && (estiloBorde.toString().toLowerCase() === 'null' || estiloBorde.toString().toLowerCase() === 'none')) {
                        anchoBorde = 0;
                    }

                    // LÓGICA DE APAGADO DE RELLENO (ADIÓS MORADO)
                    var activarRelleno = true;
                    if (estiloRelleno && (estiloRelleno.toString().toLowerCase() === 'no fill' || estiloRelleno.toString().toLowerCase() === 'none')) {
                        activarRelleno = false;
                    }
                    if (!colorRelleno) {
                        activarRelleno = false;
                    }

                    return {
                        fill: activarRelleno,
                        fillColor: colorRelleno || '#ffffff',
                        fillOpacity: activarRelleno ? opacidadFinal : 0, 
                        color: colorBorde,
                        weight: parseFloat(anchoBorde), 
                        opacity: 1,
                        dashArray: ''
                    };
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
            var nombre = (mapaDatos.nombre_mapa || "").toLowerCase();
            var categoria = (mapaDatos.categoria || "").toLowerCase();
            
            var esPendiente = nombre.includes('pendiente') || categoria.includes('pendiente');
            var esActa = nombre.includes('acta') || categoria.includes('acta');

            // Etiquetamos la capa para que el sistema sepa quién es quién en el futuro
            layer.esActa = esActa; 

            setTimeout(function() { 
                if (esPendiente) {
                    // NIVEL 1 (FONDO): La pendiente se va atrás de todo
                    layer.bringToBack();
                } 
                else if (esActa) {
                    // NIVEL 3 (FRENTE): El Acta se trae adelante de todo
                    layer.bringToFront();
                } 
                else {
                    // NIVEL 2 (MEDIO): Uso de Suelo, Caminos, etc.
                    // Primero lo traemos al frente (para que tape a la pendiente)
                    layer.bringToFront();

                    // TRUCO DE ORO:
                    // Si acabamos de cargar un "Uso de Suelo", este tapará al "Acta" si ya estaba cargada.
                    // Para evitar eso, buscamos si hay alguna Acta activa y la volvemos a poner encima.
                    if (typeof capasActivas !== 'undefined') {
                        Object.values(capasActivas).forEach(function(capaGuardada) {
                            if (capaGuardada && capaGuardada.esActa === true) {
                                capaGuardada.bringToFront(); // ¡Acta, recupera tu trono!
                            }
                        });
                    }
                }
            }, 200);
        }).catch(e => console.error("Error GeoJSON:", e));
    }

    else if (extension === 'kmz') {
        const layer = omnivore.kml(ruta, null, L.geoJSON(null, {
             style: { color: '#E0A9E0', fillColor: '#E0A9E0', fillOpacity: 0.5 },
             onEachFeature: (f, l) => { l.bindPopup(`<b>${mapaDatos.nombre_mapa}</b>`); }
        })).on('ready', function() { agregarCapaAlMapa(layer, id); });
    }
}

// Función auxiliar para añadir capas, enfocar y actualizar sistema
function agregarCapaAlMapa(layer, id) {
    if (capasActivas[id]) return; // Evitar duplicados

    // 1. Lógica Original (Vital para tu sistema)
    if (layerFondo) layerFondo.addLayer(layer); // Agregamos al grupo principal
    capasActivas[id] = layer;                   // Registramos en memoria

    // 2. Lógica de ZOOM (Fusionada: Admin + Usuario sin GPS)
    let zoomAdminAplicado = false;

    // A) PRIORIDAD: Si venimos del Admin con un mapa específico (?focus_map=X)
    if (typeof MAPA_ID_ACTUAL !== 'undefined' && parseInt(id) === parseInt(MAPA_ID_ACTUAL)) {
        console.log("📍 Enfocando mapa solicitado por Admin: " + id);
        zoomAdminAplicado = true;
        
        // Delay para asegurar que Leaflet renderizó todo antes de mover la cámara
        setTimeout(() => {
            try {
                if (layer.getBounds) {
                    map.fitBounds(layer.getBounds(), { 
                        padding: [50, 50], 
                        maxZoom: 16, 
                        animate: true 
                    });
                }
            } catch(e) { console.warn("Error enfocando mapa admin", e); }
        }, 600);
    }

    // B) FALLBACK: Tu lógica original (Solo si no hay GPS y no se usó el zoom de Admin)
    if (!zoomAdminAplicado && !ultimaPosicion) {
        try { map.fitBounds(layer.getBounds()); } catch(e){}
    }

    // 3. Actualizar Panel de Alertas (Vital para que aparezcan en la lista)
    if (typeof actualizarAlertasVisibles === 'function') actualizarAlertasVisibles();
}

// --- 4. GESTIÓN DE ALERTAS (BD) ---
// --- 4. GESTIÓN DE ALERTAS (BD) ---
function cargarDatosDeAlertas() {
    if (layerManuales) layerManuales.clearLayers();
    if (layerPendientes) layerPendientes.clearLayers();
    
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
                            icon: L.icon({ iconUrl: props.icono_url, iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], crossOrigin: true }) 
                        });
                        m.tipo_geom = 'Point';
                        m.radio_custom = parseFloat(props.radio_metros) || 0;
                        asignarDatosComunes(m, props, isAdmin);
                        marcadoresPeligro.push(m);
                    } 
                    else if (geom.type === 'Polygon' || geom.type === 'MultiPolygon') {
                        // Estilo para depuración (luego lo puedes poner invisible opacity:0)
                        const m = L.geoJSON(geom, { style: { color: '#FF5722', weight: 5, fillOpacity: 0.2, dashArray: '10, 10' } });
                        
                        m.tipo_geom = 'Polygon';
                        
                        // --- AQUÍ ESTABA EL ERROR: FALTABA ASIGNAR LA ID AL GRUPO ---
                        m.id_db = props.id;          // <--- ¡LÍNEA AGREGADA!
                        m.nombre_alerta = props.nombre;
                        m.id_mapa_asociado = props.id_mapa;
                        m.rawGeoJSON = geom; 
                        
                        // Asignar datos también a las capas internas (para clicks)
                        m.eachLayer(l => asignarDatosComunes(l, props, isAdmin));
                        
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
    
   // 1. Validar Textos
    const titulo = props.nombre || 'Alerta Manual';
    
    // 2. Formatear Descripción (Qué hacer) - Reducido 20%
    const descripcion = props.descripcion ? `<div style="margin-top:4px; font-size:0.7rem; color:#555; background:#f9f9f9; padding:5px; border-radius:3px; border-left:3px solid #f39c12;"><i>"${props.descripcion}"</i></div>` : '';
    
    // 3. Formatear Autor - Reducido 20%
    const etiquetasRol = {
        admin: 'Administrador',
        ingeniero_forestal: 'Ingeniero Forestal',
        jefe_operaciones: 'Jefe de Operaciones',
        jefe_faena: 'Jefe de Faena',
        usuario: 'Operador'
    };
    let rolTraducido = etiquetasRol[props.tipo_usuario] || 'Operador';
    const creador = props.nombre_usuario ? `<div style="margin-top:5px; font-size:0.65rem; color:#2980b9; text-transform: capitalize;"><b><i class="fas fa-user-shield"></i> ${rolTraducido}:</b> ${props.nombre_usuario}</div>` : '';

    // 4. Botón Borrar (Solo para Admin o el Creador) - Reducido 20%
    let btnBorrar = '';
    let miID = Number(ID_MI_USUARIO);
    let creadorID = Number(props.id_usuario);
    
    if ((typeof IS_ADMIN !== 'undefined' && IS_ADMIN) || miID === creadorID) {
        btnBorrar = `<button onclick="borrarMarcador(${props.id})" style="background:#e74c3c; color:white; border:none; padding:3px 6px; border-radius:3px; cursor:pointer; font-size:0.65rem; font-weight:bold;"><i class="fas fa-trash"></i> Eliminar</button>`;
    }

    // 5. Unir Todo (Contenedor más estrecho y título más pequeño)
    let html = `
        <div style="min-width: 140px; font-family: 'Segoe UI', sans-serif;">
            <h4 style="margin:0 0 4px 0; color:#e74c3c; font-size:0.9rem; border-bottom:1px solid #eee; padding-bottom:3px;"><i class="fas fa-exclamation-triangle"></i> ${titulo}</h4>
            ${descripcion}
            ${creador}
            <div style="text-align: right; margin-top:5px;">
                ${btnBorrar}
            </div>
        </div>
    `;
    
    layer.bindPopup(html, { autoClose: false, closeOnClick: false });
 
}

// --- ACTUALIZAR VISIBILIDAD (MODIFICADO) ---
function actualizarAlertasVisibles() {
    // 1. Limpiamos el lienzo para redibujar según los filtros actuales
    layerManuales.clearLayers(); 
    layerPendientes.clearLayers(); 

    marcadoresPeligro.forEach(m => {
        // Obtenemos el ID del mapa (si es null o 0, asumimos 1 para manuales)
        const idMapa = m.id_mapa_asociado || 1;
        
        // Verificamos si la capa asociada está activa en el menú
        if (capasActivas[idMapa] || idMapa == 1) {
            
            // A) CASO PENDIENTES
            if (m.nombre_alerta && m.nombre_alerta.toUpperCase().includes("PENDIENTE")) {
                layerPendientes.addLayer(m);
            } 
            // B) CASO ALERTAS MANUALES / NATIVO / AGUA
            else {
                // 1. Agregamos el PIN (Marcador)
                layerManuales.addLayer(m);

                // 2. DIBUJAMOS EL CÍRCULO (RADIO) SI TIENE
                // Verificamos si es un punto y si tiene radio guardado
                if (m.tipo_geom === 'Point' && m.radio_custom > 0) {
                    
                    // --- Recuperar Color según Nivel ---
                    // Buscamos el nivel en las propiedades que guardamos en el marcador
                    let nivel = (m.nivel || (m.feature && m.feature.properties && m.feature.properties.nivel) || "").toString().toLowerCase();
                    let colorRadio = '#3388ff'; // Azul default

                    if (nivel.includes('medio')) colorRadio = '#f1c40f';      // Amarillo
                    else if (nivel.includes('alto')) colorRadio = '#e67e22';  // Naranja
                    else if (nivel.includes('critico')) colorRadio = '#d63031'; // Rojo

                    // Creamos el círculo
                    const circulo = L.circle(m.getLatLng(), { 
                        radius: m.radio_custom, 
                        color: colorRadio, 
                        fillColor: colorRadio, 
                        fillOpacity: 0.2, 
                        weight: 1,
                        interactive: false // IMPORTANTE: Para que el clic pase al pin
                    });

                    // Lo agregamos a la misma capa que el pin
                    layerManuales.addLayer(circulo);
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


function checkPeligros(lat, lng) {
    if (typeof IS_ADMIN !== 'undefined' && IS_ADMIN) return;
    if (!seguridadGeneralActiva) {
        if (estadoAlertaActual > 0 && estadoAlertaActual < 3) cerrarAlertaMaestra();
        return;
    }

    // Nota: Eliminamos el "if (estadoAlertaActual === 3) return;" para permitir que detecte 
    // vegetación aunque la pendiente esté silenciada (pero con menor prioridad visual).

    let peligroDetectado = false;

    for (let m of marcadoresPeligro) {
        if (m.nombre_alerta && m.nombre_alerta.toUpperCase().includes("PENDIENTE")) continue;

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

        if (isDanger) {
            peligroDetectado = true;
            let nombre = m.nombre_alerta.toUpperCase();
            let titulo = "PRECAUCIÓN";
            let color = "#f1c40f"; 
            let nivel = 1;         

            if (nombre.includes("NATIVO") || nombre.includes("VEGETACION")) {
                titulo = "ZONA PROTEGIDA"; color = "#2ecc71"; 
            } else if (nombre.includes("ACTA") || nombre.includes("FAENA")) {
                titulo = "LÍMITE DE ACTA"; color = "#e67e22"; nivel = 2; 
            } else if (nombre.includes("AGUA")) {
                titulo = "PROTECCIÓN AGUA"; color = "#3498db"; 
            }

            mostrarAlertaMaestra(titulo, `Estás en: ${m.nombre_alerta}`, color, nivel, (m.id_db || m.id));
            break; 
        }
    }

    // --- LÓGICA DE RESETEO SEGURA ---
    if (!peligroDetectado) {
        // Cerramos alerta visual si era de nivel bajo
        if (estadoAlertaActual > 0 && estadoAlertaActual < 3) {
            cerrarAlertaMaestra();
        }

        // SOLO SI NO HAY PENDIENTE ACTIVA Y NO HAY PELIGRO GENERAL...
        // ENTENDEMOS QUE EL USUARIO ESTÁ EN ZONA SEGURA Y RESETEAMOS.
        if (!pendienteActiva) {
            idsSilenciados = []; 
        }
    }
}

function checkPendientes(lat, lng) {
    if (typeof IS_ADMIN !== 'undefined' && IS_ADMIN) return;
    if (!seguridadPendienteActiva) {
        if (pendienteActiva) cerrarAlertaMaestra();
        return;
    }

    let candidatos = [];
    for (let m of marcadoresPeligro) {
        if (m.tipo_geom === 'Polygon' && m.nombre_alerta && m.nombre_alerta.toUpperCase().includes('PENDIENTE')) {
            const anillos = scanCapasRecursivo(m); 
            for (let anillo of anillos) {
                if (isPointInRing(lat, lng, anillo)) {
                    let puntaje = m.nombre_alerta.toUpperCase().includes("CRÍTICA") ? 2 : 1;
                    // Usamos m.id_db como identificador único y estable
                    candidatos.push({ marcador: m, nivel: puntaje, id: (m.id_db || m.id) });
                    break; 
                }
            }
        }
    }

    let ganador = null;
    if (candidatos.length > 0) {
        candidatos.sort((a, b) => b.nivel - a.nivel);
        ganador = candidatos[0]; 
    }

    if (ganador) {
        pendienteActiva = ganador.marcador;
        const esCritica = ganador.marcador.nombre_alerta.toUpperCase().includes("CRÍTICA");
        
        mostrarAlertaMaestra(
            esCritica ? "PELIGRO DE VUELCO" : "PENDIENTE MEDIA",
            "Zona de Pendiente Detectada.",
            esCritica ? "#d63031" : "#e67e22",
            3,
            ganador.id 
        );
    } else {
        // ZONA SEGURA DE PENDIENTES
        if (pendienteActiva) {
            pendienteActiva = null;
            cerrarAlertaMaestra();
            // Nota: No borramos la lista de silenciados aquí todavía
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

function iniciarLoopAlerta(nivel) {
    if (!seguridadPendienteActiva) return;

    const colorFondo = (nivel === "ALTO") ? "#c0392b" : "#e67e22";
    const titulo = (nivel === "ALTO") ? "PENDIENTE CRÍTICA" : "PENDIENTE MEDIA";
    
    const divAlertas = document.getElementById('alertas');
    
    // Forzamos limpieza para asegurar que el navegador detecte el cambio de contenido
    divAlertas.innerHTML = ""; 
    divAlertas.style.display = 'block';
    
    // Inyectamos el nuevo mensaje
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

    // Gestión del Audio (Sin interrupciones si ya está sonando)
   playAlarmaSintetica();
}

function detenerLoopAlerta() {
    pendienteActiva = null; 
    // CORRECCIÓN: Borramos la línea "asistidoConfirmado = false;"
    
    clearTimeout(timerAlerta); 
    clearTimeout(timerEspera);
    
    stopAlarmaSintetica();
    
    const div = document.getElementById('alertas');
    if(div && div.innerHTML.includes('PENDIENTE')) div.style.display = 'none';
}

function confirmarAsistencia() {
    if (pendienteActiva) {
        // Guardamos la ID para que este polígono específico se calle
        idPoligonoSilenciado = pendienteActiva.id_db;
        
        // Detenemos sonido y ocultamos alerta
        detenerLoopAlertaVisualmente(); 
        
        alert("✅ Asistencia confirmada.");
    }
}

// Pequeña ayuda para apagar visuales sin perder la referencia de pendienteActiva
function detenerLoopAlertaVisualmente() {
    clearTimeout(timerAlerta); 
    clearTimeout(timerEspera);
    stopAlarmaSintetica();
    
    const div = document.getElementById('alertas');
    if(div) div.style.display = 'none';
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

window.saveMarker = function(ll, nom, desc, nivel, radio, id_destino = 1) { 
    // Usamos directamente los parámetros que recibe la función
    const data = {
        action: 'add_marker',
        lat: ll.lat,
        lng: ll.lng,
        nombre: nom,
        descripcion: desc,
        nivel: nivel,
        radio: radio || 0,
        id_mapa: id_destino // <-- Aquí toma el ID de mapa que le mandamos
    };

    // Feedback visual opcional
    const btn = document.querySelector('.btn-upload');
    if(btn) btn.innerText = "Guardando...";

    fetch('Api/api_mapa.php', { 
        method: 'POST', 
        body: JSON.stringify(data) 
    })
    .then(r => r.json())
    .then(res => { 
        if(btn) btn.innerText = "Guardar"; // Restaurar botón
        
        if(res.success) { 
            cargarDatosDeAlertas(); 
            alert("✅ Guardado."); 
        } else {
            alert("❌ Error: " + (res.error || "Desconocido"));
        }
    })
    .catch(err => {
        if(btn) btn.innerText = "Guardar";
        console.error(err);
        alert("Error de conexión");
    });
};


window.borrarMarcador = function(id) { if (!navigator.onLine) {
        alert("⚠️ Estás sin conexión a internet. No puedes eliminar alertas del servidor hasta que recuperes la señal.");
        return; // Detiene la función aquí mismo
    } if(confirm("¿Eliminar?")) fetch('Api/api_mapa.php', { method: 'POST', body: JSON.stringify({ action: 'delete_marker', id: id }) }).then(r=>r.json()).then(res=>{ if(res.success) cargarDatosDeAlertas(); }); };
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
window.centrarEnUsuario = function() { unlockAudio(); if(ultimaPosicion && map) map.setView(ultimaPosicion, 16, {animate: true}); };


function setupUIEvents() {
    const formContainer = document.getElementById('markerFormContainer'); // El div que contiene el form
    const form = document.getElementById('markerForm'); // El formulario dentro
    let currentLatLng;

    // A. BOTÓN CANCELAR
    const btnCancel = document.getElementById('cancelMarker');
    if (btnCancel) {
        btnCancel.onclick = () => { 
            formContainer.style.display = 'none'; 
            form.reset(); 
        };
    }

    // B. CLIC DERECHO (ABRIR FORMULARIO)
    // B. CLIC DERECHO (ABRIR FORMULARIO)
    map.on('contextmenu', function(e) {
        // Descomenta la siguiente línea si quieres que SOLO LOS ADMINS puedan usar el clic derecho
        // if (typeof IS_ADMIN !== 'undefined' && !IS_ADMIN) return; 
        
        currentLatLng = e.latlng;

        const selector = document.getElementById('selectorMapaForm');
        if (selector) {
            let opciones = '<option value="1">Capa General (Universal)</option>';
            if (typeof LISTA_MAPAS !== 'undefined') {
                if (typeof IS_ADMIN !== 'undefined' && IS_ADMIN) {
                    // ADMIN: Ve todas las actas
                    LISTA_MAPAS.forEach(m => {
                        if (m.categoria && m.categoria.toLowerCase().includes('acta')) {
                            opciones += `<option value="${m.id_mapa}">${m.nombre_mapa}</option>`;
                        }
                    });
                } else {
                    // OPERADOR: Ve solo el acta que tiene activa
                    if (typeof MAPA_ID_ACTUAL !== 'undefined' && MAPA_ID_ACTUAL > 1) {
                        let mapaActual = LISTA_MAPAS.find(m => m.id_mapa == MAPA_ID_ACTUAL);
                        if (mapaActual && mapaActual.categoria && mapaActual.categoria.toLowerCase().includes('acta')) {
                            opciones += `<option value="${mapaActual.id_mapa}" selected>${mapaActual.nombre_mapa}</option>`;
                        }
                    }
                }
            }
            selector.innerHTML = opciones;
        }

        formContainer.style.display = 'block'; // Mostrar el div contenedor
    });

   // C. ENVIAR FORMULARIO (GUARDAR)
    form.onsubmit = (e) => {
        e.preventDefault(); 
        if (!currentLatLng) return;

        const nombre = document.getElementById('popupNombre').value;
        const desc   = document.getElementById('popupDesc').value;
        const nivel  = document.getElementById('popupIcon').value; 
        const radio  = document.getElementById('popupRadio').value || 15;
        
        // ¡LA SOLUCIÓN ESTÁ AQUÍ! Leemos lo que elegiste en el combobox
        const id_mapa_elegido = Number(document.getElementById('selectorMapaForm').value) || 1;
        
        saveMarker(
            currentLatLng, 
            nombre, 
            desc, 
            nivel, 
            radio, 
            id_mapa_elegido // <--- Ahora sí enviamos el ID correcto a la base de datos
        ); 
        
        formContainer.style.display = 'none';
        form.reset();
    };

    // D. BOTÓN BORRAR TODO
    const btnBorrar = document.getElementById('borrarMarcadores');
    if(btnBorrar) {
        btnBorrar.onclick = () => { 
            if(confirm("¿RESETEAR TODO?")) { 
                fetch('Api/api_mapa.php?action=delete_all', { method: 'POST' })
                .then(r => r.json())
                .then(res => { 
                    if(res.success) { cargarDatosDeAlertas(); alert("Reset completo."); }
                }); 
            }
        };
    }
}
// --- FUNCIÓN DE DESCARGA OFFLINE "FULL PACK" ---
window.descargarZona = function(idZona) {
    const urls = [];

    // 1. RECOLECTAR MAPAS DE LA ZONA (GeoJSON, KML)
    if (typeof LISTA_MAPAS !== 'undefined') {
        LISTA_MAPAS.forEach(m => { 
            // Guardamos el mapa si es de esta zona y tiene ruta válida
            if (m.id_zona == idZona && m.ruta_archivo && m.ruta_archivo !== 'manual') {
                urls.push(m.ruta_archivo);
            }
        });
    }

    // 2. RECURSOS VITALES (APP SHELL)
    // Estos archivos son obligatorios para que la pantalla se dibuje sin internet
    const recursosSistema = [
        'index.php',             // Tu visor principal
        'script_visor.js',       // La lógica (GPS, Alertas)
        'style_visor.css',       // Estilos del visor
        'style.css',             // Estilos generales
        // Iconos de Marcadores (Para que no se vean rotos)
        'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-blue.png',
        'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-red.png',
        'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-green.png',
        'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-orange.png',
        'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-shadow.png',
        // Librerías Externas (Si usas versiones online)
        'https://unpkg.com/leaflet@1.7.1/dist/leaflet.css',
        'https://unpkg.com/leaflet@1.7.1/dist/leaflet.js',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css'
    ];
    
    // Agregamos recursos al paquete
    urls.push(...recursosSistema);

    // 3. LA BASE DE DATOS DE ALERTAS (SNAPSHOT)
    // Esto es lo más importante: Guardamos la respuesta de la API
    urls.push('Api/api_mapa.php?action=fetch_markers');

    if (urls.length > 0) {
        document.getElementById('loader').style.display = 'block';
        
        if (navigator.serviceWorker && navigator.serviceWorker.controller) {
            console.log("Iniciando descarga de zona...", urls);
            
            // Enviamos la orden al Service Worker
            navigator.serviceWorker.controller.postMessage({ 
                action: 'CACHE_NEW_ZONE', 
                urls: urls, 
                zoneId: idZona 
            });

        } else { 
            alert("⚠️ El modo Offline no está listo. Recarga la página e inténtalo de nuevo."); 
            document.getElementById('loader').style.display = 'none'; 
        }
    } else { 
        alert("Esta zona no tiene mapas para descargar."); 
    }
};
let serviceWorkerListenerReady = false;
function setupServiceWorkerListener() {
    if (serviceWorkerListenerReady) return;
    serviceWorkerListenerReady = true;
    if ('serviceWorker' in navigator) {
        
        // 1. REGISTRO ROBUSTO
        navigator.serviceWorker.register('sw.js')
            .then(reg => {
                // Si hay un SW esperando (actualización), forzamos que tome el control
                if (reg.waiting) {
                    reg.waiting.postMessage({ type: 'SKIP_WAITING' });
                }
                console.log("✅ SW v12 Registrado.");
                
                // Actualizamos la página automáticamente si detectamos que el SW cambió
                reg.onupdatefound = () => {
                    const installingWorker = reg.installing;
                    installingWorker.onstatechange = () => {
                        if (installingWorker.state === 'installed') {
                            if (navigator.serviceWorker.controller) {
                                console.log("Nueva versión disponible. Recargando...");
                                window.location.reload();
                            }
                        }
                    };
                };
            })
            .catch(err => console.error("❌ Error SW:", err));

        // 2. ESCUCHAR MENSAJES (Zona Descargada)
        navigator.serviceWorker.addEventListener('message', event => {
            if(event.data.action === 'ZONE_CACHED_OK'){
                document.getElementById('loader').style.display = 'none';
                alert("✅ ZONA DESCARGADA.\nAhora funcionará Offline.");
            }
        });

        // 3. DETECTAR CAMBIO DE CONTROLADOR
        let refreshing;
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            if (refreshing) return;
            window.location.reload();
            refreshing = true;
        });

    } else {
        console.warn("Navegador sin soporte Offline.");
    }
}

// --- FUNCIONES DEL PANEL DE DIAGNÓSTICO ---

// --- AUTO-ARRANQUE ---
document.addEventListener('DOMContentLoaded', function() {
    // 1. Iniciar Service Worker
    setupServiceWorkerListener();

    // 2. Si venimos desde el Admin con un mapa específico (?focus_map=X)
    if (typeof MAPA_ID_ACTUAL !== 'undefined' && MAPA_ID_ACTUAL > 0) {
        console.log("Auto-cargando mapa ID:", MAPA_ID_ACTUAL);
        
        // Buscamos los datos de ese mapa en la lista global
        const mapaObjetivo = LISTA_MAPAS.find(m => parseInt(m.id_mapa) === parseInt(MAPA_ID_ACTUAL));
        
        if (mapaObjetivo) {
            cargarCapaVisual(mapaObjetivo);
        } else {
            console.warn("El mapa solicitado no está asignado a este usuario.");
        }
    }
});







// =============================================================================
// SISTEMA VISUAL DE ALERTAS (CORREGIDO: ANTI-FLASH + MEMORIA MÚLTIPLE)
// =============================================================================
let estadoAlertaActual = 0; 
let idAlertaEnPantalla = null; 
let idsSilenciados = []; // Lista de alertas aceptadas


function mostrarAlertaMaestra(titulo, mensaje, color, nivel, idUnico) {
    // 1. CHEQUEO DE SILENCIO: Si ya aceptaste esta alerta, no la mostramos.
    if (idsSilenciados.includes(idUnico)) return;

    // 2. ANTI-FLASH: Si ya estamos mostrando ESTA MISMA alerta y nivel, NO HACEMOS NADA.
    // (Esto evita que parpadee o reinicie el audio cada segundo)
    if (idAlertaEnPantalla === idUnico && estadoAlertaActual === nivel) return;

    // 3. PRIORIDAD: Si hay una alerta más grave sonando, no la interrumpimos.
    if (nivel < estadoAlertaActual && estadoAlertaActual > 0) return;

    // Actualizamos estado
    estadoAlertaActual = nivel;
    idAlertaEnPantalla = idUnico; 

    // 4. DIBUJAR INTERFAZ
    let overlay = document.getElementById('alerta-maestra-overlay');
    if (!overlay) {
        crearModalAlertaHTML();
        overlay = document.getElementById('alerta-maestra-overlay');
    }

    document.getElementById('alerta-maestra-card').style.borderTop = `15px solid ${color}`;
    document.getElementById('alerta-maestra-titulo').innerText = titulo;
    document.getElementById('alerta-maestra-titulo').style.color = color;
    document.getElementById('alerta-maestra-mensaje').innerText = mensaje;
    
    let btn = document.getElementById('btn-maestra-confirmar');
    btn.style.backgroundColor = color;
    btn.innerText = (nivel === 3) ? "¡ENTENDIDO, SALIENDO!" : "ENTENDIDO";

    overlay.style.display = 'flex';
   playAlarmaSintetica();
}

// ============================================================================
// FUNCIONES DE AUDITORÍA Y SEGURIDAD LEGAL (CORREGIDO AL 100%)
// ============================================================================

function registrarFirmaSeguridad(idAlerta, tituloAlerta) {
    // Si estamos probando en PC y no hay GPS, usamos 0,0
    let lat = ultimaPosicion ? ultimaPosicion[0] : 0;
    let lng = ultimaPosicion ? ultimaPosicion[1] : 0;

    const firmaData = {
        action: 'registrar_firma_seguridad',
        id_alerta: idAlerta,
        tipo_alerta: tituloAlerta,
        lat: lat,
        lng: lng,
        fecha_hora: new Date().toISOString()
    };

    if (navigator.onLine) {
        fetch('Api/api_mapa.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(firmaData)
        })
        .then(r => r.json())
        .then(res => {
            if(res.success) console.log("✅ Firma legal guardada en BD.");
            else console.error("❌ Error en BD al guardar firma:", res.error);
        })
        .catch(e => {
            console.error("Fallo de red al firmar, guardando offline...", e);
            guardarFirmaOffline(firmaData);
        });
    } else {
        guardarFirmaOffline(firmaData);
    }
}

function guardarFirmaOffline(firmaData) {
    let registrosOffline = JSON.parse(localStorage.getItem('firmasSeguridadOffline')) || [];
    registrosOffline.push(firmaData);
    localStorage.setItem('firmasSeguridadOffline', JSON.stringify(registrosOffline));
    console.log("✅ Firma guardada OFFLINE.");
}

// ============================================================================
// TU FUNCIÓN ORIGINAL INTACTA (SOLO SE LE AGREGÓ EL GUARDADO)
// ============================================================================
function cerrarAlertaMaestra() {
    let overlay = document.getElementById('alerta-maestra-overlay');
    if (overlay) overlay.style.display = 'none';
    
    // Tu función original de sonido
    try { stopAlarmaSintetica(); } catch(e) {}
    
    // --- LÓGICA DE SILENCIADO Y GUARDADO ---
    if (idAlertaEnPantalla) {
        
        // 1. NUEVO: Tomamos la "foto" legal antes de silenciar
        let tituloElem = document.getElementById('alerta-maestra-titulo');
        let tituloVisto = tituloElem ? tituloElem.innerText : 'Alerta de Seguridad';
        registrarFirmaSeguridad(idAlertaEnPantalla, tituloVisto);
        
        // 2. TU LÓGICA ORIGINAL: Agregamos el ID actual a la lista de silenciados
        if (!idsSilenciados.includes(idAlertaEnPantalla)) {
            idsSilenciados.push(idAlertaEnPantalla);
        }
    }
    
    // 3. TU LÓGICA ORIGINAL: Reiniciar estados
    estadoAlertaActual = 0; 
    idAlertaEnPantalla = null;
}

function crearModalAlertaHTML() {
    const html = `
    <div id="alerta-maestra-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.92); z-index:99999; justify-content:center; align-items:center; flex-direction:column;">
        <div id="alerta-maestra-card" style="background:white; width:90%; max-width:380px; padding:30px; border-radius:20px; text-align:center;">
            <i class="fas fa-exclamation-triangle" style="font-size:4.5rem; margin-bottom:20px; color:#555;"></i>
            <h1 id="alerta-maestra-titulo" style="margin:0; font-size:2.2rem; font-weight:900; text-transform:uppercase;">PELIGRO</h1>
            <p id="alerta-maestra-mensaje" style="font-size:1.3rem; color:#444; margin:20px 0;">...</p>
            <button id="btn-maestra-confirmar" onclick="cerrarAlertaMaestra()" style="width:100%; padding:15px; border:none; color:white; font-size:1.2rem; font-weight:bold; border-radius:12px; cursor:pointer;">ENTENDIDO</button>
        </div>
    </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
}





// =============================================================================
// LÓGICA DE CAPTURA PIV (FOTO Y RECORTE)
// =============================================================================

function obtenerIdMapaParaCaptura() {
    if (typeof MAPA_ID_ACTUAL !== 'undefined' && Number(MAPA_ID_ACTUAL) > 0) {
        return Number(MAPA_ID_ACTUAL);
    }
    const checked = document.querySelectorAll('#layers-container input[type="radio"]:checked, #layers-container input[type="checkbox"]:checked');
    for (const input of checked) {
        const v = Number(input.value || 0);
        if (v > 0 && v !== 1) return v;
    }
    if (checked.length > 0) return Number(checked[0].value || 0);
    return 0;
}

function getCaptureStepKey(mapaId) {
    return `piv_capture_step_${mapaId}`;
}

function getCurrentCaptureStep(mapaId) {
    const raw = sessionStorage.getItem(getCaptureStepKey(mapaId));
    const step = Number(raw || 1);
    return (step === 2) ? 2 : 1;
}

function setCurrentCaptureStep(mapaId, step) {
    if (step === 2) {
        sessionStorage.setItem(getCaptureStepKey(mapaId), '2');
    } else {
        sessionStorage.removeItem(getCaptureStepKey(mapaId));
    }
}

function actualizarBotonCaptura(idMapa) {
    const btn = document.getElementById('btn-captura-piv');
    if (!btn) return;
    const mapaId = Number(idMapa || 0) || obtenerIdMapaParaCaptura();
    const step = mapaId ? getCurrentCaptureStep(mapaId) : 1;
    if (step === 1) {
        btn.innerHTML = '<i class="fas fa-camera"></i><span>Tomar foto 1</span>';
        btn.title = 'Tomar primera foto para PIV';
    } else {
        btn.innerHTML = '<i class="fas fa-camera"></i><span>Tomar foto 2</span>';
        btn.title = 'Tomar segunda foto y abrir formulario PIV';
    }
}

function toDataUrl(result) {
    return new Promise((resolve, reject) => {
        if (typeof result === 'string') { resolve(result); return; }
        if (result && typeof result.toDataURL === 'function') {
            try { resolve(result.toDataURL('image/jpeg', 0.9)); return; } catch (e) { reject(e); return; }
        }
        if (result instanceof Blob) {
            const fr = new FileReader();
            fr.onload = () => resolve(String(fr.result || ''));
            fr.onerror = reject;
            fr.readAsDataURL(result);
            return;
        }
        reject(new Error('Formato de captura no soportado'));
    });
}

function cargarImagenBase64(base64) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('No se pudo cargar la captura generada'));
        img.src = base64;
    });
}

async function capturaEsTransparenteOVacia(base64) {
    const img = await cargarImagenBase64(base64);
    const width = Number(img.naturalWidth || img.width || 0);
    const height = Number(img.naturalHeight || img.height || 0);

    if (width < 50 || height < 50) {
        return true;
    }

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    if (!ctx) {
        return false;
    }

    ctx.drawImage(img, 0, 0);

    const sampleCols = 6;
    const sampleRows = 6;
    let nonTransparentPixels = 0;

    for (let row = 0; row < sampleRows; row++) {
        for (let col = 0; col < sampleCols; col++) {
            const x = Math.min(width - 1, Math.floor((col / Math.max(1, sampleCols - 1)) * (width - 1)));
            const y = Math.min(height - 1, Math.floor((row / Math.max(1, sampleRows - 1)) * (height - 1)));
            const pixel = ctx.getImageData(x, y, 1, 1).data;
            if (pixel[3] > 0) {
                nonTransparentPixels++;
            }
        }
    }

    return nonTransparentPixels === 0;
}

function obtenerContenedorMapaCaptura() {
    const mapContainer = map && typeof map.getContainer === 'function'
        ? map.getContainer()
        : document.getElementById('map');

    if (!mapContainer) {
        throw new Error('No se encontro el contenedor del mapa');
    }

    return mapContainer;
}

function esperarFrameCaptura() {
    return new Promise((resolve) => requestAnimationFrame(() => resolve()));
}

function crearOverlayTemporalPopups(mapContainer) {
    const mapRect = mapContainer.getBoundingClientRect();
    const popups = Array.from(mapContainer.querySelectorAll('.leaflet-popup'))
        .filter((el) => el.offsetWidth > 0 && el.offsetHeight > 0);

    if (!popups.length) {
        return null;
    }

    const overlay = document.createElement('div');
    overlay.style.position = 'absolute';
    overlay.style.left = '0';
    overlay.style.top = '0';
    overlay.style.width = '100%';
    overlay.style.height = '100%';
    overlay.style.pointerEvents = 'none';
    overlay.style.zIndex = '800';

    const restores = [];

    for (const popup of popups) {
        const popupRect = popup.getBoundingClientRect();
        const clone = popup.cloneNode(true);

        clone.style.position = 'absolute';
        clone.style.left = `${Math.round(popupRect.left - mapRect.left)}px`;
        clone.style.top = `${Math.round(popupRect.top - mapRect.top)}px`;
        clone.style.margin = '0';
        clone.style.transform = 'none';
        clone.style.webkitTransform = 'none';
        clone.style.right = 'auto';
        clone.style.bottom = 'auto';
        clone.style.visibility = 'visible';
        clone.style.display = 'block';
        clone.style.pointerEvents = 'none';

        overlay.appendChild(clone);

        const prevVisibility = popup.style.visibility;
        popup.style.visibility = 'hidden';
        restores.push(() => {
            popup.style.visibility = prevVisibility;
        });r
    }

    mapContainer.appendChild(overlay);

    return {
        cleanup() {
            for (const restore of restores) restore();
            overlay.remove();
        }
    };
}

async function capturarElementoConHtml2Canvas(element) {
    if (typeof html2canvas === 'undefined') {
        throw new Error('html2canvas no esta disponible');
    }

    return html2canvas(element, {
        useCORS: true,
        allowTaint: false,
        backgroundColor: null,
        logging: false,
        scale: 1,
        removeContainer: true
    });
}

async function capturarMapaConHtml2Canvas() {
    const mapContainer = obtenerContenedorMapaCaptura();
    const overlayPopups = crearOverlayTemporalPopups(mapContainer);

    try {
        await esperarFrameCaptura();
        const canvas = await capturarElementoConHtml2Canvas(mapContainer);
        return canvas.toDataURL('image/jpeg', 0.92);
    } finally {
        if (overlayPopups) {
            overlayPopups.cleanup();
        }
    }
}

function esperarVideoListo(video) {
    return new Promise((resolve, reject) => {
        const onLoaded = () => cleanup(resolve);
        const onError = () => cleanup(() => reject(new Error('No se pudo inicializar la captura de pantalla')));

        const cleanup = (done) => {
            video.removeEventListener('loadedmetadata', onLoaded);
            video.removeEventListener('error', onError);
            done();
        };

        video.addEventListener('loadedmetadata', onLoaded, { once: true });
        video.addEventListener('error', onError, { once: true });
    });
}

async function capturarMapaDesdePantallaNativa() {
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getDisplayMedia !== 'function') {
        throw new Error('La captura nativa de pantalla no esta disponible');
    }

    const mapContainer = obtenerContenedorMapaCaptura();
    const rect = mapContainer.getBoundingClientRect();
    let stream = null;

    try {
        try {
            stream = await navigator.mediaDevices.getDisplayMedia({
                video: {
                    frameRate: { ideal: 30, max: 30 },
                    cursor: 'always'
                },
                audio: false,
                preferCurrentTab: true,
                selfBrowserSurface: 'include',
                surfaceSwitching: 'exclude'
            });
        } catch (e) {
            e.userCancelledCapture = true;
            throw e;
        }

        const track = stream.getVideoTracks()[0];
        if (!track) {
            throw new Error('No se pudo obtener el video de la captura');
        }

        const settings = typeof track.getSettings === 'function' ? track.getSettings() : {};
        if (settings && settings.displaySurface && settings.displaySurface !== 'browser') {
            throw new Error('Para que la foto salga bien, comparte esta pestaña del navegador');
        }

        const video = document.createElement('video');
        video.style.position = 'fixed';
        video.style.left = '-99999px';
        video.style.top = '-99999px';
        video.muted = true;
        video.playsInline = true;
        video.srcObject = stream;
        document.body.appendChild(video);

        try {
            await esperarVideoListo(video);
            await video.play();
            await new Promise((resolve) => setTimeout(resolve, 250));

            const viewportWidth = Math.max(document.documentElement.clientWidth, window.innerWidth || 0);
            const viewportHeight = Math.max(document.documentElement.clientHeight, window.innerHeight || 0);
            const scaleX = viewportWidth > 0 ? video.videoWidth / viewportWidth : 1;
            const scaleY = viewportHeight > 0 ? video.videoHeight / viewportHeight : 1;

            const sx = Math.max(0, Math.round(rect.left * scaleX));
            const sy = Math.max(0, Math.round(rect.top * scaleY));
            const sw = Math.max(1, Math.min(video.videoWidth - sx, Math.round(rect.width * scaleX)));
            const sh = Math.max(1, Math.min(video.videoHeight - sy, Math.round(rect.height * scaleY)));

            const canvas = document.createElement('canvas');
            canvas.width = sw;
            canvas.height = sh;
            const ctx = canvas.getContext('2d');

            if (!ctx) {
                throw new Error('No se pudo preparar el lienzo de captura');
            }

            ctx.drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
            return canvas.toDataURL('image/jpeg', 0.92);
        } finally {
            video.pause();
            video.srcObject = null;
            video.remove();
        }
    } finally {
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
        }
    }
}

function esCancelacionUsuarioDeCaptura(error) {
    if (!error) return false;

    const name = String(error.name || '').toLowerCase();
    const message = String(error.message || '').toLowerCase();

    return (
        name === 'notallowederror' ||
        name === 'aborterror' ||
        message.includes('permission dismissed') ||
        message.includes('permission denied') ||
        message.includes('cancel') ||
        message.includes('deneg') ||
        message.includes('rechaz')
    );
}

async function obtenerCapturaMapaBase64() {
    let ultimoError = null;

    // Metodo principal: captura nativa de la pestaña para fotografiar exactamente lo visible.
    try {
        const nativeBase64 = await capturarMapaDesdePantallaNativa();
        if (!(await capturaEsTransparenteOVacia(nativeBase64))) {
            return nativeBase64;
        }
        ultimoError = new Error('La captura nativa de pantalla salio vacia');
    } catch (e) {
        if (esCancelacionUsuarioDeCaptura(e)) {
            e.userCancelledCapture = true;
            throw e;
        }
        ultimoError = e;
    }

    // Respaldo: capturar el visor ya renderizado para incluir popups.
    try {
        const vistaBase64 = await capturarMapaConHtml2Canvas();
        if (!(await capturaEsTransparenteOVacia(vistaBase64))) {
            return vistaBase64;
        }
        ultimoError = new Error('La captura visible del mapa salio vacia');
    } catch (e) {
        ultimoError = e;
    }

    if (window.screenshoter && typeof window.screenshoter.takeScreen === 'function') {
        try {
            const result = await window.screenshoter.takeScreen('image');
            const base64Image = await toDataUrl(result);

            if (!(await capturaEsTransparenteOVacia(base64Image))) {
                return base64Image;
            }

            ultimoError = new Error('La captura del plugin salio vacia');
        } catch (e) {
            ultimoError = e;
        }
    }

    const fallbackBase64 = await capturarMapaConHtml2Canvas();
    if (await capturaEsTransparenteOVacia(fallbackBase64)) {
        throw ultimoError || new Error('La captura salio vacia');
    }

    return fallbackBase64;
}

function getPivGalleryInput() {
    let input = document.getElementById('piv-gallery-input');
    if (input) return input;

    input = document.createElement('input');
    input.type = 'file';
    input.id = 'piv-gallery-input';
    input.accept = 'image/*';
    input.style.display = 'none';
    document.body.appendChild(input);
    return input;
}

function leerArchivoComoDataUrl(file) {
    return new Promise((resolve, reject) => {
        if (!file) {
            reject(new Error('No se selecciono ninguna imagen'));
            return;
        }

        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result || ''));
        reader.onerror = () => reject(new Error('No se pudo leer la imagen seleccionada'));
        reader.readAsDataURL(file);
    });
}

function abrirGaleriaPIV(idMapa) {
    const mapaId = Number(idMapa || 0) || obtenerIdMapaParaCaptura();
    if (!mapaId) {
        alert("No se encontro un mapa seleccionado.");
        return;
    }

    const input = getPivGalleryInput();
    input.value = '';

    input.onchange = async function() {
        const file = input.files && input.files[0] ? input.files[0] : null;
        if (!file) return;

        if (!String(file.type || '').startsWith('image/')) {
            alert("Selecciona una imagen valida desde la galeria.");
            input.value = '';
            return;
        }

        try {
            const base64Image = await leerArchivoComoDataUrl(file);
            startCrop(base64Image, mapaId, 1);
        } catch (e) {
            alert("No se pudo abrir la imagen seleccionada: " + e.toString());
        } finally {
            input.value = '';
        }
    };

    input.click();
}

async function obtenerCapturaParaPIV() {
    if (navigator.mediaDevices && typeof navigator.mediaDevices.getDisplayMedia === 'function') {
        return capturarMapaDesdePantallaNativa();
    }

    return obtenerCapturaMapaBase64();
}

window.capturarMapaMagico = function(idMapa) {
    const mapaId = Number(idMapa || 0) || obtenerIdMapaParaCaptura();
    if (!mapaId) { alert("No se encontro un mapa seleccionado."); return; }

    const step = getCurrentCaptureStep(mapaId);
    const btn = document.getElementById('btn-captura-piv');
    const originalHTML = btn ? btn.innerHTML : '';
    if(btn) btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparando...';

    const controls = document.querySelector('.leaflet-control-container');
    if(controls) controls.style.display = 'none';

    const restore = () => {
        if(btn) btn.innerHTML = originalHTML;
        if(controls) controls.style.display = 'block';
    };

    // Se agregan 800ms de espera para que el mapa estabilice sus capas antes de la foto
    setTimeout(() => {
        try { map.invalidateSize(true); } catch (e) {}
        obtenerCapturaParaPIV()
            .then((base64Image) => {
                restore();
                startCrop(base64Image, mapaId, step);
            })
            .catch((e) => {
                restore();
                if (e && e.userCancelledCapture) {
                    return;
                }
                alert("Error interno del mapa al capturar: " + e.toString());
            });
    }, 800);
};

window.iniciarCapturaPIV = function(idMapa) {
    if (typeof MI_ROL !== 'undefined' && MI_ROL === 'jefe_faena') {
        abrirGaleriaPIV(idMapa);
        return;
    }

    window.capturarMapaMagico(idMapa);
};

let cropperInstance = null;
let cropMapaId = 0;
let cropStep = 0;

function setupCropper() {
    if (!document.getElementById('cropperModal')) {
        const div = document.createElement('div');
        div.id = 'cropperModal';
        div.style.cssText = 'display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.9); flex-direction:column; align-items:center; justify-content:center;';
        
        // El fondo a #333 y el display:block ayudan a que la imagen no colapse a 0 píxeles
        div.innerHTML = `
            <div style="width:min(90vw, 1400px); height:min(80vh, 900px); background:#333; display:flex; align-items:center; justify-content:center; overflow:hidden; border-radius:8px;">
                <img id="imageToCrop" style="display:block; max-width:100%; max-height:100%;">
            </div>
            <div style="margin-top:15px; display:flex; gap:15px;">
                <button onclick="finishCrop()" style="padding:12px 24px; background:#27ae60; color:white; border:none; border-radius:50px; font-size:16px; cursor:pointer; font-weight:bold; box-shadow:0 4px 10px rgba(0,0,0,0.3);"><i class="fas fa-crop-alt"></i> Guardar Recorte</button>
                <button onclick="closeCrop()" style="padding:12px 24px; background:#c0392b; color:white; border:none; border-radius:50px; font-size:16px; cursor:pointer; font-weight:bold; box-shadow:0 4px 10px rgba(0,0,0,0.3);">Cancelar</button>
            </div>`;
        document.body.appendChild(div);
    }
}

window.startCrop = function(base64, id, step) {
    cropMapaId = id; cropStep = step;
    setupCropper();
    
    const modal = document.getElementById('cropperModal');
    const img = document.getElementById('imageToCrop');
    
    modal.style.display = 'flex';
    
    if (cropperInstance) {
        cropperInstance.destroy();
        cropperInstance = null;
    }
    
    img.onload = null;
    img.onerror = null;
    img.src = '';

    img.onload = function() {
        if (typeof Cropper === 'undefined') { 
            alert("Cargando herramienta de recorte..."); 
            return; 
        }
        cropperInstance = new Cropper(img, { 
            viewMode: 1, 
            autoCropArea: 0.9, 
            responsive: true, 
            background: true,
            ready() {
                this.cropper.resize();
            }
        });
    };

    img.onerror = function() {
        closeCrop();
        alert("No se pudo abrir la imagen capturada para recortarla.");
    };

    img.src = base64;
};

window.closeCrop = function() {
    document.getElementById('cropperModal').style.display = 'none';
    if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
};

window.finishCrop = function() {
    if (!cropperInstance) return;
    const canvas = cropperInstance.getCroppedCanvas({ maxWidth: 1920, maxHeight: 1080, fillColor: '#fff' });
    const finalBase64 = canvas.toDataURL('image/jpeg', 0.9);
    enviarCapturaServidor(finalBase64, cropMapaId, cropStep);
    closeCrop();
};

function enviarCapturaServidor(base64Image, mapaId, step) {
    const payload = { id_mapa: mapaId, imagen: base64Image, numero_foto: step };
    if (!navigator.onLine) {
        try {
            let capturas = JSON.parse(localStorage.getItem('capturas_offline')) || [];
            capturas = capturas.filter(c => !(c.id_mapa === mapaId && c.numero_foto === step));
            capturas.push(payload);
            localStorage.setItem('capturas_offline', JSON.stringify(capturas));
            mostrarMiniaturaFlotante(base64Image);
            setTimeout(() => manejarNavegacionPostCaptura(mapaId, step, true), 500);
        } catch(e) {
            alert("❌ La memoria de tu teléfono está llena. No se puede guardar la foto sin internet.");
        }
        return;
    }
    fetch('guardar_captura_mapa.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(res => res.json())
    .then(data => {
        if (data.success) { mostrarMiniaturaFlotante(base64Image); setTimeout(() => manejarNavegacionPostCaptura(mapaId, step, false), 500); }
        else alert("Error al guardar en el servidor.");
    }).catch(() => {
        let capturas = JSON.parse(localStorage.getItem('capturas_offline')) || [];
        capturas.push(payload);
        localStorage.setItem('capturas_offline', JSON.stringify(capturas));
        mostrarMiniaturaFlotante(base64Image);
        setTimeout(() => manejarNavegacionPostCaptura(mapaId, step, true), 500);
    });
}

function manejarNavegacionPostCaptura(mapaId, step, isOffline) {
    let msgOffline = isOffline ? "\n📵 (Guardado en el teléfono. Se subirá al PDF cuando tengas señal)" : "";
    if (typeof MI_ROL !== 'undefined' && MI_ROL === 'jefe_faena') {
        alert("✅ La foto del mapa ha sido actualizada.\nEl documento PDF ahora mostrará esta nueva imagen." + msgOffline);
        return;
    }
    if (step === 1) {
        if (confirm("✅ Recorte Foto 1 guardado." + msgOffline + "\n¿Quieres ir al formulario PIV ahora?")) {
            setCurrentCaptureStep(mapaId, 1); actualizarBotonCaptura(mapaId);
            window.location.href = 'piv_formulario.php?captura_src=' + mapaId;
        } else { setCurrentCaptureStep(mapaId, 2); actualizarBotonCaptura(mapaId); alert("Perfecto. Toma la Foto 2."); }
    } else {
        setCurrentCaptureStep(mapaId, 1); actualizarBotonCaptura(mapaId);
        window.location.href = 'piv_formulario.php?captura_src=' + mapaId;
    }
}




function mostrarMiniaturaFlotante(base64) {
    let div = document.getElementById('floating-thumb');
    if (!div) {
        div = document.createElement('div');
        div.id = 'floating-thumb';
        div.style.cssText = 'position:fixed; bottom:20px; left:20px; background:white; padding:10px; border-radius:8px; box-shadow:0 4px 15px rgba(0,0,0,0.3); z-index:9999; display:flex; flex-direction:column; align-items:center; gap:8px; border-left: 4px solid #27ae60;';
        document.body.appendChild(div);
    }
    div.innerHTML = `<img src="${base64}" style="width:160px; height:90px; object-fit:cover; border-radius:4px; border:1px solid #eee;"><span style="color:#27ae60; font-weight:bold; font-size:0.9rem;"><i class="fas fa-check-circle"></i> Captura Guardada</span>`;
    div.style.display = 'flex';
    setTimeout(() => { div.style.display = 'none'; }, 4000);
}

actualizarBotonCaptura();
initMap();
