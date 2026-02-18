// js/uiAlertas.js
window.openCrearAlertaModal = function(latlng) {
  // quitar modal anterior si existe
  const prev = document.getElementById('alertOverlay');
  if (prev) { prev.remove(); const m = document.getElementById('alertModal'); if(m) m.remove(); }

  const overlay = document.createElement('div');
  overlay.className = 'alert-overlay';
  overlay.id = 'alertOverlay';
  overlay.style.position = 'fixed';
  overlay.style.inset = '0';
  overlay.style.background = 'rgba(0,0,0,0.35)';
  overlay.style.zIndex = 2000;

  const modal = document.createElement('div');
  modal.className = 'alert-modal';
  modal.id = 'alertModal';
  modal.style.position = 'fixed';
  modal.style.left = '50%';
  modal.style.top = '50%';
  modal.style.transform = 'translate(-50%,-50%)';
  modal.style.background = '#fff';
  modal.style.padding = '12px';
  modal.style.borderRadius = '8px';
  modal.style.zIndex = 2001;
  modal.style.width = '320px';
  modal.innerHTML = `
    <h3>Crear Alerta</h3>
    <label>Nombre</label><br>
    <input type="text" id="alertName" /><br>
    <label>Descripción</label><br>
    <textarea id="alertDesc" rows="3"></textarea><br>
    <label>Imagen (opcional)</label><br>
    <input type="file" id="alertImage" accept="image/*" /><br>
    <small>Lat: ${latlng.lat.toFixed(6)}, Lng: ${latlng.lng.toFixed(6)}</small>
    <div style="text-align:right; margin-top:8px;">
      <button id="cancelAlert">Cancelar</button>
      <button id="saveAlert">Guardar</button>
    </div>
  `;

  document.body.appendChild(overlay);
  document.body.appendChild(modal);

  document.getElementById('cancelAlert').onclick = () => { modal.remove(); overlay.remove(); };

  // Dentro de js/uiAlertas.js -> reemplaza la parte saveAlert.onclick por esto
document.getElementById('saveAlert').onclick = async () => {
  const name = document.getElementById('alertName').value.trim();
  const desc = document.getElementById('alertDesc').value.trim();
  const fileInput = document.getElementById('alertImage');

  if (!name) return alert('Ingresa un nombre para la alerta');

  const formData = new FormData();
  formData.append('nombre', name);
  formData.append('descripcion', desc);
  formData.append('lat', latlng.lat);
  formData.append('lng', latlng.lng);
  if (fileInput.files[0]) formData.append('imagen', fileInput.files[0]);

  try {
    const res = await fetch('mapas/subirAlerta.php', { method: 'POST', body: formData });
    const text = await res.text();
    console.log('Respuesta cruda subirAlerta.php:', text);
    let data;
    try { data = JSON.parse(text); } catch(e) {
      alert('Respuesta inválida del servidor. Revisa consola y mapas/debug.log');
      modal.remove(); overlay.remove();
      return;
    }
    alert(data.message || 'Respuesta recibida');
    modal.remove(); overlay.remove();

    if (data.success) {
      // si el backend devuelve 'alert' con la entrada guardada, usarla para crear el marker inmediatamente
      const newAlert = data.alert;
      if (newAlert && window.addAlertMarker) {
        window.addAlertMarker(newAlert);
      } else if (window.cargarAlertas) {
        // fallback: recargar todas las alertas
        window.cargarAlertas();
      }
    }
  } catch (err) {
    console.error('Error fetch subirAlerta:', err);
    alert('Error de red al subir alerta. Revisa la consola.');
    modal.remove(); overlay.remove();
  }
};
};
