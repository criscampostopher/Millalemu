<?php
// guardar_captura_mapa.php
session_start();
if (!isset($_SESSION['id_usuario'])) { die(json_encode(['success'=>false, 'error'=>'No autorizado'])); }

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['imagen']) && isset($data['id_mapa'])) {
    $id_mapa = (int)$data['id_mapa'];
    $base64 = $data['imagen'];
    
    // Detectamos si es la foto 1 o la 2 (Por defecto asume la 1)
    $numero_foto = isset($data['numero_foto']) ? (int)$data['numero_foto'] : 1;

    $base64 = str_replace('data:image/jpeg;base64,', '', $base64);
    $base64 = str_replace('data:image/png;base64,', '', $base64);
    $base64 = str_replace(' ', '+', $base64);
    $imagen_decodificada = base64_decode($base64);

    $dir_uploads = __DIR__ . '/uploads/';
    if (!is_dir($dir_uploads)) { mkdir($dir_uploads, 0777, true); }

    // Generamos el nombre dependiendo de si es la 1 o la 2
    if ($numero_foto === 2) {
        $ruta_destino = $dir_uploads . 'plano_2_' . $id_mapa . '.jpg';
    } else {
        $ruta_destino = $dir_uploads . 'plano_' . $id_mapa . '.jpg';
    }
    
    if (file_put_contents($ruta_destino, $imagen_decodificada)) {
        echo json_encode(['success' => true]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
