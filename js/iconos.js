
// iconos.js
export function crearIcono(color) {
  return L.divIcon({
    className: "",
    html: `<svg width="40" height="40" viewBox="0 0 64 64">
             <circle cx="32" cy="24" r="12" fill="${color}"/>
             <rect x="28" y="24" width="8" height="20" fill="#8B4513"/>
           </svg>`,
    iconSize: [40, 40],
    iconAnchor: [20, 40]
  });
}
