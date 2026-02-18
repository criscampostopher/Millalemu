<?php
// ==========================================================
// Archivo: menu_usuario.php (Con Módulo de Firmas PIV)
// ==========================================================
session_start();

// Evitar cacheo
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Verificar Sesión
if (!isset($_SESSION['id_usuario'])) { 
    header("Location: login.php"); 
    exit; 
}

require_once __DIR__ . '/Config/db_config.php'; 

$id_usuario = $_SESSION['id_usuario'];
$nombre_usuario = $_SESSION['nombre_usuario'] ?? 'Operador';
$mapas = [];
$pivs_pendientes = []; // Array para los documentos
$error = "";

try {
    // 1. CARGAR MAPAS ASIGNADOS (Tu código original)
    $sql_mapas = "SELECT m.* FROM public.mapa m
            JOIN public.usuario_mapa um ON m.id_mapa = um.id_mapa
            WHERE um.id_usuario = ?
            AND (um.fecha_inicio <= NOW())
            AND (um.fecha_fin IS NULL OR um.fecha_fin >= NOW())
            ORDER BY m.fecha_creacion DESC";

    $stmt = $pdo->prepare($sql_mapas);
    $stmt->execute([$id_usuario]);
    $mapas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. CARGAR PIVs PENDIENTES DE FIRMA (Nuevo)
    $sql_piv = "SELECT e.id_envio, p.id_piv, p.fecha, m.nombre_mapa, u.nombre_usuario as remitente, e.mensaje, e.estado
                FROM piv_envio e
                JOIN piv p ON e.id_piv = p.id_piv
                LEFT JOIN mapa m ON p.id_mapa = m.id_mapa
                JOIN usuario u ON e.de_usuario = u.id_usuario
                WHERE e.para_usuario = ? 
                AND e.estado IN ('enviado', 'visto')
                ORDER BY p.fecha DESC";
    
    $stmt_piv = $pdo->prepare($sql_piv);
    $stmt_piv->execute([$id_usuario]);
    $pivs_pendientes = $stmt_piv->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = "Error al cargar datos.";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel de Mapas - Millalemu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style_usuario.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .piv-container {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-left: 5px solid #e67e22; /* Naranja para llamar la atención */
        }
        .piv-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            color: #d35400;
            font-weight: bold;
            font-size: 1.1rem;
        }
        .piv-item {
            background: #fdfefe;
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }
        .piv-info strong { color: #2c3e50; display: block; }
        .piv-info span { color: #7f8c8d; font-size: 0.9rem; }
        
        .piv-actions { display: flex; gap: 10px; }
        .btn-sign {
            background: #27ae60; color: white; border: none; padding: 8px 15px;
            border-radius: 6px; cursor: pointer; font-weight: bold; transition: 0.2s;
            text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
        }
        .btn-sign:hover { background: #219150; }
        
        .btn-reject {
            background: #c0392b; color: white; border: none; padding: 8px 15px;
            border-radius: 6px; cursor: pointer; font-weight: bold; transition: 0.2s;
        }
        .btn-reject:hover { background: #a93226; }

        /* Mensajes de éxito/error */
        .msg-box { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; text-align: center; }
        .msg-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg-warn { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }


        .btn-pdf {
    background: #3498db; color: white; border: none; padding: 8px 15px;
    border-radius: 6px; cursor: pointer; font-weight: bold; transition: 0.2s;
    text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
}
.btn-pdf:hover { background: #2980b9; }
    </style>
</head>
<body>

<div class="leaves-container">
    <div class="leaf"></div><div class="leaf"></div>
    <div class="leaf"></div><div class="leaf"></div>
</div>

<div class="container">

    <div class="header-bar">
        <div>
            <h2><i class="fas fa-map-marked-alt"></i> Panel de Operaciones</h2>
            <div class="user-welcome">
                Bienvenido, <b><?= htmlspecialchars($nombre_usuario) ?></b>
            </div>
        </div>
        <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <?php if ($_GET['msg'] == 'firmado_ok'): ?>
            <div class="msg-box msg-success"><i class="fas fa-check-circle"></i> Documento PIV firmado correctamente.</div>
        <?php elseif ($_GET['msg'] == 'observacion_guardada'): ?>
            <div class="msg-box msg-warn"><i class="fas fa-exclamation-triangle"></i> Observación enviada al supervisor.</div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if(!empty($error)): ?>
        <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
    <?php endif; ?>


    <?php if (!empty($pivs_pendientes)): ?>
        <div class="piv-container">
            <div class="piv-header">
                <i class="fas fa-file-signature fa-lg"></i> Documentos Pendientes de Firma
            </div>
            <p style="margin-bottom: 15px; color: #666; font-size: 0.9rem;">
                Tienes los siguientes documentos asignados. Debes revisarlos y firmarlos (o rechazarlos) para continuar.
            </p>

            <?php foreach ($pivs_pendientes as $piv): ?>
                <div class="piv-item">
                    <div class="piv-info">
                        <strong>Documento #<?= $piv['id_piv'] ?> - <?= htmlspecialchars($piv['nombre_mapa']) ?></strong>
                        <span>Enviado por: <?= htmlspecialchars($piv['remitente']) ?></span><br>
                        <span style="font-style: italic;">"<?= htmlspecialchars($piv['mensaje']) ?>"</span>
                    </div>
                    
                    <div class="piv-actions">
    <a href="piv_pdf_v2.php?id_piv=<?= $piv['id_piv'] ?>" target="_blank" class="btn-pdf" title="Ver documento original">
        <i class="fas fa-file-pdf"></i> Ver PDF
    </a>

    <form action="procesar_firma.php" method="POST" style="margin:0; display:flex; gap:10px;">
        <input type="hidden" name="id_envio" value="<?= $piv['id_envio'] ?>">
        <input type="hidden" name="id_piv" value="<?= $piv['id_piv'] ?>">
        <input type="hidden" name="accion" id="act_<?= $piv['id_envio'] ?>">
        <input type="hidden" name="observacion" id="obs_<?= $piv['id_envio'] ?>">

        <button type="button" class="btn-sign" onclick="firmar(<?= $piv['id_envio'] ?>)">
            <i class="fas fa-check"></i> Firmar
        </button>
        <button type="button" class="btn-reject" onclick="rechazar(<?= $piv['id_envio'] ?>)">
            <i class="fas fa-times"></i> Observar
        </button>
    </form>
</div>
                </div>
            <?php endforeach; ?>
        </div>
        
    <?php endif; ?>


    <?php if (!empty($mapas)): ?>
        <h3 style="color:#2c3e50; margin-bottom:15px;"><i class="fas fa-layer-group"></i> Tus Zonas Asignadas</h3>
        <div class="grid-mapas">
            <?php foreach ($mapas as $m): ?>
                <div class="map-card">
                    <div>
                        <div class="map-title"><?= htmlspecialchars($m["nombre_mapa"]) ?></div>
                        <div class="map-type"><?= htmlspecialchars($m["tipo_mapa"]) ?></div>
                        
                        <div class="map-date" style="color:#27ae60; font-size:0.8rem;">
                            <i class="fas fa-check-circle"></i> Disponible ahora
                        </div>
                    </div>

                    <a href="index.php?focus_map=<?= $m['id_mapa']; ?>" class="btn-ver">
                        Ingresar al Mapa <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            <?php endforeach; ?>
        </div> 
    <?php else: ?>
        <div class="sin-mapas">
            <i class="fas fa-clock fa-3x" style="margin-bottom:15px; display:block; color:#f39c12;"></i>
            <p>No tienes mapas asignados para este horario.</p>
            <small>Contacta a tu supervisor.</small>
        </div>
    <?php endif; ?>

</div>

<script>
    function firmar(idEnvio) {
        if(confirm("¿Confirmas que has revisado el documento y procedes con tu firma?")) {
            document.getElementById('act_' + idEnvio).value = 'aprobar';
            document.getElementById('act_' + idEnvio).closest('form').submit();
        }
    }

    function rechazar(idEnvio) {
        let motivo = prompt("Por favor indica el motivo de la observación:");
        if(motivo && motivo.trim() !== "") {
            document.getElementById('act_' + idEnvio).value = 'rechazar';
            document.getElementById('obs_' + idEnvio).value = motivo;
            document.getElementById('act_' + idEnvio).closest('form').submit();
        } else if (motivo !== null) {
            alert("Debes escribir un motivo.");
        }
    }
</script>

</body>
</html>