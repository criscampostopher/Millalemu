<?php
// ==========================================================
// Api/api_subirMapa.php (v13 - Cálculo de Anillo Preciso en Metros)
// ==========================================================
session_start();
require_once __DIR__ . '/../Config/db_config.php';

// --- NUEVAS FUNCIONES PARA PENDIENTES ---
function extraerRGB($str) {
    if (preg_match('/(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $str, $m)) {
        return [(int)$m[1], (int)$m[2], (int)$m[3]]; 
    }
    return null;
}

function esColorSimilar($rgbInput, $listaObjetivo, $tolerancia = 10) {
    if (!$rgbInput) return false;
    foreach ($listaObjetivo as $target) {
        $diffR = abs($rgbInput[0] - $target[0]);
        $diffG = abs($rgbInput[1] - $target[1]);
        $diffB = abs($rgbInput[2] - $target[2]);
        if ($diffR <= $tolerancia && $diffG <= $tolerancia && $diffB <= $tolerancia) return true;
    }
    return false;
}



ini_set('memory_limit', '512M');

$es_admin = ($_SESSION['tipo_usuario'] ?? '') === 'admin';
$id_usuario_actual = $_SESSION['id_usuario'] ?? 1;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["mapa"])) {
    if (!$es_admin) { header("Location: ../index.php?status=error&msg=Acceso denegado"); exit; }

    $dir = "../uploads/"; if (!file_exists($dir)) mkdir($dir, 0755, true); 
    
    // 1. Datos del Formulario y Zona
    $categoria = $_POST['categoria'] ?? 'Escenario';
    $nombre_zona_input = trim($_POST['nombre_zona'] ?? 'Zona General');
    if (empty($nombre_zona_input)) $nombre_zona_input = 'Zona General';

    $id_zona = null;
    try {
        $stmtCheckZona = $pdo->prepare("SELECT id_zona FROM public.zona WHERE LOWER(nombre_zona) = LOWER(?) LIMIT 1");
        $stmtCheckZona->execute([$nombre_zona_input]);
        $zonaExistente = $stmtCheckZona->fetch(PDO::FETCH_ASSOC);
        if ($zonaExistente) { $id_zona = $zonaExistente['id_zona']; } else {
            $stmtInsertZona = $pdo->prepare("INSERT INTO public.zona (nombre_zona, descripcion) VALUES (?, ?) RETURNING id_zona");
            $stmtInsertZona->execute([$nombre_zona_input, 'Auto-creada']);
            $id_zona = $stmtInsertZona->fetchColumn();
        }

        // 2. Procesamiento del Archivo
        $nombre_original = $_FILES["mapa"]["name"];
        $tmp_name = $_FILES["mapa"]["tmp_name"];
        $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
        $nombre_mapa_limpio = pathinfo($nombre_original, PATHINFO_FILENAME);
        $uid = uniqid();
        
        $ruta_final = ""; 
        $tipo_mapa = "";
        $contentForScanner = null; 

        $check = $pdo->prepare("SELECT 1 FROM public.mapa WHERE nombre_mapa = ?");
        $check->execute([$nombre_mapa_limpio]);
        if ($check->fetch()) { header("Location: ../index.php?status=error&msg=El nombre ya existe"); exit; }

        // --- CARGA DE ARCHIVO ---
        if ($ext === 'kmz') {
            $zip = new ZipArchive;
            if ($zip->open($tmp_name) === TRUE) {
                $kmlName = false;
                for($i = 0; $i < $zip->numFiles; $i++){
                    $info = pathinfo($zip->statIndex($i)['name']);
                    if(isset($info['extension']) && strtolower($info['extension']) === 'kml'){ 
                        $kmlName = $zip->statIndex($i)['name']; break; 
                    }
                }
                if($kmlName) {
                    $contentForScanner = $zip->getFromName($kmlName);
                    $ruta_final = "uploads/" . $uid . '-' . $nombre_mapa_limpio . '.kml';
                    file_put_contents("../" . $ruta_final, $contentForScanner);
                    $tipo_mapa = "KML"; 
                } else {
                    $zip->close();
                    header("Location: ../index.php?status=error&msg=El KMZ no contiene un archivo KML válido."); exit;
                }
                $zip->close();
            } else {
                header("Location: ../index.php?status=error&msg=Error al abrir el KMZ."); exit;
            }
        } elseif ($ext === 'kml') {
            $contentForScanner = file_get_contents($tmp_name);
            $ruta_final = "uploads/" . $uid . '-' . basename($nombre_original);
            move_uploaded_file($tmp_name, "../" . $ruta_final);
            $tipo_mapa = "KML";
        } elseif ($ext === 'geojson' || $ext === 'json') {
            $contentForScanner = file_get_contents($tmp_name);
            if (json_decode($contentForScanner) === null) { header("Location: ../index.php?status=error&msg=GeoJSON inválido"); exit; }
            $ruta_final = "uploads/" . $uid . '-' . basename($nombre_original);
            move_uploaded_file($tmp_name, "../" . $ruta_final);
            $tipo_mapa = "GeoJSON";
        }

        // 3. Insertar Mapa en BD
        if ($ruta_final && $id_zona) {
            $sql = "INSERT INTO public.mapa (nombre_mapa, tipo_mapa, ruta_archivo, fecha_creacion, categoria, id_zona) VALUES (?, ?, ?, NOW(), ?, ?) RETURNING id_mapa";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nombre_mapa_limpio, $tipo_mapa, $ruta_final, $categoria, $id_zona]);
            $nuevo_id_mapa = $stmt->fetchColumn();
            
            $pdo->prepare("INSERT INTO public.usuario_mapa (id_usuario, id_mapa) VALUES (?, ?)")->execute([$id_usuario_actual, $nuevo_id_mapa]);

            // =========================================================
            // 4. GENERACIÓN DE ALERTAS (CORRECCIÓN MATEMÁTICA 3857)
            // =========================================================
            $alertasDetectadas = 0;

            if ($contentForScanner) {
                
                function normalizarColor($color) {
                    return str_replace(' ', '', strtoupper(trim($color)));
                }

                function detectarPeligroPorColor($props) {
                    $color = $props['FILL_COLOR'] ?? $props['Fill_Color'] ?? $props['fill_color'] ?? '';
                    if (!$color) return false;
                    $colorNorm = normalizarColor($color);

                    if ($colorNorm === 'RGB(128,128,64)') return 'Vegetación Nativa';
                    if ($colorNorm === 'RGB(0,128,255)') return 'Protección de Agua';
                    return false;
                }

                // --- LÓGICA GEOJSON ---
                if ($tipo_mapa === 'GeoJSON') {
                    $json = json_decode($contentForScanner, true);

                    //NNUEVO

                    // 1. CASO PENDIENTES: Fusión Topológica (NUEVO)
                    if (stripos($categoria, 'Pendiente') !== false && isset($json['features'])) {
                        $targetAlto = [[0,0,0], [168,0,0], [255,0,0], [52,52,52]]; 
                        $targetMedio = [[255,85,0], [230,152,0]]; 

                        $geomsAlto = [];
                        $geomsMedio = [];

                        foreach ($json['features'] as $f) {
                            $props = $f['properties'] ?? [];
                            $colorStr = $props['FILL_COLOR'] ?? $props['Fill_Color'] ?? $props['fill_color'] ?? '';
                            $rgb = extraerRGB($colorStr);
                            if ($rgb && isset($f['geometry'])) {
                                if (esColorSimilar($rgb, $targetAlto, 10)) $geomsAlto[] = $f['geometry'];
                                elseif (esColorSimilar($rgb, $targetMedio, 10)) $geomsMedio[] = $f['geometry'];
                            }
                        }

                        $insertarFusion = function($geoms, $nombre, $nivel) use ($pdo, $nuevo_id_mapa, $id_usuario_actual) {
                            if (empty($geoms)) return;
                            $col = ["type" => "GeometryCollection", "geometries" => $geoms];
                            $sql = "INSERT INTO public.peligro (nombre, descripcion, tipo, nivel, estado, id_mapa, id_usuario, geom) 
                                    SELECT ?, 'Pendiente Fusionada', 'poligono', ?, 'activa', ?, ?, 
                                    ST_Multi(ST_Union(d.geom)) 
                                    FROM (SELECT (ST_Dump(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)))).geom) AS d";
                            $pdo->prepare($sql)->execute([$nombre, $nivel, $nuevo_id_mapa, $id_usuario_actual, json_encode($col)]);
                        };

                        $insertarFusion($geomsAlto, "PENDIENTE CRÍTICA", "Critico");
                        $insertarFusion($geomsMedio, "PENDIENTE MEDIA", "Alto");
                        $alertasDetectadas++;
                    }


                    //FIN NUEVO    




                    if (isset($json['features'])) {
                        foreach ($json['features'] as $feature) {
                            $props = $feature['properties'] ?? [];
                            $geomJSON = json_encode($feature['geometry']);
                            
                            // A) LÓGICA ACTAS (Buffer Interno -15m usando Proyección Plana 3857)
                            if (strtolower($categoria) === 'acta') {
                            
$sqlAnillo = "INSERT INTO public.peligro (nombre, descripcion, tipo, nivel, estado, id_mapa, id_usuario, geom) 
              VALUES (?, ?, 'poligono', 'Advertencia', 'activa', ?, ?, 
              ST_Multi(ST_Transform(
                  ST_Difference(
                      ST_MakeValid(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), 3857)),
                      ST_Buffer(ST_MakeValid(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), 3857)), -20) 
                  ), 
              4326)::geometry))";

