<?php

declare(strict_types=1);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$name = getenv('DB_NAME') ?: 'hekta_pay';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

// Migrations that cannot be re-run map to a query that returns a positive count once they are applied.
$appliedChecks = [
    '20260923_add_invoice_environment.sql' => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hekta_invoices' AND COLUMN_NAME = 'environment'",
    '20261101_hekta_pay_refunded_status.sql' => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hekta_invoices' AND COLUMN_NAME = 'refunded_at'",
];

try {
    $db = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $migrations = [
        '20260918_create_payment_infrastructure.sql',
        '20260923_add_invoice_environment.sql',
        '20261101_hekta_pay_google_play_tables.sql',
        '20261101_hekta_pay_refunded_status.sql',
        '20261101_hekta_pay_enforce_allowed_gateways.sql',
    ];
    foreach ($migrations as $migration) {
        $path = __DIR__ . DIRECTORY_SEPARATOR . $migration;
        if (isset($appliedChecks[$migration]) && (int) $db->query($appliedChecks[$migration])->fetchColumn() > 0) {
            echo "Skipping {$migration}; already applied.\n";
            continue;
        }
        echo "Applying {$migration}... ";
        $db->exec(file_get_contents($path));
        echo "OK\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "FAILED: {$error->getMessage()}\n");
    exit(1);
}
