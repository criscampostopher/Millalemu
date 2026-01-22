<?php
// ==========================================================
// Archivo: mapas.php 
// ==========================================================
session_start();

// Cabeceras Anti-Caché
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Seguridad de Admin
if (!isset($_SESSION['id_usuario']) || $_SESSION['tipo_usuario'] !== 'admin') { 
    header("Location: login.php"); 
    exit; 
}

$nombre_user = $_SESSION['nombre_usuario'];
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

        .table-container {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-top: 20px;
        }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #eee; color: #555; }
        th { background: #2c3e50; color: white; border-radius: 5px 5px 0 0; }
        tr:hover { background-color: #f9f9f9; }

        /* Botones de Acción */
        .btn { padding: 6px 12px; border-radius: 6px; color: white; text-decoration: none; font-size: 0.9rem; border: none; cursor: pointer; transition: 0.3s; margin-right: 5px; }
        .btn-view { background: #3498db; }
        .btn-view:hover { background: #2980b9; }
        .btn-del { background: #e74c3c; }
        .btn-del:hover { background: #c0392b; }
    </style>
</head>
<body>

    <div class="leaves-container">
        <div class="leaf" style="--i:1;"></div>
        <div class="leaf" style="--i:2;"></div>
        <div class="leaf" style="--i:3;"></div>
        <div class="leaf" style="--i:4;"></div>
        <div class="leaf" style="--i:5;"></div>
        <div class="leaf" style="--i:6;"></div>
    </div>

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

        <div class="table-container">
            <table id="tabla">
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
                    <tr><td colspan="5" style="text-align:center;">Cargando mapas...</td></tr>
                </tbody>
            </table>
        </div>
    </main>

    <script>
        // Cargar mapas al iniciar
        fetch('Api/api_mapa.php?action=fetch_maps')
        .then(r => r.json())
        .then(res => {
            const t = document.querySelector('#tabla tbody');
            t.innerHTML = ''; // Limpiar carga

            if (res.success && res.maps && res.maps.length > 0) {
                res.maps.forEach(m => {
                    // Protegemos el ID 1 para que no se pueda borrar (Es la Capa Manual)
                    const botonEliminar = m.id_mapa != 1 
                        ? `<button onclick="del(${m.id_mapa})" class="btn btn-del"><i class="fas fa-trash"></i> Eliminar</button>` 
                        : '<span style="color:#999; font-size:0.8rem;">(Sistema)</span>';

                    t.innerHTML += `<tr>
                        <td style="font-weight:bold;">${m.id_mapa}</td>
                        <td style="color:#2c3e50;">${m.nombre_mapa}</td>
                        <td><span style="background:#eafaf1; color:#2ecc71; padding:3px 8px; border-radius:10px; font-size:0.8rem;">${m.tipo_mapa}</span></td>
                        <td>${m.cantidad_elementos}</td>
                        <td>
                            <a href="index.php?focus_map=${m.id_mapa}" class="btn btn-view"><i class="fas fa-eye"></i> Ver</a>
                            ${botonEliminar}
                        </td>
                    </tr>`;
                });
            } else {
                t.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px;">No se encontraron mapas cargados.</td></tr>';
            }
        })
        .catch(err => {
            console.error(err);
            document.querySelector('#tabla tbody').innerHTML = '<tr><td colspan="5" style="color:red; text-align:center;">Error de conexión con la API.</td></tr>';
        });

        // Función Eliminar
        function del(id) {
            if(confirm("⚠️ ¿Estás seguro?\n\nSe eliminará el mapa y todos los reportes asociados a él.")) {
                fetch('Api/api_mapa.php', {
                    method:'POST', 
                    body:JSON.stringify({action:'delete_map', id:id})
                })
                .then(r => r.json())
                .then(res => {
                    if(res.success) {
                        location.reload();
                    } else {
                        alert("Error: " + (res.error || "No se pudo eliminar"));
                    }
                });
            }
        }
    </script>

</body>
</html>