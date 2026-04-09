<?php
// ==========================================================
// Api/api_subirChunk.php (Receptor de Archivos Gigantes)
// ==========================================================
session_start();
ini_set('memory_limit', '1024M'); // 1GB para procesar cuando se una el mapa
set_time_limit(600);              // 10 minutos de tiempo de ejecución
ini_set('display_errors', 0);     // Evitar que errores PHP rompan el JSON

require_once __DIR__ . '/../Config/db_config.php';

header('Content-Type: application/json');

// --- TUS FUNCIONES DE DETECCIÓN (INTACTAS) ---
function extraerRGB($str) {
    if (preg_match('/(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $str, $m)) { return [(int)$m[1], (int)$m[2], (int)$m[3]]; }
    return null;
}
function esColorSimilar($rgbInput, $listaObjetivo, $tolerancia = 10) {
    if (!$rgbInput) return false;
    foreach ($listaObjetivo as $target) {
        $diffR = abs($rgbInput[0] - $target[0]); $diffG = abs($rgbInput[1] - $target[1]); $diffB = abs($rgbInput[2] - $target[2]);
        if ($diffR <= $tolerancia && $diffG <= $tolerancia && $diffB <= $tolerancia) return true;
    }
    return false;
}
function normalizarColor($color) { return str_replace(' ', '', strtoupper(trim($color))); }
function detectarPeligroPorColor($props) {
    $color = $props['FILL_COLOR'] ?? $props['Fill_Color'] ?? $props['fill_color'] ?? '';
    if (!$color) return false;
    $colorNorm = normalizarColor($color);
    if ($colorNorm === 'RGB(128,128,64)') return 'Zona De Protección(Buffer)';
    if ($colorNorm === 'RGB(0,128,255)') return 'Zona De Protección(Buffer)';
    return false;
}

// --- VERIFICACION DE ACCESO ---
$rol = strtolower(trim($_SESSION['tipo_usuario'] ?? ''));
$roles_permitidos = ['admin', 'ingeniero_forestal', 'jefe_operaciones'];

$es_admin = in_array($rol, $roles_permitidos, true);
$id_usuario_actual = $_SESSION['id_usuario'] ?? 1;

if (!$es_admin) {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
    exit;
}


// --- RECEPCIÓN DE DATOS DEL CHUNK ---
$chunkIndex  = isset($_POST['chunkIndex']) ? (int)$_POST['chunkIndex'] : 0;
$totalChunks = isset($_POST['totalChunks']) ? (int)$_POST['totalChunks'] : 1;
$fileName    = $_POST['fileName'] ?? 'mapa_desconocido.geojson';
$fileId      = $_POST['fileId'] ?? 'temp_id';
$categoria   = $_POST['categoria'] ?? 'Escenario';
$tipo_suelo  = $_POST['tipo_suelo'] ?? 'rocoso';
$nombre_zona_input = trim($_POST['nombre_zona'] ?? 'Zona General');

// Crear directorio temporal si no existe
$tempDir = __DIR__ . '/../uploads/temp/';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

$targetFile = $tempDir . basename($fileId) . '.part';

// --- GUARDAR EL CHUNK EN DISCO ---
if (isset($_FILES['chunk']['tmp_name']) && is_uploaded_file($_FILES['chunk']['tmp_name'])) {
    // Si es el primer trozo, sobreescribimos (por si quedó basura anterior), sino, agregamos al final (APPEND)
    $modo = ($chunkIndex === 0) ? 'wb' : 'ab';
    $out = fopen($targetFile, $modo);
    $in = fopen($_FILES['chunk']['tmp_name'], 'rb');
    
    if ($out && $in) {
        while ($buff = fread($in, 4096)) { fwrite($out, $buff); }
    } else {
        echo json_encode(['success' => false, 'error' => 'Error al abrir streams de archivo temporal']);
        exit;
    }
    fclose($in);
    fclose($out);
} else {
    echo json_encode(['success' => false, 'error' => 'No se recibió el archivo chunk correctamente']);
    exit;
}

// =========================================================
// --- LÓGICA FINAL: SI ES EL ÚLTIMO TROZO, PROCESAMOS ---
// =========================================================
if ($chunkIndex == $totalChunks - 1) {
    try {
        // 1. GESTIÓN DE ZONA
        if (empty($nombre_zona_input)) $nombre_zona_input = 'Zona General';
        $id_zona = null;
        $stmtCheckZona = $pdo->prepare("SELECT id_zona FROM public.zona WHERE LOWER(nombre_zona) = LOWER(?) LIMIT 1");
        $stmtCheckZona->execute([$nombre_zona_input]);
        $zonaExistente = $stmtCheckZona->fetch(PDO::FETCH_ASSOC);
        
        if ($zonaExistente) { 
            $id_zona = $zonaExistente['id_zona']; 
        } else {
            $stmtInsertZona = $pdo->prepare("INSERT INTO public.zona (nombre_zona, descripcion) VALUES (?, ?) RETURNING id_zona");
            $stmtInsertZona->execute([$nombre_zona_input, 'Auto-creada']);
            $id_zona = $stmtInsertZona->fetchColumn();
        }

        // 2. EXTRACCIÓN DE CONTENIDO
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $nombre_mapa_limpio = pathinfo($fileName, PATHINFO_FILENAME);
        $tipo_mapa = "";
        $contentForBD = null;

        // A) PRIMERO EXTRAEMOS EL CONTENIDO (Para poder leerlo por dentro)
        if ($ext === 'kmz') {
            $zip = new ZipArchive;
            if ($zip->open($targetFile) === TRUE) {
                $kmlName = false;
                for($i = 0; $i < $zip->numFiles; $i++){
                    $info = pathinfo($zip->statIndex($i)['name']);
                    if(isset($info['extension']) && strtolower($info['extension']) === 'kml'){ 
                        $kmlName = $zip->statIndex($i)['name']; break; 
                    }
                }
                if($kmlName) {
                    $contentForBD = $zip->getFromName($kmlName);
                    $tipo_mapa = "KML"; 
                } else {
                    $zip->close(); unlink($targetFile);
                    echo json_encode(['success' => false, 'error' => 'El KMZ no contiene un KML válido']); exit;
                }
                $zip->close();
            } else {
                unlink($targetFile);
                echo json_encode(['success' => false, 'error' => 'Error al abrir el KMZ']); exit;
            }
        } elseif ($ext === 'kml') {
            $contentForBD = file_get_contents($targetFile);
            $tipo_mapa = "KML";
        } elseif ($ext === 'geojson' || $ext === 'json') {
            $contentForBD = file_get_contents($targetFile);
            if (json_decode($contentForBD) === null) { 
                unlink($targetFile);
                echo json_encode(['success' => false, 'error' => 'GeoJSON inválido o corrupto al ensamblar']); exit; 
            }
            $tipo_mapa = "GeoJSON";
        }

        // B) MAGIA PARA EL "ACTA": BUSCAR NUMESCENAR Y REEMPLAZAR EL NOMBRE
        if (strtolower($categoria) === 'acta' && $contentForBD) {
            if ($tipo_mapa === 'GeoJSON') {
                $jsonParsed = json_decode($contentForBD, true);
                if (isset($jsonParsed['features'])) {
                    foreach ($jsonParsed['features'] as $f) {
                        // Buscamos si el polígono tiene la propiedad NUMESCENAR
                        if (!empty($f['properties']['NUMESCENAR'])) {
                            // Le agrego la palabra "Acta " por estética (Ej: Acta 145A)
                            $nombre_mapa_limpio = "Escenario " . trim($f['properties']['NUMESCENAR']);
                            break; // Tomamos el primero que encontremos y salimos del bucle
                        }
                    }
                }
            } elseif ($tipo_mapa === 'KML') {
                // Búsqueda rápida inteligente por si suben el Acta en KML o KMZ
                if (preg_match('/<SimpleData name="NUMESCENAR">([^<]+)<\/SimpleData>/i', $contentForBD, $matches) || 
                    preg_match('/<Data name="NUMESCENAR">\s*<value>([^<]+)<\/value>\s*<\/Data>/i', $contentForBD, $matches)) {
                    $nombre_mapa_limpio = "Escenario " . trim($matches[1]);
                }
            }
        }

        // C) AHORA SÍ, VALIDACIÓN DE NOMBRE DUPLICADO
        $check = $pdo->prepare("SELECT 1 FROM public.mapa WHERE nombre_mapa = ?");
        $check->execute([$nombre_mapa_limpio]);
        if ($check->fetch()) { 
            unlink($targetFile); // Borramos el archivo temporal
            echo json_encode(['success' => false, 'error' => 'El mapa "' . $nombre_mapa_limpio . '" ya existe en la base de datos.']); 
            exit; 
        }

        // 3. INSERCIÓN EN BD Y ALERTAS (LÓGICA INTACTA)

        // 3. INSERCIÓN EN BD Y ALERTAS (LÓGICA INTACTA)
        if ($contentForBD && $id_zona) {
            $referencia_archivo = "BD_STORED: " . $fileName;

            $sql = "INSERT INTO public.mapa (nombre_mapa, tipo_mapa, ruta_archivo, fecha_creacion, categoria, id_zona, contenido_source) 
                    VALUES (?, ?, ?, NOW(), ?, ?, ?) RETURNING id_mapa";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nombre_mapa_limpio, $tipo_mapa, $referencia_archivo, $categoria, $id_zona, $contentForBD]);
            $nuevo_id_mapa = $stmt->fetchColumn();
            
            // --- ALERTAS TOPOLÓGICAS POSTGIS ---
            if ($tipo_mapa === 'GeoJSON') {
                $json = json_decode($contentForBD, true);

                // A) Pendientes RGB(230,230,0)amarrillo opaco   (230,152,0) naranja claro
                if (stripos($categoria, 'Pendiente') !== false && isset($json['features'])) {
                    
                    // --- LÓGICA DE COLORES SEGÚN TIPO DE SUELO ---
                    if ($tipo_suelo === 'humedo') {
                        // AQUÍ TUS COLORES PARA SUELO HÚMEDO (Ej: Desde amarillo en adelante)
                        $targetAlto = [
                            [0,0,0], [168,0,0], [255,0,0], [52,52,52], [255,85,0], [230,152,0], [230,230,0] , [255,255,0] // <-- Reemplaza este por tu amarillo real
                        ]; 
                    } else {
                        // AQUÍ TUS COLORES PARA SUELO ROCOSO (Ej: Desde verde claro en adelante)
                        $targetAlto = [
                            [0,0,0], [168,0,0], [255,0,0], [52,52,52], [255,85,0], [230,152,0], [230,230,0] , [255,255,0], [76,230,0]// rocoso desde el verde claro 
                        ]; 
                    }
                    $geomsAlto = [];
                
                    foreach ($json['features'] as $f) {
                        $props = $f['properties'] ?? [];
                        $colorStr = $props['FILL_COLOR'] ?? $props['Fill_Color'] ?? $props['fill_color'] ?? '';
                        $rgb = extraerRGB($colorStr);
                        if ($rgb && isset($f['geometry'])) {
                            if (esColorSimilar($rgb, $targetAlto, 10)) $geomsAlto[] = $f['geometry'];
                        }
                    }

                    if (!empty($geomsAlto)) {
                        $lotes = array_chunk($geomsAlto, 1000); 
                        foreach ($lotes as $index => $lote) {
                            $col = ["type" => "GeometryCollection", "geometries" => $lote];
                            $jsonLote = json_encode($col);
                            $nombreFinal = ($index === 0) ? "PENDIENTE CRÍTICA" : "PENDIENTE CRÍTICA (Parte " . ($index + 1) . ")";
                            
                            $sqlInsertPendiente = "INSERT INTO public.peligro (nombre, descripcion, tipo, nivel, estado, id_mapa, id_usuario, geom) 
                                    SELECT ?, 'Pendiente Fusionada', 'poligono', 'Critico', 'activa', ?, ?, 
                                    ST_Multi(ST_Union(d.geom)) 
                                    FROM (SELECT (ST_Dump(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)))).geom) AS d";
                            $pdo->prepare($sqlInsertPendiente)->execute([$nombreFinal, $nuevo_id_mapa, $id_usuario_actual, $jsonLote]);
                        }
                    }
                }

                // B) Buffers (Acta y Colores)
                if (isset($json['features'])) {
                    foreach ($json['features'] as $feature) {
                        $props = $feature['properties'] ?? [];
                        $geomJSON = json_encode($feature['geometry']);
                        
                        if (strtolower($categoria) === 'acta') {
                            $sqlAnillo = "INSERT INTO public.peligro (nombre, descripcion, tipo, nivel, estado, id_mapa, id_usuario, geom) 
                                          VALUES (?, ?, 'poligono', 'Advertencia', 'activa', ?, ?, 
                                          ST_Multi(ST_Transform(
                                              ST_Difference(
                                                  ST_MakeValid(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), 3857)),
                                                  ST_Buffer(ST_MakeValid(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), 3857)), -20) 
                                              ), 
                                          4326)::geometry))";
                            $pdo->prepare($sqlAnillo)->execute(["⚠️ Límite de Acta", "Zona de advertencia (20m)", $nuevo_id_mapa, $id_usuario_actual, $geomJSON, $geomJSON]);
                        }

                        $tipoDetectado = detectarPeligroPorColor($props);
                        if ($tipoDetectado) {
                            $sqlAlerta = "INSERT INTO public.peligro (nombre, descripcion, tipo, nivel, estado, id_mapa, id_usuario, geom) 
                                          VALUES (?, ?, 'poligono', 'Critico', 'activa', ?, ?, 
                                          ST_Multi(ST_Buffer(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))::geography, 10)::geometry))";
                            $pdo->prepare($sqlAlerta)->execute(["⚠️ Zona Protegida ($tipoDetectado)", "Buffer automático  (10m)", $nuevo_id_mapa, $id_usuario_actual, $geomJSON]);
                        }
                    }
                }
            }
            
            // C) KML (Buffer -15m)
            elseif ($tipo_mapa === 'KML') {
                try {
                    libxml_use_internal_errors(true);
                    $xml = simplexml_load_string($contentForBD);
                    if ($xml !== false) {
                        $xml->registerXPathNamespace('kml', 'http://www.opengis.net/kml/2.2');
                        $placemarks = $xml->xpath("//kml:Placemark");
                        foreach ($placemarks as $pm) {
                            $geomXML = "";
                            if (isset($pm->Polygon)) $geomXML = $pm->Polygon->asXML();
                            elseif (isset($pm->MultiGeometry)) $geomXML = $pm->MultiGeometry->asXML();
                            
                            if ($geomXML && strtolower($categoria) === 'acta') {
                                $sqlAnillo = "INSERT INTO public.peligro (nombre, descripcion, tipo, nivel, estado, id_mapa, id_usuario, geom) 
                                              VALUES (?, ?, 'poligono', 'Advertencia', 'activa', ?, ?, 
                                              ST_Multi(ST_Transform(
                                                  ST_Difference(
                                                      ST_MakeValid(ST_Transform(ST_SetSRID(ST_GeomFromKML(?), 4326), 3857)),
                                                      ST_Buffer(ST_MakeValid(ST_Transform(ST_SetSRID(ST_GeomFromKML(?), 4326), 3857)), -15)
                                                  ), 
                                              4326)::geometry))";
                                $pdo->prepare($sqlAnillo)->execute(["⚠️ Límite de Acta", "Zona de advertencia (15m)", $nuevo_id_mapa, $id_usuario_actual, $geomXML, $geomXML]);
                            }
                        }
                    }
                    libxml_clear_errors();
                } catch (Exception $e) { }
            }

            // --- LIMPIEZA DEL ARCHIVO TEMPORAL ---
            unlink($targetFile);
            
            // Devolvemos el ID al frontend para que redireccione
            echo json_encode(['success' => true, 'completed' => true, 'id_mapa' => $nuevo_id_mapa]);
            exit;
        }

    } catch (Exception $e) {
        if (file_exists($targetFile)) unlink($targetFile);
        echo json_encode(['success' => false, 'error' => 'Error de Base de Datos: ' . $e->getMessage()]);
        exit;
    }
} else {
    // Si no es el último trozo, simplemente avisamos que todo va bien
    echo json_encode(['success' => true, 'completed' => false]);
    exit;
}
?>