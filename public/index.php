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

Connection::loadEnvironment();

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

$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
if ($path === 'src' || strpos($path, 'src/') === 0) {
    respond(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'Application source paths are not public.']], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === 'health') {
    respond([
        'success' => true,
        'service' => 'hekta-pay',
        'environment' => getenv('APP_ENV') ?: 'production',
    ]);
}

try {
    $db = Connection::create();
    $orchestrator = new PaymentOrchestrator($db, new WebhookDispatcher($db));
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $appId = (string) ($_SERVER['HTTP_X_APP_ID'] ?? '');
    $secret = (string) ($_SERVER['HTTP_X_APP_SECRET'] ?? '');

    if (preg_match('#^api/ipn/([^/]+)$#', $path, $matches)) {
        respond($orchestrator->processIpn($matches[1], $input));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === 'payment-callback') {
        renderPaymentCallback($orchestrator->callbackDetails(
            $_GET['OrderTrackingId'] ?? null,
            $_GET['OrderMerchantReference'] ?? null,
        ));
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^(?:api/v1/)?api/payment/([^/]+)/initialize$#', $path, $matches)) {
        respond($orchestrator->initializeExisting($appId, $matches[1]));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^(?:api/v1/)?api/payment/([^/]+)/cancel$#', $path, $matches)) {
        respond($orchestrator->cancelInvoice($appId, $matches[1]));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^(?:api/v1/)?api/payment/([^/]+)/status$#', $path, $matches)) {
        respond($orchestrator->status($appId, $matches[1]));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^(?:api/v1/)?api/invoices$#', $path)) {
        respond($orchestrator->listInvoices($appId, (string) ($_GET['tenant_id'] ?? ''), $_GET['status'] ?? null, (int) ($_GET['limit'] ?? 50), (int) ($_GET['offset'] ?? 0)));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^(?:api/v1/)?api/transactions$#', $path)) {
        respond($orchestrator->listTransactions($appId, (string) ($_GET['tenant_id'] ?? ''), (int) ($_GET['limit'] ?? 50), (int) ($_GET['offset'] ?? 0)));
    }
    respond(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Route not found.']], 404);
} catch (Throwable $error) {
    error_log('[hekta-pay] ' . $error->getMessage());
    respond(['success' => false, 'error' => ['code' => 'PAYMENT_ERROR', 'message' => $error->getMessage()]], 500);
}

function renderPaymentCallback(?array $details): never
{
    $invoice = $details['invoice'] ?? null;
    $appName = $details['app_name'] ?? 'your app';
    $status = strtolower((string) ($invoice['status'] ?? 'pending'));
    $isComplete = $status === 'completed';
    $isFailed = in_array($status, ['failed', 'cancelled'], true);
    $title = $isComplete ? 'Payment confirmed' : ($isFailed ? 'Payment not completed' : 'Payment received');
    $message = $isComplete
        ? 'Your payment was confirmed successfully. You can now return to ' . $appName . ' to continue.'
        : ($isFailed
            ? 'This payment was not completed. Return to ' . $appName . ' to try again or choose another option.'
            : 'Your payment request was received and is still being verified. Return to ' . $appName . ' shortly to continue.');
    $accent = $isComplete ? '#159570' : ($isFailed ? '#b42318' : '#a15c00');
    $icon = $isComplete ? '&#10003;' : ($isFailed ? '&#33;' : '&#8230;');
    $returnUrl = $details['return_url'] ?? null;
    $safeAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeReturnUrl = $returnUrl ? htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') : '';
    $invoiceId = htmlspecialchars((string) ($invoice['id'] ?? ''), ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    http_response_code($details ? 200 : 404);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $safeTitle . ' | Hekta Pay</title><style>'
        . ':root{color-scheme:light}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f5f7f8;color:#17212b;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.page{width:min(100%,520px);text-align:center}.brand{font-size:13px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:#66737d;margin-bottom:18px}.card{background:#fff;border:1px solid #e1e7ea;border-radius:24px;padding:42px 32px 34px;box-shadow:0 18px 55px rgba(22,39,49,.1)}.icon{width:72px;height:72px;margin:0 auto 24px;border-radius:50%;display:grid;place-items:center;background:' . $accent . ';color:#fff;font-size:42px;font-weight:700;line-height:1}.title{margin:0;font-size:30px;line-height:1.15;letter-spacing:-.02em}.app{margin:12px 0 0;font-size:16px;font-weight:700;color:' . $accent . '}.message{margin:22px auto 0;max-width:390px;color:#5c6870;font-size:16px;line-height:1.6}.return{display:inline-block;margin-top:28px;padding:13px 20px;border-radius:12px;background:#17212b;color:#fff;text-decoration:none;font-weight:700}.hint{margin:24px 0 0;color:#89939a;font-size:12px}.reference{margin-top:20px;color:#a0a9ae;font-size:11px;word-break:break-all}@media(max-width:420px){.card{padding:34px 22px 28px}.title{font-size:26px}}'
        . '</style></head><body><main class="page"><div class="brand">Hekta Pay</div><section class="card">'
        . '<div class="icon" aria-hidden="true">' . $icon . '</div><h1 class="title">' . $safeTitle . '</h1><div class="app">' . $safeAppName . '</div><p class="message">' . $safeMessage . '</p>'
        . ($safeReturnUrl !== '' ? '<a class="return" href="' . $safeReturnUrl . '">Return to ' . $safeAppName . '</a>' : '<p class="hint">Please return to ' . $safeAppName . ' to continue.</p>')
        . ($invoiceId !== '' ? '<div class="reference">Reference: ' . $invoiceId . '</div>' : '')
        . '</section></main></body></html>';
    exit;
}
