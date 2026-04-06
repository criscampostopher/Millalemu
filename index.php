<?php
// ==========================================================
// Archivo: index.php (v6.0 - Fix Cámara + Sistema Offline Intacto)
// ==========================================================
session_start();
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 1. Seguridad de Sesión
if (!isset($_SESSION['id_usuario'])) { header("Location: login.php"); exit; }
require_once __DIR__ . '/Config/db_config.php'; 
require_once __DIR__ . '/Config/roles.php';

$id_usuario   = $_SESSION['id_usuario'];
$nombre_user  = $_SESSION['nombre_usuario'];
$tipo_usuario = $_SESSION['tipo_usuario'];
$es_admin     = rolTieneFuncionesAdmin($tipo_usuario);

// 2. Manejo de Mensajes 
$mensaje = ""; $tipo_msg = ""; 
if (isset($_GET['msg'])) {
    $mensaje = htmlspecialchars($_GET['msg']);
    $tipo_msg = $_GET['status'] ?? 'error';
}

// -----------------------------------------------------------------------
// 3. CARGA DE DATOS (Zonas, Categorías y Mapas)
// -----------------------------------------------------------------------
$lista_mapas_visualizar = [];
$zonas_disponibles = []; 
$id_mapa_actual = isset($_GET['focus_map']) ? (int)$_GET['focus_map'] : 0;

