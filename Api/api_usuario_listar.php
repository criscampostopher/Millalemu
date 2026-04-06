<?php
session_start();
require_once __DIR__ . '/../Config/db_config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$mi = (int)$_SESSION['id_usuario'];

$stmt = $pdo->prepare("
  SELECT id_usuario, nombre_usuario, tipo_usuario
  FROM public.usuario
  WHERE id_usuario <> :mi
  ORDER BY nombre_usuario ASC
");
$stmt->execute([':mi' => $mi]);

echo json_encode(['success' => true, 'usuarios' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

