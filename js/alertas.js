// js/alertas.js
const alertMarkers = {};        // Para almacenar los marcadores en el mapa
let selectionMode = false;      // Modo selección de alertas
let selectedForDistance = [];   // Array para las alertas seleccionadas

// -----------------------------
// Función para añadir alerta al mapa
// -----------------------------
window.addAlertMarker = function(a) {
  if (!a.lat || !a.lng || !a.id) return;

  const latlng = [parseFloat(a.lat), parseFloat(a.lng)];

  // Icono tipo Google Maps
  const icon = L.icon({
    iconUrl: 'https://cdn-icons-png.flaticon.com/512/684/684908.png', 
    iconSize: [40, 40],
    iconAnchor: [20, 40],
    popupAnchor: [0, -40]
  });

  const marker = L.marker(latlng, { icon: icon, riseOnHover: true });
  marker._alertId = a.id;
  alertMarkers[a.id] = marker;

  // Contenido del popup
  const imgThumb = a.imagen ? `
    <div style="float:left;margin-right:8px">
      <img src="${a.imagen}" style="width:64px;height:64px;object-fit:cover;border-radius:6px;">
    </div>` : '';
  const html = `${imgThumb}<strong>${a.nombre}</strong><br>${a.descripcion || ''}<br>
    <button onclick="window.calcularDistanciaConMiPos('${a.id}')">Distancia a mi ubicación</button>`;

  marker.bindPopup(html);

  // Abrir popup al pasar cursor
  marker.on('mouseover', () => marker.openPopup());
  marker.on('mouseout', () => marker.closePopup());

  // Click para selección o abrir popup
  marker.on('click', () => {
    if (selectionMode) {
      toggleSelectMarker(a.id, marker);
    } else {
      marker.openPopup();
    }
  });

  marker.addTo(window._MM.map);
};

// -----------------------------
// Cargar todas las alertas guardadas
// -----------------------------
window.cargarAlertas = async function() {
  try {
    const res = await fetch('mapas/listarAlertasGuardadas.php');
    const alertas = await res.json();

    // Limpiar marcadores existentes
    Object.values(alertMarkers).forEach(m => { 
      try { window._MM.map.removeLayer(m); } catch(e){} 
    });
    Object.keys(alertMarkers).forEach(k => delete alertMarkers[k]);

    alertas.forEach(a => window.addAlertMarker(a));
    console.log('Alertas cargadas:', Object.keys(alertMarkers).length);
  } catch (err) {
    console.error('Error cargarAlertas:', err);
  }
};

// -----------------------------
// Toggle selección para distancia
// -----------------------------
function toggleSelectMarker(id, marker) {
  const idx = selectedForDistance.indexOf(id);
  if (idx === -1) {
    if (selectedForDistance.length >= 2) return alert('Solo puedes seleccionar 2 alertas a la vez');
    selectedForDistance.push(id);
    const el = marker.getElement && marker.getElement();
    if (el) el.classList.add('selected-marker');
  } else {
    selectedForDistance.splice(idx,1);
    const el = marker.getElement && marker.getElement();
    if (el) el.classList.remove('selected-marker');
  }

  if (selectedForDistance.length === 2) {
    const mA = alertMarkers[selectedForDistance[0]];
    const mB = alertMarkers[selectedForDistance[1]];
    if (mA && mB) {
      const d = window._MM.map.distance(mA.getLatLng(), mB.getLatLng());
      document.getElementById('infoDistancia').textContent = `Distancia: ${d.toFixed(1)} m`;
    }
  } else {
    document.getElementById('infoDistancia').textContent = '';
  }
}

// -----------------------------
// Activar/desactivar modo selección
// -----------------------------
window.toggleSelectionMode = function() {
  selectionMode = !selectionMode;
  selectedForDistance = [];

  Object.values(alertMarkers).forEach(m => {
    const el = m.getElement && m.getElement();
    if (el) el.classList.remove('selected-marker');
  });

  document.getElementById('infoDistancia').textContent = selectionMode ? 'Modo selección activo' : '';
};

// -----------------------------
// Calcular distancia con mi ubicación
// -----------------------------
window.calcularDistanciaConMiPos = function(alertId) {
  const m = alertMarkers[alertId];
  if (!m) return alert('Alerta no encontrada');
  const myMarker = window._MM.myLocationMarker;
  if (!myMarker) return alert('Mi ubicación no activa');

  const d = window._MM.map.distance(myMarker.getLatLng(), m.getLatLng());
  document.getElementById('infoDistancia').textContent = `Distancia a la alerta: ${d.toFixed(1)} m`;
};
