<?php
// ==========================================================
// Archivo: index.php 
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
$mensaje = ""; 
$tipo_msg = ""; 
if (isset($_GET['msg'])) {
    $mensaje = htmlspecialchars($_GET['msg']);
    $tipo_msg = $_GET['status'] ?? 'error';
}

// 3. Carga de Datos para el Visor
$lista_mapas_visualizar = [];
$id_mapa_actual = isset($_GET['focus_map']) ? (int)$_GET['focus_map'] : 0;

if ($es_admin) {
    $stmt = $pdo->query("SELECT id_mapa, nombre_mapa, ruta_archivo FROM public.mapa ORDER BY id_mapa ASC");
    $lista_mapas_visualizar = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Si hay un mapa enfocado, verificar permiso
    if ($id_mapa_actual > 0) {
        $stmt = $pdo->prepare("SELECT m.* FROM public.mapa m 
                               JOIN public.usuario_mapa um ON m.id_mapa = um.id_mapa 
                               WHERE m.id_mapa = ? AND um.id_usuario = ? 
                               AND (um.fecha_inicio <= NOW()) 
                               AND (um.fecha_fin IS NULL OR um.fecha_fin >= NOW())");
        $stmt->execute([$id_mapa_actual, $id_usuario]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) $lista_mapas_visualizar[] = $res;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Visor Millalemu</title>
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="style_visor.css">
</head>
<body>

    <div id="loader"><i class="fas fa-circle-notch fa-spin"></i> Cargando...</div>

    <div id="controls">
        <div class="panel-header"><h1 class="app-title"><i class="fas fa-leaf"></i> Millalemu</h1></div>
        
        <div class="user-card">
            <div class="user-avatar"><?php echo strtoupper(substr($nombre_user, 0, 1)); ?></div>
            <div class="user-info">
                <h4><?php echo htmlspecialchars($nombre_user); ?></h4>
                <span class="<?php echo $es_admin ? 'badge-admin' : 'badge-user'; ?>">
                    <?php echo $es_admin ? 'Supervisor' : 'Operador'; ?>
                </span>
            </div>
        </div>
        
        <?php if ($es_admin) { ?>
            <span class="section-title">Administración</span>
            
            <form action="Api/api_subirMapa.php" method="post" enctype="multipart/form-data" id="uploadForm">
                <label class="file-upload">
                    <label for="file-upload" class="custom-file-upload">
                        <i class="fas fa-cloud-upload-alt"></i> Subir Mapa (GeoJSON)
                    </label>
                    <input id="file-upload" type="file" name="mapa" accept=".geojson" required 
                           onchange="document.getElementById('uploadForm').submit(); document.getElementById('loader').style.display='block';"/>
                </label>
            </form>
            
            <?php if(!empty($mensaje)) echo "<div style='font-size:0.8em; padding:8px; margin-bottom:10px; border-radius:4px; background:".($tipo_msg=='success'?'#d4edda':'#f8d7da')."; color:".($tipo_msg=='success'?'#155724':'#721c24').";'>$mensaje</div>"; ?>
            
            <a href="menuadmin.php" class="btn-panel btn-manage"><i class="fas fa-tachometer-alt"></i> Panel General</a>
            <a href="mapas.php" class="btn-panel btn-manage" style="background:#16a085;"><i class="fas fa-layer-group"></i> Gestionar Capas</a>
            <button id="borrarMarcadores" class="btn-panel btn-reset"><i class="fas fa-trash"></i> Borrar mapas </button>
        <?php } else { ?>
            <span class="section-title">Herramientas</span>
            <button id="btnToggleAlertas" onclick="toggleAlertas()" class="btn-panel btn-alert-on"><i class="fas fa-bell"></i> <span id="txtAlertas">Alertas: ON</span></button>
            <button onclick="reportarUser()" class="btn-panel btn-report"><i class="fas fa-broadcast-tower"></i> <b>SOS / ALERTA GPS</b></button>
            <a href="menu_usuario.php" class="btn-panel" style="background:#3498db; color:white;"><i class="fas fa-arrow-left"></i> Cambiar de Zona</a>
        <?php } ?>
        
        <hr style="border:0; border-top:1px solid #eee; margin: 15px 0;">
        <a href="logout.php" class="btn-panel btn-logout"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
    </div>

    <div class="legend">
        <div style="font-weight:bold; margin-bottom:8px;">Simbología</div>
        <div><span class="dot dot-red"></span> Crítico</div>
        <div><span class="dot dot-orange"></span> Alto</div>
        <div><span class="dot dot-green"></span> Medio</div>
        <div><span class="poly-zone"></span> Zona Forestal</div>
    </div>

    <div class="gps-btn" onclick="centrarEnUsuario()" title="Mi Ubicación"><i class="fas fa-location-arrow"></i></div>
    <div id="map"></div>
    <div id="alertas"></div>

    <div id="markerFormContainer">
        <form id="markerForm">
            <h3 style="text-align:center; color:#2c3e50; margin-top:0;">Agregar Alerta Manual</h3>
            <select id="selectorMapaForm" style="display:none;"><option value="">-- Selecciona el Mapa --</option></select>
            <input type="text" id="popupNombre" placeholder="Nombre / Título" required>
            <textarea id="popupDesc" placeholder="Descripción de la situación" rows="3"></textarea>
            
            <label style="font-size:0.85em; font-weight:bold; color:#7f8c8d;">Nivel de Riesgo:</label>
            <select id="popupIcon">
                <option value="Critico">🔴 Crítico (ROjoooo)</option>
                <option value="Alto">🟠 Alto (Naranja)</option>
                <option value="Medio">🟢 Medio (Verde)</option>
            </select>
            
            <label style="font-size:0.85em; font-weight:bold; color:#7f8c8d;">Radio de afectación (m):</label>
            <input type="number" id="popupRadio" placeholder="Ej: 50" min="0" max="2147483647">
            
            <div style="display:flex; gap:10px;">
                <button type="submit" style="flex:1; background:#27ae60; color:white; border:none; padding:12px; border-radius:6px; font-weight:bold; cursor:pointer;">Guardar</button>
                <button type="button" id="cancelMarker" style="flex:1; background:#95a5a6; color:white; border:none; padding:12px; border-radius:6px; cursor:pointer;">Cancelar</button>
            </div>
        </form>
    </div>

    <audio id="alertaAudio" src="https://actions.google.com/sounds/v1/alarms/beep_short.ogg"></audio>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <script>
        // Variables globales necesarias para script_visor.js
        const LISTA_MAPAS = <?php echo json_encode($lista_mapas_visualizar); ?>;
        const MAPA_ID_ACTUAL = <?php echo $id_mapa_actual; ?>;
        const IS_ADMIN = <?php echo $es_admin ? 'true' : 'false'; ?>;
    </script>

    <script src="script_visor.js"></script>

</body>
</html>