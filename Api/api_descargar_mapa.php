<?php
// Api/api_descargar_mapa.php (Versión V2 - Limpieza de Buffer)
// Iniciamos el buffer para capturar cualquier error o espacio en blanco previo
ob_start();

session_start();
require_once __DIR__ . '/../Config/db_config.php';

// Validar acceso
if (!isset($_SESSION['id_usuario'])) {
    ob_end_clean(); // Borramos basura
    http_response_code(403);
    die("Acceso denegado");
}

$id_mapa = $_GET['id'] ?? null;

if ($id_mapa) {
    try {
        $stmt = $pdo->prepare("SELECT contenido_source, tipo_mapa, nombre_mapa FROM public.mapa WHERE id_mapa = ?");
        $stmt->execute([$id_mapa]);
        $mapa = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($mapa && !empty($mapa['contenido_source'])) {
            
            // --- LIMPIEZA TOTAL DEL BUFFER ---
            // Esto borra cualquier "echo", espacio o error que haya soltado db_config.php
            if (ob_get_length()) ob_clean(); 
            
            // Cabeceras correctas
            if ($mapa['tipo_mapa'] === 'GeoJSON') {
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: inline; filename="' . $mapa['nombre_mapa'] . '.geojson"');
            } elseif ($mapa['tipo_mapa'] === 'KML') {
                header('Content-Type: application/vnd.google-earth.kml+xml; charset=utf-8');
                header('Content-Disposition: inline; filename="' . $mapa['nombre_mapa'] . '.kml"');
            } else {
                header('Content-Type: text/plain; charset=utf-8');
            }

            // Entregar contenido LIMPIO
            echo $mapa['contenido_source'];
            exit;
        } else {
            if (ob_get_length()) ob_clean();
            http_response_code(404);
            die("Error: El mapa existe en BD pero el contenido está vacío.");
        }
    } catch (Exception $e) {
        if (ob_get_length()) ob_clean();
        http_response_code(500);
        die("Error interno: " . $e->getMessage());
    }
}
// Finalizar buffer si no se usó
if (ob_get_length()) ob_end_flush();
?>