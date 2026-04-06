<?php
// Archivo: guardar_obs_terreno.php
session_start();
require_once __DIR__ . '/Config/db_config.php';

header('Content-Type: application/json; charset=utf-8');

// Verificar que el usuario sea un trabajador válido
if (!isset($_SESSION['id_usuario'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$id_piv = $_POST['id_piv'] ?? null;
$observacion = trim((string)($_POST['observacion'] ?? ''));
$nombre_trabajador = $_SESSION['nombre_usuario'] ?? 'Trabajador';

if (!$id_piv || $observacion === '') {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

try {
    // 1. Buscamos si el PIV ya tenía alguna observación previa
    $stmt = $pdo->prepare("SELECT observaciones FROM public.piv WHERE id_piv = ?");
    $stmt->execute([$id_piv]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'El documento PIV no existe']);
        exit;
    }

    // 2. Preparamos el texto (Le ponemos la fecha y el nombre del trabajador)
    $fecha_actual = date('d/m H:i');
    $texto_nuevo = "[$fecha_actual] $nombre_trabajador: $observacion";

    $obs_actual = trim((string)$row['observaciones']);
    
    // Si ya había texto antes, lo ponemos abajo. Si estaba vacío, ponemos el nuevo directamente.
    if ($obs_actual !== '') {
        $obs_final = $obs_actual . " | " . $texto_nuevo;
    } else {
        $obs_final = $texto_nuevo;
    }

    // 3. Guardamos en la base de datos
    $upd = $pdo->prepare("UPDATE public.piv SET observaciones = ? WHERE id_piv = ?");
    $upd->execute([$obs_final, $id_piv]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
