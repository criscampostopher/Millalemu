<?php
session_start();
require_once 'Config/db_config.php';

// 1. SEGURIDAD: Verificar que el usuario esté logueado
if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit;
}
$mi_id = (int)$_SESSION['id_usuario'];
$tipo_usuario = $_SESSION['tipo_usuario'] ?? 'usuario'; // Detectamos si es admin o trabajador

// Definimos a dónde volver según el rol
$pagina_destino = ($tipo_usuario === 'admin') ? 'menuadmin.php' : 'menu_usuario.php';

// 2. Verificar que recibimos datos por POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $id_envio = isset($_POST['id_envio']) ? (int)$_POST['id_envio'] : 0;
    $id_piv   = isset($_POST['id_piv']) ? (int)$_POST['id_piv'] : 0;
    $accion   = $_POST['accion'] ?? '';
    $motivo   = trim($_POST['observacion'] ?? ''); 

    if ($id_envio <= 0) {
        // En caso de error, volvemos al menú correspondiente con mensaje de error
        header("Location: $pagina_destino?msg=error_datos");
        exit;
    }

    try {
        // 3. LÓGICA DE APROBACIÓN (CHECK)
        if ($accion === 'aprobar') {
            
            // Verificamos que sea EL usuario correcto quien firma
            $sql = "UPDATE public.piv_envio 
                    SET estado = 'aprobado', 
                        fecha_respuesta = NOW(), 
                        visto_en = NOW() 
                    WHERE id_envio = :id_envio AND para_usuario = :mi_id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id_envio' => $id_envio,
                ':mi_id'    => $mi_id
            ]);
            
            // Redirigir al menú correcto
            header("Location: $pagina_destino?msg=firmado_ok"); 
            exit;

        // 4. LÓGICA DE RECHAZO / OBSERVACIÓN (NO CHECK)
        } elseif ($accion === 'rechazar') {
            
            if (empty($motivo)) {
                // Si falta motivo, no hacemos nada y volvemos
                header("Location: $pagina_destino?msg=falta_motivo");
                exit;
            }

            $sql = "UPDATE public.piv_envio 
                    SET estado = 'rechazado', 
                        observacion_firma = :motivo, 
                        fecha_respuesta = NOW(), 
                        visto_en = NOW() 
                    WHERE id_envio = :id_envio AND para_usuario = :mi_id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':motivo'   => $motivo,
                ':id_envio' => $id_envio,
                ':mi_id'    => $mi_id
            ]);

            // Redirigir al menú correcto
            header("Location: $pagina_destino?msg=observacion_guardada");
            exit;
            
        } else {
            // Acción desconocida
            header("Location: $pagina_destino");
            exit;
        }

    } catch (PDOException $e) {
        die("Error en la base de datos: " . $e->getMessage());
    }

} else {
    // Acceso directo sin POST
    header("Location: $pagina_destino");
    exit;
}
?>