if ($es_admin) {
    // Añadimos la exclusión de sistema_oculto
    $stmtZonas = $pdo->query("SELECT id_zona, nombre_zona FROM public.zona WHERE nombre_zona != 'SISTEMA_OCULTO' ORDER BY nombre_zona ASC");
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
            WHERE uz.id_usuario = :id_usuario 
            -- VALIDACIÓN DE FECHAS DE LA ZONA
            AND (uz.fecha_inicio <= NOW()) 
            AND (uz.fecha_fin IS NULL OR uz.fecha_fin >= NOW())";

    // --- NUEVO: FILTRO INTELIGENTE SI ENTRA DESDE EL MENÚ ---
    if ($id_mapa_actual > 0) {
        $sql .= " AND z.id_zona = (SELECT id_zona FROM public.mapa WHERE id_mapa = :id_mapa_actual)";
        $sql .= " AND (LOWER(m.categoria) != 'acta' OR m.id_mapa = :id_mapa_actual)";
    }

    $sql .= " ORDER BY z.nombre_zona ASC, m.categoria ASC";
            
    $stmt = $pdo->prepare($sql);
    
    $params = [':id_usuario' => $id_usuario];
    if ($id_mapa_actual > 0) {
        $params[':id_mapa_actual'] = $id_mapa_actual;
    }
    
    $stmt->execute($params);
    $lista_mapas_visualizar = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// C) CORRECCIÓN DE RUTAS (El Puente Mágico)
foreach ($lista_mapas_visualizar as &$mapa) {
    if (strpos($mapa['ruta_archivo'], 'BD_STORED') !== false) {
        $mapa['ruta_archivo'] = "Api/api_descargar_mapa.php?id=" . $mapa['id_mapa'];
    } 
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
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" crossorigin="anonymous">
    
    <style>
        /* ESTILOS GENERALES */
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
        
        .sidebar-footer { margin-top: auto; padding-top: 15px; padding-bottom: 10px; border-top: 1px solid #eee; width: 100%; box-sizing: border-box; }

        /* Componentes UI */
        .toggle-btn {
            position: absolute; top: 20px; z-index: 1000; background: white; border: none; 
            padding: 14px 22px; /* <-- AUMENTADO (antes 10px 15px) */
            border-radius: 12px; /* <-- Bordes más redondeados */
            box-shadow: 0 4px 10px rgba(0,0,0,0.3); cursor: pointer; 
            font-weight: bold; color: #2c3e50; font-size: 1.1rem; /* <-- LETRA MÁS GRANDE */
            display: flex; align-items: center; gap: 10px; transition: transform 0.2s;
        }
        .toggle-btn:hover { transform: scale(1.05); }
        #btn-left { left: 20px; } #btn-right { right: 20px; }

        .gps-btn {
            position: absolute; top: 75px; right: 20px; z-index: 1000; background: #3498db; color: white; 
            width: 55px; height: 55px; /* <-- AUMENTADO (antes 45px) */
            border-radius: 50%; display: flex; align-items: center; justify-content: center; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.3); cursor: pointer; 
            font-size: 1.6rem; /* <-- ICONO MÁS GRANDE (antes 1.2rem) */
        }

        .user-card { 
            background: #f8f9fa; 
            padding: 20px; /* <-- Más espacio interior (antes 15px) */
            border-radius: 12px; /* <-- Bordes más redondeados */
            margin-bottom: 25px; 
            display: flex; 
            align-items: center; 
            gap: 15px; /* <-- Más separación entre foto y texto */
        }
        
        .user-avatar { 
            width: 55px; /* <-- Foto más grande (antes 40px) */
            height: 55px; 
            background: #34495e; 
            color: white; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: bold; 
            font-size: 1.5rem; /* <-- Letra de la foto más grande */
        }

        .user-card div {
            font-size: 1.1rem; /* <-- Nombre del usuario más grande */
        }

        .badge-admin, .badge-user { 
            padding: 4px 8px; /* <-- Etiqueta de rol más grande */
            border-radius: 6px; 
            font-size: 0.85rem; /* <-- Texto de la etiqueta más grande */
            display: inline-block;
            margin-top: 5px;
        }

        /* Botones de acción */
        .btn-action {
            width: 100%; padding: 12px; border: none; border-radius: 6px; cursor: pointer; 
            font-weight: bold; transition: 0.2s; color: white; display: block; text-align: center; text-decoration: none; box-sizing: border-box;
        }
        .btn-upload { background: #3498db; margin-top: 15px; } 
        .btn-panel { background: #8e44ad; margin-top: 10px; } 
        .btn-logout { background: white; color: #e74c3c; border: 1px solid #e74c3c; }
        .btn-reset { background: #e67e22; margin-top: 10px; }
        
        .btn-alert-on { background: #27ae60; color: white; font-size:0.8rem; }
        .btn-alert-off { background: #95a5a6; color: white; font-size:0.8rem; }

        /* Scroll Zonas */
        .layers-scroll { overflow-y: auto; flex: 1; padding-right: 5px; }
        .layers-scroll::-webkit-scrollbar { width: 6px; }
        .layers-scroll::-webkit-scrollbar-thumb { background: #bdc3c7; border-radius: 3px; }

        .zone-group { margin-bottom: 8px; border: 1px solid #e0e0e0; border-radius: 6px; overflow: hidden; }
        .zone-header { 
            background: #34495e; 
            color: white; 
            padding: 16px 20px; /* <-- Más espacio para tocar (antes 12px 15px) */
            cursor: pointer; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-radius: 6px; 
            margin-bottom: 8px; 
            font-size: 1.15rem; /* <-- Letra más grande (antes 0.95rem) */
            transition: background 0.3s; 
        }
        .zone-header.active { background: #e8f6f3; color: #16a085; border-left: 4px solid #16a085; }
        .zone-layers { display: none; padding: 10px; background: #fafafa; border-top: 1px solid #eee; }
        .layer-item { 
            padding: 12px 15px; /* <-- Más separación entre cada mapa (antes 8px 10px) */
            border-bottom: 1px solid #eee; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            font-size: 1.1rem; /* <-- Nombre del mapa más grande (antes 0.9rem) */
        }

        /* ESTO ES NUEVO: Agranda los cuadritos (checkbox/radios) del menú izquierdo */
        .layer-item input[type="checkbox"], 
        .layer-item input[type="radio"] {
            transform: scale(1.6); /* <-- Hace los cuadritos 60% más grandes */
            margin-right: 15px !important;
        }
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

        /* Modal de Recorte PIV */
        #cropModal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:5000; flex-direction:column; align-items:center; justify-content:center; }
        #cropContainer { max-width:90%; max-height:80%; background:#000; box-shadow:0 0 20px rgba(255,255,255,0.2); }
        #cropActions { margin-top:15px; display:flex; gap:15px; }

        @media (max-width: 768px) {
            .sidebar { width: 100% !important; border-radius: 0 !important; top: 0 !important; height: 100% !important; }
            .toggle-btn span { display: none; } 
        }
        
        
        
        
        
        /* =========================================
           AGRANDAR EL CONTROL DE CAPAS DE LEAFLET
           ========================================= */
        .leaflet-control-layers-expanded {
            padding: 15px 20px !important; /* Más espacio interno */
            border-radius: 12px !important; /* Bordes más suaves */
        }
        
        .leaflet-control-layers-list {
            font-size: 1.2rem !important; /* Letra mucho más grande */
            line-height: 2.2 !important; /* Separación vertical entre opciones */
        }

        /* Agrandar los cuadritos de Satelital y Alertas */
        .leaflet-control-layers-selector {
            transform: scale(1.6); /* Cuadritos 60% más grandes */
            margin-right: 12px !important;
            cursor: pointer;
        }
        
        
        /* =========================================
           AGRANDAR EL MENÚ PRINCIPAL IZQUIERDO
           ========================================= */
        
        /* Botones principales ("Volver al Menú", "Reportar Peligro", etc) */
        .sidebar-left .btn-action {
            padding: 16px !important; /* <-- Botones mucho más altos y fáciles de tocar */
            font-size: 1.15rem !important; /* <-- Letra más grande */
            border-radius: 8px !important; 
            margin-bottom: 18px !important; /* <-- Más separación entre botones */
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px; /* <-- Separación entre el icono y el texto */
        }
        
        /* Iconos dentro de los botones */
        .sidebar-left .btn-action i {
            font-size: 1.3rem; 
        }
        /*

        /* Caja de "Protección Activa" (Switch ON/OFF)
        .sidebar-left div[style*="background: #fdf2f2"] {
            padding: 20px !important; 
            border-radius: 12px !important;
            margin-top: 25px !important;
        }
        
        .sidebar-left div[style*="background: #fdf2f2"] h4 {
            font-size: 1.1rem !important; 
            margin-bottom: 15px !important;
        }

        .sidebar-left div[style*="background: #fdf2f2"] span {
            font-size: 1rem !important; 
        }

        
        .btn-alert-on, .btn-alert-off {
            width: 75px !important; 
            padding: 8px !important; 
            font-size: 0.95rem !important; 
            border-radius: 6px !important;
        }
         */
    </style>
</head>
<body>

    <div id="loader"><i class="fas fa-circle-notch fa-spin"></i> Cargando...</div>
    <div id="map"></div>

    <button id="btn-left" class="toggle-btn" onclick="toggleSidebar('left')"><i class="fas fa-bars"></i> <span>Menú</span></button>
    <button id="btn-right" class="toggle-btn" onclick="toggleSidebar('right')"><i class="fas fa-map"></i> <span>Predios</span></button>
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
                <span class="<?php echo $es_admin ? 'badge-admin' : 'badge-user'; ?>">
                    <?php
                        if ($es_admin) {
                            echo htmlspecialchars(nombreVisibleRol($tipo_usuario));
                        } elseif ($tipo_usuario === 'jefe_faena') {
                            echo 'Jefe de Faena';
                        } elseif ($tipo_usuario === 'jefe_operaciones') {
                            echo 'Jefe de Operaciones';
                        } else {
                            echo 'Operador';
                        }
                    ?>
                </span>
            </div>
        </div>

        <div style="flex:1; overflow-y:auto; padding-right:5px;">
            <?php if ($es_admin) { ?>
                <h4 style="margin: 10px 0; color:#3498db;">Subir un Nuevo Mapa </h4>
                <form action="Api/api_subirMapa.php" method="post" enctype="multipart/form-data" id="uploadForm">
                    <a href="piv_formulario.php" class="btn-action" style="background:#27ae60; margin-bottom:10px; text-decoration:none;">
                        <i class="fas fa-clipboard-list"></i> <b>INGRESAR AL PIV</b>
                    </a>
                    <button type="button" onclick="iniciarCapturaPIV()" class="btn-action" style="background:#8e44ad; margin-bottom:15px;">
                        <i class="fas fa-camera"></i> <b>FOTO PIV</b>
                    </button>

                    <label>1. Predio:</label>
                    <input list="lista_zonas" name="nombre_zona" placeholder="Escribe o selecciona..." required autocomplete="off">
                    <datalist id="lista_zonas">
                        <?php foreach($zonas_disponibles as $z): echo "<option value='".htmlspecialchars($z['nombre_zona'])."'>"; endforeach; ?>
                    </datalist>
                    <label>2. Categoría:</label>
                    <select name="categoria" id="selectCategoria" onchange="mostrarOcultarSuelo()">
                        <option value="Uso de Suelo">Uso de Suelo</option>
                        <option value="Pendiente">Pendiente</option>
                        <option value="Acta">Acta</option>
                      
                    </select>

                    <div id="divTipoSuelo" style="display: none; background: #fff3e0; padding: 10px; border-radius: 6px; border-left: 4px solid #e67e22; margin-top: 10px;">
                        <label style="color:#d35400; margin-top:0;">2.1. Tipo de Suelo:</label>
                        <select name="tipo_suelo" id="selectTipoSuelo">
                            <option value="rocoso">Rocoso</option>
                            <option value="humedo">Húmedo</option>
                        </select>
                    </div>
                    <label>3. Archivo:</label>
                    <label for="file-upload" class="custom-file-upload"><i class="fas fa-cloud-upload-alt"></i> Seleccionar (KML/GeoJSON)</label>
                    <input id="file-upload" type="file" name="mapa" accept=".geojson, .kml, .kmz" required onchange="subirMapaPorTrozos()">
                </form>
                <?php if(!empty($mensaje)) echo "<div style='font-size:0.85em; padding:10px; margin-top:10px; border-radius:4px; background:".($tipo_msg=='success'?'#d4edda':'#f8d7da')."; color:".($tipo_msg=='success'?'#155724':'#721c24').";'>$mensaje</div>"; ?>
                
                <a href="menuadmin.php" class="btn-action btn-panel"><i class="fas fa-tachometer-alt"></i> Volver al Panel</a>
            

            <?php } else { ?>
                
                <a href="menu_usuario.php" class="btn-action btn-panel" style="margin-bottom:15px; text-decoration:none;">
                    <i class="fas fa-home"></i> Volver al Menú
                </a>

                <?php if ($tipo_usuario === 'jefe_faena') { ?>
                    <button type="button" onclick="iniciarCapturaPIV()" class="btn-action" style="background:#9b59b6; margin-bottom:15px; border:none; cursor:pointer; width: 100%; color: white; padding: 12px; border-radius: 6px; font-weight: bold; box-sizing:border-box;">
                        <i class="fas fa-camera"></i> ACTUALIZAR FOTO PDF
                    </button>
                <?php } ?>

                <button onclick="abrirModalReporteTrabajador()" class="btn-action" style="background:#e67e22; margin-bottom:15px; border:none; cursor:pointer; width: 100%; color: white; padding: 12px; border-radius: 6px; font-weight: bold; box-sizing:border-box;">
                    <i class="fas fa-map-marker-alt"></i> REPORTAR PELIGRO (GPS)
                </button>
                
                
                
                
                
                
                

            <?php } ?>
        </div>

        <div class="sidebar-footer">
            <a href="logout.php" class="btn-action btn-logout"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </div>
    </div>

    <div id="sidebar-right" class="sidebar sidebar-right">
        <div class="sidebar-header">
            <h3 style="margin:0;">🗺️ Predios y Mapas</h3>
            <button class="close-btn" onclick="toggleSidebar('right')">&times;</button>
        </div>
        <div id="layers-container" class="layers-scroll">
            <div style="text-align:center; color:#999; margin-top:20px;"><i class="fas fa-circle-notch fa-spin"></i> Cargando Predios...</div>
        </div>
    </div>

    <div id="alertas"></div>

    <div id="markerFormContainer">
        <form id="markerForm">
            <h3 style="text-align:center; margin-top:0;">Nueva Alerta</h3>
            <label>Asignar a Mapa:</label> <select id="selectorMapaForm"></select>
            <label>Título:</label> 
<input type="text" id="popupNombre" list="listaCondiciones" onchange="autoCompletarAdmin()" autocomplete="off" placeholder="Escribe o elige del catálogo..." required>
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

    <div id="modalReporteTrabajador" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:20px; border-radius:10px; z-index:99999; box-shadow:0 0 20px rgba(0,0,0,0.5); width: 320px; max-width: 90%;">
        <form id="formReporteTrabajador">
            <h3 style="text-align:center; margin-top:0; color:#e67e22;"><i class="fas fa-map-marker-alt"></i> Nueva Alerta</h3>
            
            <label style="font-weight:bold; font-size:0.9rem; color:#333;">Título / Peligro:</label>
            <input list="listaCondiciones" id="repTrabNombre" onchange="autoCompletarAlerta()" placeholder="Toca para buscar o elegir..." style="width: 100%; padding: 8px;" autocomplete="off">
    <datalist id="listaCondiciones"></datalist>
            
            <label style="font-weight:bold; font-size:0.9rem; color:#333;">Descripción (Opcional):</label>
            <textarea id="repTrabDesc" readonly placeholder="La medida de gestión aparecerá aquí automáticamente..." style="width: 100%; height: 80px; background-color: #f4f4f4; color: #333;"></textarea>
            
            <label style="font-weight:bold; font-size:0.9rem; color:#333;">Nivel de Riesgo:</label>
            <select id="repTrabNivel" style="width:100%; margin-bottom:10px; padding:8px; border-radius:5px; border:1px solid #ccc; box-sizing:border-box;">
                <option value="Critico">🔴 Crítico</option>
                <option value="Alto" selected>🟠 Alto</option>
                <option value="Medio">🟢 Medio</option>
            </select>
            
            <label style="font-weight:bold; font-size:0.9rem; color:#333;">Radio de Peligro (m):</label>
            <input type="number" id="repTrabRadio" placeholder="Ej: 15" min="0" style="width:100%; margin-bottom:10px; padding:8px; border-radius:5px; border:1px solid #ccc; box-sizing:border-box;">
            
            <p id="repTrabStatusGps" style="font-size:0.85rem; font-weight:bold; text-align:center; margin:10px 0; padding:5px; background:#f8f9fa; border-radius:5px;">
                <i class="fas fa-satellite-dish fa-spin"></i> Obteniendo ubicación...
            </p>
            
            <input type="hidden" id="repTrabLat">
            <input type="hidden" id="repTrabLng">

            <div style="display:flex; gap:10px; margin-top:15px;">
                <button type="submit" id="repTrabSubmit" class="btn-action" style="margin:0; width:50%; background:#27ae60; color:white; font-weight:bold; border:none; padding:10px; border-radius:5px; cursor:pointer;" disabled>Guardar</button>
                <button type="button" onclick="cerrarModalReporteTrabajador()" class="btn-action" style="margin:0; background:#95a5a6; width:50%; color:white; font-weight:bold; border:none; padding:10px; border-radius:5px; cursor:pointer;">Cancelar</button>
            </div>
        </form>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet-rotate@0.2.8/dist/leaflet-rotate.min.js"></script>
    <script src='https://api.tiles.mapbox.com/mapbox.js/plugins/leaflet-omnivore/v0.3.1/leaflet-omnivore.min.js'></script>
    
    <script src="https://unpkg.com/leaflet-simple-map-screenshoter"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>


<script>
                        function mostrarOcultarSuelo() {
                            const cat = document.getElementById('selectCategoria').value;
                            const div = document.getElementById('divTipoSuelo');
                            if (cat === 'Pendiente') {
                                div.style.display = 'block';
                            } else {
                                div.style.display = 'none';
                            }
                        }
                    </script>
    <script>
        const LISTA_MAPAS = <?php echo json_encode($lista_mapas_visualizar); ?>;
        const MAPA_ID_ACTUAL = <?php echo isset($_GET['focus_map']) ? (int)$_GET['focus_map'] : 1; ?>;
        const IS_ADMIN = <?php echo $es_admin ? 'true' : 'false'; ?>;
        const ID_MI_USUARIO = <?= isset($_SESSION['id_usuario']) ? $_SESSION['id_usuario'] : 0 ?>;
        const MI_ROL = "<?= isset($_SESSION['tipo_usuario']) ? $_SESSION['tipo_usuario'] : '' ?>";

        function toggleSidebar(side) {
            const el = document.getElementById('sidebar-' + side);
            const other = document.getElementById('sidebar-' + (side === 'left' ? 'right' : 'left'));
            if(window.innerWidth < 768) other.classList.remove('sidebar-visible');
            el.classList.toggle('sidebar-visible');
        }

        // =======================================================
        // SCRIPT: REPORTE MANUAL CON GPS DEL TRABAJADOR
        // =======================================================
        function abrirModalReporteTrabajador() {
            document.getElementById('modalReporteTrabajador').style.display = 'block';
            const statusGps = document.getElementById('repTrabStatusGps');
            const btnSubmit = document.getElementById('repTrabSubmit');
            
            statusGps.innerHTML = '<i class="fas fa-satellite-dish fa-spin"></i> Buscando señal GPS...';
            statusGps.style.color = "#e67e22";
            btnSubmit.disabled = true;

            if ("geolocation" in navigator) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        document.getElementById('repTrabLat').value = position.coords.latitude;
                        document.getElementById('repTrabLng').value = position.coords.longitude;
                        statusGps.innerHTML = `✅ Ubicación lista (Precisión: ${position.coords.accuracy.toFixed(0)}m)`;
                        statusGps.style.color = "#27ae60";
                        btnSubmit.disabled = false; 
                    }, 
                    function(error) {
                        statusGps.innerHTML = "❌ Error GPS: Activa tu ubicación y da permisos al navegador.";
                        statusGps.style.color = "#c0392b";
                        alert("No pudimos obtener tu ubicación.\nPor favor, asegúrate de tener el GPS activado y de darle permisos a la página.");
                    }, 
                    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
                );
            } else {
                statusGps.innerHTML = "❌ Tu dispositivo no soporta GPS.";
                statusGps.style.color = "#c0392b";
            }
        }

        function cerrarModalReporteTrabajador() {
            document.getElementById('modalReporteTrabajador').style.display = 'none';
            document.getElementById('formReporteTrabajador').reset();
        }

        // =======================================================
        // SISTEMA OFFLINE Y GUARDADO DE ALERTAS MANUALES
        // =======================================================
        const offlineIndicator = document.getElementById('offlineIndicator');
        const offlineCount = document.getElementById('offlineCount');
        
        window.borrarAlertaOfflineLocal = function(lat, lng) {
            if(confirm("¿Estás seguro de descartar esta alerta local?")) {
                let pendientes = JSON.parse(localStorage.getItem('alertasOffline')) || [];
                pendientes = pendientes.filter(p => p.lat != lat || p.lng != lng);
                localStorage.setItem('alertasOffline', JSON.stringify(pendientes));
                
                map.closePopup();

                map.eachLayer(function(layer) {
                    if (layer.options && layer.options.icon && layer.options.icon.options.className === 'custom-offline-icon') {
                        map.removeLayer(layer);
                    }
                    if (layer.options && layer.options.dashArray === '5, 5' && layer.options.color === '#e74c3c') {
                        map.removeLayer(layer);
                    }
                });

                if (typeof marcadoresPeligro !== 'undefined') {
                    marcadoresPeligro = marcadoresPeligro.filter(m => !(m.lat == lat && m.lng == lng));
                }

                actualizarUIOffline(); 
                pendientes.forEach(p => dibujarAlertaOffline(p));
                alert("🗑️ Alerta local descartada exitosamente.");
            }
        };

        function actualizarUIOffline() {
            let pendientes = JSON.parse(localStorage.getItem('alertasOffline')) || [];
            if (pendientes.length > 0) {
                if(offlineIndicator) offlineIndicator.style.display = 'block';
                if(offlineCount) offlineCount.innerText = pendientes.length;
                if (navigator.onLine) {
                    if(offlineIndicator) {
                        offlineIndicator.style.background = '#f39c12';
                        offlineIndicator.innerHTML = `<i class="fas fa-sync fa-spin"></i> Subiendo ${pendientes.length} alertas...`;
                    }
                } else {
                    if(offlineIndicator) {
                        offlineIndicator.style.background = '#e74c3c';
                        offlineIndicator.innerHTML = `<i class="fas fa-wifi-slash"></i> <span id="offlineCount">${pendientes.length}</span> pendientes (Sin red)`;
                    }
                }
            } else {
                if(offlineIndicator) offlineIndicator.style.display = 'none';
            }
        }

       const formReporte = document.getElementById('formReporteTrabajador');
if (formReporte) {
    formReporte.addEventListener('submit', function(e) {
        e.preventDefault();

        // --- NUEVO: BLOQUEO DE SEGURIDAD (CANDADO) ---
        const nombreInput = document.getElementById('repTrabNombre').value.trim();
        const descInput = document.getElementById('repTrabDesc').value.trim();

        // Si la descripción está vacía, es porque no seleccionó nada del catálogo
        if (descInput === "" || nombreInput === "") {
            alert("⚠️ ERROR: Debes seleccionar un peligro válido del catálogo. No se permite el ingreso de datos manuales.");
            return; // Detiene el proceso de guardado aquí mismo
        }
        // ---------------------------------------------

        const btn = document.getElementById('repTrabSubmit');
        if(btn) { btn.disabled = true; btn.innerText = "Procesando..."; }

        // Inteligencia: Buscar si el operador está dentro de un Acta
        let idMapaDestino = 1; // Capa General por defecto
        if (typeof MAPA_ID_ACTUAL !== 'undefined' && MAPA_ID_ACTUAL > 1) {
            let mapaActual = LISTA_MAPAS.find(m => m.id_mapa == MAPA_ID_ACTUAL);
            if (mapaActual && mapaActual.categoria && mapaActual.categoria.toLowerCase().includes('acta')) {
                idMapaDestino = mapaActual.id_mapa; 
            }
        }

        const payload = {
            action: 'add_marker',
            lat: document.getElementById('repTrabLat').value,
            lng: document.getElementById('repTrabLng').value,
            nombre: nombreInput,
            descripcion: descInput,
            nivel: document.getElementById('repTrabNivel').value,
            radio: document.getElementById('repTrabRadio').value || 0,
            id_mapa: idMapaDestino
        };

        if (navigator.onLine) {
            enviarAlServidor(payload, false);
        } else {
            guardarLocalmente(payload);
            if(typeof cerrarModalReporteTrabajador === 'function') cerrarModalReporteTrabajador();
            if(btn) { btn.disabled = false; btn.innerText = "Guardar"; }
        }
    });
}

        function guardarLocalmente(payload) {
            let pendientes = JSON.parse(localStorage.getItem('alertasOffline')) || [];
            pendientes.push(payload);
            localStorage.setItem('alertasOffline', JSON.stringify(pendientes));
            actualizarUIOffline();
            dibujarAlertaOffline(payload);
            alert("⚠️ Estás sin conexión a Internet.\n\nLa alerta se guardó en tu teléfono y ya está activa en tu mapa. Se subirá automáticamente cuando recuperes la señal.");
        }

        function dibujarAlertaOffline(data) {
            if (typeof L === 'undefined' || typeof map === 'undefined' || !map) {
                setTimeout(() => dibujarAlertaOffline(data), 500);
                return;
            }

            const lat = parseFloat(data.lat);
            const lng = parseFloat(data.lng);
            const nivel = data.nivel || 'Alto';
            const radio = parseFloat(data.radio) || 0;
            const nombre = data.nombre || 'Alerta Local';

            const iconEmoji = nivel.toLowerCase() === 'critico' ? '🔴' : (nivel.toLowerCase() === 'medio' ? '🟢' : '🟠');
            const localIcon = L.divIcon({
                className: 'custom-offline-icon',
                html: `<div style="font-size:24px; filter: grayscale(30%);">${iconEmoji}</div>
                       <div style="font-size:9px; background:#e74c3c; color:white; border-radius:3px; padding:1px 3px; margin-top:-5px; text-align:center; box-shadow: 0 1px 3px rgba(0,0,0,0.5);">OFFLINE</div>`,
                iconSize: [30, 40],
                iconAnchor: [15, 20]
            });

            const marker = L.marker([lat, lng], {icon: localIcon}).addTo(map);
            
            const desc = data.descripcion ? `<div style="margin-top:6px; font-size:0.85rem; color:#555; background:#f9f9f9; padding:8px; border-radius:4px; border-left:3px solid #f39c12;"><i>"${data.descripcion}"</i></div>` : '';
            const rolUsuario = "<?php echo htmlspecialchars(nombreVisibleRol($tipo_usuario)); ?>";
            const nombreUsuario = "<?php echo htmlspecialchars($nombre_user); ?>";
            const creador = `<div style="margin-top:8px; font-size:0.75rem; color:#2980b9; text-transform: capitalize;"><b><i class="fas fa-user-shield"></i> ${rolUsuario}:</b> ${nombreUsuario}</div>`;

            let htmlPopup = `
                <div style="min-width: 180px; font-family: 'Segoe UI', sans-serif;">
                    <h4 style="margin:0 0 5px 0; color:#e74c3c; font-size:1.1rem; border-bottom:1px solid #eee; padding-bottom:5px;"><i class="fas fa-exclamation-triangle"></i> ${nombre}</h4>
                    ${desc}
                    ${creador}
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px;">
                        <div style="color:#e74c3c; font-size:0.80rem; font-weight:bold;">
                            <i class="fas fa-wifi-slash"></i> Sin red
                        </div>
                        <button onclick="borrarAlertaOfflineLocal('${lat}', '${lng}')" style="background:#e74c3c; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:0.8rem; font-weight:bold;"><i class="fas fa-trash"></i> Descartar</button>
                    </div>
                </div>
            `;
            marker.bindPopup(htmlPopup);

            if (radio > 0) {
                L.circle([lat, lng], {
                    color: '#e74c3c', fillColor: '#e74c3c', fillOpacity: 0.1, radius: radio, dashArray: '5, 5'
                }).addTo(map);
            }

            marker.tipo_geom = 'Point';
            marker.radio_custom = radio;
            marker.nombre_alerta = nombre;
            marker.id_db = 'offline_' + Date.now(); 

            if (typeof marcadoresPeligro !== 'undefined') {
                marcadoresPeligro.push(marker);
            }
        }

        function enviarAlServidor(payload, esSincronizacion) {
            const btn = document.getElementById('repTrabSubmit');
            fetch('Api/api_mapa.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(res => {
                if(res.success && !esSincronizacion) {
                    alert("📍 ¡Peligro reportado exitosamente!");
                    if(typeof cerrarModalReporteTrabajador === 'function') cerrarModalReporteTrabajador();
                    location.reload(); 
                }
            })
            .catch(err => {
                if(!esSincronizacion) {
                    guardarLocalmente(payload);
                    if(typeof cerrarModalReporteTrabajador === 'function') cerrarModalReporteTrabajador();
                }
            })
            .finally(() => {
                if(btn) { btn.disabled = false; btn.innerText = "Guardar"; }
            });
        }

        function sincronizarPendientes() {
            if (!navigator.onLine) return;
            
            let pendientes = JSON.parse(localStorage.getItem('alertasOffline')) || [];
            if (pendientes.length === 0) return;
            actualizarUIOffline();

            let alertasRestantes = []; 
            let alertasExitosas = 0;

            let promesas = pendientes.map(payload => {
                return fetch('Api/api_mapa.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                .then(async r => {
                    if (r.redirected || !r.ok) throw new Error('Sesión caducada o error de red');
                    return r.json();
                })
                .then(res => {
                    if (res.success) {
                        alertasExitosas++;
                    } else {
                        alertasRestantes.push(payload);
                    }
                })
                .catch(err => {
                    alertasRestantes.push(payload);
                });
            });

            Promise.allSettled(promesas).then(() => {
                localStorage.setItem('alertasOffline', JSON.stringify(alertasRestantes));
                actualizarUIOffline();

                if (alertasExitosas > 0) {
                    alert(`✅ ¡Se subieron ${alertasExitosas} alertas exitosamente al servidor!`);
                    location.reload(); 
                } else if (alertasRestantes.length > 0) {
                    alert("⚠️ No se pudieron subir las alertas. Es probable que tu sesión haya caducado. La página se recargará para reconectar.");
                    location.reload(); 
                }
            });
        }

        function sincronizarFirmasSeguridad() {
            if (!navigator.onLine) return; 
            
            let firmasPendientes = JSON.parse(localStorage.getItem('firmasSeguridadOffline')) || [];
            if (firmasPendientes.length === 0) return; 

            let firmasFallidas = [];
            let promesas = firmasPendientes.map(firma => {
                return fetch('Api/api_mapa.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(firma)
                })
                .then(async r => {
                    if (!r.ok || r.redirected) throw new Error("Sin sesión PHP");
                    return r.json();
                })
                .then(res => {
                    if (!res.success) throw new Error("Error interno al guardar");
                })
                .catch(err => {
                    firmasFallidas.push(firma);
                });
            });

            Promise.allSettled(promesas).then(() => {
                localStorage.setItem('firmasSeguridadOffline', JSON.stringify(firmasFallidas));
            });
        }

        // ==========================================
        // ROBOT SINCRONIZADOR DE FOTOS PIV OFFLINE
        // ==========================================
        function sincronizarCapturasOffline() {
            if (!navigator.onLine) return;

            let capturas = JSON.parse(localStorage.getItem('capturas_offline')) || [];
            if (capturas.length === 0) return;

            let fallidas = [];
            let promesas = capturas.map(cap => {
                return fetch('guardar_captura_mapa.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(cap)
                })
                .then(r => r.json())
                .then(res => {
                    if(!res.success) throw new Error("Fallo interno");
                }).catch(() => fallidas.push(cap));
            });

            Promise.allSettled(promesas).then(() => {
                localStorage.setItem('capturas_offline', JSON.stringify(fallidas));
                if(capturas.length > fallidas.length) console.log("✅ Fotos del mapa sincronizadas al servidor.");
            });
        }

        window.addEventListener('online', () => {
            sincronizarPendientes();
            sincronizarFirmasSeguridad();
            sincronizarCapturasOffline();
        });

        window.addEventListener('offline', actualizarUIOffline);

        document.addEventListener('DOMContentLoaded', () => {
            actualizarUIOffline();
            sincronizarPendientes();
            sincronizarFirmasSeguridad();
            sincronizarCapturasOffline();

            let pendientes = JSON.parse(localStorage.getItem('alertasOffline')) || [];
            pendientes.forEach(payload => {
                dibujarAlertaOffline(payload);
            });
        });

        async function subirMapaPorTrozos() {
            const input = document.getElementById('file-upload');
            const file = input.files[0];
            if (!file) return;

            const zonaInput = document.querySelector('input[name="nombre_zona"]').value.trim();
            if (!zonaInput) {
                alert("⚠️ Por favor, escribe o selecciona una Zona antes de subir el mapa.");
                input.value = ''; 
                return;
            }

            if (!confirm(`¿Subir este mapa de ${(file.size / 1024 / 1024).toFixed(2)} MB?`)) {
                input.value = '';
                return;
            }

            const loader = document.getElementById('loader');
            loader.innerHTML = `
                <div style="text-align:center; color:#3498db; background:white; padding:30px; border-radius:10px; box-shadow:0 0 20px rgba(0,0,0,0.2);">
                    <i class="fas fa-circle-notch fa-spin" style="font-size:3rem; margin-bottom:15px;"></i><br>
                    <strong style="font-size:1.2rem;">Subiendo Mapa ...</strong><br>
                    <span id="progreso-subida" style="font-size:1.5rem; font-weight:bold; color:#2c3e50;">0%</span>
                </div>`;
            loader.style.display = 'flex';

            const chunkSize = 1048576; 
            const totalChunks = Math.ceil(file.size / chunkSize);
            const categoria = document.querySelector('select[name="categoria"]').value;
            const uniqueFileId = Date.now() + '_' + file.name.replace(/[^a-zA-Z0-9.]/g, '');

            for (let i = 0; i < totalChunks; i++) {
                const start = i * chunkSize;
                const end = Math.min(start + chunkSize, file.size);
                const chunk = file.slice(start, end);

                const formData = new FormData();
                formData.append('chunk', chunk);
                formData.append('chunkIndex', i);
                formData.append('totalChunks', totalChunks);
                formData.append('fileName', file.name);
                formData.append('fileId', uniqueFileId);
                formData.append('nombre_zona', zonaInput);
                formData.append('categoria', categoria);
                const selectSuelo = document.getElementById('selectTipoSuelo');
                if (selectSuelo) {
                    formData.append('tipo_suelo', selectSuelo.value);
                }

                try {
                    const response = await fetch('Api/api_subirChunk.php', { method: 'POST', body: formData });
                    const textResponse = await response.text();
                    let result;
                    try { result = JSON.parse(textResponse); } catch (err) { throw new Error("El servidor no devolvió un JSON válido."); }
                    
                    if (!result.success) {
                        alert("❌ Error del Servidor: " + result.error);
                        loader.style.display = 'none';
                        input.value = '';
                        return;
                    }

                    const percent = Math.round(((i + 1) / totalChunks) * 100);
                    document.getElementById('progreso-subida').innerText = percent + '%';

                    if (result.completed) {
                        window.location.href = "index.php?status=success&focus_map=" + result.id_mapa + "&msg=Mapa de gran tamaño procesado y guardado con éxito.";
                        return;
                    }

                } catch (error) {
                    alert("❌ Ocurrió un error de red o de procesamiento. Revisa la consola.");
                    loader.style.display = 'none';
                    input.value = '';
                    return;
                }
            }
        }
      
    </script>
      <script>
    let catalogoAlertas = [];

    // 1. Descargar el catálogo silenciosamente al entrar al sistema
    document.addEventListener('DOMContentLoaded', async () => {
        try {
            const respuesta = await fetch('Api/api_mapa.php?action=fetch_catalogo');
            catalogoAlertas = await respuesta.json();
            
            // Llenar el "Buscador" (Datalist)
            const datalist = document.getElementById('listaCondiciones');
            if (datalist) {
                datalist.innerHTML = catalogoAlertas.map(item => 
                    `<option value="${item.nombre}"></option>`
                ).join('');
            }
        } catch (error) {
            console.error("Error al cargar el catálogo de alertas:", error);
        }
    });

    // 2. Función que se dispara cuando el operador elige una condición
    function autoCompletarAlerta() {
        const inputNombre = document.getElementById('repTrabNombre').value;
        
        // Buscar la condición elegida en nuestro catálogo
        const condicion = catalogoAlertas.find(c => c.nombre === inputNombre);
        
        if (condicion) {
            // Autorellenar y bloquear
            document.getElementById('repTrabDesc').value = condicion.descripcion;
            document.getElementById('repTrabNivel').value = condicion.nivel.toLowerCase(); // bajo, medio, alto
            document.getElementById('repTrabRadio').value = 0; // Inicia en 0 como pediste
            
            // (Opcional) Guardamos el trigger PIV en una variable global para usarlo después
            window.alertaActualEsPIV = condicion.piv_trigger; 
        } else {
            // Si borra el texto, limpiamos la descripción
            document.getElementById('repTrabDesc').value = "";
        }
    }
    
    
    
    // 3. Función de autocompletado LIBRE para el Administrador
    function autoCompletarAdmin() {
        const inputNombre = document.getElementById('popupNombre').value;
        const condicion = catalogoAlertas.find(c => c.nombre === inputNombre);
        
        if (condicion) {
            // Si elige algo del catálogo, le ahorramos trabajo y autocompletamos
            document.getElementById('popupDesc').value = condicion.descripcion;
            
            // Mapeamos el nivel del catálogo al formato del select del Admin
            let nivelDB = condicion.nivel.toLowerCase();
            if(nivelDB.includes('critic')) {
                document.getElementById('popupIcon').value = "Critico";
            } else if(nivelDB.includes('alto')) {
                document.getElementById('popupIcon').value = "Alto";
            } else {
                document.getElementById('popupIcon').value = "Medio";
            }
        }
        // NOTA CLAVE: Aquí NO hay un "else" que borre la descripción, 
        // ni tampoco bloqueamos el campo (readonly). 
        // Si el admin escribe un peligro inventado, simplemente no entra al 'if' y escribe libremente.
    }
</script>
    
    <script src="script_visor.js?v=4.6"></script>

</body>
</html>
