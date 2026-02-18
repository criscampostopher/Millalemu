// geolocalizacion.js
if (!navigator.geolocation) {
  console.warn('Geolocalización no soportada');
} else {
  let watchId = null;
  function onLocation(pos) {
    const lat = pos.coords.latitude;
    const lng = pos.coords.longitude;
    const ll = L.latLng(lat, lng);

    // si no existe marker, crearlo
    if (!window._MM.myLocationMarker) {
      const m = L.marker(ll, { title: 'Mi ubicación' }).addTo(window._MM.map);
      m.bindPopup('Aquí estoy');
      window._MM.myLocationMarker = m;
    } else {
      window._MM.myLocationMarker.setLatLng(ll);
    }
  }

  function onErr(err) {
    console.warn('Error geolocalización', err);
  }

  // iniciar seguimiento
  watchId = navigator.geolocation.watchPosition(onLocation, onErr, {
    enableHighAccuracy: true,
    maximumAge: 1000,
    timeout: 10000
  });

  // exponer stop/start si necesitas
  window._MM.stopGeo = () => { if (watchId) navigator.geolocation.clearWatch(watchId); };
}
