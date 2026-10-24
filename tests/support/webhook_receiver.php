<?php

declare(strict_types=1);

$webhookStateDir = getenv('WEBHOOK_STATE_DIR') ?: sys_get_temp_dir();
$body = file_get_contents('php://input') ?: '';
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_X_HEKTA_') === 0) {
        $headers[$key] = $value;
    }
}
file_put_contents($webhookStateDir . '/requests.jsonl', json_encode(['headers' => $headers, 'body' => $body]) . "\n", FILE_APPEND | LOCK_EX);
$mode = is_file($webhookStateDir . '/mode') ? trim((string) file_get_contents($webhookStateDir . '/mode')) : 'ok';
http_response_code($mode === 'fail' ? 500 : 200);
header('Content-Type: application/json');
echo json_encode(['success' => $mode !== 'fail']);
