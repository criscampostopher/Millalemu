<?php
// ==========================================================
// db_config.php (LOCAL + RAILWAY) ✅ CORRECTO
// ==========================================================

try {

    if (getenv("PGHOST")) {
        // 🟢 PRODUCCIÓN (Railway)
        $host = getenv("PGHOST");
        $dbname = getenv("PGDATABASE");
        $user = getenv("PGUSER");
        $password = getenv("PGPASSWORD");
        $port = getenv("PGPORT");

        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
        $pdo = new PDO($dsn, $user, $password);
    } else {
        // 🔵 LOCAL (XAMPP / pgAdmin)
        $host = "localhost";
        $dbname = "Millalemu";
        $user = "postgres";
        $password = "oso";
        $port = "5432";

        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;options='--client_encoding=UTF8'";
        $pdo = new PDO($dsn, $user, $password);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("❌ Error DB: " . $e->getMessage());
}
