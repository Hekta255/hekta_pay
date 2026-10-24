<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use HektaPay\Database\Connection;

Connection::loadEnvironment();

$appId = $argv[1] ?? '';
$gateway = strtolower($argv[2] ?? '');
$action = $argv[3] ?? 'add';
if ($appId === '' || !in_array($gateway, ['pesapal', 'google_play'], true) || !in_array($action, ['add', 'remove'], true)) {
    fwrite(STDERR, "Usage: php bin/allow_gateway.php <app_id> <pesapal|google_play> [add|remove]\n");
    exit(2);
}

$db = Connection::create();
$stmt = $db->prepare('SELECT allowed_gateways FROM hekta_app_credentials WHERE app_id = ? LIMIT 1');
$stmt->execute([$appId]);
$row = $stmt->fetch();
if (!$row) {
    fwrite(STDERR, "App not found: {$appId}\n");
    exit(1);
}
$allowed = json_decode((string) $row['allowed_gateways'], true);
$allowed = is_array($allowed) ? $allowed : ['pesapal'];
$allowed = $action === 'add' ? array_values(array_unique(array_merge($allowed, [$gateway]))) : array_values(array_diff($allowed, [$gateway]));
$db->prepare('UPDATE hekta_app_credentials SET allowed_gateways = ? WHERE app_id = ?')->execute([json_encode($allowed), $appId]);
fwrite(STDOUT, "{$appId} allowed_gateways: " . json_encode($allowed) . PHP_EOL);
