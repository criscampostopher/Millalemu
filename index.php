<?php
// ==========================================================
// Archivo: index.php (v6.0 - Botones Separados y Fix Admin)
// ==========================================================
session_start();
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 1. Seguridad de Sesión
if (!isset($_SESSION['id_usuario'])) { header("Location: login.php"); exit; }
require_once __DIR__ . '/Config/db_config.php'; 

$id_usuario   = $_SESSION['id_usuario'];
$nombre_user  = $_SESSION['nombre_usuario'];
$tipo_usuario = $_SESSION['tipo_usuario'];
$es_admin     = ($tipo_usuario == 'admin');

// 2. Manejo de Mensajes 
$mensaje = ""; $tipo_msg = ""; 
if (isset($_GET['msg'])) {
    $mensaje = htmlspecialchars($_GET['msg']);
    $tipo_msg = $_GET['status'] ?? 'error';
}

// -----------------------------------------------------------------------
// 3. CARGA DE DATOS (Zonas, Categorías y Mapas)
// -----------------------------------------------------------------------
// -----------------------------------------------------------------------
// 3. CARGA DE DATOS (Zonas, Categorías y Mapas)
// -----------------------------------------------------------------------
// -----------------------------------------------------------------------
// 3. CARGA DE DATOS (Zonas, Categorías y Mapas)
// -----------------------------------------------------------------------
$lista_mapas_visualizar = [];
$zonas_disponibles = []; 
$id_mapa_actual = isset($_GET['focus_map']) ? (int)$_GET['focus_map'] : 0;

if ($es_admin) {
    // A) Admin: Carga zonas y mapas (AGREGADO: m.tipo_mapa)
    $stmtZonas = $pdo->query("SELECT id_zona, nombre_zona FROM public.zona ORDER BY nombre_zona ASC");
    $zonas_disponibles = $stmtZonas->fetchAll(PDO::FETCH_ASSOC);

    $sql = "SELECT m.id_mapa, m.nombre_mapa, m.ruta_archivo, m.tipo_mapa, m.categoria, m.es_excluyente, m.id_zona, z.nombre_zona 
            FROM public.mapa m
            LEFT JOIN public.zona z ON m.id_zona = z.id_zona
            ORDER BY z.nombre_zona ASC, m.categoria ASC, m.nombre_mapa ASC";
    $stmt = $pdo->query($sql);
    $lista_mapas_visualizar = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {
    // B) Usuario: Carga mapas SEGÚN ZONAS + VALIDACIÓN DE FECHAS
    $sql = "SELECT m.id_mapa, m.nombre_mapa, m.ruta_archivo, m.tipo_mapa, m.categoria, m.es_excluyente, m.id_zona, z.nombre_zona
            FROM public.mapa m 
            JOIN public.zona z ON m.id_zona = z.id_zona
            JOIN public.usuario_zona uz ON z.id_zona = uz.id_zona 
            WHERE uz.id_usuario = ? 
            -- VALIDACIÓN DE FECHAS DE LA ZONA
            AND (uz.fecha_inicio <= NOW()) 
            AND (uz.fecha_fin IS NULL OR uz.fecha_fin >= NOW())
            ORDER BY z.nombre_zona ASC, m.categoria ASC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_usuario]);
    $lista_mapas_visualizar = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// C) CORRECCIÓN DE RUTAS (El Puente Mágico)
