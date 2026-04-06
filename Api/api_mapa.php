<?php
// ==========================================================
// Api/api_mapa.php 
// ==========================================================
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/roles.php';
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

$inputJSON = file_get_contents('php://input');
$inputData = json_decode($inputJSON, true);
$action = $_GET['action'] ?? $_POST['action'] ?? $inputData['action'] ?? null;

if (session_status() === PHP_SESSION_NONE) session_start();
$id_usuario_actual = $_SESSION['id_usuario'] ?? null;
$es_admin = usuarioSesionPuedeAdministrar();

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
                    u.nombre_usuario, u.tipo_usuario,u.id_usuario,
                    ST_AsGeoJSON(p.geom) AS geojson,
                    CASE 
                        -- Iconos OpenSource
                        WHEN LOWER(p.nivel) = 'critico' THEN 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-red.png'
                        WHEN LOWER(p.nivel) = 'alto'    THEN 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-orange.png'
                        WHEN LOWER(p.nivel) = 'medio'   THEN 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-green.png'
                        ELSE 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-blue.png'
                    END as icono_url
                    FROM public.peligro p
                    LEFT JOIN public.usuario u ON p.id_usuario = u.id_usuario
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
                $id_destino = isset($inputData['id_mapa']) ? (int)$inputData['id_mapa'] : 1;

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
            //if (!$es_admin) throw new Exception("Acceso denegado");
            $id = $inputData['id'];
            $pdo->prepare("DELETE FROM public.peligro WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;
        case 'fetch_catalogo':
            $stmt = $pdo->query("SELECT * FROM public.catalogo_alertas ORDER BY nombre ASC");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
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

            case 'delete_zone':
            if (!$es_admin) throw new Exception("Acceso denegado");
            $id_zona = isset($inputData['id_zona']) ? (int)$inputData['id_zona'] : 0;
            
            if ($id_zona > 0) {
                try {
                    // Iniciamos una transacción segura
                    $pdo->beginTransaction();
                    
                    // 1. Desasignar a todos los trabajadores de este predio
                    $stmt1 = $pdo->prepare("DELETE FROM public.usuario_zona WHERE id_zona = ?");
                    $stmt1->execute([$id_zona]);
                    
                    // 2. Borrar todas las alertas (peligros) de los mapas que pertenezcan a este predio
                    $stmt2 = $pdo->prepare("DELETE FROM public.peligro WHERE id_mapa IN (SELECT id_mapa FROM public.mapa WHERE id_zona = ?)");
                    $stmt2->execute([$id_zona]);
                    
                    // 3. Borrar los mapas físicos que pertenecen a este predio
                    $stmt3 = $pdo->prepare("DELETE FROM public.mapa WHERE id_zona = ?");
                    $stmt3->execute([$id_zona]);
                    
                    // 4. Finalmente, borrar la zona/predio
                    $stmt4 = $pdo->prepare("DELETE FROM public.zona WHERE id_zona = ?");
                    $stmt4->execute([$id_zona]);
                    
                    // Si todo salió bien, guardamos los cambios
                    $pdo->commit();
                    
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    // Si algo falla, deshacemos todo para evitar errores
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'ID de zona inválido.']);
            }
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
            // ==========================================================
        // TRAZABILIDAD Y SEGURIDAD (VERSIÓN INTELIGENTE)
        // ==========================================================
        case 'registrar_firma_seguridad':
            if (!$id_usuario_actual) throw new Exception("Usuario no autenticado");
            
            $tipo_alerta = $inputData['tipo_alerta'] ?? 'Alerta Desconocida';
            $id_alerta = $inputData['id_alerta'] ?? 0;
            $lat = $inputData['lat'] ?? 0;
            $lng = $inputData['lng'] ?? 0;
            $nombre_mapa = 'General / Sistema'; // Mapa por defecto
            
            // 1. TRADUCTOR DE ALERTAS MANUALES Y MAPAS
            // Si la ID es un número, significa que es una alerta creada en la BD
            if (is_numeric($id_alerta) && $id_alerta > 0) {
                $check = $pdo->prepare("
                    SELECT p.nombre as nombre_real, m.nombre_mapa 
                    FROM public.peligro p 
                    LEFT JOIN public.mapa m ON p.id_mapa = m.id_mapa 
                    WHERE p.id = ?
                ");
                $check->execute([$id_alerta]);
                if ($row = $check->fetch(PDO::FETCH_ASSOC)) {
                    $tipo_alerta = $row['nombre_real']; // Ej: "Árbol Nativo"
                    if (!empty($row['nombre_mapa'])) {
                        $nombre_mapa = $row['nombre_mapa']; // Ej: "Predio Los Álamos"
                    }
                }
            } else {
                // Si NO es numérico, es un Polígono (Acta, Pendiente) o una alerta Offline
                // Limpiamos los emojis o textos extraños que venían de la pantalla
                $tipo_alerta = str_ireplace(['⚠️', 'PELIGRO:', 'ATENCIÓN:'], '', $tipo_alerta);
                $tipo_alerta = trim($tipo_alerta);
            }

            // 2. CORRECCIÓN DE ROLES
            $rol_usr = $_SESSION['tipo_usuario'] ?? 'operador';
            // Si tu sistema originalmente guardaba a los operadores como "usuario", lo arreglamos:
            if (strtolower($rol_usr) === 'usuario') { 
                $rol_usr = 'operador'; 
            }

            $fecha_hora_celular = $inputData['fecha_hora'] ?? date('Y-m-d H:i:s');
            $fecha_hora = date('Y-m-d H:i:s', strtotime($fecha_hora_celular));
            $nombre_usr = $_SESSION['nombre_usuario'] ?? 'Desconocido';

            // 3. GUARDAR TODO (Ahora incluyendo el nombre del mapa)
            $sql_firma = "INSERT INTO public.registro_seguridad 
                    (id_usuario, nombre_usuario, rol_usuario, tipo_alerta, nombre_mapa, latitud, longitud, fecha_hora) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $pdo->prepare($sql_firma)->execute([
                $id_usuario_actual, $nombre_usr, $rol_usr, $tipo_alerta, $nombre_mapa, $lat, $lng, $fecha_hora
            ]);
            
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
