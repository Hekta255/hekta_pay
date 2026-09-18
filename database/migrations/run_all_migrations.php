<?php

declare(strict_types=1);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$name = getenv('DB_NAME') ?: 'hekta_pay';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

try {
    $db = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $migrations = ['20260918_create_payment_infrastructure.sql'];
    foreach ($migrations as $migration) {
        $path = __DIR__ . DIRECTORY_SEPARATOR . $migration;
        echo "Applying {$migration}... ";
        $db->exec(file_get_contents($path));
        echo "OK\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "FAILED: {$error->getMessage()}\n");
    exit(1);
}
