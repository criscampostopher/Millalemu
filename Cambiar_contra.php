<?php
// Incluimos tu configuración
include_once 'Config/db_config.php';

try {
    $nueva_clave = 'Octavio1@@';
    $hash = password_hash($nueva_clave, PASSWORD_DEFAULT);
    
    $sql = "UPDATE public.usuario SET contrasena_hash = :hash WHERE nombre_usuario = 'cesar'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['hash' => $hash]);
    
    echo "✅ Contraseña de 'cesar' actualizada a: " . $nueva_clave;
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>