<?php
// mapas/subirMapa.php  (version segura para devolver JSON siempre)
ini_set('display_errors', 0); // en dev puedes poner 1, pero en general evitar imprimir HTML en responses JSON
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// debug log
file_put_contents(__DIR__ . '/debug.log', date('c') . " - subirMapa.php iniciada. \n", FILE_APPEND);

try {
    $directorio = __DIR__ . "/uploads/";
    if (!file_exists($directorio)) mkdir($directorio, 0777, true);

    $response = ["success" => false, "message" => ""];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        $response['message'] = 'Método no permitido';
        echo json_encode($response);
        exit;
    }

    if (!isset($_FILES['mapa'])) {
        $response['message'] = 'No se recibió el archivo (campo "mapa")';
        echo json_encode($response);
        exit;
    }

    $nombreArchivo = basename($_FILES['mapa']['name']);
    $tipoArchivo = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
    file_put_contents(__DIR__ . '/debug.log', date('c') . " - Archivo recibido: $nombreArchivo ext=$tipoArchivo\n", FILE_APPEND);

    if ($tipoArchivo !== 'kml') {
        $response['message'] = '❌ Solo se permiten archivos con extensión .kml';
        echo json_encode($response);
        exit;
    }

    $rutaDestino = $directorio . $nombreArchivo;
    if (move_uploaded_file($_FILES['mapa']['tmp_name'], $rutaDestino)) {
        $response['success'] = true;
        $response['message'] = '✅ Archivo KML subido correctamente.';
        $response['ruta'] = "mapas/uploads/" . $nombreArchivo;
    } else {
        $response['message'] = '⚠️ Error al guardar el archivo (move_uploaded_file falló).';
        file_put_contents(__DIR__ . '/debug.log', date('c') . " - move_uploaded_file FAILED tmp: " . ($_FILES['mapa']['tmp_name'] ?? 'no_tmp') . "\n", FILE_APPEND);
    }
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    file_put_contents(__DIR__ . '/debug.log', date('c') . " - Excepción: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(["success" => false, "message" => "Excepción: " . $e->getMessage()]);
}
