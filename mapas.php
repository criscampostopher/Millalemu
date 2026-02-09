<?php
// ==========================================================
// Archivo: mapas.php 
// ==========================================================
session_start();
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['id_usuario']) || $_SESSION['tipo_usuario'] !== 'admin') { 
    header("Location: login.php"); 
    exit; 
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Capas - Millalemu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="style-admin.css">
    <style>
        .table-container { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #eee; color: #555; }
        th { background: #2c3e50; color: white; border-radius: 5px 5px 0 0; }
        tr:hover { background-color: #f9f9f9; }
        .btn { padding: 6px 12px; border-radius: 6px; color: white; text-decoration: none; font-size: 0.9rem; border: none; cursor: pointer; transition: 0.3s; margin-right: 5px; }
        .btn-view { background: #3498db; }
        .btn-view:hover { background: #2980b9; }
        .btn-del { background: #e74c3c; }
        .btn-del:hover { background: #c0392b; }
        .btn-back { background: #7f8c8d; margin-bottom: 15px; display: inline-block; }
        .btn-back:hover { background: #606f71; }

        /* Estilos Tarjetas Zonas */
        .zones-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; margin-top: 20px; }
        .zone-card { background: white; padding: 20px; border-radius: 12px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.1); cursor: pointer; transition: 0.3s; border: 2px solid transparent; }
        .zone-card:hover { transform: translateY(-5px); border-color: #2ecc71; }
        .zone-icon { font-size: 40px; color: #2ecc71; margin-bottom: 10px; }
        .zone-title { font-weight: 600; font-size: 1.1rem; color: #2c3e50; }
        .zone-info { color: #7f8c8d; font-size: 0.9rem; margin-top: 5px; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <h2 style="text-align:center; padding:10px; color:#fdd835;">Millalemu</h2>
        <a href="menuadmin.php"><i class="fas fa-home"></i> Inicio</a>
        <a href="index.php"><i class="fas fa-eye"></i> <b>Visor Global</b></a>
        <a href="mapas.php" class="active"><i class="fas fa-layer-group"></i> Gestión de Mapas</a>
        <a href="usuarios.php"><i class="fas fa-users"></i> Usuarios</a>
        <div style="margin-top:auto; padding-bottom:20px;">
            <a href="logout.php" style="color:#ef5350;"><i class="fas fa-sign-out-alt"></i> Salir</a>
        </div>
    </aside>

    <main class="main">
        <div class="top-bar">
            <h1>Gestión de Capas y Mapas</h1>
        </div>

        <div id="view-zones">
            <h3 style="color:#2ecc71; -webkit-text-stroke: 0.5px white;">Selecciona una Zona para ver sus mapas:</h3>
            <div class="zones-grid" id="zones-container">
                <p>Cargando zonas...</p>
            </div>
            
            <div style="margin-top: 30px;">
                <div class="zone-card" onclick="cargarMapas(null, 'Mapas Generales / Sin Zona')" style="max-width: 200px; background: #fdfdfd;">
                    <div class="zone-icon" style="color:#2ecc71;"><i class="fas fa-globe"></i></div>
                    <div class="zone-title">General / Otros</div>
                    <div class="zone-info">Mapas sin zona asignada</div>
                </div>
            </div>
        </div>

        <div id="view-maps" style="display:none;">
            <button onclick="volverAZonas()" class="btn btn-back"><i class="fas fa-arrow-left"></i> Volver a Zonas</button>
            <h2 id="zona-actual-titulo" style="color:#2ecc71; margin-bottom:15px; -webkit-text-stroke: 0.5px white; ">Mapas de la Zona</h2>

            <div class="table-container">
                <table id="tabla-mapas">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre del Mapa</th>
                            <th>Tipo</th>
                            <th>Elementos</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="5" style="text-align:center;">Cargando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
        // AL INICIAR: Cargar Zonas
        document.addEventListener('DOMContentLoaded', () => {
            fetch('Api/api_mapa.php?action=fetch_zones')
            .then(r => r.json())
            .then(res => {
                const container = document.getElementById('zones-container');
                container.innerHTML = '';
                if(res.success && res.zones && res.zones.length > 0) {
                    res.zones.forEach(z => {
                        container.innerHTML += `
                            <div class="zone-card" onclick="cargarMapas(${z.id_zona}, '${z.nombre_zona}')">
                                <div class="zone-icon"><i class="fas fa-map-marked-alt"></i></div>
                                <div class="zone-title">${z.nombre_zona}</div>
                                <div class="zone-info">${z.cantidad_mapas} Mapas</div>
                            </div>
                        `;
                    });
                } else {
                    container.innerHTML = '<p>No hay zonas creadas aún.</p>';
                }
            });
        });

        // FUNCIÓN: Mostrar mapas de una zona
        function cargarMapas(idZona, nombreZona) {
            // Cambio de vista
            document.getElementById('view-zones').style.display = 'none';
            document.getElementById('view-maps').style.display = 'block';
            document.getElementById('zona-actual-titulo').innerText = 'Mapas de: ' + nombreZona;

            const tbody = document.querySelector('#tabla-mapas tbody');
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Cargando mapas...</td></tr>';

            // Petición a la API con filtro de zona
            let url = 'Api/api_mapa.php?action=fetch_maps';
            if (idZona) url += '&id_zona=' + idZona;

            fetch(url)
            .then(r => r.json())
            .then(res => {
                tbody.innerHTML = '';
                if (res.success && res.maps && res.maps.length > 0) {
                    res.maps.forEach(m => {
                        const btnEliminar = m.id_mapa != 1 
                            ? `<button onclick="del(${m.id_mapa})" class="btn btn-del"><i class="fas fa-trash"></i></button>` 
                            : '';
                        
                        tbody.innerHTML += `<tr>
                            <td><b>${m.id_mapa}</b></td>
                            <td>${m.nombre_mapa}</td>
                            <td><span style="background:#eafaf1; color:#2ecc71; padding:3px 8px; border-radius:10px; font-size:0.8rem;">${m.tipo_mapa}</span></td>
                            <td>${m.cantidad_elementos}</td>
                            <td>
                                <a href="index.php?focus_map=${m.id_mapa}" class="btn btn-view"><i class="fas fa-eye"></i></a>
                                ${btnEliminar}
                            </td>
                        </tr>`;
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px;">No hay mapas en esta zona.</td></tr>';
                }
            });
        }

        function volverAZonas() {
            document.getElementById('view-maps').style.display = 'none';
            document.getElementById('view-zones').style.display = 'block';
        }

        function del(id) {
            if(confirm("⚠️ ¿Eliminar este mapa?")) {
                fetch('Api/api_mapa.php', {
                    method:'POST', 
                    body:JSON.stringify({action:'delete_map', id:id})
                })
                .then(r => r.json())
                .then(res => {
                    if(res.success) {
                        // Recargar la vista actual (no toda la página)
                        // Para simplificar, recargamos la pagina
                        location.reload(); 
                    } else {
                        alert("Error: " + (res.error || "Fallo al eliminar"));
                    }
                });
            }
        }
    </script>

</body>
</html>