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


        
        // 0. LISTAR ZONAS (FILTRANDO LA OCULTA)
        case 'fetch_zones':
            $sql = "SELECT z.id_zona, z.nombre_zona, COUNT(m.id_mapa) as cantidad_mapas
                    FROM public.zona z
                    LEFT JOIN public.mapa m ON z.id_zona = m.id_zona
                    WHERE z.id_zona != 0  -- <--- ESTO OCULTA LA ZONA 'SISTEMA'
                    GROUP BY z.id_zona, z.nombre_zona
                    ORDER BY z.nombre_zona ASC";
            $stmt = $pdo->query($sql);
            echo json_encode(['success' => true, 'zones' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        
        
        // 1. LEER MARCADORES 
        case 'fetch_markers':
            // Limpieza automática diaria
            //esto elimina todas las elrtas de la bd ???, que estupides $pdo->exec("DELETE FROM public.peligro WHERE fecha_creacion::date < CURRENT_DATE");

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
        // 2. AGREGAR MARCADOR (O REPORTE)
        // 2. AGREGAR MARCADOR (CORREGIDO - NO MÁS NULL)
        case 'add_marker': 
            if (!$id_usuario_actual) { echo json_encode(['success'=>false, 'error'=>'Auth']); exit; }

            $nombre = $inputData['nombre'] ?? 'Punto';
            $desc   = $inputData['descripcion'] ?? '';
            $nivel  = $inputData['nivel'] ?? 'Medio';
            $radio  = isset($inputData['radio']) ? (int)$inputData['radio'] : 15;
            $lat    = $inputData['lat'];
            $lng    = $inputData['lng'];
            $tipo_guardado = ($radio > 0) ? 'radio' : 'punto';

            try {
                $pdo->beginTransaction();

                // --- GESTIÓN DEL MAPA GENERAL (ID 1) ---
                $id_destino = 1; // Asumimos por defecto que es el 1

                // 1. Verificamos si existe el Mapa 1 realmente
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM public.mapa WHERE id_mapa = 1");
                $stmtCheck->execute();
                $existe = $stmtCheck->fetchColumn();

                if (!$existe) {
                    // 2. SI NO EXISTE, LO CREAMOS (Junto con la Zona 0 si hace falta)
                    
                    // A) Asegurar Zona 0 (Sistema)
                    $pdo->exec("INSERT INTO public.zona (id_zona, nombre_zona, descripcion) 
                                VALUES (0, 'SISTEMA_OCULTO', 'Zona interna para alertas manuales') 
                                ON CONFLICT (id_zona) DO NOTHING");

                    // B) Crear Mapa 1 asignado a Zona 0
                    // IMPORTANTE: Quitamos el RETURNING para no depender de él
                    $pdo->exec("INSERT INTO public.mapa (id_mapa, nombre_mapa, tipo_mapa, id_zona, fecha_creacion) 
                                VALUES (1, 'Capa General', 'Manual', 0, NOW()) 
                                ON CONFLICT (id_mapa) DO NOTHING");
                }
                
                // Aquí $id_destino sigue valiendo 1, pase lo que pase.
                // ----------------------------------------

                // Insertamos la alerta usando $id_destino (que es 1)
                $sql = "INSERT INTO public.peligro (nombre, descripcion, tipo, nivel, radio_metros, estado, id_mapa, id_usuario, geom) 
                        VALUES (?, ?, ?, ?, ?, 'activa', ?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326))";
                
                $stmt = $pdo->prepare($sql);
                // Si esto falla ahora, es imposible que sea por id_mapa null
                $stmt->execute([$nombre, $desc, $tipo_guardado, $nivel, $radio, $id_destino, $id_usuario_actual, $lng, $lat]);
                
                $pdo->commit();
                echo json_encode(['success' => true]);

            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => "BD Error: " . $e->getMessage()]);
            }
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
            $zona_id = $_GET['id_zona'] ?? null;
            
            $sql = "SELECT m.id_mapa, m.nombre_mapa, m.tipo_mapa, m.fecha_creacion, COUNT(p.id) as cantidad_elementos
                    FROM public.mapa m 
                    LEFT JOIN public.peligro p ON m.id_mapa = p.id_mapa";
            
            // Filtro por zona (si es 0 o null, trae los que no tienen zona o la zona 'General')
            if ($zona_id) {
                $sql .= " WHERE m.id_zona = :zona_id";
            } else {
                // Si quieres mostrar mapas sin zona cuando no se selecciona nada:
                 $sql .= " WHERE m.id_zona IS NULL"; 
                 // O si prefieres mostrar TODOS si no hay ID, quita el WHERE.
            }

            $sql .= " GROUP BY m.id_mapa, m.nombre_mapa, m.tipo_mapa, m.fecha_creacion
                      ORDER BY m.id_mapa ASC";

            $stmt = $pdo->prepare($sql);
            if ($zona_id) {
                $stmt->execute(['zona_id' => $zona_id]);
            } else {
                $stmt->execute();
            }
            
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
        // 6. RESET TOTAL (ZONAS, MAPAS, ASIGNACIONES Y PELIGROS)
        // 6. RESET TOTAL (INTELIGENTE)
        case 'delete_all':
            if (!$es_admin) throw new Exception("Acceso denegado");
            
            // 1. Borrar archivos físicos
            $files = glob(__DIR__ . '/../uploads/*'); 
            foreach($files as $file){ if(is_file($file)) unlink($file); }

            // 2. Borrar datos de BD pero SALVANDO LA ESTRUCTURA BASE (ID 0 y ID 1)
            // Borramos alertas
            $pdo->exec("DELETE FROM public.peligro");
            
            // Borramos mapas EXCEPTO el 1 (General)
            $pdo->exec("DELETE FROM public.mapa WHERE id_mapa <> 1");
            
            // Borramos zonas EXCEPTO la 0 (Sistema)
            $pdo->exec("DELETE FROM public.zona WHERE id_zona <> 0");
            
            // Reiniciamos contadores de ID para que las nuevas zonas empiecen ordenadas
            // (Opcional, depende de si usas SERIAL)
            
            echo json_encode(['success' => true, 'msg' => 'Sistema limpiado (Capa General conservada).']);
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