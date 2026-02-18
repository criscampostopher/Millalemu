<?php
// listarAlertasGuardadas.php
$directorio = __DIR__ . "/uploads_alertas/";
$archivos = glob($directorio . "*.json");  // solo JSON de alertas

$alertas = [];

foreach ($archivos as $archivo) {
    $contenido = file_get_contents($archivo);
    $json = json_decode($contenido, true);
    if ($json) $alertas[] = $json;
}

header("Content-Type: application/json");
echo json_encode($alertas);