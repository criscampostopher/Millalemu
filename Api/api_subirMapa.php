<?php
// ==========================================================
// Api/api_subirMapa.php (Versión BD - Lógica Intacta)
// ==========================================================
session_start();
require_once __DIR__ . '/../Config/db_config.php';

// Aumentamos memoria para procesar geometrías complejas
ini_set('memory_limit', '512M');

// --- TUS FUNCIONES DE DETECCIÓN (INTACTAS) ---
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

// --- VERIFICACIÓN DE ACCESO ---
$es_admin = ($_SESSION['tipo_usuario'] ?? '') === 'admin';
$id_usuario_actual = $_SESSION['id_usuario'] ?? 1;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["mapa"])) {
    if (!$es_admin) { header("Location: ../index.php?status=error&msg=Acceso denegado"); exit; }

    try {
        // 1. GESTIÓN DE ZONA (Igual que antes)
        $categoria = $_POST['categoria'] ?? 'Escenario';
        $nombre_zona_input = trim($_POST['nombre_zona'] ?? 'Zona General');
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

        // 2. PROCESAMIENTO DEL ARCHIVO (EXTRACCIÓN DE CONTENIDO)
        // Aquí está el cambio: Leemos el contenido a una variable en lugar de moverlo a disco.
        $nombre_original = $_FILES["mapa"]["name"];
        $tmp_name = $_FILES["mapa"]["tmp_name"];
        $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
        $nombre_mapa_limpio = pathinfo($nombre_original, PATHINFO_FILENAME);
        
        $tipo_mapa = "";
        $contentForBD = null; // Variable clave: Aquí vivirá el mapa

        // Validación de nombre duplicado
        $check = $pdo->prepare("SELECT 1 FROM public.mapa WHERE nombre_mapa = ?");
        $check->execute([$nombre_mapa_limpio]);
        if ($check->fetch()) { header("Location: ../index.php?status=error&msg=El nombre ya existe"); exit; }

        // Extracción según tipo
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
                    $contentForBD = $zip->getFromName($kmlName);
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
            $contentForBD = file_get_contents($tmp_name);
            $tipo_mapa = "KML";
        } elseif ($ext === 'geojson' || $ext === 'json') {
            $contentForBD = file_get_contents($tmp_name);
            if (json_decode($contentForBD) === null) { header("Location: ../index.php?status=error&msg=GeoJSON inválido"); exit; }
            $tipo_mapa = "GeoJSON";
        }

        // 3. INSERCIÓN EN BD (Guardamos contenido + metadatos)
        if ($contentForBD && $id_zona) {
            
            // Usamos 'ruta_archivo' para guardar el nombre original como referencia (BACKUP)
            $referencia_archivo = "BD_STORED: " . $nombre_original;

            $sql = "INSERT INTO public.mapa (nombre_mapa, tipo_mapa, ruta_archivo, fecha_creacion, categoria, id_zona, contenido_source) 
                    VALUES (?, ?, ?, NOW(), ?, ?, ?) RETURNING id_mapa";
            $stmt = $pdo->prepare($sql);
            // OJO: Aquí pasamos $contentForBD al final
            $stmt->execute([$nombre_mapa_limpio, $tipo_mapa, $referencia_archivo, $categoria, $id_zona, $contentForBD]);
            $nuevo_id_mapa = $stmt->fetchColumn();
            
            // Vincular usuario
            $pdo->prepare("INSERT INTO public.usuario_mapa (id_usuario, id_mapa) VALUES (?, ?)")->execute([$id_usuario_actual, $nuevo_id_mapa]);

            // =========================================================
            // 4. GENERACIÓN DE ALERTAS (LÓGICA PRESERVADA AL 100%)
            // =========================================================
            $alertasDetectadas = 0;

            if ($tipo_mapa === 'GeoJSON') {
                $json = json_decode($contentForBD, true);

                // A) CASO PENDIENTES: Fusión Topológica
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

                // B) BUFFER Y OTRAS ALERTAS
                if (isset($json['features'])) {
                    foreach ($json['features'] as $feature) {
                        $props = $feature['properties'] ?? [];
                        $geomJSON = json_encode($feature['geometry']);
                        
                        // CASO ACTA (Buffer Interno -20m)
                        if (strtolower($categoria) === 'acta') {
                            $sqlAnillo = "INSERT INTO public.peligro (nombre, descripcion, tipo, nivel, estado, id_mapa, id_usuario, geom) 
                                          VALUES (?, ?, 'poligono', 'Advertencia', 'activa', ?, ?, 
                                          ST_Multi(ST_Transform(
                                              ST_Difference(
                                                  ST_MakeValid(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), 3857)),
                                                  ST_Buffer(ST_MakeValid(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), 3857)), -20) 
                                              ), 
                                          4326)::geometry))";
                            // NOTA: Mantenemos el -20 exacto de tu código original
                            $pdo->prepare($sqlAnillo)->execute(["⚠️ Límite de Acta", "Zona de advertencia (20m)", $nuevo_id_mapa, $id_usuario_actual, $geomJSON, $geomJSON]);
                            $alertasDetectadas++;
                        }

                        // CASO COLORES (Buffer Externo 10m)
                        $tipoDetectado = detectarPeligroPorColor($props);
                        if ($tipoDetectado) {
                            $sqlAlerta = "INSERT INTO public.peligro (nombre, descripcion, tipo, nivel, estado, id_mapa, id_usuario, geom) 
                                          VALUES (?, ?, 'poligono', 'Critico', 'activa', ?, ?, 
                                          ST_Multi(ST_Buffer(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))::geography, 10)::geometry))";
                            // NOTA: Mantenemos el buffer de 10m exacto de tu código original
                            $pdo->prepare($sqlAlerta)->execute(["⚠️ Zona Protegida ($tipoDetectado)", "Buffer automático color (10m)", $nuevo_id_mapa, $id_usuario_actual, $geomJSON]);
                            $alertasDetectadas++;
                        }
                    }
                }
            }
            
            // C) LÓGICA KML (Buffer -15m)
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
                                // NOTA: Mantenemos el -15m para KML como estaba en tu archivo
                                $pdo->prepare($sqlAnillo)->execute(["⚠️ Límite de Acta", "Zona de advertencia (15m)", $nuevo_id_mapa, $id_usuario_actual, $geomXML, $geomXML]);
                                $alertasDetectadas++;
                            }
                        }
                    }
                    libxml_clear_errors();
                } catch (Exception $e) { }
            }

            $msgExtra = $alertasDetectadas > 0 ? " Se generaron $alertasDetectadas zonas de seguridad." : "";
            // Redirección Exitosa
            header("Location: ../index.php?focus_map=" . $nuevo_id_mapa . "&status=success&msg=Mapa guardado en BD." . $msgExtra);
            exit;
        }

    } catch (Exception $e) {
        header("Location: ../index.php?status=error&msg=Error: " . $e->getMessage());
        exit;
    }
}
header("Location: ../index.php");
?>