foreach ($lista_mapas_visualizar as &$mapa) {
    // 1. Si viene de BD, convertimos a API
    if (strpos($mapa['ruta_archivo'], 'BD_STORED') !== false) {
        $mapa['ruta_archivo'] = "Api/api_descargar_mapa.php?id=" . $mapa['id_mapa'];
    } 
    // 2. Si es archivo antiguo en uploads
    elseif (!empty($mapa['ruta_archivo']) && !filter_var($mapa['ruta_archivo'], FILTER_VALIDATE_URL)) {
        if (strpos($mapa['ruta_archivo'], 'uploads/') === false) {
            $mapa['ruta_archivo'] = "uploads/" . $mapa['ruta_archivo'];
        }
    }
}
unset($mapa); // Limpieza
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Visor Millalemu Pro</title>
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* ESTILOS GENERALES (v6.0) */
        body { margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; overflow: hidden; background: #2c3e50; }
        
        #loader { 
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(255,255,255,0.9); z-index: 9999; 
            display: none; justify-content: center; align-items: center; 
            font-size: 1.5rem; color: #3498db; 
        }

        #map { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 1; }

        .leaflet-right .leaflet-control-layers {
            margin-top: 140px !important; margin-right: 20px !important;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2) !important; border: none !important; border-radius: 8px !important;
        }

        /* Sidebars */
        .sidebar {
            position: fixed; top: 0; height: 100%; width: 320px;
            background: rgba(255, 255, 255, 0.96); backdrop-filter: blur(10px);
            z-index: 2000; box-shadow: 0 0 20px rgba(0,0,0,0.2);
            transition: transform 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
            padding: 20px; box-sizing: border-box; display: flex; flex-direction: column; 
        }

        .sidebar-left { left: 0; transform: translateX(-100%); border-right: 1px solid #eee; }
        .sidebar-right { right: 0; transform: translateX(100%); border-left: 1px solid #eee; top: 10px; height: calc(100% - 20px); border-radius: 15px 0 0 15px; }
        .sidebar-visible { transform: translateX(0) !important; }

        .sidebar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #f1f1f1; padding-bottom: 10px; }
        .close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #95a5a6; }
        
        /* Footer arreglado */
        .sidebar-footer { margin-top: auto; padding-top: 15px; padding-bottom: 10px; border-top: 1px solid #eee; width: 100%; box-sizing: border-box; }

        /* Componentes UI */
        .toggle-btn {
            position: absolute; top: 20px; z-index: 1000; background: white; border: none; padding: 10px 15px;
            border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); cursor: pointer; font-weight: bold; color: #2c3e50;
            display: flex; align-items: center; gap: 8px; transition: transform 0.2s;
        }
        .toggle-btn:hover { transform: scale(1.05); }
        #btn-left { left: 20px; } #btn-right { right: 20px; }

        .gps-btn {
            position: absolute; top: 80px; right: 20px; z-index: 1000; background: #3498db; color: white; width: 45px; height: 45px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 8px rgba(0,0,0,0.3); cursor: pointer; font-size: 1.2rem;
        }

        .user-card { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .user-avatar { width: 40px; height: 40px; background: #34495e; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .badge-admin { background: #e74c3c; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; }
        .badge-user { background: #27ae60; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; }

        /* Botones de acción */
        .btn-action {
            width: 100%; padding: 12px; border: none; border-radius: 6px; cursor: pointer; 
            font-weight: bold; transition: 0.2s; color: white; display: block; text-align: center; text-decoration: none; box-sizing: border-box;
        }
        .btn-upload { background: #3498db; margin-top: 15px; } 
        .btn-panel { background: #8e44ad; margin-top: 10px; } /* Botón volver al panel */
        .btn-logout { background: white; color: #e74c3c; border: 1px solid #e74c3c; }
        .btn-reset { background: #e67e22; margin-top: 10px; }
        
        /* Estilos interruptores */
        .btn-alert-on { background: #27ae60; color: white; font-size:0.8rem; }
        .btn-alert-off { background: #95a5a6; color: white; font-size:0.8rem; }

        /* Scroll Zonas */
        .layers-scroll { overflow-y: auto; flex: 1; padding-right: 5px; }
        .layers-scroll::-webkit-scrollbar { width: 6px; }
        .layers-scroll::-webkit-scrollbar-thumb { background: #bdc3c7; border-radius: 3px; }

        .zone-group { margin-bottom: 8px; border: 1px solid #e0e0e0; border-radius: 6px; overflow: hidden; }
        .zone-header { padding: 12px; cursor: pointer; background: #ffffff; display: flex; align-items: center; justify-content: space-between; font-weight: 600; color: #2c3e50; }
        .zone-header.active { background: #e8f6f3; color: #16a085; border-left: 4px solid #16a085; }
        .zone-layers { display: none; padding: 10px; background: #fafafa; border-top: 1px solid #eee; }
        .layer-item { display: flex; align-items: center; padding: 6px 0; font-size: 0.9rem; }
        .badge-cat { font-size: 0.7rem; padding: 2px 5px; background: #95a5a6; color: white; border-radius: 4px; margin-left: auto; }

        /* Formulario Inputs */
        form label { font-size: 0.85rem; font-weight: bold; color: #7f8c8d; display: block; margin-top: 10px; }
        form input, form select { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #dfe6e9; border-radius: 6px; box-sizing: border-box; }
        .custom-file-upload { display: inline-block; padding: 10px; cursor: pointer; background: #ecf0f1; color: #2c3e50; border-radius: 6px; text-align: center; width: 100%; margin-top: 5px; border: 1px dashed #bdc3c7; font-size: 0.9rem; box-sizing: border-box; }
        input[type="file"] { display: none; }

        /* Alertas */
        #alertas { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); z-index: 3000; width: 90%; max-width: 400px; }
        .alerta-card { background: #c0392b; color: white; padding: 15px; border-radius: 8px; text-align: center; box-shadow: 0 4px 15px rgba(0,0,0,0.3); margin-top: 10px; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.02); } 100% { transform: scale(1); } }
        #markerFormContainer { display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 10px; z-index: 4000; box-shadow: 0 0 20px rgba(0,0,0,0.4); width: 300px; }

        @media (max-width: 768px) {
            .sidebar { width: 100% !important; border-radius: 0 !important; top: 0 !important; height: 100% !important; }
            .toggle-btn span { display: none; } 
        }
    </style>
</head>
<body>

    <div id="loader"><i class="fas fa-circle-notch fa-spin"></i> Cargando...</div>
    <div id="map"></div>

    <button id="btn-left" class="toggle-btn" onclick="toggleSidebar('left')"><i class="fas fa-bars"></i> <span>Menú</span></button>
    <button id="btn-right" class="toggle-btn" onclick="toggleSidebar('right')"><i class="fas fa-map"></i> <span>Zonas</span></button>
    <div class="gps-btn" onclick="centrarEnUsuario()" title="Mi Ubicación"><i class="fas fa-location-arrow"></i></div>

    <div id="sidebar-left" class="sidebar sidebar-left">
        <div class="sidebar-header">
            <h2 style="margin:0; font-size:1.2rem; color:#2c3e50;"><i class="fas fa-leaf"></i> Millalemu</h2>
            <button class="close-btn" onclick="toggleSidebar('left')">&times;</button>
        </div>

        <div class="user-card">
            <div class="user-avatar"><?php echo strtoupper(substr($nombre_user, 0, 1)); ?></div>
            <div>
                <div style="font-weight:bold;"><?php echo htmlspecialchars($nombre_user); ?></div>
                <span class="<?php echo $es_admin ? 'badge-admin' : 'badge-user'; ?>"><?php echo $es_admin ? 'Supervisor' : 'Operador'; ?></span>
            </div>
        </div>

        <div style="flex:1; overflow-y:auto; padding-right:5px;">
            <?php if ($es_admin) { ?>
                <h4 style="margin: 10px 0; color:#3498db; border-bottom: 2px solid #3498db; display:inline-block;">Subir Mapa </h4>
                <form action="Api/api_subirMapa.php" method="post" enctype="multipart/form-data" id="uploadForm">
                    <label>1. Zona:</label>
                    <input list="lista_zonas" name="nombre_zona" placeholder="Escribe o selecciona..." required autocomplete="off">
                    <datalist id="lista_zonas">
                        <?php foreach($zonas_disponibles as $z): echo "<option value='".htmlspecialchars($z['nombre_zona'])."'>"; endforeach; ?>
                    </datalist>
                    <label>2. Categoría:</label>
                    <select name="categoria">
                        <option value="Escenario">Escenario General</option>
                        <option value="Pendiente">Pendiente</option>
                        <option value="Uso de Suelo">Uso de Suelo</option>
                        <option value="Acta">Acta</option>
                        <option value="Otro">Otro</option>
                    </select>
                    <label>3. Archivo:</label>
                    <label for="file-upload" class="custom-file-upload"><i class="fas fa-cloud-upload-alt"></i> Seleccionar (KML/GeoJSON)</label>
                    <input id="file-upload" type="file" name="mapa" accept=".geojson, .kml, .kmz" required onchange="if(confirm('¿Subir este mapa?')) { document.getElementById('uploadForm').submit(); document.getElementById('loader').style.display='block'; }">
                </form>
                <?php if(!empty($mensaje)) echo "<div style='font-size:0.85em; padding:10px; margin-top:10px; border-radius:4px; background:".($tipo_msg=='success'?'#d4edda':'#f8d7da')."; color:".($tipo_msg=='success'?'#155724':'#721c24').";'>$mensaje</div>"; ?>
                
                <a href="menuadmin.php" class="btn-action btn-panel"><i class="fas fa-tachometer-alt"></i> Volver al Panel</a>
                <button id="borrarMarcadores" class="btn-action btn-reset"><i class="fas fa-trash"></i> Resetear Sistema</button>
            <?php } else { ?>
                
                <button onclick="reportarUser()" class="btn-action" style="background:#e74c3c;"><i class="fas fa-broadcast-tower"></i> <b>SOS / ALERTA</b></button>
                
                <div style="background: #fdf2f2; padding: 15px; border-radius: 10px; border: 1px solid #f5c6cb; margin-top: 15px;">
                    <h4 style="margin: 0 0 10px 0; color: #721c24; font-size: 0.9rem; text-align:center;"><i class="fas fa-shield-alt"></i> PROTECCIÓN ACTIVA</h4>
                    
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <span style="font-size:0.85rem; font-weight:bold; color:#2c3e50;">General / Actas</span>
                        <button id="btnToggleGeneral" onclick="toggleSeguridad('general')" class="btn-action btn-alert-on" style="width:60px; padding:5px; margin:0;">ON</button>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size:0.85rem; font-weight:bold; color:#2c3e50;">Pendientes</span>
                        <button id="btnTogglePendiente" onclick="toggleSeguridad('pendiente')" class="btn-action btn-alert-on" style="width:60px; padding:5px; margin:0;">ON</button>
                    </div>
                    <small style="display:block; text-align:center; color:#7f8c8d; margin-top:8px; font-size:0.7rem;">(Funciona aunque ocultes el mapa)</small>
                </div>

            <?php } ?>
        </div>

        <div class="sidebar-footer">
            <a href="logout.php" class="btn-action btn-logout"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </div>
    </div>

    <div id="sidebar-right" class="sidebar sidebar-right">
        <div class="sidebar-header">
            <h3 style="margin:0;">🗺️ Zonas y Mapas</h3>
            <button class="close-btn" onclick="toggleSidebar('right')">&times;</button>
        </div>
        <div id="layers-container" class="layers-scroll">
            <div style="text-align:center; color:#999; margin-top:20px;"><i class="fas fa-circle-notch fa-spin"></i> Cargando zonas...</div>
        </div>
    </div>

    <div id="alertas"></div>

    <div id="markerFormContainer">
        <form id="markerForm">
            <h3 style="text-align:center; margin-top:0;">Nueva Alerta</h3>
            <label>Asignar a Mapa:</label> <select id="selectorMapaForm"></select>
            <label>Título:</label> <input type="text" id="popupNombre" required>
            <label>Descripción:</label> <input type="text" id="popupDesc">
            <label>Nivel:</label> 
            <select id="popupIcon">
                <option value="Critico">🔴 Crítico</option>
                <option value="Alto">🟠 Alto</option>
                <option value="Medio">🟢 Medio</option>
            </select>
            <label>Radio (m):</label> <input type="number" id="popupRadio" placeholder="0" min="0">
            <div style="display:flex; gap:10px; margin-top:15px;">
                <button type="submit" class="btn-action btn-upload" style="margin:0;">Guardar</button>
                <button type="button" id="cancelMarker" class="btn-action" style="margin:0; background:#95a5a6;">Cancelar</button>
            </div>
        </form>
    </div>

    <audio id="alertaAudio" src="https://actions.google.com/sounds/v1/alarms/beep_short.ogg"></audio>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src='https://api.tiles.mapbox.com/mapbox.js/plugins/leaflet-omnivore/v0.3.1/leaflet-omnivore.min.js'></script>
    
    <script>
        const LISTA_MAPAS = <?php echo json_encode($lista_mapas_visualizar); ?>;
        const MAPA_ID_ACTUAL = <?php echo $id_mapa_actual; ?>;
        const IS_ADMIN = <?php echo $es_admin ? 'true' : 'false'; ?>;

        function toggleSidebar(side) {
            const el = document.getElementById('sidebar-' + side);
            const other = document.getElementById('sidebar-' + (side === 'left' ? 'right' : 'left'));
            if(window.innerWidth < 768) other.classList.remove('sidebar-visible');
            el.classList.toggle('sidebar-visible');
        }
    </script>
    <script src="script_visor.js?v=4.0"></script>
</body>
</html>