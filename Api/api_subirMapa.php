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
    
    // 1. Validar Extensiones Permitidas (KML, KMZ, GeoJSON)
    $allowed = ['geojson', 'kml', 'kmz'];
    if (!in_array($ext, $allowed)) { 
        header("Location: ../index.php?status=error&msg=Formato no válido. Use .kml, .kmz o .geojson");
        exit;
    } 

    // Nombre base único
    $uid = uniqid();
    $ruta_final = "";
    $tipo_mapa = "";

    try {
        // Verificar duplicados por nombre
        $check = $pdo->prepare("SELECT 1 FROM public.mapa WHERE nombre_mapa = ?");
        $check->execute([$nombre_mapa_limpio]);
        if ($check->fetch()) {
            header("Location: ../index.php?status=error&msg=El nombre del mapa ya existe");
            exit;
        }

        // 2. Procesamiento según tipo de archivo
        if ($ext === 'kmz') {
            // Manejo de KMZ: Descomprimir y extraer el KML
            $zip = new ZipArchive;
            if ($zip->open($tmp_name) === TRUE) {
                $kmlName = false;
                // Buscar el archivo .kml dentro del zip
                for($i = 0; $i < $zip->numFiles; $i++){
                    $stat = $zip->statIndex($i);
                    $info = pathinfo($stat['name']);
                    if(isset($info['extension']) && strtolower($info['extension']) === 'kml'){
                        $kmlName = $stat['name'];
                        break;
                    }
                }

                if($kmlName) {
                    $nombre_archivo_kml = $uid . '-' . $nombre_mapa_limpio . '.kml';
                    $contenido_kml = $zip->getFromName($kmlName);
                    file_put_contents($dir . $nombre_archivo_kml, $contenido_kml);
                    $zip->close();
                    
                    $ruta_final = "uploads/" . $nombre_archivo_kml;
                    $tipo_mapa = "KML"; // Guardamos como KML (procesado)
                } else {
                    $zip->close();
                    throw new Exception("El archivo KMZ no contiene un archivo .kml válido.");
                }
            } else {
                throw new Exception("No se pudo abrir el archivo KMZ.");
            }
        } elseif ($ext === 'kml') {
            // Manejo de KML: Validar XML básico
            $content = file_get_contents($tmp_name);
            if (simplexml_load_string($content) === false) {
                 header("Location: ../index.php?status=error&msg=Archivo KML corrupto o inválido");
                 exit;
            }
            $nombre_archivo = $uid . '-' . basename($nombre_original);
            move_uploaded_file($tmp_name, $dir . $nombre_archivo);
            $ruta_final = "uploads/" . $nombre_archivo;
            $tipo_mapa = "KML";

        } else {
            // Manejo de GeoJSON (Legacy)
            if (json_decode(file_get_contents($tmp_name)) === null) {
                header("Location: ../index.php?status=error&msg=GeoJSON inválido");
                exit;
            }
            $nombre_archivo = $uid . '-' . basename($nombre_original);
            move_uploaded_file($tmp_name, $dir . $nombre_archivo);
            $ruta_final = "uploads/" . $nombre_archivo;
            $tipo_mapa = "GeoJSON";
        }
        
        // 3. Insertar en BD
        if ($ruta_final) {
            $sql = "INSERT INTO public.mapa (nombre_mapa, tipo_mapa, ruta_archivo, fecha_creacion) 
                    VALUES (?, ?, ?, NOW()) RETURNING id_mapa";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nombre_mapa_limpio, $tipo_mapa, $ruta_final]);
            $nuevo_id = $stmt->fetchColumn();
            
            header("Location: ../index.php?focus_map=" . $nuevo_id . "&status=success&msg=Mapa cargado correctamente");
            exit;
        }

    } catch (Exception $e) {
        header("Location: ../index.php?status=error&msg=Error: " . $e->getMessage());
        exit;
    }
}
header("Location: ../index.php");
?>