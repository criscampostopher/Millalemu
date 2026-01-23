<?php
// ==========================================================
// Api/api_subirMapa.php (v6 - Con Buffer Automático de 10m)
// ==========================================================
session_start();
require_once __DIR__ . '/../Config/db_config.php';

// Aumentar memoria para procesamiento de XML/JSON grandes
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

        // 2. Procesamiento del Archivo Físico
        $nombre_original = $_FILES["mapa"]["name"];
        $tmp_name = $_FILES["mapa"]["tmp_name"];
        $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
        $nombre_mapa_limpio = pathinfo($nombre_original, PATHINFO_FILENAME);
        $uid = uniqid();
        $ruta_final = ""; $tipo_mapa = "";
        
        $contentForScanner = null; 
        $isKmlContent = false;

        $check = $pdo->prepare("SELECT 1 FROM public.mapa WHERE nombre_mapa = ?");
        $check->execute([$nombre_mapa_limpio]);
        if ($check->fetch()) { header("Location: ../index.php?status=error&msg=El nombre ya existe"); exit; }

        if ($ext === 'kmz') {
            $zip = new ZipArchive;
            if ($zip->open($tmp_name) === TRUE) {
                $kmlName = false;
                for($i = 0; $i < $zip->numFiles; $i++){
                    $info = pathinfo($zip->statIndex($i)['name']);
                    if(isset($info['extension']) && strtolower($info['extension']) === 'kml'){ $kmlName = $zip->statIndex($i)['name']; break; }
                }
                if($kmlName) {
                    $contentForScanner = $zip->getFromName($kmlName); 
                    $isKmlContent = true;
                    $ruta_final = "uploads/" . $uid . '-' . $nombre_mapa_limpio . '.kml';
                    file_put_contents("../" . $ruta_final, $contentForScanner);
                    $tipo_mapa = "KML";
                }
                $zip->close();
            }
        } elseif ($ext === 'kml') {
            $contentForScanner = file_get_contents($tmp_name);
            $isKmlContent = true;
            $ruta_final = "uploads/" . $uid . '-' . basename($nombre_original);
            move_uploaded_file($tmp_name, "../" . $ruta_final);
            $tipo_mapa = "KML";
        } elseif ($ext === 'geojson') {
            $contentForScanner = file_get_contents($tmp_name);
            $isKmlContent = false;
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
            // 4. ESCÁNER AUTOMÁTICO CON BUFFER DE 10 METROS
            // =========================================================
            $targetID_String = "595029840"; 
            $targetID_Int = 595029840;      
            $alertasDetectadas = 0;

            // NOTA TÉCNICA:
            // Usamos ST_Buffer sobre tipo 'geography' para que el radio (10) sea en metros.
            // ST_Multi asegura compatibilidad si la columna es MultiPolygon.
            // ST_SetSRID(..., 4326) asegura que PostGIS sepa que son Lat/Long.

            if ($contentForScanner) {
                if ($isKmlContent) {
                    // --- KML (Buffer 10m) ---
                    try {
                        libxml_use_internal_errors(true);
                        $xml = simplexml_load_string($contentForScanner);
                        if ($xml !== false) {
                            $xml->registerXPathNamespace('kml', 'http://www.opengis.net/kml/2.2');
                            $xpathQuery = "//kml:Placemark[.//kml:SimpleData[@name='OBJECTID'][.='$targetID_String'] or .//kml:SimpleData[@name='objectid'][.='$targetID_String']]";
                            $placemarks = $xml->xpath($xpathQuery);

                            foreach ($placemarks as $pm) {
                                $geomXML = "";
                                if (isset($pm->Polygon)) { $geomXML = $pm->Polygon->asXML(); }
                                elseif (isset($pm->MultiGeometry)) { $geomXML = $pm->MultiGeometry->asXML(); }
                                elseif (isset($pm->LineString)) { $geomXML = $pm->LineString->asXML(); }
                                
                                if ($geomXML) {
                                    $nombreAlerta = "⚠️ Buffer KML (10m)";
                                    $descAlerta = "Zona de seguridad (Buffer 10m) para ID: " . $targetID_String;
                                    
                                    // CONSULTA CON BUFFER 10 METROS
                                    $sqlAlerta = "INSERT INTO public.peligro (nombre, descripcion, tipo, nivel, estado, id_mapa, id_usuario, geom) 
                                                  VALUES (?, ?, 'poligono', 'Critico', 'activa', ?, ?, 
                                                  ST_Multi(ST_Buffer(ST_SetSRID(ST_GeomFromKML(?), 4326)::geography, 10)::geometry))";
                                    
                                    $stmtP = $pdo->prepare($sqlAlerta);
                                    $stmtP->execute([$nombreAlerta, $descAlerta, $nuevo_id_mapa, $id_usuario_actual, $geomXML]);
                                    $alertasDetectadas++;
                                }
                            }
                        }
                        libxml_clear_errors();
                    } catch (Exception $e) { }

                } else {
                    // --- GeoJSON (Buffer 10m) ---
                    $json = json_decode($contentForScanner, true);
                    if (isset($json['features'])) {
                        foreach ($json['features'] as $feature) {
                            $props = $feature['properties'] ?? [];
                            $objID = $props['OBJECTID'] ?? $props['objectid'] ?? $props['ObjectId'] ?? null;
                            if ($objID == $targetID_Int || $objID == $targetID_String) {
                                $geomJSON = json_encode($feature['geometry']);
                                
                                // CONSULTA CON BUFFER 10 METROS
                                $sqlAlerta = "INSERT INTO public.peligro (nombre, descripcion, tipo, nivel, estado, id_mapa, id_usuario, geom) 
                                              VALUES (?, ?, 'poligono', 'Critico', 'activa', ?, ?, 
                                              ST_Multi(ST_Buffer(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)::geography, 10)::geometry))";

                                $stmtP = $pdo->prepare($sqlAlerta);
                                $stmtP->execute(["⚠️ Buffer GeoJSON (10m)", "Zona de seguridad (Buffer 10m) para ID: $objID", $nuevo_id_mapa, $id_usuario_actual, $geomJSON]);
                                $alertasDetectadas++;
                            }
                        }
                    }
                }
            }

            $msgExtra = $alertasDetectadas > 0 ? " Se generaron $alertasDetectadas buffers de seguridad automáticos." : "";
            header("Location: ../index.php?focus_map=" . $nuevo_id_mapa . "&status=success&msg=Mapa cargado." . $msgExtra);
            exit;
        }

    } catch (Exception $e) {
        header("Location: ../index.php?status=error&msg=Error: " . $e->getMessage());
        exit;
    }
}
header("Location: ../index.php");
?>