<?php
// ==========================================================
// Api/api_subirMapa.php (Versión: Buscar o Crear Zona)
// ==========================================================
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
    
    // ---------------------------------------------------------
    // 1. LÓGICA DE ZONA (BUSCAR O CREAR)
    // ---------------------------------------------------------
    $categoria = $_POST['categoria'] ?? 'Escenario';
    $nombre_zona_input = trim($_POST['nombre_zona'] ?? '');
    
    if (empty($nombre_zona_input)) {
        $nombre_zona_input = 'Zona General'; // Fallback por seguridad
    }

    $id_zona = null;

    try {
        // A. Verificar si la zona ya existe (insensible a mayúsculas/minúsculas)
        $stmtCheckZona = $pdo->prepare("SELECT id_zona FROM public.zona WHERE LOWER(nombre_zona) = LOWER(?) LIMIT 1");
        $stmtCheckZona->execute([$nombre_zona_input]);
        $zonaExistente = $stmtCheckZona->fetch(PDO::FETCH_ASSOC);

        if ($zonaExistente) {
            // Si existe, usamos su ID
            $id_zona = $zonaExistente['id_zona'];
        } else {
            // B. Si no existe, la CREAMOS automáticamente
            $stmtInsertZona = $pdo->prepare("INSERT INTO public.zona (nombre_zona, descripcion) VALUES (?, ?) RETURNING id_zona");
            $stmtInsertZona->execute([$nombre_zona_input, 'Creada automáticamente al subir mapa']);
            $id_zona = $stmtInsertZona->fetchColumn();
        }

        // ---------------------------------------------------------
        // 2. PROCESAMIENTO DEL ARCHIVO (Igual que antes)
        // ---------------------------------------------------------
        $nombre_original = $_FILES["mapa"]["name"];
        $tmp_name = $_FILES["mapa"]["tmp_name"];
        $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
        $nombre_mapa_limpio = pathinfo($nombre_original, PATHINFO_FILENAME);
        
        $allowed = ['geojson', 'kml', 'kmz'];
        if (!in_array($ext, $allowed)) { 
            header("Location: ../index.php?status=error&msg=Formato no válido. Use .kml, .kmz o .geojson");
            exit;
        } 

        $uid = uniqid();
        $ruta_final = "";
        $tipo_mapa = "";

        // Verificar duplicados de nombre de mapa
        $check = $pdo->prepare("SELECT 1 FROM public.mapa WHERE nombre_mapa = ?");
        $check->execute([$nombre_mapa_limpio]);
        if ($check->fetch()) {
            header("Location: ../index.php?status=error&msg=El nombre del mapa ya existe");
            exit;
        }

        if ($ext === 'kmz') {
            $zip = new ZipArchive;
            if ($zip->open($tmp_name) === TRUE) {
                $kmlName = false;
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
                    $tipo_mapa = "KML";
                } else {
                    $zip->close();
                    throw new Exception("El archivo KMZ no contiene un .kml válido.");
                }
            } else {
                throw new Exception("Error al abrir KMZ.");
            }
        } elseif ($ext === 'kml') {
            $content = file_get_contents($tmp_name);
            if (simplexml_load_string($content) === false) {
                 header("Location: ../index.php?status=error&msg=KML corrupto");
                 exit;
            }
            $nombre_archivo = $uid . '-' . basename($nombre_original);
            move_uploaded_file($tmp_name, $dir . $nombre_archivo);
            $ruta_final = "uploads/" . $nombre_archivo;
            $tipo_mapa = "KML";
        } else {
            if (json_decode(file_get_contents($tmp_name)) === null) {
                header("Location: ../index.php?status=error&msg=GeoJSON inválido");
                exit;
            }
            $nombre_archivo = $uid . '-' . basename($nombre_original);
            move_uploaded_file($tmp_name, $dir . $nombre_archivo);
            $ruta_final = "uploads/" . $nombre_archivo;
            $tipo_mapa = "GeoJSON";
        }
        
        // 3. INSERT FINAL EN BASE DE DATOS
        if ($ruta_final && $id_zona) {
            $sql = "INSERT INTO public.mapa (nombre_mapa, tipo_mapa, ruta_archivo, fecha_creacion, categoria, id_zona) 
                    VALUES (?, ?, ?, NOW(), ?, ?) RETURNING id_mapa";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nombre_mapa_limpio, $tipo_mapa, $ruta_final, $categoria, $id_zona]);
            $nuevo_id = $stmt->fetchColumn();
            
            // Permiso automático al admin
            $pdo->prepare("INSERT INTO public.usuario_mapa (id_usuario, id_mapa) VALUES (?, ?)")
                ->execute([$_SESSION['id_usuario'], $nuevo_id]);

            header("Location: ../index.php?focus_map=" . $nuevo_id . "&status=success&msg=Mapa cargado y zona actualizada");
            exit;
        }

    } catch (Exception $e) {
        header("Location: ../index.php?status=error&msg=Error: " . $e->getMessage());
        exit;
    }
}
header("Location: ../index.php");
?>