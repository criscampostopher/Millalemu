<?php
// mapas/listarAlertas.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header("Content-Type: application/json; charset=utf-8");

$alertsFile = __DIR__ . "/alerts.json";
if (!file_exists($alertsFile)) {
    echo json_encode([]);
    exit;
}
$content = file_get_contents($alertsFile);
$json = json_decode($content, true);
if (!is_array($json)) $json = [];
echo json_encode($json);
