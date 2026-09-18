<?php

declare(strict_types=1);

session_name('hekta_pay_test');
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$configuredPassword = (string) (getenv('HEKTA_PAY_TEST_UI_PASSWORD') ?: '');
if ($configuredPassword === '') {
    http_response_code(404);
    echo 'Not found';
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function jsonPretty($value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: (string) $value;
}

function uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'status' => $status,
        'elapsed_ms' => round((microtime(true) - $start) * 1000, 2),
        'error' => $error,
        'body' => is_string($response) ? (json_decode($response, true) ?? $response) : null,
    ];
}

function checkResult(string $name, bool $ok, string $detail, $data = null): array
{
    return ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'data' => $data];
}

if (isset($_POST['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (!isset($_SESSION['authenticated'])) {
    $loginError = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (hash_equals($configuredPassword, (string) $_POST['password'])) {
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        $loginError = 'Invalid test UI password.';
    }
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hekta Pay Test Login</title><style>body{font-family:system-ui;background:#08131f;color:#eef5ff;display:grid;place-items:center;min-height:100vh;margin:0}.box{width:min(420px,calc(100% - 32px));background:#102235;padding:28px;border:1px solid #28425b;border-radius:14px}input,button{width:100%;padding:12px;margin-top:10px;border-radius:8px;border:1px solid #42627f;box-sizing:border-box}button{background:#42d3a4;color:#06131c;font-weight:700;cursor:pointer}.error{color:#ff8d8d}</style></head><body><main class="box"><h1>Hekta Pay Test UI</h1><p>Restricted backend diagnostics.</p><?= $loginError ? '<p class="error">' . h($loginError) . '</p>' : '' ?><form method="post"><label>Test UI password<input type="password" name="password" required autofocus></label><button type="submit">Open diagnostics</button></form></main></body></html>
    <?php
    exit;
}

$results = [];
$invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
$baseUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$appId = (string) (getenv('TEST_APP_ID') ?: 'com.hekta.nafdex');
$appSecret = (string) (getenv('TEST_APP_SECRET') ?: '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_safe_tests'])) {
    $results[] = checkResult('Local health route', true, 'The route is available to the deployer and does not require MySQL.', ['url' => $baseUrl . '/health']);

    try {
        $autoload = is_file(__DIR__ . '/src/autoload.php')
            ? __DIR__ . '/src/autoload.php'
            : __DIR__ . '/../src/autoload.php';
        require_once $autoload;
        $db = \HektaPay\Database\Connection::create();
        $results[] = checkResult('Database connection', true, 'PDO connected successfully.');

        $requiredTables = ['hekta_app_credentials', 'hekta_gateway_configs', 'hekta_invoices', 'hekta_transactions', 'hekta_webhook_logs', 'hekta_webhook_retry_queue'];
        $missing = [];
        foreach ($requiredTables as $table) {
            $statement = $db->query('SHOW TABLES LIKE ' . $db->quote($table));
            if (!$statement->fetchColumn()) {
                $missing[] = $table;
            }
        }
        $results[] = checkResult('Payment schema', $missing === [], $missing ? 'Missing tables: ' . implode(', ', $missing) : 'All required Hekta Pay tables exist.', ['required' => $requiredTables, 'missing' => $missing]);

        $credential = null;
        if ($appId !== '') {
            $statement = $db->prepare('SELECT app_id, is_active, webhook_url, allowed_gateways FROM hekta_app_credentials WHERE app_id = ? LIMIT 1');
            $statement->execute([$appId]);
            $credential = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $results[] = checkResult('NafDex app registration', is_array($credential) && (int) $credential['is_active'] === 1, $credential ? 'App credential exists and is active.' : 'App credential is missing or inactive.', $credential);

        $eventId = uuid();
        $timestamp = gmdate('c');
        $payload = json_encode(['event_id' => $eventId, 'event' => 'payment.test', 'invoice_id' => 'test-' . $eventId, 'timestamp' => $timestamp], JSON_THROW_ON_ERROR);
        $signature = \HektaPay\Services\WebhookSigner::sign($timestamp, $payload, 'remote-test-secret');
        $verified = \HektaPay\Services\WebhookSigner::verify($timestamp, $payload, $signature, 'remote-test-secret');
        $rejected = !\HektaPay\Services\WebhookSigner::verify($timestamp, $payload . 'tampered', $signature, 'remote-test-secret');
        $results[] = checkResult('Webhook signing', $verified && $rejected, $verified && $rejected ? 'Valid signatures verify and tampered payloads are rejected.' : 'Webhook signer verification failed.', ['event_id' => $eventId]);
    } catch (Throwable $error) {
        $results[] = checkResult('Database and service bootstrap', false, $error->getMessage());
    }

    $unauthorized = httpRequest('GET', $baseUrl . '/api/payment/test-invoice/status', ['Accept: application/json']);
    $results[] = checkResult('Unauthenticated API rejection', $unauthorized['status'] === 401, 'Expected HTTP 401 without X-App-ID and X-App-Secret.', $unauthorized);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_gateway_test'])) {
    if ($appSecret === '') {
        $results[] = checkResult('Sandbox gateway initialization', false, 'TEST_APP_SECRET is not configured on the server.');
    } else {
        $payload = [
            'tenant_id' => 'remote-test-' . gmdate('YmdHis'),
            'amount' => 1.00,
            'currency' => 'USD',
            'description' => 'Hekta Pay remote sandbox test',
            'gateway' => 'pesapal',
            'metadata' => ['source' => 'hekta_pay_remote_test'],
            'customer' => ['email' => getenv('TEST_CUSTOMER_EMAIL') ?: 'remote-test@example.com'],
        ];
        $gatewayResult = httpRequest('POST', $baseUrl . '/api/payment/initialize', [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-App-ID: ' . $appId,
            'X-App-Secret: ' . $appSecret,
        ], json_encode($payload));
        $created = is_array($gatewayResult['body']) && !empty($gatewayResult['body']['invoice']['id']);
        if ($created) {
            $invoiceId = (string) $gatewayResult['body']['invoice']['id'];
        }
        $results[] = checkResult('Sandbox gateway initialization', $created, $created ? 'Invoice created and payment URL returned.' : 'Initialization failed; inspect the response for gateway or configuration errors.', $gatewayResult);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_invoice']) && $invoiceId !== '') {
    if ($appSecret === '') {
        $results[] = checkResult('Invoice status lookup', false, 'TEST_APP_SECRET is not configured on the server.');
    } else {
        $statusResult = httpRequest('GET', $baseUrl . '/api/payment/' . rawurlencode($invoiceId) . '/status', ['Accept: application/json', 'X-App-ID: ' . $appId, 'X-App-Secret: ' . $appSecret]);
        $results[] = checkResult('Invoice status lookup', $statusResult['status'] === 200, 'Expected HTTP 200 for an invoice belonging to the configured app.', $statusResult);
    }
}

$passed = count(array_filter($results, static fn(array $result): bool => $result['ok']));
$failed = count($results) - $passed;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hekta Pay Remote Test UI</title><style>:root{--bg:#08131f;--panel:#102235;--line:#28425b;--text:#eef5ff;--muted:#a8bfd4;--ok:#42d3a4;--bad:#ff7f7f;--accent:#68b6ff}*{box-sizing:border-box}body{margin:0;padding:24px;background:linear-gradient(145deg,#08131f,#102c3e);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,sans-serif}.shell{max-width:1050px;margin:auto}.panel{background:rgba(16,34,53,.96);border:1px solid var(--line);border-radius:16px;padding:22px;margin-bottom:18px}h1{margin-top:0}h2{font-size:1.1rem}.muted{color:var(--muted)}button,input{font:inherit;padding:11px 13px;border-radius:8px;border:1px solid #42627f}button{background:var(--accent);border:0;color:#07131f;font-weight:700;cursor:pointer;margin:5px 5px 5px 0}input{background:#091725;color:var(--text);min-width:320px}.danger{background:#704047;color:#fff}.summary{display:flex;gap:12px;flex-wrap:wrap}.pill{padding:8px 12px;border-radius:999px;background:#1e3b50}.ok{color:var(--ok)}.bad{color:var(--bad)}details{margin-top:10px}pre{white-space:pre-wrap;overflow:auto;background:#091725;padding:14px;border-radius:8px;color:#cfe5f6}.result{border-top:1px solid var(--line);padding:14px 0}.result:first-child{border-top:0}.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}@media(max-width:600px){body{padding:12px}input{min-width:100%;width:100%}}</style></head><body><main class="shell"><section class="panel"><div class="row"><div style="flex:1"><h1>Hekta Pay Remote Test UI</h1><p class="muted">Protected backend diagnostics for pay.sebuleni.com. No app or real payment is required for the safe suite.</p></div><form method="post"><button class="danger" name="logout" value="1">Lock UI</button></form></div><div class="summary"><span class="pill">Base: <?= h($baseUrl) ?></span><span class="pill">App: <?= h($appId) ?></span><span class="pill">Passed: <b class="ok"><?= $passed ?></b></span><span class="pill">Failed: <b class="bad"><?= $failed ?></b></span></div></section><section class="panel"><h2>Safe backend diagnostics</h2><p class="muted">Checks PHP bootstrap, database connection, required tables, NafDex registration, webhook signing, and unauthenticated API rejection.</p><form method="post"><button name="run_safe_tests" value="1">Run safe suite</button></form></section><section class="panel"><h2>Optional Pesapal sandbox initialization</h2><p class="muted">Creates a small sandbox invoice and returns a payment URL. It does not complete or charge a payment unless you open the URL and finish the sandbox flow.</p><form method="post"><button name="run_gateway_test" value="1">Initialize sandbox invoice</button></form></section><section class="panel"><h2>Check an invoice</h2><form method="post"><div class="row"><input name="invoice_id" value="<?= h($invoiceId) ?>" placeholder="Invoice UUID" required><button name="check_invoice" value="1">Check status</button></div></form></section><?php if ($results): ?><section class="panel"><h2>Results</h2><?php foreach ($results as $result): ?><article class="result"><strong class="<?= $result['ok'] ? 'ok' : 'bad' ?>"><?= $result['ok'] ? 'PASS' : 'FAIL' ?></strong> <?= h($result['name']) ?><div class="muted"><?= h($result['detail']) ?></div><?php if ($result['data'] !== null): ?><details><summary>Response details</summary><pre><?= h(jsonPretty($result['data'])) ?></pre></details><?php endif; ?></article><?php endforeach; ?></section><?php endif; ?><section class="panel"><p class="muted">Never leave this tool enabled without a strong password. Disable it by removing <code>HEKTA_PAY_TEST_UI_PASSWORD</code> or removing this file after backend validation.</p></section></main></body></html>
