<?php
// ==========================================================
// Archivo: auditoria.php (Panel de Trazabilidad Legal)
// ==========================================================
session_start();
require_once __DIR__ . '/Config/db_config.php';

// Seguridad: Solo administradores pueden ver esto
$rol = strtolower(trim($_SESSION['tipo_usuario'] ?? ''));
$roles_permitidos = ['admin', 'ingeniero_forestal', 'jefe_operaciones'];

$es_admin = in_array($rol, $roles_permitidos, true);
$id_usuario_actual = $_SESSION['id_usuario'] ?? 1;

if (!$es_admin) {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
    exit;

    header("Location: login.php"); 
    exit; 
}

// --- MAGIA DEL BOTÓN EXCEL (Cierre de Mes Inteligente) ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    
    // 1. Buscamos SOLO los registros del mes pasado (o más antiguos).
    // Como ahora guardamos el 'nombre_mapa' directo, ya no necesitamos el JOIN.
    $sql_export = "SELECT * FROM public.registro_seguridad 
                   WHERE date_trunc('month', fecha_hora) < date_trunc('month', CURRENT_DATE)
                   ORDER BY fecha_hora ASC";
                   
    $stmt_exp = $pdo->query($sql_export);
    $datos_export = $stmt_exp->fetchAll(PDO::FETCH_ASSOC);

    if (count($datos_export) > 0) {
        header('Content-Type: text/csv; charset=utf-8');
        $mes_pasado = date('Y-m', strtotime('last month'));
        header('Content-Disposition: attachment; filename=Reporte_Auditoria_' . $mes_pasado . '.csv');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Usuario', 'Rol', 'Alerta', 'Mapa', 'Latitud', 'Longitud', 'Fecha']);
        
        foreach ($datos_export as $row) {
            $mapa_mostrar = !empty($row['nombre_mapa']) ? $row['nombre_mapa'] : 'General / Sistema';
            
            fputcsv($output, [
                $row['id_registro'], // <--- CORREGIDO (Antes decía solo 'id')
                $row['nombre_usuario'], 
                $row['rol_usuario'], 
                $row['tipo_alerta'], 
                $mapa_mostrar, 
                $row['latitud'], 
                $row['longitud'], 
                $row['fecha_hora']
            ]);
        }
        fclose($output);

        // 2. BORRAMOS SOLO LO DEL MES PASADO. 
        $pdo->exec("DELETE FROM public.registro_seguridad 
                    WHERE date_trunc('month', fecha_hora) < date_trunc('month', CURRENT_DATE)");
        exit;
    } else {
        echo "<script>alert('El mes pasado ya fue cerrado o no hay registros antiguos para descargar.'); window.location.href='auditoria.php';</script>";
        exit;
    }
}

