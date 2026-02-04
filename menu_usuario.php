<?php
// ==========================================================
// Archivo: menu_usuario.php (Versión: VISTA POR ZONAS)
// ==========================================================
session_start();

// Evitar caché para que los cambios de asignación se reflejen al instante
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['id_usuario'])) { 
    header("Location: login.php"); 
    exit; 
}

require_once __DIR__ . '/Config/db_config.php'; 

$zonas = [];
$error = "";
$id_usuario = $_SESSION['id_usuario'];

try {
    // 1. Traer Zonas Asignadas
    $sql = "SELECT z.id_zona, z.nombre_zona, uz.fecha_inicio, uz.fecha_fin 
            FROM public.zona z
            JOIN public.usuario_zona uz ON z.id_zona = uz.id_zona
            WHERE uz.id_usuario = ?
            AND (uz.fecha_inicio <= NOW())
            AND (uz.fecha_fin IS NULL OR uz.fecha_fin >= NOW())
            ORDER BY z.nombre_zona ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_usuario]);
    $zonas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Para cada zona, buscar el "Mapa Líder" para hacer zoom
    foreach ($zonas as &$zona) {
        // A) Contar mapas (para mostrar en la tarjeta)
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM public.mapa WHERE id_zona = ?");
        $stmtCount->execute([$zona['id_zona']]);
        $zona['total_mapas'] = $stmtCount->fetchColumn();

        // B) Buscar el mapa prioritario (Pendiente > Uso Suelo > Acta)
        // Usamos CASE WHEN para darle puntaje a las categorías
        $sqlPrioridad = "SELECT id_mapa FROM public.mapa 
                         WHERE id_zona = ? 
                         ORDER BY 
                            CASE 
                                WHEN categoria ILIKE '%pendiente%' THEN 1
                                WHEN categoria ILIKE '%uso%suelo%' THEN 2
                                WHEN categoria ILIKE '%acta%' THEN 3
                                ELSE 4 
                            END ASC,
                            id_mapa ASC
                         LIMIT 1"; // Solo queremos el ganador
        
        $stmtPrio = $pdo->prepare($sqlPrioridad);
        $stmtPrio->execute([$zona['id_zona']]);
        $mapaLider = $stmtPrio->fetchColumn();

        // Guardamos el ID del mapa ganador (o 0 si no hay mapas)
        $zona['id_mapa_zoom'] = $mapaLider ? $mapaLider : 0;
    }
    unset($zona);

} catch (Exception $e) {
    $error = "Error al cargar zonas.";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mis Zonas - Millalemu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style_usuario.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Estilos específicos para Tarjetas de Zona */
        .grid-mapas {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); /* Tarjetas más anchas */
            gap: 20px;
            padding: 20px 0;
        }
        .zone-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            padding: 25px;
            border-left: 6px solid #f1c40f; /* Color amarillo zona */
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .zone-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.1);
        }
        .zone-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            color: #2c3e50;
        }
        .zone-icon {
            font-size: 2rem;
            color: #f1c40f;
            margin-right: 15px;
        }
        .zone-title {
            font-size: 1.4rem;
            font-weight: bold;
        }
        .zone-info {
            color: #7f8c8d;
            font-size: 0.95rem;
            margin-bottom: 20px;
        }
        .btn-enter-zone {
            background-color: #27ae60;
            color: white;
            text-decoration: none;
            padding: 12px;
            border-radius: 6px;
            text-align: center;
            font-weight: bold;
            transition: background 0.3s;
            display: block;
        }
        .btn-enter-zone:hover { background-color: #219150; }
    </style>
</head>
<body>

<div class="leaves-container">
    <div class="leaf"></div><div class="leaf"></div>
</div>

<div class="container">

    <div class="header-bar">
        <div>
            <h2 style="color:#2c3e50;"><i class="fas fa-cubes"></i> Mis Zonas de Trabajo</h2>
            <div class="user-welcome">
                Operador: <b><?= htmlspecialchars($_SESSION['nombre_usuario'] ?? 'Usuario') ?></b>
            </div>
        </div>
        <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Salir</a>
    </div>

    <?php if(!empty($error)): ?>
        <div class="error-msg"><?= $error ?></div>
    <?php endif; ?>

    <?php if (!empty($zonas)): ?>
        <div class="grid-mapas">
            <?php foreach ($zonas as $z): ?>
                <div class="zone-card">
                    <div>
                        <div class="zone-header">
                            <i class="fas fa-map-marked-alt zone-icon"></i>
                            <div class="zone-title"><?= htmlspecialchars($z["nombre_zona"]) ?></div>
                        </div>
                        
                        <div class="zone-info">
                            <p><i class="fas fa-layer-group"></i> <b><?= $z['total_mapas'] ?></b> mapas disponibles</p>
                            <?php if($z['fecha_fin']): ?>
                                <p style="font-size:0.8rem; color:#e67e22;">
                                    <i class="fas fa-clock"></i> Acceso hasta: <?= date('d/m/Y', strtotime($z['fecha_fin'])) ?>
                                </p>
                            <?php else: ?>
                                <p style="font-size:0.8rem; color:#27ae60;">
                                    <i class="fas fa-check-circle"></i> Acceso Permanente
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php 
    $linkDestino = "index.php";
    if ($z['id_mapa_zoom'] > 0) {
        // Si encontramos un mapa prioritario, le decimos al visor que haga focus ahí
        $linkDestino .= "?focus_map=" . $z['id_mapa_zoom'];
    }
?>

<a href="<?= $linkDestino ?>" class="btn-enter-zone">
    Ingresar a Zona <i class="fas fa-arrow-right"></i>
</a>
                </div>
            <?php endforeach; ?>
        </div> 
    <?php else: ?>
        <div class="sin-mapas" style="text-align:center; padding:50px;">
            <i class="fas fa-folder-open fa-4x" style="color:#bdc3c7; margin-bottom:20px;"></i>
            <h3 style="color:#7f8c8d;">No tienes zonas asignadas</h3>
            <p style="color:#95a5a6;">Contacta a tu administrador para solicitar acceso.</p>
        </div>
    <?php endif; ?>

</div>
</body>
</html>