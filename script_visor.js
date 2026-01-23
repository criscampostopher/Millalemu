/* ==========================================================
  Lógica del Visor: Mapas, GPS, Alertas y Offline (FIX V3)
   ========================================================== */

let map, userMarker, watchId;
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

    const satelite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: 'Tiles &copy; Esri', maxZoom: 19 }).addTo(map);
    const calles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap', maxZoom: 19 });

    layerFondo = L.featureGroup().addTo(map);    
    layerManuales = L.layerGroup().addTo(map);   
    L.control.layers({ "Satélite": satelite, "Mapa": calles }, { "Alertas Visibles": layerManuales }).addTo(map);

    renderizarMenuCapas();
    cargarDatosDeAlertas(); 
    iniciarRastreoGPS();
    setupUIEvents();
    setupServiceWorkerListener();
    
    // Activar Capa General (ID 1) por defecto para recuperar la "Capa Lógica"
    // Buscamos si existe en la lista y la activamos virtualmente
    if (typeof LISTA_MAPAS !== 'undefined') {
        const capaGeneral = LISTA_MAPAS.find(m => m.id_mapa == 1);
        if (capaGeneral) {
            console.log("Activando Capa Lógica (General) por defecto");
            // No la marcamos visualmente en el checkbox para no confundir, 
            // o forzamos la carga visual:
            cargarCapaVisual(capaGeneral); 
            // Si quieres que el checkbox aparezca marcado, habría que buscarlo en el DOM, 
            // pero con cargarla internamente basta para ver las alertas.
        }
    }

    setInterval(() => { if(ultimaPosicion) checkPeligros(ultimaPosicion[0], ultimaPosicion[1]); }, 1000); 
}

