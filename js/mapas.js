// mapas.js -> inicialización (si ya existe, mantén la tuya y añade las funciones que siguen)
window._MM = window._MM || {};
if (!window._MM.map) {
  window._MM.map = L.map('map').setView([-33.45, -70.66], 13);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(window._MM.map);
}

// variable para controlar la capa KML actualmente mostrada
window._MM.currentKmlLayer = null;

/**
 * mostrarMapa(nombre)
 *   - nombre: string (ej. "mi_mapa.kml")
 *   - intenta cargar mapas/uploads/<nombre>, verifica existencia y lo muestra
 */
window.mostrarMapa = async function(nombre) {
  if (!nombre) {
    console.warn('mostrarMapa: nombre vacío');
    return alert('Selecciona un mapa primero');
  }
  const ruta = `mapas/uploads/${nombre}`;

  // 1) verificar que el archivo existe (obtener HEAD o GET pequeño)
  try {
    const res = await fetch(ruta, { method: 'GET' });
    if (!res.ok) {
      console.error('Archivo KML no accesible, status:', res.status);
      return alert(`No se puede acceder al archivo KML: ${res.status}`);
    }
  } catch (err) {
    console.error('Error al verificar ruta KML:', err);
    return alert('Error de red al verificar el KML. Revisa la consola.');
  }

  // 2) quitar KML anterior si existe
  if (window._MM.currentKmlLayer) {
    try {
      window._MM.map.removeLayer(window._MM.currentKmlLayer);
    } catch (e) { /* ignore */ }
    window._MM.currentKmlLayer = null;
  }

  // 3) cargar con omnivore (manejo de eventos ready + error)
  try {
    const kmlLayer = omnivore.kml(ruta)
      .on('ready', function() {
        // si la capa es un featureGroup, obtener bounds y ajustar
        try {
          const layerBounds = this.getBounds && this.getBounds();
          if (layerBounds && layerBounds.isValid && layerBounds.isValid()) {
            window._MM.map.fitBounds(layerBounds, { padding: [20,20] });
          } else {
            // si no tiene bounds, centrar en primer punto (fallback)
            const layerCenter = this.getLayers && this.getLayers()[0] && this.getLayers()[0].getLatLng && this.getLayers()[0].getLatLng();
            if (layerCenter) window._MM.map.setView(layerCenter, 14);
          }
        } catch(e) {
          console.warn('fitBounds fallo:', e);
        }
      })
      .on('error', function(err) {
        console.error('omnivore error al cargar KML:', err);
        alert('Error al parsear el KML. Revisa la consola y el archivo KML.');
      })
      .addTo(window._MM.map);

    // guardar referencia para poder eliminarla luego
    window._MM.currentKmlLayer = kmlLayer;
    console.log('KML cargado:', ruta);
  } catch (err) {
    console.error('Error al cargar KML con omnivore:', err);
    alert('Error al cargar KML. Mira la consola.');
  }
};

/**
 * cargarListaMapas() - opcional: mueve la lógica de listar para centralizar en mapas.js
 * Si ya la tienes en index.php, puedes omitir integrarla, pero es útil tenerla aquí.
 */
window.cargarListaMapas = async function() {
  try {
    const res = await fetch('mapas/listarMapas.php');
    const mapas = await res.json();
    const select = document.getElementById('listaMapas');
    if (!select) return console.warn('No existe <select id="listaMapas">');
    select.innerHTML = '';
    mapas.forEach(nombre => {
      const option = document.createElement('option');
      option.value = nombre;
      option.textContent = nombre;
      select.appendChild(option);
    });
    console.log('Lista de mapas cargada:', mapas);
  } catch (err) {
    console.error('Error al listar mapas:', err);
    alert('No se pudo cargar la lista de mapas. Revisa la consola.');
  }
};
// js/mapas.js
window._MM = window._MM || {};
if (!window._MM.map) {
  window._MM.map = L.map('map').setView([-33.45, -70.66], 13);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(window._MM.map);
}

// long-press 3s
(function enableLongPress(){
  let pressTimer = null;
  let startLatLng = null;

  window._MM.map.on('mousedown touchstart', function(e){
    startLatLng = e.latlng;
    pressTimer = setTimeout(()=> {
      if (window.openCrearAlertaModal) window.openCrearAlertaModal(startLatLng);
      else console.warn('openCrearAlertaModal no definida (js/uiAlertas.js)');
    }, 3000);
  });

  function cancel() { if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; } }

  window._MM.map.on('mouseup touchend touchcancel mouseleave', cancel);
})();