// NOTA: Aumenté a -20 metros para darte un margen de seguridad un poco más amplio
// y asegurarme de que el GPS lo detecte bien.;
                                
                                $pdo->prepare($sqlAnillo)->execute([
                                    "⚠️ Límite de Acta", 
                                    "Zona de advertencia (15m antes de salir)", 
                                    $nuevo_id_mapa, 
                                    $id_usuario_actual, 
                                    $geomJSON, 
                                    $geomJSON
                                ]);
                                $alertasDetectadas++;
                            }

                            // B) LÓGICA COLORES (Buffer Externo 10m)
                            $tipoDetectado = detectarPeligroPorColor($props);
                            if ($tipoDetectado) {
                                $sqlAlerta = "INSERT INTO public.peligro (nombre, descripcion, tipo, nivel, estado, id_mapa, id_usuario, geom) 
                                              VALUES (?, ?, 'poligono', 'Critico', 'activa', ?, ?, 
                                              ST_Multi(ST_Buffer(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))::geography, 10)::geometry))";

                                $pdo->prepare($sqlAlerta)->execute([
                                    "⚠️ Zona Protegida ($tipoDetectado)", 
                                    "Buffer automático por color (10m).", 
                                    $nuevo_id_mapa, 
                                    $id_usuario_actual, 
                                    $geomJSON
                                ]);
                                $alertasDetectadas++;
                            }
                        }
                    }
                }
                
                // --- LÓGICA KML (Actas con corrección 3857 también) ---
                elseif ($tipo_mapa === 'KML') {
                    try {
                        libxml_use_internal_errors(true);
                        $xml = simplexml_load_string($contentForScanner);
                        if ($xml !== false) {
                            $xml->registerXPathNamespace('kml', 'http://www.opengis.net/kml/2.2');
                            $placemarks = $xml->xpath("//kml:Placemark");
                            foreach ($placemarks as $pm) {
                                $geomXML = "";
                                if (isset($pm->Polygon)) $geomXML = $pm->Polygon->asXML();
                                elseif (isset($pm->MultiGeometry)) $geomXML = $pm->MultiGeometry->asXML();
                                
                                if ($geomXML) {
                                    if (strtolower($categoria) === 'acta') {
                                        $sqlAnillo = "INSERT INTO public.peligro (nombre, descripcion, tipo, nivel, estado, id_mapa, id_usuario, geom) 
                                                      VALUES (?, ?, 'poligono', 'Advertencia', 'activa', ?, ?, 
                                                      ST_Multi(ST_Transform(
                                                          ST_Difference(
                                                              ST_MakeValid(ST_Transform(ST_SetSRID(ST_GeomFromKML(?), 4326), 3857)),
                                                              ST_Buffer(ST_MakeValid(ST_Transform(ST_SetSRID(ST_GeomFromKML(?), 4326), 3857)), -15)
                                                          ), 
                                                      4326)::geometry))";
                                        $pdo->prepare($sqlAnillo)->execute(["⚠️ Límite de Acta", "Zona de advertencia (15m)", $nuevo_id_mapa, $id_usuario_actual, $geomXML, $geomXML]);
                                        $alertasDetectadas++;
                                    }
                                }
                            }
                        }
                        libxml_clear_errors();
                    } catch (Exception $e) { }
                }
            }

            $msgExtra = $alertasDetectadas > 0 ? " Se generaron $alertasDetectadas zonas de seguridad." : "";
            header("Location: ../index.php?focus_map=" . $nuevo_id_mapa . "&status=success&msg=Mapa cargado correctamente." . $msgExtra);
            exit;
        }

    } catch (Exception $e) {
        header("Location: ../index.php?status=error&msg=Error: " . $e->getMessage());
        exit;
    }
}
header("Location: ../index.php");
?>