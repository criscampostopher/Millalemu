<?php
require "Config/db_config.php";

$stmt = $pdo->query("SELECT current_database(), current_user");
echo "<pre>";
print_r($stmt->fetchAll());
echo "</pre>";
