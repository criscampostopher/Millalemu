<?php
// ==========================================================
// Archivo: menuadmin.php (Con integración PIV)
// ==========================================================
session_start();
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/Config/db_config.php'; 
require_once __DIR__ . '/Config/roles.php';

if (!usuarioSesionPuedeAdministrar()) { 
    header("Location: login.php"); 
    exit; 
}

// --- NUEVO: Variables de usuario necesarias para el PIV ---
$id_usuario   = $_SESSION['id_usuario'];
$tipo_usuario = $_SESSION['tipo_usuario'];
$es_admin     = rolTieneFuncionesAdmin($tipo_usuario);



// Mini api del buscador 
if (isset($_GET['ajax_monitor']) && $es_admin) {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $est = trim($_GET['estado'] ?? '');

    $sql_ajax = "SELECT p.id_piv, p.id_mapa, m.nombre_mapa, f.predio, u.nombre_usuario as destinatario, e.estado, e.observacion_firma,
                 TO_CHAR(e.fecha_respuesta, 'DD-MM HH24:MI') as fecha_fmt
                 FROM piv_envio e
                 JOIN piv p ON e.id_piv = p.id_piv
                 LEFT JOIN mapa m ON p.id_mapa = m.id_mapa
                 LEFT JOIN piv_ficha f ON p.id_ficha = f.id_ficha
                 JOIN usuario u ON e.para_usuario = u.id_usuario
                 WHERE 1=1 ";
    $params = [];

    // Si escriben texto, buscamos coincidencias en la BD usando ILIKE (no distingue mayúsculas)
    if ($q !== '') {
        $sql_ajax .= " AND (p.id_piv::text ILIKE :q OR m.nombre_mapa ILIKE :q OR u.nombre_usuario ILIKE :q OR e.observacion_firma ILIKE :q) ";
        $params[':q'] = "%$q%";
    }
    if ($est !== '') {
        $sql_ajax .= " AND e.estado = :est ";
        $params[':est'] = $est;
    }

    $sql_ajax .= " ORDER BY e.fecha_respuesta DESC NULLS LAST, p.id_piv DESC ";
    
    // Si no están buscando nada mostramos 10, si buscan damos hasta 50 resultados
    $sql_ajax .= ($q === '' && $est === '') ? " LIMIT 10" : " LIMIT 50";

    try {
        $stmt = $pdo->prepare($sql_ajax);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}


// mini api Alertas Manuales

if (isset($_GET['ajax_alertas_manuales']) && $es_admin) {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');

    // Traemos descripción, radio y formateamos la hora para que iguale a tu diseño
   $sql_al = "SELECT p.id as id, p.nombre, p.descripcion, p.nivel, p.radio_metros, 
                      TO_CHAR(p.fecha_creacion, 'HH24:MI') as hora_fmt,
                      TO_CHAR(p.fecha_creacion, 'DD/MM/YYYY') as fecha_fmt,
                      u.nombre_usuario, 
                      COALESCE(m.nombre_mapa, 'Capa General') as nombre_mapa
               FROM public.peligro p
               JOIN public.usuario u ON p.id_usuario = u.id_usuario
               LEFT JOIN public.mapa m ON p.id_mapa = m.id_mapa
               WHERE p.id_usuario IS NOT NULL 
               AND p.nombre NOT ILIKE 'PENDIENTE%'   
               AND p.nombre NOT ILIKE '%Límite de Acta%'
            AND p.nombre NOT ILIKE '%Zona Protegida (Vegetación Nativa)%'
            AND p.nombre NOT ILIKE '%Zona Protegida (Protección de Agua)%'";
               
               
            
    // Si además quieres ser ultra estricto y que solo busque en la Capa General (1) 
    // y en los mapas que sean exclusivamente de la categoría 'acta', 
    // descomenta (quítale las dos barras //) a la siguiente línea:
    // $sql_al .= " AND (p.id_mapa = 1 OR m.categoria ILIKE '%acta%')";

    $params = [];
    if ($q !== '') {
        $sql_al .= " AND (p.nombre ILIKE :q OR p.descripcion ILIKE :q OR u.nombre_usuario ILIKE :q OR m.nombre_mapa ILIKE :q)";
        $params[':q'] = "%$q%";
    }

    $sql_al .= " ORDER BY p.fecha_creacion DESC ";
    $sql_al .= ($q === '') ? " LIMIT 10" : " LIMIT 50";

    try {
        $stmt = $pdo->prepare($sql_al);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}






// --- CONTADORES ORIGINALES ---
$stats = ['u'=>0, 'm'=>0, 'p'=>0];
$reportes = [];

try {
    $stats['u'] = $pdo->query("SELECT COUNT(*) FROM public.usuario")->fetchColumn();
    $stats['m'] = $pdo->query("SELECT COUNT(*) FROM public.mapa")->fetchColumn();
    $stats['p'] = $pdo->query("SELECT COUNT(*) FROM public.peligro WHERE estado = 'activa' AND fecha_creacion::date = CURRENT_DATE")->fetchColumn();

    // --- FILTRO POR DIA ---
    $sql_rep = "SELECT p.id, p.nombre, p.descripcion, p.nivel, p.radio_metros, p.fecha_creacion, 
                       m.nombre_mapa, u.nombre_usuario 
                FROM public.peligro p 
                LEFT JOIN public.mapa m ON p.id_mapa = m.id_mapa
                LEFT JOIN public.usuario u ON p.id_usuario = u.id_usuario 
                WHERE p.estado = 'activa'
                AND p.fecha_creacion::date = CURRENT_DATE
                ORDER BY p.fecha_creacion DESC"; 
                
    $reportes = $pdo->query($sql_rep)->fetchAll(PDO::FETCH_ASSOC);

    // --- NUEVO: PIVs PENDIENTES DE FIRMA (Para este usuario) ---
    $sql_piv = "SELECT e.id_envio, p.id_piv, p.fecha, m.nombre_mapa, u.nombre_usuario as remitente, e.mensaje, e.estado
                FROM piv_envio e
                JOIN piv p ON e.id_piv = p.id_piv
                LEFT JOIN mapa m ON p.id_mapa = m.id_mapa
                JOIN usuario u ON e.de_usuario = u.id_usuario
                WHERE e.para_usuario = :mi_id 
                AND e.estado IN ('enviado', 'visto')
                ORDER BY p.fecha DESC";
    $stmt = $pdo->prepare($sql_piv);
    $stmt->execute([':mi_id' => $id_usuario]);
    $mis_pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- NUEVO: MONITOR DE FIRMAS GLOBAL (Solo Admin) ---
    $monitor_pivs = [];
    if ($es_admin) {
        $sql_mon = "SELECT p.id_piv, p.id_mapa, m.nombre_mapa, f.predio, u.nombre_usuario as destinatario, e.estado, e.observacion_firma,
                    TO_CHAR(e.fecha_respuesta, 'DD-MM HH24:MI') as fecha_fmt
                    FROM piv_envio e
                    JOIN piv p ON e.id_piv = p.id_piv
                    LEFT JOIN mapa m ON p.id_mapa = m.id_mapa
                    LEFT JOIN piv_ficha f ON p.id_ficha = f.id_ficha
                    JOIN usuario u ON e.para_usuario = u.id_usuario
                    ORDER BY e.fecha_respuesta DESC NULLS LAST, p.id_piv DESC LIMIT 10";
        $monitor_pivs = $pdo->query($sql_mon)->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel Admin - Millalemu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="style-admin.css">

    <style>
        .piv-alert-box { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; }
        .piv-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .piv-warn { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        
        .piv-section { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-top: 25px; border-left: 5px solid #2e7d32; }
        .piv-section h3 { margin-top: 0; color: #2e7d32; display: flex; align-items: center; gap: 10px; }
        
        .table-piv { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .table-piv th { text-align: left; padding: 12px; background: #f8fafc; color: #64748b; font-size: 0.9rem; }
        .table-piv td { padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 0.95rem; }
        
        .btn-action { border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; color: white; font-weight: 600; font-size: 0.85rem; transition: 0.2s; margin-right: 5px; }
        .btn-ok { background: #22c55e; }
        .btn-ok:hover { background: #16a34a; }
        .btn-no { background: #ef4444; }
        .btn-no:hover { background: #dc2626; }
        .btn-pdf { background: #3498db; }
        .btn-pdf:hover { background: #2980b9; }
        
        .pill-st { padding: 4px 10px; border-radius: 99px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .st-aprobado { background: #dcfce7; color: #166534; }
        .st-rechazado { background: #fee2e2; color: #991b1b; }
        .st-pendiente { background: #f1f5f9; color: #475569; }
        
        .monitor-toolbar { display:flex; gap:10px; flex-wrap:wrap; margin: 10px 0 6px 0; }
        .monitor-input, .monitor-select {
            height: 38px; border: 1px solid #dbe4ee; border-radius: 10px; padding: 0 12px;
            font-family: inherit; font-size: 0.92rem; color: #1f2937; background: #fff;
        }
        .monitor-input { min-width: 280px; flex: 1 1 320px; }
        .monitor-select { min-width: 170px; }
        .monitor-empty { display:none; margin-top:10px; color:#64748b; font-weight:600; text-align:center; }
    </style>
</head>
<body>

    <div class="leaves-container">
        <div class="leaf" style="--i:1;"></div><div class="leaf" style="--i:2;"></div>
        <div class="leaf" style="--i:3;"></div><div class="leaf" style="--i:4;"></div>
        <div class="leaf" style="--i:5;"></div><div class="leaf" style="--i:6;"></div>
    </div>

    <aside class="sidebar">
        <h2 style="text-align:center; padding:10px; color:#fdd835;">Millalemu</h2>
        <a href="menuadmin.php" class="active"><i class="fas fa-home"></i> Inicio</a>
        <a href="index.php"><i class="fas fa-eye"></i> <b>Visor Global</b></a>
        <a href="mapas.php"><i class="fas fa-layer-group"></i> Gestión de Mapas</a>
        <a href="usuarios.php"><i class="fas fa-users"></i> Usuarios</a>
        
        <a href="auditoria.php"><i class="fas fa-shield-alt"></i> Auditoría de Seguridad</a>
        
        <a href="piv_formulario.php" class="piv-btn"><i class="fas fa-clipboard-list"></i> PIV Formulario</a>

        <div style="margin-top:auto; padding-bottom:20px;">
            <a href="logout.php" style="color:#ef5350;"><i class="fas fa-sign-out-alt"></i> Salir</a>
        </div>
    </aside>

    <main class="main">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h1>Bienvenido, <?php echo htmlspecialchars($nombre_user); ?></h1>
            <span style="background:#2c3e50; color:white; padding:5px 12px; border-radius:15px; font-size:0.85rem; border:1px solid #fdd835;">
                <i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars(nombreVisibleRol($tipo_usuario)); ?>
            </span>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] == 'firmado_ok'): ?>
                <div class="piv-alert-box piv-success"><i class="fas fa-check-circle"></i> Documento firmado exitosamente.</div>
            <?php elseif ($_GET['msg'] == 'observacion_guardada'): ?>
                <div class="piv-alert-box piv-warn"><i class="fas fa-exclamation-circle"></i> La observación ha sido registrada.</div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="cards">
            <div class="card">
                <h3><i class="fas fa-users"></i> Usuarios</h3>
                <p class="number"><?php echo $stats['u']; ?></p>
            </div>
            <div class="card">
                <h3><i class="fas fa-map"></i> Mapas Activos</h3>
                <p class="number"><?php echo $stats['m']; ?></p>
            </div>
            <div class="card">
                <h3><i class="fas fa-exclamation-triangle"></i> Alertas Hoy</h3>
                <p class="number"><?php echo $stats['p']; ?></p>
            </div>
        </div>

        <?php if (count($mis_pendientes) > 0): ?>
        <div class="piv-section">
            <h3><i class="fas fa-file-signature"></i> Tienes documentos pendientes de firma</h3>
            <div style="overflow-x:auto;">
                <table class="table-piv">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fecha</th>
                            <th>Proyecto / Mapa</th>
                            <th>Enviado por</th>
                            <th>Mensaje</th>
                            <th style="text-align:center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($mis_pendientes as $row): ?>
                        <?php
                            $rol_bloqueante = obtenerRolBloqueanteFirmaPiv($pdo, (int)$row['id_piv'], (int)$id_usuario);
                            $bloqueado_por_jerarquia = $rol_bloqueante !== null;
                        ?>
                        <tr>
                            <td><strong>#<?php echo $row['id_piv']; ?></strong></td>
                            <td><?php echo date('d/m/Y', strtotime($row['fecha'])); ?></td>
                            <td style="color:#15803d; font-weight:600;"><?php echo htmlspecialchars($row['nombre_mapa'] ?? 'Sin Mapa'); ?></td>
                            <td><?php echo htmlspecialchars($row['remitente']); ?></td>
                            <td style="font-style:italic; color:#666;"><?php echo htmlspecialchars($row['mensaje']); ?></td>
                            <td style="text-align:center; white-space: nowrap;">
                                <a href="piv_pdf_v2.php?id_piv=<?php echo $row['id_piv']; ?>" target="_blank" class="btn-action btn-pdf" title="Ver PDF">
                                    <i class="fas fa-file-pdf"></i>
                                </a>
                                <?php if ($bloqueado_por_jerarquia): ?>
                                <button type="button" class="btn-action" disabled style="background:#95a5a6; cursor:not-allowed;" title="Bloqueado por jerarquía">
                                    <i class="fas fa-lock"></i> Esperando firma de <?php echo htmlspecialchars(nombreVisibleRol($rol_bloqueante)); ?>
                                </button>
                                <?php else: ?>
                                <form action="procesar_firma.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="id_envio" value="<?php echo $row['id_envio']; ?>">
                                    <input type="hidden" name="id_piv" value="<?php echo $row['id_piv']; ?>">
                                    <input type="hidden" name="accion" id="act_<?php echo $row['id_envio']; ?>">
                                    <input type="hidden" name="observacion" id="obs_<?php echo $row['id_envio']; ?>">
                                    
                                    <button type="button" class="btn-action btn-ok" onclick="firmarPiv(<?php echo $row['id_envio']; ?>)" title="Aprobar / Firmar">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button type="button" class="btn-action btn-no" onclick="rechazarPiv(<?php echo $row['id_envio']; ?>)" title="Observar / Rechazar">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:30px; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
            <h2 style="color: #fdd835; font-size:1.3rem; margin:0;">
                <i class="fas fa-bullhorn" style="color: #F54927;"></i> Gestión de Alertas Manuales
            </h2>
            <input type="text" id="alertaManualSearch" class="monitor-input" placeholder="Buscar alerta, usuario o mapa..." style="max-width:300px;">
        </div>
        
        <table style="width:100%; border-collapse:collapse; background:rgba(255,255,255,0.9); border-radius:10px; overflow:hidden;" id="alertaManualTable">
            <thead style="background:#2c3e50; color:white;">
                <tr>
                    <th style="padding:10px; text-align:left;">Reporte</th>
                    <th style="padding:10px; text-align:left;">Ubicación</th>
                    <th style="padding:10px; text-align:left;">Usuario</th>
                    <th style="padding:10px; text-align:left;">Nivel</th>
                    <th style="padding:10px; text-align:left;">Radio</th> 
                    <th style="padding:10px; text-align:left;">Fecha</th>
                    <th style="padding:10px; text-align:center;">Acción</th>
                </tr>
            </thead>
            <tbody>
                </tbody>
        </table>
        <div id="alertaEmpty" style="display:none; text-align:center; padding:30px; color:#777; background:rgba(255,255,255,0.9); border-radius:0 0 10px 10px;">
            No se encontraron alertas manuales.
        </div>
        
        

        <?php if ($es_admin): ?>
        <div class="piv-section" style="border-left-color: #f59e0b;">
            <h3 style="color:#f59e0b;"><i class="fas fa-tower-observation"></i> Monitor Global de Firmas (Últimos 10)</h3>
            <div class="monitor-toolbar">
                <input type="text" id="monitorSearch" class="monitor-input" placeholder="Buscar por PIV, mapa, destinatario u observación...">
                <select id="monitorEstado" class="monitor-select">
                    <option value="">Todos los estados</option>
                    <option value="aprobado">Aprobado</option>
                    <option value="rechazado">Rechazado</option>
                    <option value="pendiente">Pendiente</option>
                </select>
            </div>
            <div style="overflow-x:auto;">
                <table class="table-piv" id="monitorTable">
                    <thead>
                        <tr>
                            <th>PIV</th>
                            <th>Mapa</th>
                            <th>Destinatario</th>
                            <th>Estado</th>
                            <th>Fecha Respuesta</th>
                            <th>Observación</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($monitor_pivs) > 0): ?>
                            <?php foreach($monitor_pivs as $mon): 
                                $clase = 'st-pendiente';
                                if($mon['estado'] == 'aprobado') $clase = 'st-aprobado';
                                if($mon['estado'] == 'rechazado') $clase = 'st-rechazado';
                                $estadoRaw = strtolower(trim((string)($mon['estado'] ?? 'pendiente')));
                                $obsRaw = strtolower(trim((string)($mon['observacion_firma'] ?? '')));
                                $txtSearch = strtolower(trim(
                                    '#' . (int)$mon['id_piv'] . ' ' .
                                    (string)($mon['nombre_mapa'] ?? '') . ' ' .
                                    (string)($mon['id_mapa'] ?? '') . ' ' .
                                    (string)($mon['destinatario'] ?? '') . ' ' .
                                    $estadoRaw . ' ' .
                                    $obsRaw
                                ));
                            ?>
                            <tr class="monitor-row"
                                data-estado="<?php echo htmlspecialchars($estadoRaw, ENT_QUOTES, 'UTF-8'); ?>"
                                data-search="<?php echo htmlspecialchars($txtSearch, ENT_QUOTES, 'UTF-8'); ?>">
                                <td>#<?php echo $mon['id_piv']; ?></td>
                                <td><?php echo htmlspecialchars($mon['nombre_mapa'] ?? 'Sin mapa'); ?></td>
                                <td><?php echo htmlspecialchars($mon['destinatario']); ?></td>
                                <td><span class="pill-st <?php echo $clase; ?>"><?php echo ucfirst($mon['estado']); ?></span></td>
                                <td><?php echo $mon['fecha_fmt'] ? $mon['fecha_fmt'] : '-'; ?></td>
                                <td style="color:#dc2626; font-size:0.85rem; font-style:italic;">
                                    <?php echo htmlspecialchars($mon['observacion_firma'] ?? ''); ?>
                                </td>
                                <td style="text-align:center; white-space:nowrap;">
                                    <a href="piv_formulario.php?edit_piv=<?php echo (int)$mon['id_piv']; ?>" class="btn-action" style="background:#f59e0b;" title="Editar PIV">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <a href="piv_pdf_v2.php?id_piv=<?php echo (int)$mon['id_piv']; ?>" target="_blank" class="btn-action btn-pdf" title="Ver PDF">
                                        <i class="fas fa-file-pdf"></i>
                                    </a>
                                    <button class="btn-action btn-no" onclick="eliminarPiv(<?php echo (int)$mon['id_piv']; ?>, this)" title="Eliminar PIV">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align:center; color:#64748b; padding:16px;">
                                    No hay registros en el monitor global por ahora.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div id="monitorEmpty" class="monitor-empty">No hay resultados con ese filtro.</div>
        </div>
        <?php endif; ?>

    </main>

    <script>
        // Recargar página al volver atrás (para actualizar estados de PIV)
        window.addEventListener("pageshow", function(event){
            var historyTraversal = event.persisted || (typeof window.performance != "undefined" && window.performance.navigation.type === 2);
            if(historyTraversal){window.location.reload();}
        });

        // Alertas (Original)
        function resolver(id) {
            if(confirm("¿Borrar este reporte permanentemente?")) {
                fetch('Api/api_mapa.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action:'delete_marker', id:id}) })
                .then(r=>r.json()).then(res=>{ if(res.success) location.reload(); else alert("Error al borrar"); });
            }
        }

        // --- NUEVO: Funciones para firmar/rechazar PIVs ---
        function firmarPiv(idEnvio) {
            if(confirm("¿Confirma que ha revisado y aprueba este documento PIV?")) {
                document.getElementById('act_' + idEnvio).value = 'aprobar';
                document.getElementById('act_' + idEnvio).closest('form').submit();
            }
        }

        function rechazarPiv(idEnvio) {
            let motivo = prompt("Por favor, indique el motivo de la observación o rechazo:");
            if(motivo && motivo.trim() !== "") {
                document.getElementById('act_' + idEnvio).value = 'rechazar';
                document.getElementById('obs_' + idEnvio).value = motivo;
                document.getElementById('act_' + idEnvio).closest('form').submit();
            } else if (motivo !== null) {
                alert("Debe escribir un motivo para observar el documento.");
            }
        }

        // --- Eliminar PIV ---
        function eliminarPiv(idPiv, btn) {
            if (!confirm(`¿Eliminar permanentemente el PIV #${idPiv} y todos sus envíos?`)) return;
            btn.disabled = true;
            fetch('piv_eliminar.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id_piv: idPiv})
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    btn.closest('tr').remove();
                } else {
                    alert('Error al eliminar: ' + (res.error || 'desconocido'));
                    btn.disabled = false;
                }
            })
            .catch(() => { alert('Error de conexión.'); btn.disabled = false; });
        }

      // --- NUEVO: Buscador AJAX para el Monitor (Busca en toda la BD) ---
        const monitorSearch = document.getElementById('monitorSearch');
        const monitorEstado = document.getElementById('monitorEstado');
        const tbody = document.querySelector('#monitorTable tbody');
        const monitorEmpty = document.getElementById('monitorEmpty');

        let debounceTimer;

        function buscarMonitorAJAX() {
            clearTimeout(debounceTimer);
            // Esperamos 300ms después de que el usuario deje de teclear para no saturar el servidor
            debounceTimer = setTimeout(() => {
                const q = encodeURIComponent(monitorSearch ? monitorSearch.value.trim() : '');
                const est = encodeURIComponent(monitorEstado ? monitorEstado.value : '');

                tbody.style.opacity = '0.4'; // Efecto visual de cargando

                fetch(`menuadmin.php?ajax_monitor=1&q=${q}&estado=${est}`)
                .then(r => r.json())
                .then(res => {
                    tbody.style.opacity = '1';
                    if (!res.success) return;

                    const data = res.data;
                    tbody.innerHTML = ''; // Limpiamos la tabla

                    if (data.length === 0) {
                        monitorEmpty.style.display = 'block';
                    } else {
                        monitorEmpty.style.display = 'none';
                        
                        data.forEach(mon => {
                            let clase = 'st-pendiente';
                            if (mon.estado === 'aprobado') clase = 'st-aprobado';
                            if (mon.estado === 'rechazado') clase = 'st-rechazado';
                            
                            const estadoCap = mon.estado.charAt(0).toUpperCase() + mon.estado.slice(1);
                            const fecha = mon.fecha_fmt ? mon.fecha_fmt : '-';
                            const mapa = mon.nombre_mapa ? mon.nombre_mapa : 'Sin mapa';
                            const obs = mon.observacion_firma ? mon.observacion_firma : '';

                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td>#${mon.id_piv}</td>
                                <td>${escapeHtml(mapa)}</td>
                                <td>${escapeHtml(mon.destinatario)}</td>
                                <td><span class="pill-st ${clase}">${estadoCap}</span></td>
                                <td>${fecha}</td>
                                <td style="color:#dc2626; font-size:0.85rem; font-style:italic;">${escapeHtml(obs)}</td>
                                <td style="text-align:center; white-space:nowrap;">
                                    <a href="piv_formulario.php?edit_piv=${mon.id_piv}" class="btn-action" style="background:#f59e0b;" title="Editar PIV">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <a href="piv_pdf_v2.php?id_piv=${mon.id_piv}" target="_blank" class="btn-action btn-pdf" title="Ver PDF">
                                        <i class="fas fa-file-pdf"></i>
                                    </a>
                                    <button class="btn-action btn-no" onclick="eliminarPiv(${mon.id_piv}, this)" title="Eliminar PIV">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            `;
                            tbody.appendChild(tr);
                        });
                    }
                })
                .catch(err => {
                    tbody.style.opacity = '1';
                    console.error("Error de búsqueda:", err);
                });
            }, 300); 
        }

        // Utilidad de seguridad para evitar inyección de código al dibujar HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.innerText = text;
            return div.innerHTML;
        }

        if (monitorSearch) monitorSearch.addEventListener('input', buscarMonitorAJAX);
        if (monitorEstado) monitorEstado.addEventListener('change', buscarMonitorAJAX);
        
        
        
        
        const alertaSearch = document.getElementById('alertaManualSearch');
        const alertaTbody = document.querySelector('#alertaManualTable tbody');
        let debounceAlerta;

        function buscarAlertasAJAX() {
            clearTimeout(debounceAlerta);
            debounceAlerta = setTimeout(() => {
                const q = encodeURIComponent(alertaSearch ? alertaSearch.value.trim() : '');
                alertaTbody.style.opacity = '0.5';
                
                fetch(`menuadmin.php?ajax_alertas_manuales=1&q=${q}`)
                .then(r => r.json())
                .then(res => {
                    alertaTbody.style.opacity = '1';
                    if (!res.success) return;
                    
                    alertaTbody.innerHTML = ''; // Limpiar tabla
                    
                    if (res.data.length === 0) {
                        document.getElementById('alertaEmpty').style.display = 'block';
                    } else {
                        document.getElementById('alertaEmpty').style.display = 'none';
                        
                        res.data.forEach(r => {
                            // Lógica de colores idéntica a tu PHP
                            let colorNivel = '#333';
                            const nivelLower = r.nivel ? r.nivel.toLowerCase() : '';
                            if(nivelLower.includes('critico')) colorNivel = '#dc3545';
                            else if(nivelLower.includes('alto')) colorNivel = '#e67e22';
                            else if(nivelLower.includes('medio')) colorNivel = '#27ae60';

                            // Lógica de Radio idéntica a tu PHP
                            let radioHtml = r.radio_metros > 0 
                                ? `<span style="background:#ffebee; color:#c0392b; padding:2px 6px; border-radius:4px; font-size:0.85rem; border:1px solid #ffcdd2;"><i class="fas fa-bullseye"></i> ${r.radio_metros}m</span>` 
                                : `<span style="color:#aaa;">-</span>`;

                            const mapaName = r.nombre_mapa ? r.nombre_mapa : 'Capa Manual';
                            const desc = r.descripcion ? r.descripcion : '';

                            // Construcción de la fila imitando tu HTML
                            const tr = document.createElement('tr');
                            tr.style.borderBottom = '1px solid #eee';
                            tr.innerHTML = `
                                <td style="padding:10px;">
                                    <div style="font-weight:bold; color:#2c3e50;">${escapeHtml(r.nombre)}</div>
                                    <small style="color:#666; font-style:italic;">${escapeHtml(desc)}</small>
                                </td>
                                <td style="color:#2980b9; font-weight:600; padding:10px;">
                                    <i class="fas fa-layer-group"></i> ${escapeHtml(mapaName)}
                                </td>
                                <td style="padding:10px;">${escapeHtml(r.nombre_usuario)}</td>
                                <td style="color:${colorNivel}; font-weight:bold; text-transform:capitalize; padding:10px;">
                                    ${escapeHtml(r.nivel)}
                                </td>
                                <td style="padding:10px;">${radioHtml}</td>
                                <td style="font-size:0.9rem; color:#555; padding:10px;">
                                    <div style="font-weight:bold;">${escapeHtml(r.hora_fmt)}</div>
                                    <div style="font-size:0.8rem; color:#888;">${escapeHtml(r.fecha_fmt)}</div>
                                </td>
                                <td style="padding:10px; text-align:center;">
                                    <button class="btn-del" onclick="resolver(${r.id})" title="Eliminar Alerta" style="background:#e74c3c; color:white; border:none; padding:5px 8px; border-radius:4px; cursor:pointer;">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            `;
                            alertaTbody.appendChild(tr);
                        });
                    }
                }).catch(() => alertaTbody.style.opacity = '1');
            }, 300);
        }

        if (alertaSearch) alertaSearch.addEventListener('input', buscarAlertasAJAX);
        // Carga la tabla apenas entras a la página
        document.addEventListener('DOMContentLoaded', buscarAlertasAJAX);
    </script>
</body>
</html>
