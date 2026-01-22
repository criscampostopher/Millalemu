<?php
// ==========================================================
// Archivo: Config/db_config.php
// ==========================================================

$host = "localhost"; 
$dbname = "Millalemu"; 
$user = "postgres";          
$password = "#sagitario18"; 


$dsn = "pgsql:host=$host;dbname=$dbname;options='--client_encoding=UTF8'";

try {
    $pdo = new PDO($dsn, $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
 
    die("Error de conexión a la Base de Datos: " . $e->getMessage()); 
}
?>