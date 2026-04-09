<?php
date_default_timezone_set('America/Santiago');  // ← AGREGAR ESTA LÍNEA

$host = "localhost";
$dbname = "rutasegu_rutasegu_millalemu";
$user = "rutasegu_millalemu";
$password = "Octavio1@@";

$dsn = "pgsql:host=$host;dbname=$dbname;options='--client_encoding=UTF8'";

try {
    $pdo = new PDO($dsn, $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET TIME ZONE 'America/Santiago'");
} catch (PDOException $e) {
    die("Error de conexión a la Base de Datos: " . $e->getMessage());
}

try {
    // Revisa silenciosamente si hay asignaciones en la tabla 'usuario_zona'
    // cuya 'fecha_fin' exacta (día y hora) ya pasó, y las elimina.
    $sql_limpieza = "DELETE FROM public.usuario_zona WHERE fecha_fin < NOW()";
    $pdo->exec($sql_limpieza);
    
} catch (Exception $e) {
    // Si hay algún error, lo ignoramos para no romper la página al usuario.
}


// por probar

?>