<?php
// ==========================================================
// Archivo: menu_usuario.php
// ==========================================================
session_start();

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['id_usuario'])) { 
    header("Location: login.php"); 
    exit; 
}

require_once __DIR__ . '/Config/db_config.php'; 

$mapas = [];
$error = "";
$id_usuario = $_SESSION['id_usuario'];

try {

    
    $sql = "SELECT m.* FROM public.mapa m
            JOIN public.usuario_mapa um ON m.id_mapa = um.id_mapa
            WHERE um.id_usuario = ?
            AND (um.fecha_inicio <= NOW())
            AND (um.fecha_fin IS NULL OR um.fecha_fin >= NOW())
            ORDER BY m.fecha_creacion DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_usuario]);
    $mapas = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = "Error al cargar mapas asignados.";
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
</head>
<body>

<div class="leaves-container">
    <div class="leaf"></div><div class="leaf"></div>
    <div class="leaf"></div><div class="leaf"></div>
</div>

<div class="container">

    <div class="header-bar">
        <div>
            <h2><i class="fas fa-map-marked-alt"></i> Zonas Asignadas</h2>
            <div class="user-welcome">
                Bienvenido, <b><?= htmlspecialchars($_SESSION['nombre_usuario'] ?? 'Operador') ?></b>
            </div>
        </div>
        <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
    </div>

    <?php if(!empty($error)): ?>
        <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
    <?php endif; ?>

    <?php if (!empty($mapas)): ?>
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
</body>
</html>