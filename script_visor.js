/* ==========================================================
  Lógica del mapa, GPS y alertas
   ========================================================== */

let map, userMarker, watchId;
let marcadoresPeligro = [];
let alertasSilenciadas = false;
let ultimaPosicion = null;
let lastSoundTime = 0; 



// --- 1. GESTIÓN DE ALERTAS (ON/OFF) ---
window.toggleAlertas = function() {
    alertasSilenciadas = !alertasSilenciadas;
    const btn = document.getElementById('btnToggleAlertas');
    const txt = document.getElementById('txtAlertas');
    const icon = btn.querySelector('i');
    if (alertasSilenciadas) {
        btn.className = "btn-panel btn-alert-off"; icon.className = "fas fa-bell-slash"; txt.innerText = "Alertas: OFF"; document.getElementById('alertas').innerHTML = '';
    } else {
        btn.className = "btn-panel btn-alert-on"; icon.className = "fas fa-bell"; txt.innerText = "Alertas: ON";
        if(ultimaPosicion) checkPeligros(ultimaPosicion[0], ultimaPosicion[1]); 
    }
};

// --- 2. FUNCIÓN DE REPORTE SOS ---
window.reportarUser = function() {
    const msg = prompt("⚠️ REPORTE SOS / EMERGENCIA\n\nDescribe la situación:");
    if(!msg) return;
    
    document.getElementById('loader').style.display = 'block';
    
    if (!navigator.geolocation) {
        document.getElementById('loader').style.display = 'none';
        alert("GPS no disponible en este dispositivo.");
        return;
    }

    navigator.geolocation.getCurrentPosition(p => {
       
        const idDestino = (typeof MAPA_ID_ACTUAL !== 'undefined' && MAPA_ID_ACTUAL > 0) ? MAPA_ID_ACTUAL : 1;

        saveMarker(
            {lat: p.coords.latitude, lng: p.coords.longitude}, 
            "🚨 SOS - OPERADOR", 
            msg, 
            "Critico", 
            20, 
            idDestino, 
            "✅ SOS Enviado con éxito" 
        );
    }, err => {
        document.getElementById('loader').style.display = 'none';
        alert("Error GPS: " + err.message + ". Verifica permisos.");
    }, {
        enableHighAccuracy: true,
        timeout: 30000 // 30 segundos
    });
};

// --- 3. GUARDAR MARCADOR ---
function saveMarker(ll, nom, desc, nivel, radio = 0, id_destino = 0, mensajePersonalizado = "✅ Acción realizada") {

    if (id_destino === 0) { 
        const selector = document.getElementById('selectorMapaForm'); 
        if (selector && selector.value) {
            id_destino = selector.value;
        } else {
 
            id_destino = (typeof MAPA_ID_ACTUAL !== 'undefined' && MAPA_ID_ACTUAL > 0) ? MAPA_ID_ACTUAL : 1;
        }
    }
    
    const data = { 
        action: 'add_marker', 
        lat: ll.lat, 
        lng: ll.lng, 
        nombre: nom, 
        descripcion: desc, 
        nivel: nivel, 
        radio: radio, 
        id_mapa: id_destino 
    };
    
    fetch('Api/api_mapa.php', { method:'POST', body:JSON.stringify(data) })
    .then(r=>r.json())
    .then(res=>{
        document.getElementById('loader').style.display = 'none';
        if(res.success) { 
            alert(mensajePersonalizado); 
            location.reload(); 
        }
        else alert("Error del servidor: " + (res.error || 'Desconocido'));
    })
    .catch(err => {
        document.getElementById('loader').style.display = 'none';
        alert("Error de conexión: No se pudo enviar el reporte.");
        console.error(err);
    });
}

// --- 4. BORRAR MARCADOR ---
window.borrarMarcador = function(id) {
    if(confirm("¿Eliminar este reporte?")) fetch('Api/api_mapa.php', { method: 'POST', body: JSON.stringify({ action: 'delete_marker', id: id }) }).then(r => r.json()).then(res => { if(res.success) location.reload(); });
};

