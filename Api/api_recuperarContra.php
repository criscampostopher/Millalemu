<?php
// ==========================================================
//  Api/api_recuperarContra.php 
// ==========================================================
require_once __DIR__ . '/../Config/db_config.php';
header('Content-Type: application/json');

// Obtener datos del JSON enviado
$input = file_get_contents('php://input');
$data = json_decode($input, true);
$email = $data['email'] ?? '';

if (empty($email)) { 
    echo json_encode(['success'=>false, 'message'=>'Debes escribir un correo']); 
    exit; 
}

try {
    // 1. Verificar si existe el correo
    $stmt = $pdo->prepare("SELECT id_usuario, nombre_usuario FROM public.usuario WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // 2. Generar Token y Expiración 
        $token = bin2hex(random_bytes(32));
        $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Guardar token en la base de datos
        $upd = $pdo->prepare("UPDATE public.usuario SET token_recuperacion = ?, token_expiracion = ? WHERE id_usuario = ?");
        $upd->execute([$token, $expira, $user['id_usuario']]);

        // 3. Generar el Enlace
    
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        
   
        $path = dirname(dirname($_SERVER['PHP_SELF']));
        
        $link = "$protocol://$host$path/restablecerContra.php?token=$token";


        echo json_encode([
            'success' => true, 
            'message' => "MODO PRUEBA: El sistema generó el siguiente enlace (Cópialo y pégalo en el navegador): \n\n" . $link
        ]);

    } else {

        echo json_encode(['success'=>false, 'message'=>'Ese correo no está registrado en la base de datos.']);
    }

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'message'=>'Error del sistema: ' . $e->getMessage()]);
}
?>