// --- 2. RENDERIZADOR DE MENÚ DE CAPAS ---
// --- 2. RENDERIZADOR DE MENÚ DE CAPAS (CON ACORDEÓN) ---
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

        // Verificamos si alguna capa de esta zona está activa para decidir si la mostramos abierta
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
                    <button class="btn-download" onclick="descargarZona(${idZona})" title="Descargar Zona">
                        <i class="fas fa-download"></i>
                    </button>
                </div>
            </div>
        `;

        const divCapas = document.createElement('div');
        divCapas.className = 'zone-layers';
        divCapas.id = `layers-zone-${idZona}`; // ID único para controlarlo
        divCapas.style.display = displayStyle; // Estado inicial

        zona.mapas.forEach(mapa => {
            const isRadio = (mapa.es_excluyente === true || mapa.es_excluyente === "t");
            const inputType = isRadio ? 'radio' : 'checkbox';
            const nameGroup = isRadio ? `zona_${idZona}` : `mapa_${mapa.id_mapa}`;
            const isChecked = (typeof MAPA_ID_ACTUAL !== 'undefined' && MAPA_ID_ACTUAL == mapa.id_mapa);

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
            if (isChecked) gestionarCapa(input, mapa, nameGroup);
        });

        divZona.appendChild(divCapas);
        container.appendChild(divZona);
    });
}

// --- FUNCIÓN NUEVA: ABRIR/CERRAR ACORDEÓN ---
window.toggleZona = function(idZona) {
    const layersDiv = document.getElementById(`layers-zone-${idZona}`);
    const chevron = document.getElementById(`chevron-${idZona}`);
    const header = chevron.closest('.zone-header');

    if (layersDiv.style.display === 'none') {
        // ABRIR
        layersDiv.style.display = 'block';
        header.classList.add('active');
        chevron.classList.remove('fa-chevron-right');
        chevron.classList.add('fa-chevron-down');
    } else {
        // CERRAR
        layersDiv.style.display = 'none';
        header.classList.remove('active');
        chevron.classList.remove('fa-chevron-down');
        chevron.classList.add('fa-chevron-right');
    }
};

// --- 3. GESTIÓN VISUAL DE CAPAS (FIX "CAPA LÓGICA") ---
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

function cargarCapaVisual(mapaDatos) {
    const id = mapaDatos.id_mapa;
    if (capasActivas[id]) return; 

    // --- CORRECCIÓN CLAVE: SOPORTE PARA CAPAS SIN ARCHIVO (MANUALES) ---
    // Si la ruta es "manual" o vacía, es una Capa Lógica (solo contenedora de alertas)
    if (mapaDatos.ruta_archivo === 'manual' || !mapaDatos.ruta_archivo) {
        // Activamos "virtualmente" la capa para que sus alertas se muestren
        capasActivas[id] = { type: 'logic_only' };
        console.log(`Capa Lógica activada: ${mapaDatos.nombre_mapa}`);
        actualizarAlertasVisibles();
        return; 
    }

    // Carga normal de archivos (KML/GeoJSON)
    const ruta = mapaDatos.ruta_archivo;
    const estiloComun = {
        style: feature => {
            if (feature.geometry.type.includes('Polygon')) return { fillColor: '#E0A9E0', color: '#800080', weight: 2, fillOpacity: 0.5 };
            return { color: '#FF0000', weight: 2 };
        },
        pointToLayer: (f, ll) => L.circleMarker(ll, { radius: 5, fillColor: "#FF0000", color: "#fff", weight: 1, fillOpacity: 1 }),
        onEachFeature: (f, l) => { l.bindPopup("<b>" + mapaDatos.categoria + ":</b> " + mapaDatos.nombre_mapa); }
    };

    if (ruta.toLowerCase().endsWith('.kml')) {
        const layer = omnivore.kml(ruta, null, L.geoJSON(null, estiloComun));
        layer.on('ready', function() {
            layerFondo.addLayer(this);
            if(!ultimaPosicion) map.fitBounds(this.getBounds());
        });
        capasActivas[id] = layer;
    } else {
        fetch(ruta).then(r => r.json()).then(data => {
            const layer = L.geoJSON(data, estiloComun);
            layerFondo.addLayer(layer);
            if(!ultimaPosicion) map.fitBounds(layer.getBounds());
            capasActivas[id] = layer;
        }).catch(e => console.error("Error cargando capa:", e));
    }
    actualizarAlertasVisibles();
}

function removerCapaVisual(id) {
    if (capasActivas[id]) {
        // Si no es lógica pura, la removemos del mapa
        if (capasActivas[id].type !== 'logic_only') {
            layerFondo.removeLayer(capasActivas[id]);
        }
        delete capasActivas[id];
        actualizarAlertasVisibles();
    }
}

// --- 4. GESTIÓN DE ALERTAS (Actualización sin recarga) ---
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
                        m.radio_custom = parseFloat(props.radio_metros) || 0;
                        m.nombre_alerta = props.nombre;
                        m.id_mapa_asociado = props.id_mapa;
                        m.id_db = props.id;

                        let html = `<div style='text-align:center;'><h3 style='margin:0;color:#2c3e50;font-size:1rem'>${props.nombre}</h3><p>${props.descripcion || ''}</p>${m.radio_custom > 0 ? `<p><b>Radio:</b> ${m.radio_custom}m</p>` : ''}`;
                        if(isAdmin) html += `<button onclick="borrarMarcador(${props.id_db})" style="background:#e74c3c; color:white; border:none; padding:5px; cursor:pointer;">Eliminar</button>`;
                        html += `</div>`;
                        m.bindPopup(html);
                        marcadoresPeligro.push(m);
                    }
                } catch(e){}
            });
            actualizarAlertasVisibles();
        }
    });
}

function actualizarAlertasVisibles() {
    layerManuales.clearLayers(); 
    marcadoresPeligro.forEach(m => {
        const idMapa = m.id_mapa_asociado;
        // MOSTRAR SI: La capa está activa (Checkbox) O es el mapa 1 (General)
        // La condición '|| idMapa == 1' asegura que el mapa General siempre se vea si no se ha desactivado explícitamente, 
        // pero con la lógica nueva de 'capasActivas', si el usuario activa el checkbox de 'Capa General', entra en capasActivas.
        if (capasActivas[idMapa]) {
            layerManuales.addLayer(m);
            if (m.radio_custom > 0) {
                L.circle(m.getLatLng(), { radius: m.radio_custom, color: '#e74c3c', fillColor: '#c0392b', fillOpacity: 0.3, weight: 1 }).addTo(layerManuales);
            }
        }
    });
}

function checkPeligros(lat, lng) {
    if (IS_ADMIN) return; 
    if (alertasSilenciadas) return;
    const divAlertas = document.getElementById('alertas'); divAlertas.innerHTML = '';
    let dangerDetected = false;
    
    marcadoresPeligro.forEach(m => {
        const distancia = map.distance([lat, lng], m.getLatLng());
        const radioAlerta = (m.radio_custom > 0) ? m.radio_custom : 15; 
        if(distancia <= radioAlerta) {
            dangerDetected = true;
            divAlertas.innerHTML += `<div class="alerta-card"><i class="fas fa-exclamation-triangle"></i><br>⚠️ ¡PELIGRO!<br><span style="font-size:0.9rem;">${m.nombre_alerta}</span><br><small>A ${Math.round(distancia)}m</small></div>`;
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
    LISTA_MAPAS.forEach(m => {
        if (m.id_zona == idZona && m.ruta_archivo && m.ruta_archivo !== 'manual') {
            urls.push(m.ruta_archivo);
        }
    });

    if (urls.length > 0) {
        document.getElementById('loader').style.display = 'block';
        setTimeout(() => { if(document.getElementById('loader').style.display === 'block') document.getElementById('loader').style.display = 'none'; }, 8000);

        if (navigator.serviceWorker && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({ action: 'CACHE_NEW_ZONE', urls: urls, zoneId: idZona });
        } else {
            alert("⚠️ El sistema offline se está actualizando. Recarga la página.");
            document.getElementById('loader').style.display = 'none';
        }
    } else {
        alert("Esta zona no tiene mapas descargables.");
    }
};

function setupServiceWorkerListener() {
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', event => {
            if(event.data.action === 'ZONE_CACHED_OK'){
                document.getElementById('loader').style.display = 'none';
                alert("✅ Zona descargada.");
                const badge = document.getElementById('offline-badge-' + event.data.zoneId);
                if(badge) { badge.innerHTML = '<i class="fas fa-check-circle" style="color:#27ae60;"></i> Offline'; }
            }
        });
    }
}

// --- 6. EVENTOS DE UI (Guardado SIN Recarga) ---
function setupUIEvents() {
    const form = document.getElementById('markerFormContainer');
    let currentLatLng;
    document.getElementById('cancelMarker').onclick = () => { form.style.display='none'; document.getElementById('markerForm').reset(); };

    map.on('contextmenu', function(e) {
        if (!IS_ADMIN) return; 
        currentLatLng = e.latlng;
        if(navigator.vibrate) navigator.vibrate(50);
        
        const selector = document.getElementById('selectorMapaForm');
        if(selector) {
            selector.innerHTML = ''; // Limpiar opciones anteriores
            if(typeof LISTA_MAPAS !== 'undefined' && Array.isArray(LISTA_MAPAS)) {
                LISTA_MAPAS.forEach(m => {
                    let opt = document.createElement('option');
                    opt.value = m.id_mapa;
                    opt.text = `[${m.categoria}] ${m.nombre_mapa}`;
                    if (m.id_mapa == 1) opt.selected = true; // Preseleccionar Capa General
                    selector.appendChild(opt);
                });
            }
        }
        form.style.display = 'block';
    });

    document.getElementById('markerForm').onsubmit = (e) => {
        e.preventDefault(); 
        if (!currentLatLng) return;
        
        const radioVal = document.getElementById('popupRadio').value;
        const nombre = document.getElementById('popupNombre').value;
        const desc = document.getElementById('popupDesc').value;
        const nivel = document.getElementById('popupIcon').value;
        const idMapaSeleccionado = document.getElementById('selectorMapaForm').value; 

        saveMarker(currentLatLng, nombre, desc, nivel, radioVal, idMapaSeleccionado); 
        form.style.display='none';
    };
    
    // Botón borrar (SIN Recarga)
    const btnBorrar = document.getElementById('borrarMarcadores');
    if(btnBorrar) btnBorrar.onclick = () => { if(confirm("¿RESETEAR TODO?")) fetch('Api/api_mapa.php?action=delete_all', { method: 'POST' }).then(() => { cargarDatosDeAlertas(); alert("Sistema reseteado."); }); };
}

window.borrarMarcador = function(id) {
    if(confirm("¿Eliminar reporte?")) {
        fetch('Api/api_mapa.php', { method: 'POST', body: JSON.stringify({ action: 'delete_marker', id: id }) })
        .then(r => r.json())
        .then(res => { 
            if(res.success) {
                // Actualizamos visualización sin recargar
                cargarDatosDeAlertas(); 
            }
        });
    }
};

window.saveMarker = function(ll, nom, desc, nivel, radio, id_destino = 0) {
    const data = { action: 'add_marker', lat: ll.lat, lng: ll.lng, nombre: nom, descripcion: desc, nivel: nivel, radio: radio, id_mapa: id_destino };
    
    fetch('Api/api_mapa.php', { method:'POST', body:JSON.stringify(data) })
    .then(r=>r.json())
    .then(res=>{ 
        if(res.success) { 
            // ÉXITO: Recargamos solo los datos de alertas
            cargarDatosDeAlertas(); 
            alert("✅ Alerta guardada correctamente.");
        } else {
            alert("Error al guardar: " + (res.error || 'Desconocido'));
        }
    })
    .catch(e => alert("Error de conexión"));
};

window.reportarUser = function() {
    const msg = prompt("⚠️ REPORTE SOS");
    if(!msg) return;
    navigator.geolocation.getCurrentPosition(p => saveMarker({lat: p.coords.latitude, lng: p.coords.longitude}, "🚨 SOS", msg, "Critico", 20, 1), null, {enableHighAccuracy:true});
};

window.toggleAlertas = function() {
    alertasSilenciadas = !alertasSilenciadas;
    const btn = document.getElementById('btnToggleAlertas');
    if (alertasSilenciadas) {
        btn.className = "btn-panel btn-alert-off"; btn.innerHTML = '<i class="fas fa-bell-slash"></i> <span id="txtAlertas">Alertas: OFF</span>'; document.getElementById('alertas').innerHTML = '';
    } else {
        btn.className = "btn-panel btn-alert-on"; btn.innerHTML = '<i class="fas fa-bell"></i> <span id="txtAlertas">Alertas: ON</span>';
        if(ultimaPosicion) checkPeligros(ultimaPosicion[0], ultimaPosicion[1]); 
    }
};

window.iniciarRastreoGPS = function() {
    if (!navigator.geolocation) { console.warn("GPS no soportado"); return; }
    watchId = navigator.geolocation.watchPosition(p => {
        const lat = p.coords.latitude; const lng = p.coords.longitude;
        ultimaPosicion = [lat, lng];
        if(userMarker) userMarker.setLatLng([lat, lng]);
        else if(map) userMarker = L.circleMarker([lat, lng], { radius: 8, fillColor: "#3498db", color: "#fff", weight: 2, fillOpacity: 1 }).addTo(map).bindPopup("<b>Estás aquí</b>");
        checkPeligros(lat, lng);
    }, err => console.error("Error GPS", err), { enableHighAccuracy: true, maximumAge: 10000, timeout: 5000 });
};

window.centrarEnUsuario = function() {
    if(ultimaPosicion && map) { map.setView(ultimaPosicion, 16, {animate: true}); if(userMarker) userMarker.openPopup(); }
    else alert("Buscando señal GPS...");
};

initMap();