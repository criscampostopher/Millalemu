<?php
// ==========================================================
// Api/api_mapa.php 
// ==========================================================
require_once __DIR__ . '/../Config/db_config.php';
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

$inputJSON = file_get_contents('php://input');
$inputData = json_decode($inputJSON, true);
$action = $_GET['action'] ?? $_POST['action'] ?? $inputData['action'] ?? null;

if (session_status() === PHP_SESSION_NONE) session_start();
$id_usuario_actual = $_SESSION['id_usuario'] ?? null;
$es_admin = ($_SESSION['tipo_usuario'] ?? '') === 'admin';

try {
    switch ($action) {
        
        
        // 1. LEER MARCADORES 
        case 'fetch_markers':
            // Limpieza automática diaria
            $pdo->exec("DELETE FROM public.peligro WHERE fecha_creacion::date < CURRENT_DATE");

            $sql = "SELECT p.id, p.nombre, p.descripcion, p.tipo, p.nivel, p.id_mapa, p.radio_metros,
                    ST_AsGeoJSON(p.geom) AS geojson,
                    CASE 
                        -- Iconos OpenSource
                        WHEN LOWER(p.nivel) = 'critico' THEN 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-red.png'
                        WHEN LOWER(p.nivel) = 'alto'    THEN 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-orange.png'
                        WHEN LOWER(p.nivel) = 'medio'   THEN 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-green.png'
                        ELSE 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-blue.png'
                    END as icono_url
                    FROM public.peligro p
                    WHERE p.estado = 'activa'";
            
            $stmt = $pdo->query($sql);
            echo json_encode(['success' => true, 'markers' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'is_admin' => $es_admin]);
            break;
        // 2. AGREGAR MARCADOR
        case 'add_marker': 
        case 'add_report': 
            if (!$id_usuario_actual) { echo json_encode(['success'=>false, 'error'=>'Auth']); exit; }

            $es_reporte = ($action === 'add_report');
            $nombre = $es_reporte ? '🚨 REPORTE TERRENO' : ($inputData['nombre'] ?? 'Punto');
            $desc   = $es_reporte ? ($inputData['mensaje'] ?? '') : ($inputData['descripcion'] ?? '');
            $nivelInput = $inputData['nivel'] ?? 'Medio';
            $nivel = $es_reporte ? 'Critico' : $nivelInput;
            
        
            $radio = isset($inputData['radio']) ? (int)$inputData['radio'] : 0;

            // --- LÓGICA DE TIPO ---
          
            $tipo_guardado = ($radio > 0) ? 'radio' : 'punto';

            $lat = $inputData['lat'];
            $lng = $inputData['lng'];

            $id_mapa_destino = isset($inputData['id_mapa']) && $inputData['id_mapa'] > 0 ? (int)$inputData['id_mapa'] : 1;

            $pdo->beginTransaction();
            
      
            $stmtLink = $pdo->prepare("SELECT 1 FROM public.usuario_mapa WHERE id_usuario = ? AND id_mapa = ?");
            $stmtLink->execute([$id_usuario_actual, $id_mapa_destino]);
            if (!$stmtLink->fetch()) {
                $pdo->prepare("INSERT INTO public.usuario_mapa (id_usuario, id_mapa) VALUES (?, ?)")
                    ->execute([$id_usuario_actual, $id_mapa_destino]);
            }

           
           
            $sql = "INSERT INTO public.peligro (nombre, descripcion, tipo, nivel, radio_metros, estado, id_mapa, id_usuario, geom) 
                    VALUES (?, ?, ?, ?, ?, 'activa', ?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326))";
            
            $stmt = $pdo->prepare($sql);
      
            $stmt->execute([$nombre, $desc, $tipo_guardado, $nivel, $radio, $id_mapa_destino, $id_usuario_actual, $lng, $lat]);
            
            $pdo->commit();
            echo json_encode(['success' => true]);
            break;

        // 3. BORRAR ELEMENTO
        case 'delete_marker':
            if (!$es_admin) throw new Exception("Acceso denegado");
            $id = $inputData['id'];
            $pdo->prepare("DELETE FROM public.peligro WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        // 4. LISTAR MAPAS
        case 'fetch_maps':
            $sql = "SELECT m.id_mapa, m.nombre_mapa, m.tipo_mapa, m.fecha_creacion, COUNT(p.id) as cantidad_elementos
                    FROM public.mapa m 
                    LEFT JOIN public.peligro p ON m.id_mapa = p.id_mapa
                    GROUP BY m.id_mapa, m.nombre_mapa, m.tipo_mapa, m.fecha_creacion
                    ORDER BY m.id_mapa ASC";
            $stmt = $pdo->query($sql);
            echo json_encode(['success' => true, 'maps' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // 5. BORRAR MAPA
        case 'delete_map':
            if (!$es_admin) throw new Exception("Acceso denegado");
            $id = $inputData['id'];
            if ($id == 1) throw new Exception("No se puede borrar la Capa General");
            
            $stmt = $pdo->prepare("SELECT ruta_archivo FROM public.mapa WHERE id_mapa = ?");
            $stmt->execute([$id]);
            $mapa = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($mapa && !empty($mapa['ruta_archivo'])) {
                $rutaFisica = __DIR__ . '/../' . $mapa['ruta_archivo']; 
                if (file_exists($rutaFisica)) unlink($rutaFisica);
            }
            $pdo->prepare("DELETE FROM public.mapa WHERE id_mapa = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        // 6. RESET TOTAL
        case 'delete_all':
            if (!$es_admin) throw new Exception("Acceso denegado");
            $files = glob(__DIR__ . '/../uploads/*'); 
            foreach($files as $file){ if(is_file($file)) unlink($file); }
            $pdo->exec("TRUNCATE TABLE public.mapa RESTART IDENTITY CASCADE;");
            $pdo->exec("INSERT INTO public.mapa (nombre_mapa, tipo_mapa, ruta_archivo) VALUES ('Capa General', 'Manual', 'manual')");
            $pdo->exec("INSERT INTO public.usuario_mapa (id_usuario, id_mapa) VALUES (1, 1)"); 
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
            break;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>