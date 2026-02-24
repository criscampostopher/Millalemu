<?php
// ==========================================================
// Api/api_usuarios.php 
// ==========================================================
require_once __DIR__ . '/../Config/db_config.php';
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['tipo_usuario'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$inputJSON = file_get_contents('php://input');
$data = json_decode($inputJSON, true);
$action = $_GET['action'] ?? $data['action'] ?? '';

try {
    switch ($action) {
        
        // ---  LISTAR USUARIOS ---
        case 'fetch':
            $sql = "SELECT id_usuario AS id, nombre_usuario, email, tipo_usuario AS rol FROM public.usuario ORDER BY id_usuario ASC";
            $stmt = $pdo->query($sql);
            echo json_encode(['success' => true, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ---  CREAR USUARIO ---
        case 'create':
            $user = trim($data['user'] ?? '');
            $email = trim($data['email'] ?? ''); 
            $pass = trim($data['pass'] ?? '');
            $rol  = $data['rol'] ?? 'usuario';

            if (empty($user) || empty($pass)) throw new Exception("Faltan datos obligatorios");

            // Validar duplicados
            $check = $pdo->prepare("SELECT 1 FROM public.usuario WHERE nombre_usuario = ? OR email = ?");
            $check->execute([$user, $email]);
            if ($check->fetch()) throw new Exception("Usuario o Email ya existe");

            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $sql = "INSERT INTO public.usuario (nombre_usuario, email, contrasena_hash, tipo_usuario) VALUES (?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$user, empty($email) ? null : $email, $hash, $rol]);
            

            

            echo json_encode(['success' => true]);
            break;

        // ---  ACTUALIZAR USUARIO ---
        case 'update':
            $id   = $data['id'];
            if ($id == 1 && $_SESSION['id_usuario'] != 1) throw new Exception("Solo el Super Admin puede editarse a sí mismo.");

            $user = trim($data['user']);
            $email = trim($data['email'] ?? ''); 
            $pass = trim($data['pass'] ?? '');
            $rol  = $data['rol'];

            $sqlBase = "UPDATE public.usuario SET nombre_usuario=?, email=?, tipo_usuario=?";
            $params = [$user, empty($email) ? null : $email, $rol];

            if (!empty($pass)) {
                $sqlBase .= ", contrasena_hash=?";
                $params[] = password_hash($pass, PASSWORD_DEFAULT);
            }
            
            $sqlBase .= " WHERE id_usuario=?";
            $params[] = $id;

            $pdo->prepare($sqlBase)->execute($params);
            echo json_encode(['success' => true]);
            break;

        case 'delete':
            $id = $data['id'];
            if ($id == 1 || $id == $_SESSION['id_usuario']) throw new Exception("No puedes borrarte a ti mismo ni al admin principal");
            $pdo->prepare("DELETE FROM public.usuario WHERE id_usuario = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        case 'get_user_maps': // Ahora trae las Zonas asignadas
            $id_usuario = $data['id_usuario'];
            $sql = "SELECT uz.id_zona, z.nombre_zona 
                    FROM public.usuario_zona uz
                    JOIN public.zona z ON uz.id_zona = z.id_zona
                    WHERE uz.id_usuario = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id_usuario]);
            echo json_encode(['success' => true, 'maps' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'assign_map':
            $id_usuario = $data['id_usuario'];
            $id_zona    = $data['id_zona']; // Cambiado de id_mapa a id_zona
            $inicio     = $data['inicio'] ?: date('Y-m-d H:i:s');

            $sql = "INSERT INTO public.usuario_zona (id_usuario, id_zona, fecha_inicio) VALUES (?, ?, ?)";
            $pdo->prepare($sql)->execute([$id_usuario, $id_zona, $inicio]);
            echo json_encode(['success' => true]);
            break;

        // --- MODIFICAR FECHAS DE ASIGNACIÓN ---
        case 'update_map_assignment':
            $id_usuario = $data['id_usuario'];
            $id_mapa    = $data['id_zona'];
            $inicio     = $data['inicio'] ?: date('Y-m-d H:i:s');
            $fin        = $data['fin'] ?: null;

            if ($fin && strtotime($fin) <= strtotime($inicio)) throw new Exception("Fecha fin debe ser mayor a inicio");

            $sql = "UPDATE public.usuario_zona SET fecha_inicio = ?, fecha_fin = ? WHERE id_usuario = ? AND id_zona = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$inicio, $fin, $id_usuario, $id_zona]);
            
            echo json_encode(['success' => true]);
            break;

        case 'unassign_map':
            $id_usuario = $data['id_usuario'];
            $id_zona    = $data['id_zona'];
            if ($id_usuario == 1 && $id_zona == 1) throw new Exception("No se puede quitar el mapa base al Super Admin.");
            $pdo->prepare("DELETE FROM public.usuario_zona WHERE id_usuario = ? AND id_zona = ?")->execute([$id_usuario, $id_zona]);
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción inválida']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>