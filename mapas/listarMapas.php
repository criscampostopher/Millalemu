<?php
$directorio = __DIR__ . "/uploads/";
$archivos = glob($directorio . "*.kml");

$mapas = [];
foreach ($archivos as $archivo) {
    $mapas[] = basename($archivo);
}

header("Content-Type: application/json");
echo json_encode($mapas);