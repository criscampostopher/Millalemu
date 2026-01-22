<?php
//  Api/procesar_geojson.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($pdo)) require_once __DIR__ . '/../Config/db_config.php';

$id_user = $_SESSION['id_usuario'] ?? null;
if (!$id_user || empty($geojson_url)) return;

try {
    $nombre_real = basename($geojson_url);
    // Limpieza de nombre
    $parts = explode('-', $nombre_real, 2); 
    if(count($parts)>1) $nombre_real = $parts[1];

    $check = $pdo->prepare("SELECT id_mapa FROM public.mapa WHERE nombre_mapa = ?");
    $check->execute([$nombre_real]);
    if($check->fetch()) { unlink($geojson_url); return; } 

    $json = file_get_contents($geojson_url);
    $data = json_decode($json, true);
    
    // Auto-detectar UTM
    $es_utm = false;
    $c = $data['features'][0]['geometry']['coordinates'] ?? null;
    while(is_array($c) && isset($c[0])) $c = $c[0];
    if(is_numeric($c) && abs($c) > 180) $es_utm = true;

    $pdo->beginTransaction();

    // Crear Mapa
    $stmt = $pdo->prepare("INSERT INTO public.mapa (nombre_mapa, tipo_mapa, ruta_archivo) VALUES (?, 'Escenario', ?) RETURNING id_mapa");
    $stmt->execute([$nombre_real, $geojson_url]);
    $nuevo_mapa_id = $stmt->fetchColumn();

    // Vincular usuario al mapa
    $pdo->prepare("INSERT INTO public.usuario_mapa (id_usuario, id_mapa) VALUES (?, ?)")->execute([$id_user, $nuevo_mapa_id]);

    // Insertar Peligros
    $geom_sql = $es_utm 
        ? "ST_Transform(ST_SetSRID(ST_MakeValid(ST_GeomFromGeoJSON(?)), 32718), 4326)" 
        : "ST_SetSRID(ST_MakeValid(ST_GeomFromGeoJSON(?)), 4326)";

    $sql = "INSERT INTO public.peligro (nombre, descripcion, tipo, geom, nivel, estado, id_mapa, id_usuario) 
            VALUES (?, ?, ?, $geom_sql, 'medio', 'activa', ?, ?)";
    $stmtP = $pdo->prepare($sql);

    foreach ($data['features'] as $f) {
        $geom = $f['geometry'];
        $tipo = ($geom['type'] == 'Point') ? 'punto' : (($geom['type']=='LineString')?'linea':'poligono');
        $stmtP->execute([$f['properties']['name']??'Zona', '', $tipo, json_encode($geom), $nuevo_mapa_id, $id_user]);
    }
    $pdo->commit();
} catch (Exception $e) { if($pdo->inTransaction()) $pdo->rollBack(); }
?>