// --- CARGA DE DATOS PARA LA PANTALLA ---
$stmt = $pdo->query("SELECT * FROM public.registro_seguridad ORDER BY fecha_hora DESC LIMIT 500");
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Auditoría de Seguridad - Millalemu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <style>
        body { background: #f4f7f6; font-family: 'Poppins', sans-serif; margin: 0; padding: 0; }
        .container { max-width: 1100px; margin: 30px auto; padding: 20px; position: relative; z-index: 10; }
        .header-panel { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .header-panel h1 { margin: 0; color: #2c3e50; font-size: 1.8rem; }
        .btn-export { background: #27ae60; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600; transition: 0.3s; display: inline-block; }
        .btn-export:hover { background: #219653; }
        .btn-back { background: #34495e; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600; margin-right: 10px; display: inline-block; }
        
        .table-container { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow-x: auto; position: relative; z-index: 10; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; color: #2c3e50; font-weight: 600; }
        tr:hover { background: #f1f2f6; }
        .badge { background: #e74c3c; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; font-weight: 600; }
        .badge-role { background: #3498db; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; text-transform: uppercase; }
        .empty-state { text-align: center; padding: 40px; color: #7f8c8d; }
    </style>
</head>
<body>

<?php
// --- INICIO: RECORDATORIO DINÁMICO DE CIERRE DE MES ---
$roles_autorizados = ['ingeniero_forestal', 'jefe_operaciones', 'admin'];

// 1. Consultamos si EXISTEN registros del mes pasado o anteriores
$sql_check_pendientes = "SELECT COUNT(*) FROM public.registro_seguridad 
                         WHERE date_trunc('month', fecha_hora) < date_trunc('month', CURRENT_DATE)";
$stmt_check = $pdo->query($sql_check_pendientes);
$pendientes_cierre = ($stmt_check->fetchColumn() > 0);

// 2. Si hay datos viejos pendientes y el rol es el correcto, mostramos la alerta
if ($pendientes_cierre && isset($_SESSION['tipo_usuario']) && in_array($_SESSION['tipo_usuario'], $roles_autorizados)) {
    
    // Nombres de meses para hacerlo amigable
    $meses = ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];
    $nombre_mes_pasado = $meses[(date('n') == 1 ? 12 : date('n') - 1) - 1];

    echo "
    <div style='background-color: #c0392b; color: white; padding: 12px 18px; 
                border-radius: 8px; font-weight: normal; margin: 15px; display: inline-block; 
                box-shadow: 0 4px 6px rgba(0,0,0,0.2); font-size: 0.95rem;'>
        <i class='fas fa-exclamation-triangle' style='font-weight: bold; margin-right: 5px;'></i> 
        ¡ATENCIÓN! Tienes registros pendientes de <b>{$nombre_mes_pasado}</b> (o anteriores). Descarga el Excel para cerrar el mes y limpiar el sistema.
    </div>
    <div style='clear:both;'></div>"; 
}
// --- FIN: RECORDATORIO DINÁMICO DE CIERRE DE MES ---
?>

<div class="container">
    <div class="header-panel">
        <div>
            <h1><i class="fas fa-shield-alt" style="color:#e74c3c;"></i> Auditoría de Seguridad Legal</h1>
            <p style="color: #7f8c8d; margin-top: 5px;">Registro inmutable de alertas confirmadas en terreno.</p>
        </div>
        <div>
            <a href="menuadmin.php" class="btn-back"><i class="fas fa-arrow-left"></i> Volver al Mapa</a>
            <a href="#" onclick="descargarYRecargar(); return false;" class="btn-export">
                <i class="fas fa-file-excel"></i> Descargar Excel
            </a>
        </div>

        <script>
        function descargarYRecargar() {
            // 1. Dispara la descarga del Excel
            window.location.href = 'auditoria.php?export=csv';
            
            // 2. Espera 1.5 segundos (para darle tiempo a la base de datos de borrar todo) 
            // y luego recarga la página a la fuerza para limpiar la tabla visualmente.
            setTimeout(function() {
                window.location.href = 'auditoria.php';
            }, 1500);
        }
        </script>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>N°</th>
                    <th>Trabajador</th>
                    <th>Rol</th>
                    <th>Mapa Asociado</th>
                    <th>Alerta Confirmada</th>
                    <th>Coordenadas GPS</th>
                    <th>Fecha y Hora</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($registros) > 0): ?>
                    <?php foreach ($registros as $r): 
                        // Formateo visual del ROL
                        $rol_mostrar = strtolower($r['rol_usuario']);
                        if ($rol_mostrar === 'jefe_faena') $rol_mostrar = 'Jefe de Faena';
                        elseif ($rol_mostrar === 'operador' || $rol_mostrar === 'usuario') $rol_mostrar = 'Operador';
                        else $rol_mostrar = ucfirst($rol_mostrar);
                    ?>
                        <tr>
                            <td>#<?= $r['id_registro'] ?></td>
                            <td style="font-weight: 500;"><?= htmlspecialchars($r['nombre_usuario']) ?></td>
                            <td><span class="badge-role"><?= htmlspecialchars($rol_mostrar) ?></span></td>
                            
                            <td><span style="color:#2c3e50; font-weight:600;"><i class="fas fa-map"></i> <?= htmlspecialchars($r['nombre_mapa']) ?></span></td>
                            
                            <td><span class="badge"><?= htmlspecialchars($r['tipo_alerta']) ?></span></td>
                            
                            <td>
                                <a href="https://www.google.com/maps/search/?api=1&query=<?= $r['latitud'] ?>,<?= $r['longitud'] ?>" target="_blank" style="color:#3498db; text-decoration:none;">
                                    <i class="fas fa-map-marker-alt"></i> <?= $r['latitud'] ?>, <?= $r['longitud'] ?>
                                </a>
                            </td>
                            <td><?= date('d/m/Y H:i', strtotime($r['fecha_hora'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="empty-state">
                            <i class="fas fa-clipboard-check" style="font-size: 3rem; margin-bottom: 10px; color:#bdc3c7;"></i><br>
                            Aún no hay registros de seguridad. Los datos aparecerán aquí cuando un trabajador confirme una alerta.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>