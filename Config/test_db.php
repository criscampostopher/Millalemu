<?php
include 'db_config.php';

try {
    $stmt = $pdo->query("SELECT current_user, current_database()");
    $row = $stmt->fetch();
    echo "✅ Conexión Exitosa!<br>";
    echo "Usuario DB: " . $row['current_user'] . "<br>";
    echo "Base de datos: " . $row['current_database'] . "<br>";
    
    $stmt2 = $pdo->query("SELECT COUNT(*) FROM public.usuario");
    echo "Total usuarios: " . $stmt2->fetchColumn();
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage();
}
?>