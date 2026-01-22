<?php
// ==========================================================
// Archivo: menuadmin.php
// ==========================================================
session_start();
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/Config/db_config.php'; 

if (!isset($_SESSION['id_usuario']) || $_SESSION['tipo_usuario'] !== 'admin') { 
    header("Location: login.php"); 
    exit; 
}

$nombre_user = $_SESSION['nombre_usuario'];

// --- CONTADORES ---
$stats = ['u'=>0, 'm'=>0, 'p'=>0];
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
                AND p.fecha_creacion::date = CURRENT_DATE  -- SOLO HOY
                ORDER BY p.fecha_creacion DESC"; 
               
                
    $reportes = $pdo->query($sql_rep)->fetchAll(PDO::FETCH_ASSOC);
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
        <div style="margin-top:auto; padding-bottom:20px;">
            <a href="logout.php" style="color:#ef5350;"><i class="fas fa-sign-out-alt"></i> Salir</a>
        </div>
    </aside>

    <main class="main">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h1>Bienvenido, <?php echo htmlspecialchars($nombre_user); ?></h1>
            <span style="background:#2c3e50; color:white; padding:5px 12px; border-radius:15px; font-size:0.85rem; border:1px solid #fdd835;">
                <i class="fas fa-shield-alt"></i> Supervisor
            </span>
        </div>

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

        <h2 style="color: #fdd835; font-size:1.3rem; margin-top:30px; margin-bottom:15px;">
            <i class="fas fa-bullhorn" style="color: #F54927;"></i> Reportes del Día (<?php echo date('d/m'); ?>)
        </h2>
        
        <table>
            <thead>
                <tr>
                    <th>Reporte</th><th>Ubicación</th><th>Usuario</th><th>Nivel</th><th>Radio</th> <th>Fecha</th><th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!empty($reportes)): ?>
                    <?php foreach($reportes as $r): 
                        $color = '#333';
                        $nivel = strtolower($r['nivel']);
                        if(strpos($nivel, 'critico') !== false) $color = '#dc3545';
                        elseif(strpos($nivel, 'alto') !== false) $color = '#e67e22';
                        elseif(strpos($nivel, 'medio') !== false) $color = '#27ae60';
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight:bold; color:#2c3e50;"><?php echo htmlspecialchars($r['nombre']); ?></div>
                            <small style="color:#666; font-style:italic;"><?php echo htmlspecialchars($r['descripcion']); ?></small>
                        </td>
                        <td style="color:#2980b9; font-weight:600;"><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($r['nombre_mapa'] ?? 'Capa Manual'); ?></td>
                        <td><?php echo htmlspecialchars($r['nombre_usuario']); ?></td>
                        <td style="color:<?php echo $color; ?>; font-weight:bold; text-transform:capitalize;"><?php echo htmlspecialchars($r['nivel']); ?></td>
                        <td>
                            <?php if($r['radio_metros'] > 0): ?>
                                <span style="background:#ffebee; color:#c0392b; padding:2px 6px; border-radius:4px; font-size:0.85rem; border:1px solid #ffcdd2;">
                                    <i class="fas fa-bullseye"></i> <?php echo $r['radio_metros']; ?>m
                                </span>
                            <?php else: ?> <span style="color:#aaa;">-</span> <?php endif; ?>
                        </td>
                        <td style="font-size:0.9rem; color:#555;"><?php echo date("H:i", strtotime($r['fecha_creacion'])); ?></td>
                        <td>
                            <button class="btn-del" onclick="resolver(<?php echo $r['id']; ?>)" title="Eliminar Alerta"><i class="fas fa-trash-alt"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align:center; padding:30px; color:#777;">No hay reportes hoy.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </main>

    <script>
        window.addEventListener("pageshow", function(event){
            var historyTraversal = event.persisted || (typeof window.performance != "undefined" && window.performance.navigation.type === 2);
            if(historyTraversal){window.location.reload();}
        });
        function resolver(id) {
            if(confirm("¿Borrar este reporte permanentemente?")) {
                fetch('Api/api_mapa.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action:'delete_marker', id:id}) })
                .then(r=>r.json()).then(res=>{ if(res.success) location.reload(); else alert("Error al borrar"); });
            }
        }
    </script>
</body>
</html>