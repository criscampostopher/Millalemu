<?php
session_start();
require_once __DIR__ . '/Config/db_config.php';
require_once __DIR__ . '/Config/roles.php';

header('Content-Type: application/json');

// Solo perfiles con funciones administrativas
if (!usuarioSesionPuedeAdministrar()) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$id_piv = (int)($body['id_piv'] ?? 0);

if ($id_piv <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit;
}

try {
    // Eliminar envíos primero (FK), luego el PIV
    $pdo->prepare("DELETE FROM public.piv_envio WHERE id_piv = :id")->execute([':id' => $id_piv]);
    $stmt = $pdo->prepare("DELETE FROM public.piv WHERE id_piv = :id");
    $stmt->execute([':id' => $id_piv]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
