<?php
session_start();
require_once __DIR__ . '/Config/roles.php';
if (isset($_SESSION['id_usuario'])) {
    if (rolTieneFuncionesAdmin($_SESSION['tipo_usuario'] ?? '')) {
        header("Location: menuadmin.php");
    } else {
        header("Location: index.php");
    }
    exit;
} 
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso - Forestal Millalemu</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>"> </head>
<body class="bg-forest">
    
    <div class="login-card">
        
        <div class="logo-container" style="text-align: center; margin-bottom: 20px;">
            <img src="https://static.wixstatic.com/media/10afc5_cda678d9eebb40459c24d67c6901d218~mv2.png/v1/crop/x_233,y_266,w_3650,h_1064/fill/w_600,h_175,al_c,q_85,usm_0.66_1.00_0.01,enc_avif,quality_auto/Transparente.png" 
                 alt="Logo Millalemu"
                 style="width: 320px !important; max-width: 100%; height: auto; display: block; margin: 0 auto;">
        </div>

        <h2 class="title-forest">Forestal Millalemu</h2>
        <p class="subtitle">Sistema de Gestión de Rutas y Seguridad</p>
        
        <form action="Api/procesar_login.php" method="POST">
            
            <?php if (isset($_GET['error'])): ?>
                <div class="error-msg">
                    Usuario o contraseña incorrectos
                </div>
            <?php endif; ?>

            <div class="input-group">
                <input type="text" name="username" class="form-control" placeholder="Nombre de usuario" required autocomplete="off">
            </div>

           
            <div class="input-group">
    <input type="password" name="password" class="form-control" placeholder="Contraseña" required>
</div>
            
            <button type="submit" class="btn-forest">Iniciar Sesión</button>
            
        </form>
    </div>

</body>
</html>