// --- 5. INICIAR RASTREO GPS ---
function iniciarRastreoGPS() {
    if (!navigator.geolocation) { console.warn("GPS no soportado"); return; }
    
    watchId = navigator.geolocation.watchPosition(p => {
        const lat = p.coords.latitude;
        const lng = p.coords.longitude;
        ultimaPosicion = [lat, lng];

        if(userMarker) {
            userMarker.setLatLng([lat, lng]);
        } else if(map) {
            userMarker = L.circleMarker([lat, lng], { radius: 8, fillColor: "#3498db", color: "#fff", weight: 2, fillOpacity: 1 }).addTo(map).bindPopup("<b>Estás aquí</b>");
        }
        
        checkPeligros(lat, lng);

    }, err => { console.error("Error GPS", err); }, { enableHighAccuracy: true, maximumAge: 10000, timeout: 5000 });
}

// --- 6. CENTRAR EN USUARIO ---
window.centrarEnUsuario = function() {
    if(ultimaPosicion && map) {
        map.setView(ultimaPosicion, 16, {animate: true});
        if(userMarker) userMarker.openPopup();
    } else {
        alert("Buscando señal GPS... espere un momento.");
    }
}

// --- 7. INICIALIZAR MAPA ---
function initMap() {
    map = L.map('map', { zoomControl: false }).setView([-35.4, -72.0], 9);
    L.control.zoom({ position: 'bottomright' }).addTo(map);

    // --- CAMBIO: Capas OpenSource ---
    
    // 1. Capa Satélite (Usamos Esri World Imagery, es gratis)
    const satelite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community',
        maxZoom: 19
    }).addTo(map);

    // 2. Capa Mapa/Calles (Usamos OpenStreetMap estándar)
    const calles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19
    });

    const layerManuales = L.layerGroup().addTo(map); 
    const layerFondo = L.featureGroup().addTo(map);
    L.control.layers({ "Satélite": satelite, "Mapa": calles }, { "Reportes": layerManuales, "Capa de Fondo": layerFondo }).addTo(map);

    // Cargar Mapas GeoJSON si existen
    if (typeof LISTA_MAPAS !== 'undefined' && LISTA_MAPAS.length > 0) {
        if(IS_ADMIN) {
            const sel = document.getElementById('selectorMapaForm');
            if(sel) {
                sel.style.display = 'block'; sel.innerHTML = '';
                let defaultOpt = document.createElement('option'); 
                defaultOpt.value = (typeof MAPA_ID_ACTUAL !== 'undefined') ? MAPA_ID_ACTUAL : 1; 
                defaultOpt.text = (typeof MAPA_ID_ACTUAL !== 'undefined' && MAPA_ID_ACTUAL > 0) ? "-- Mapa Actual --" : "-- Capa General --"; 
                sel.appendChild(defaultOpt);
                
                LISTA_MAPAS.forEach(m => { 
                    let opt = document.createElement('option'); 
                    opt.value = m.id_mapa; 
                    opt.text = "📍 " + m.nombre_mapa; 
                    if(typeof MAPA_ID_ACTUAL !== 'undefined' && m.id_mapa == MAPA_ID_ACTUAL) opt.selected = true; 
                    sel.appendChild(opt); 
                });
            }
        }
        LISTA_MAPAS.forEach(datosMapa => {
            if(!datosMapa.ruta_archivo) return;
            fetch(datosMapa.ruta_archivo).then(r => r.json()).then(data => {
                const capa = L.geoJSON(data, {
                    style: feature => {
                        if (feature.geometry.type.includes('Polygon')) return { fillColor: '#E0A9E0', color: '#800080', weight: 2, fillOpacity: 0.5 };
                        return { color: '#FF0000', weight: 1 };
                    },
                    pointToLayer: (feature, latlng) => L.circleMarker(latlng, { radius: 5, fillColor: "#FF0000", color: "#fff", weight: 1, fillOpacity: 1 }),
                    onEachFeature: (f, l) => { l.bindPopup("<b>Mapa:</b> " + datosMapa.nombre_mapa); }
                });
                layerFondo.addLayer(capa);
                if(typeof MAPA_ID_ACTUAL !== 'undefined' && MAPA_ID_ACTUAL == datosMapa.id_mapa) map.fitBounds(capa.getBounds());
            }).catch(e => console.error(e));
        });
    }

    // --- 8. CARGAR DATOS DE API ---
    function cargarDatos() {
        fetch('Api/api_mapa.php?action=fetch_markers').then(r => r.json()).then(res => {
            if (res.success && res.markers) {
                const isAdmin = res.is_admin;
                res.markers.forEach(row => {
                    try {
                        if (!row.geojson) return;

                        if (!isAdmin && typeof MAPA_ID_ACTUAL !== 'undefined' && MAPA_ID_ACTUAL > 0 && row.id_mapa != MAPA_ID_ACTUAL && row.id_mapa != 1) return; 

                        const geom = JSON.parse(row.geojson);
                        const props = { ...row }; delete props.geojson;
                        
                        if (geom.type === 'Point') {
                            const m = L.marker([geom.coordinates[1], geom.coordinates[0]], { 
                                icon: L.icon({ iconUrl: props.icono_url, iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34] }) 
                            });
                            
                            m.radio_custom = parseFloat(props.radio_metros) || 0;
                            m.nombre_alerta = props.nombre;

                            let html = `<div style='text-align:center;'><h3 style='margin:0;color:#2c3e50;font-size:1rem'>${props.nombre}</h3><p>${props.descripcion || ''}</p>${m.radio_custom > 0 ? `<p><b>Radio:</b> ${m.radio_custom}m</p>` : ''}`;
                            if(isAdmin) html += `<button onclick="borrarMarcador(${props.id})" style="background:#e74c3c; color:white; border:none; padding:5px; cursor:pointer;">Eliminar</button>`;
                            html += `</div>`;
                            m.bindPopup(html);
                            layerManuales.addLayer(m);
                            marcadoresPeligro.push(m);

                            if (m.radio_custom > 0) {
                                L.circle([geom.coordinates[1], geom.coordinates[0]], { radius: m.radio_custom, color: '#e74c3c', fillColor: '#c0392b', fillOpacity: 0.3, weight: 1 }).addTo(layerManuales);
                            }
                        }
                    } catch(e){}
                });
                
                if(ultimaPosicion) checkPeligros(ultimaPosicion[0], ultimaPosicion[1]);
            }
        });
    }

    // --- 9. EVENTOS DE UI ---
    const form = document.getElementById('markerFormContainer');
    let currentLatLng;
    document.getElementById('cancelMarker').onclick = () => { form.style.display='none'; document.getElementById('markerForm').reset(); };

    map.on('contextmenu', function(e) {
        if (!IS_ADMIN) return; 
        currentLatLng = e.latlng;
        if(navigator.vibrate) navigator.vibrate(50);
        form.style.display = 'block';
    });

    document.getElementById('markerForm').onsubmit = (e) => {
        e.preventDefault(); 
        if (!currentLatLng) return;
        const radioVal = document.getElementById('popupRadio').value;
        
        // VALIDACIÓN RADIO (Max Integer)
        if(radioVal > 2147483647) { alert("El radio excede el límite permitido."); return; }

        saveMarker(
            currentLatLng, 
            document.getElementById('popupNombre').value, 
            document.getElementById('popupDesc').value, 
            document.getElementById('popupIcon').value, 
            radioVal ? parseInt(radioVal) : 0, 
            0,
            "✅ Reporte creado correctamente" 
        );
        form.style.display='none';
    };
    
    // Botón borrar todo
    const btnBorrar = document.getElementById('borrarMarcadores');
    if(btnBorrar) {
        btnBorrar.onclick = () => { 
            if(confirm("⚠️ ¿RESETEAR TODO EL SISTEMA?")) fetch('Api/api_mapa.php?action=delete_all', { method: 'POST' }).then(() => location.reload()); 
        };
    }

    // --- 10. LÓGICA DE DETECCIÓN DE PELIGRO ---
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
                divAlertas.innerHTML += `<div class="alerta-card"><i class="fas fa-exclamation-triangle"></i><br>⚠️ ¡PELIGRO DENTRO DEL ÁREA!<br><span style="font-size:0.9rem;">${m.nombre_alerta}</span><br><small>Estás a ${Math.round(distancia)}m del centro</small></div>`;
            }
        });

        divAlertas.style.display = dangerDetected ? 'block' : 'none';

        if(dangerDetected && !IS_ADMIN) {
            const now = Date.now();
            // Loop de sonido cada 5 segundos
            if (now - lastSoundTime > 5000) { 
                const audio = document.getElementById('alertaAudio');
                audio.currentTime = 0; 
                audio.play().catch(()=>{});
                lastSoundTime = now; 
            }
        }
    }

    // Arrancar todo
    cargarDatos();
    iniciarRastreoGPS();
    
    // Intervalo de revisión constante
    setInterval(() => {
        if(ultimaPosicion) {
            checkPeligros(ultimaPosicion[0], ultimaPosicion[1]);
        }
    }, 1000); 
}