<?php
// ==========================================================
//  Api/procesar_login.php 
// ==========================================================
session_start();
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/roles.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $user = trim($_POST["username"]);
    $pass = trim($_POST["password"]);

    try {
        // 1. Buscamos el usuario por su nombre

        $sql = "SELECT id_usuario, nombre_usuario, contrasena_hash, tipo_usuario 
                FROM public.usuario 
                WHERE nombre_usuario = :user";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user' => $user]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2. Verificamos la contraseña

        if ($usuario && password_verify($pass, $usuario['contrasena_hash'])) {

            session_regenerate_id(true);

            // Guardar datos en sesión
            $_SESSION['id_usuario'] = $usuario['id_usuario'];
            $_SESSION['nombre_usuario'] = $usuario['nombre_usuario'];
            $_SESSION['tipo_usuario'] = $usuario['tipo_usuario'];

            // --- REDIRECCIÓN INTELIGENTE ---
            if (rolTieneFuncionesAdmin($usuario['tipo_usuario'])) {
            
                header("Location: ../menuadmin.php");
            } else {
              
                header("Location: ../menu_usuario.php");
            }
            exit;

        } else {
            // --- LOGIN FALLIDO ---
            header("Location: ../login.php?error=1");
            exit;
        }

    } catch (Exception $e) {
        die("Error del sistema: " . $e->getMessage());
    }
} else {
   
    header("Location: ../login.php");
    exit;
}
?>
