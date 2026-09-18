<?php

declare(strict_types=1);

$autoloadCandidates = [
    // Production FTP layout: public/index.php and src/ are siblings below the document root.
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/src/autoload.php',
    // Local layout: public/ and src/ are siblings in the repository.
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../src/autoload.php',
];

$autoload = null;
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}

if ($autoload === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => ['code' => 'AUTOLOAD_NOT_FOUND', 'message' => 'Hekta Pay application source is not installed.']]);
    exit;
}

require_once $autoload;

use HektaPay\Database\Connection;
use HektaPay\Payment\PaymentOrchestrator;
use HektaPay\Services\WebhookDispatcher;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-App-ID, X-App-Secret');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

try {
    $db = Connection::create();
    $orchestrator = new PaymentOrchestrator($db, new WebhookDispatcher($db));
    $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $appId = (string) ($_SERVER['HTTP_X_APP_ID'] ?? '');
    $secret = (string) ($_SERVER['HTTP_X_APP_SECRET'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === 'health') {
        respond(['success' => true, 'service' => 'hekta-pay', 'environment' => getenv('APP_ENV') ?: 'production']);
    }

    if (preg_match('#^api/ipn/([^/]+)$#', $path, $matches)) {
        respond($orchestrator->processIpn($matches[1], $input));
    }
    if ($appId === '' || $secret === '') {
        respond(['success' => false, 'error' => ['code' => 'APP_NOT_AUTHORIZED', 'message' => 'App credentials are required.']], 401);
    }
    $credential = $orchestrator->credential($appId);
    if (!$credential || !password_verify($secret, $credential['app_secret_hash'])) {
        respond(['success' => false, 'error' => ['code' => 'APP_NOT_AUTHORIZED', 'message' => 'Invalid app credentials.']], 401);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($path === 'api/payment/initialize' || $path === 'api/v1/api/payment/initialize')) {
        respond($orchestrator->initialize($appId, $input));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^(?:api/v1/)?api/payment/([^/]+)/status$#', $path, $matches)) {
        respond($orchestrator->status($appId, $matches[1]));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^(?:api/v1/)?api/invoices$#', $path)) {
        respond($orchestrator->listInvoices($appId, (string) ($_GET['tenant_id'] ?? ''), $_GET['status'] ?? null, (int) ($_GET['limit'] ?? 50), (int) ($_GET['offset'] ?? 0)));
    }
    respond(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Route not found.']], 404);
} catch (Throwable $error) {
    error_log('[hekta-pay] ' . $error->getMessage());
    respond(['success' => false, 'error' => ['code' => 'PAYMENT_ERROR', 'message' => $error->getMessage()]], 500);
}
