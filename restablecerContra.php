<?php
require_once __DIR__ . '/Config/db_config.php';
$token = $_GET['token'] ?? '';
$mensaje = '';
$tokenValido = false;

if ($token) {
    $stmt = $pdo->prepare("SELECT id_usuario FROM public.usuario WHERE token_recuperacion = ? AND token_expiracion > NOW()");
    $stmt->execute([$token]);
    if ($stmt->fetch()) {
        $tokenValido = true;
    } else {
        $mensaje = "El enlace es inválido o ha expirado.";
    }
}

// Procesar Cambio
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $tokenValido) {
    $pass = $_POST['password'];
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    
    // Actualizar y borrar token
    $upd = $pdo->prepare("UPDATE public.usuario SET contrasena_hash = ?, token_recuperacion = NULL, token_expiracion = NULL WHERE token_recuperacion = ?");
    $upd->execute([$hash, $token]);
    
    header("Location: login.php?msg=Clave restablecida con éxito");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer Contraseña</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-forest">
    <div class="login-card">
        <h2 class="title-forest">Nueva Contraseña</h2>
        
        <?php if ($tokenValido): ?>
            <form method="POST">
                <div class="input-group">
                    <input type="password" name="password" class="form-control" placeholder="Escribe tu nueva clave" required minlength="4">
                </div>
                <button type="submit" class="btn-forest">Cambiar Clave</button>
            </form>
        <?php else: ?>
            <div class="error-msg"><?= $mensaje ?></div>
            <a href="login.php" class="btn-forest" style="display:block; text-decoration:none;">Volver al inicio</a>
        <?php endif; ?>
    </div>
</body>
</html>