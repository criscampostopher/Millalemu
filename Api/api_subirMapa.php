<?php
session_start();
require_once __DIR__ . '/../Config/db_config.php';

$es_admin = ($_SESSION['tipo_usuario'] ?? '') === 'admin';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["mapa"])) {
    if (!$es_admin) {
        header("Location: ../index.php?status=error&msg=Acceso denegado");
        exit;
    }

    $dir = "../uploads/"; 
    if (!file_exists($dir)) mkdir($dir, 0755, true); 
    
    $nombre_original = $_FILES["mapa"]["name"];
    $tmp_name = $_FILES["mapa"]["tmp_name"];
    $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
    $nombre_mapa_limpio = pathinfo($nombre_original, PATHINFO_FILENAME);
    
    // Validaciones
    if (!in_array($ext, ['geojson'])) { 
        header("Location: ../index.php?status=error&msg=Solo archivos .geojson");
        exit;
    } 
    elseif (json_decode(file_get_contents($tmp_name)) === null) {
        header("Location: ../index.php?status=error&msg=Contenido inválido");
        exit;
    }

    try {
        $check = $pdo->prepare("SELECT 1 FROM public.mapa WHERE nombre_mapa = ?");
        $check->execute([$nombre_mapa_limpio]);
        
        if ($check->fetch()) {
            header("Location: ../index.php?status=error&msg=El mapa ya existe");
            exit;
        }

        $nombre_archivo = uniqid() . '-' . basename($nombre_original);
        $ruta_fisica = "uploads/" . $nombre_archivo; 
        
        if (move_uploaded_file($tmp_name, $dir . $nombre_archivo)) {
            $sql = "INSERT INTO public.mapa (nombre_mapa, tipo_mapa, ruta_archivo, fecha_creacion) 
                    VALUES (?, ?, ?, NOW()) RETURNING id_mapa";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nombre_mapa_limpio, 'GeoJSON', $ruta_fisica]);
            $nuevo_id = $stmt->fetchColumn();
            
            header("Location: ../index.php?focus_map=" . $nuevo_id . "&status=success&msg=Mapa cargado");
            exit;
        } else {
            header("Location: ../index.php?status=error&msg=Error al mover archivo");
            exit;
        }
    } catch (Exception $e) {
        header("Location: ../index.php?status=error&msg=Error BD");
        exit;
    }
}
header("Location: ../index.php");