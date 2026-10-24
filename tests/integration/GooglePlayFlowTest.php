<?php

declare(strict_types=1);

use HektaPay\GooglePlay\GooglePlayException;
use HektaPay\GooglePlay\GooglePlayRtdn;
use HektaPay\GooglePlay\GooglePlayService;
use HektaPay\GooglePlay\HttpTransport;
use HektaPay\GooglePlay\PlayApiClient;
use HektaPay\GooglePlay\PlayProductCatalog;
use HektaPay\GooglePlay\PubSubJwtVerifier;
use HektaPay\GooglePlay\ServiceAccountAuth;
use HektaPay\GooglePlay\TokenVault;
use HektaPay\Payment\PaymentOrchestrator;
use HektaPay\Services\WebhookDispatcher;

/** In-memory Google Play: purchases by token, consume tracking, and a voided list. */
final class FakeGoogle implements HttpTransport
{
    /** @var array<string,array<string,mixed>> */
    public array $purchases = [];
    /** @var string[] */
    public array $consumed = [];
    /** @var array<int,array<string,mixed>> */
    public array $voided = [];
    public int $fetches = 0;
    public bool $failConsume = false;

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 20): array
    {
        if ($url === 'https://oauth2.googleapis.com/token') {
            return ['status' => 200, 'body' => json_encode(['access_token' => 'fake', 'expires_in' => 3600])];
        }
        if (preg_match('#/purchases/productsv2/tokens/(.+)$#', $url, $m)) {
            $token = rawurldecode($m[1]);
            if (!isset($this->purchases[$token])) {
                return ['status' => 404, 'body' => '{"error":{"message":"not found"}}'];
            }
            $this->fetches++;
            return ['status' => 200, 'body' => json_encode($this->purchases[$token])];
        }
        if (preg_match('#/purchases/products/([^/]+)/tokens/(.+):consume$#', $url, $m)) {
            $token = rawurldecode($m[2]);
            if ($this->failConsume) {
                return ['status' => 400, 'body' => '{"error":{"message":"consume failed"}}'];
            }
            $this->consumed[] = $token;
            $this->purchases[$token]['productLineItem'][0]['productOfferDetails']['consumptionState'] = 'CONSUMPTION_STATE_CONSUMED';
            return ['status' => 200, 'body' => ''];
        }
        if (strpos($url, '/purchases/voidedpurchases?') !== false) {
            return ['status' => 200, 'body' => json_encode(['voidedPurchases' => $this->voided])];
        }
        return ['status' => 404, 'body' => '{}'];
    }
}

final class FlowContext
{
    public PDO $db;
    public string $dbName;
    public FakeGoogle $google;
    public PaymentOrchestrator $orchestrator;
    public GooglePlayService $service;
    public string $stateDir;
    public int $port;
    /** @var resource|null */
    public $server = null;
    public array $keys;
    public int $counter = 0;

    public function setWebhookMode(string $mode): void
    {
        file_put_contents($this->stateDir . '/mode', $mode);
    }

    /** @return array<int,array{headers:array<string,string>, body:array<string,mixed>}> */
    public function webhooks(): array
    {
        $file = $this->stateDir . '/requests.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $rows = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            $rows[] = ['headers' => $decoded['headers'], 'body' => json_decode($decoded['body'], true)];
        }
        return $rows;
    }

    /** @return array<int,array<string,mixed>> Webhooks delivered for an invoice and event. */
    public function webhooksFor(string $invoiceId, string $event): array
    {
        return array_values(array_filter($this->webhooks(), static fn(array $w): bool => ($w['body']['invoice_id'] ?? '') === $invoiceId && ($w['body']['event'] ?? '') === $event));
    }

    /** @return array<int,array<string,mixed>> */
    public function q(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function newInvoice(string $productId = 'nafdex_shop_monthly', string $environment = 'test', string $business = 'biz-1', string $invoiceType = 'upgrade', string $appId = 'com.sebuleni.nafdex'): array
    {
        $response = $this->orchestrator->initialize($appId, [
            'tenant_id' => $business,
            'amount' => 0.01,
            'currency' => 'EUR',
            'gateway' => 'google_play',
            'environment' => $environment,
            'invoice_type' => $invoiceType,
            'subscription_term_months' => 1,
            'metadata' => [
                'product_id' => $productId,
                'business_data' => ['id' => $business, 'owner_email' => 'owner@example.com', 'business_name' => 'Biz', 'plan_type' => 'growth', 'subscription_term_months' => 1],
            ],
        ]);
        return $response['invoice'];
    }

    /** Registers a Google purchase for an invoice and returns its token. */
    public function playPurchase(array $invoice, ?string $productId = null, array $override = []): string
    {
        $this->counter++;
        $token = 'token-' . $this->dbName . '-' . $this->counter;
        $productId = $productId ?? json_decode((string) $invoice['metadata'], true)['product_id'];
        $this->google->purchases[$token] = array_replace_recursive([
            'productLineItem' => [['productId' => $productId, 'productOfferDetails' => ['quantity' => 1, 'consumptionState' => 'CONSUMPTION_STATE_YET_TO_BE_CONSUMED']]],
            'purchaseStateContext' => ['purchaseState' => 'PURCHASED'],
            'orderId' => 'GPA.0000-0000-0000-' . str_pad((string) $this->counter, 5, '0', STR_PAD_LEFT),
            'obfuscatedExternalAccountId' => $invoice['id'],
            'regionCode' => 'UG',
            'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_PENDING',
        ], $override);
        return $token;
    }

    public function verify(array $invoice, string $token, ?string $productId = null): array
    {
        $productId = $productId ?? json_decode((string) $invoice['metadata'], true)['product_id'];
        return $this->orchestrator->verifyGooglePlayPurchase($invoice['app_id'], $invoice['id'], ['purchase_token' => $token, 'product_id' => $productId]);
    }

    public function purchaseRow(string $token): ?array
    {
        $rows = $this->q('SELECT * FROM hekta_google_play_purchases WHERE purchase_token_hash = ?', [TokenVault::hash($token)]);
        return $rows[0] ?? null;
    }

    public function invoice(string $id): array
    {
        return $this->q('SELECT * FROM hekta_invoices WHERE id = ?', [$id])[0];
    }
}

function flow_context(): ?FlowContext
{
    static $context = false;
    if ($context !== false) {
        return $context;
    }
    try {
        $root = new PDO('mysql:host=127.0.0.1;port=3306', getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $error) {
        echo "  skip integration tests: MySQL unavailable ({$error->getMessage()})\n";
        return $context = null;
    }

    $ctx = new FlowContext();
    $ctx->dbName = 'hekta_pay_test_' . bin2hex(random_bytes(4));
    $root->exec("CREATE DATABASE `{$ctx->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    putenv('DB_NAME=' . $ctx->dbName);
    putenv('DB_USER=' . (getenv('TEST_DB_USER') ?: 'root'));
    putenv('DB_PASS=' . (getenv('TEST_DB_PASS') ?: ''));
    $runner = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../database/migrations/run_all_migrations.php') . ' 2>&1';
    $output = (string) shell_exec($runner);
    if (strpos($output, 'FAILED') !== false) {
        throw new RuntimeException("Migrations failed:\n" . $output);
    }
    $second = (string) shell_exec($runner);
    if (strpos($second, 'FAILED') !== false || strpos($second, 'Skipping 20261101_hekta_pay_refunded_status.sql') === false) {
        throw new RuntimeException("Migrations are not re-runnable:\n" . $second);
    }

    $ctx->db = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$ctx->dbName};charset=utf8mb4", getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $ctx->stateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $ctx->dbName;
    mkdir($ctx->stateDir, 0777, true);
    putenv('WEBHOOK_STATE_DIR=' . $ctx->stateDir);
    $ctx->setWebhookMode('ok');
    $ctx->port = 18000 + random_int(0, 1500);
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $ctx->port, __DIR__ . '/../support/webhook_receiver.php'];
    $ctx->server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $ctx->stateDir . '/server.out', 'w'], 2 => ['file', $ctx->stateDir . '/server.err', 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $ctx->port, $errno, $errstr, 0.2);
        if ($socket) {
            fclose($socket);
            break;
        }
        usleep(100000);
    }

    $hash = password_hash('app-secret', PASSWORD_DEFAULT);
    $insert = $ctx->db->prepare('INSERT INTO hekta_app_credentials (app_id, app_secret_hash, app_name, owner_email, allowed_gateways, webhook_url, webhook_secret) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $webhookUrl = 'http://127.0.0.1:' . $ctx->port . '/webhook';
    $insert->execute(['com.sebuleni.nafdex', $hash, 'NafDex', 'ops@example.com', json_encode(['pesapal', 'google_play']), $webhookUrl, 'whsec_test']);
    $insert->execute(['com.pesapal.only', $hash, 'Legacy', 'ops@example.com', null, $webhookUrl, 'whsec_test']);

    $ctx->keys = test_rsa_key();
    $ctx->google = new FakeGoogle();
    $auth = new ServiceAccountAuth(['client_email' => 'svc@x', 'private_key' => $ctx->keys['private']], $ctx->google);
    $ctx->orchestrator = new PaymentOrchestrator($ctx->db, new WebhookDispatcher($ctx->db));
    $ctx->service = new GooglePlayService(
        $ctx->db,
        new PlayApiClient($auth, $ctx->google, static function (int $ms): void {}),
        new PlayProductCatalog($ctx->db),
        new TokenVault('integration-test-secret-key'),
        $ctx->orchestrator,
    );
    $ctx->orchestrator->useGooglePlay($ctx->service);

    register_shutdown_function(static function () use ($ctx, $root): void {
        if (is_resource($ctx->server)) {
            proc_terminate($ctx->server);
            proc_close($ctx->server);
        }
        $root->exec("DROP DATABASE IF EXISTS `{$ctx->dbName}`");
        foreach (glob($ctx->stateDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($ctx->stateDir);
    });
    return $context = $ctx;
}

function flow_test(string $name, callable $fn): void
{
    T::test($name, static function () use ($fn): void {
        $ctx = flow_context();
        if ($ctx === null) {
            return;
        }
        $fn($ctx);
    });
}

flow_test('migrations seed the NafDex catalog and populate allowed gateways', function (FlowContext $ctx): void {
    $ctx->db->exec("INSERT INTO hekta_google_play_products (app_id, package_name, product_id, plan_id, term_months, billing_period, amount, currency) VALUES ('com.hekta.nafdex', 'com.sebuleni.nafdex', 'stale_test_row', 'essential', 1, 'monthly', 1.9900, 'USD')");
    $ctx->db->exec((string) file_get_contents(__DIR__ . '/../../database/migrations/20261101_hekta_pay_google_play_tables.sql'));
    $products = $ctx->q('SELECT app_id, product_id, term_months, amount, plan_id, package_name FROM hekta_google_play_products ORDER BY term_months');
    T::same(2, count($products));
    T::same('com.sebuleni.nafdex', $products[0]['app_id']);
    T::same(0, (int) $ctx->db->query("SELECT COUNT(*) FROM hekta_google_play_products WHERE app_id = 'com.hekta.nafdex'")->fetchColumn());
    T::same('nafdex_shop_monthly', $products[0]['product_id']);
    T::same('nafdex_shop_annual', $products[1]['product_id']);
    T::same('com.sebuleni.nafdex', $products[0]['package_name']);
    T::same('19.9000', $products[1]['amount']);
    $ctx->db->exec(file_get_contents(__DIR__ . '/../../database/migrations/20261101_hekta_pay_enforce_allowed_gateways.sql'));
    $legacy = $ctx->q("SELECT allowed_gateways FROM hekta_app_credentials WHERE app_id = 'com.pesapal.only'")[0]['allowed_gateways'];
    T::same(['pesapal'], json_decode($legacy, true));
});

flow_test('initialize enforces allowed gateways for every gateway', function (FlowContext $ctx): void {
    T::throws(RuntimeException::class, fn() => $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-1', 'upgrade', 'com.pesapal.only'));
    $error = T::throws(RuntimeException::class, fn() => $ctx->orchestrator->initialize('com.sebuleni.nafdex', ['tenant_id' => 'b', 'amount' => 1, 'gateway' => 'stripe']));
    T::true(strpos($error->getMessage(), 'not enabled') !== false);
});

flow_test('initialize takes amount, term, and plan from the catalog instead of the client', function (FlowContext $ctx): void {
    $invoice = $ctx->newInvoice('nafdex_shop_annual');
    T::same('initiated', $invoice['status']);
    T::same('google_play', $invoice['gateway']);
    T::same('19.9000', $invoice['amount']);
    T::same('USD', $invoice['currency']);
    T::same(12, (int) $invoice['subscription_term_months']);
    T::same('yearly', $invoice['billing_period']);
    T::same(null, $invoice['payment_url']);
    $metadata = json_decode($invoice['metadata'], true);
    T::same(12, $metadata['business_data']['subscription_term_months']);
    T::same('essential', $metadata['business_data']['plan_type']);
    T::same('upgrade', $metadata['invoice_type']);
});

flow_test('initialize rejects missing, unknown products and invalid invoice types', function (FlowContext $ctx): void {
    T::throws(RuntimeException::class, fn() => $ctx->orchestrator->initialize('com.sebuleni.nafdex', ['tenant_id' => 'b', 'gateway' => 'google_play', 'metadata' => []]));
    T::throws(RuntimeException::class, fn() => $ctx->orchestrator->initialize('com.sebuleni.nafdex', ['tenant_id' => 'b', 'gateway' => 'google_play', 'metadata' => ['product_id' => 'nope']]));
    T::throws(RuntimeException::class, fn() => $ctx->newInvoice('nafdex_shop_monthly', 'test', 'b', 'one_time'));
});

flow_test('verify completes the invoice, sends one signed webhook, and consumes the purchase', function (FlowContext $ctx): void {
    $invoice = $ctx->newInvoice('nafdex_shop_annual', 'test', 'biz-happy');
    $token = $ctx->playPurchase($invoice);
    $result = $ctx->verify($invoice, $token);

    T::same(true, $result['success']);
    T::same('completed', $result['status']);
    T::same(true, $result['consumed']);
    T::same('completed', $result['invoice']['status']);
    T::same('GPA.0000-0000-0000-' . substr($ctx->purchaseRow($token)['order_id'], -5), $result['invoice']['gateway_order_id']);

    $row = $ctx->purchaseRow($token);
    T::true($row['consumed_at'] !== null && $row['webhook_confirmed_at'] !== null);
    T::same($invoice['id'], $row['invoice_id']);
    T::true($row['purchase_token_enc'] !== $token && strpos($row['purchase_token_enc'], $token) === false, 'token must not be stored in clear text');
    T::same([$token], $ctx->google->consumed);

    $hooks = $ctx->webhooksFor($invoice['id'], 'payment.completed');
    T::same(1, count($hooks));
    T::same('upgrade', $hooks[0]['body']['invoice_type']);
    T::same(12, $hooks[0]['body']['subscription_term_months']);
    T::same('google_play', $hooks[0]['body']['gateway']);
    T::same('biz-happy', $hooks[0]['body']['tenant_id']);
    T::same(12, $hooks[0]['body']['metadata']['business_data']['subscription_term_months']);
    $signature = 'sha256=' . hash_hmac('sha256', $hooks[0]['headers']['HTTP_X_HEKTA_TIMESTAMP'] . json_encode($hooks[0]['body']), 'whsec_test');
    T::true(strlen($hooks[0]['headers']['HTTP_X_HEKTA_SIGNATURE']) === strlen($signature));
    T::same(1, count($ctx->q('SELECT * FROM hekta_transactions WHERE invoice_id = ?', [$invoice['id']])));
});

flow_test('replaying a verified purchase changes nothing', function (FlowContext $ctx): void {
    $invoice = $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-replay');
    $token = $ctx->playPurchase($invoice);
    $ctx->verify($invoice, $token);
    $fetchesBefore = $ctx->google->fetches;
    $again = $ctx->verify($ctx->invoice($invoice['id']), $token);
    T::same('completed', $again['status']);
    T::same($fetchesBefore, $ctx->google->fetches, 'replay must not re-query Google');
    T::same(1, count($ctx->webhooksFor($invoice['id'], 'payment.completed')));
    T::same(1, count(array_keys($ctx->google->consumed, $token, true)), 'consumed exactly once');
    T::same(1, count($ctx->q('SELECT * FROM hekta_google_play_purchases WHERE invoice_id = ?', [$invoice['id']])));
});

flow_test('a purchase token cannot be reused for another invoice', function (FlowContext $ctx): void {
    $first = $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-a');
    $token = $ctx->playPurchase($first);
    $ctx->verify($first, $token);
    $second = $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-b');
    T::throws(GooglePlayException::class, fn() => $ctx->verify($second, $token), 'TOKEN_REUSED');
    T::same('initiated', $ctx->invoice($second['id'])['status']);
    $otherToken = $ctx->playPurchase($first);
    T::throws(GooglePlayException::class, fn() => $ctx->verify($ctx->invoice($first['id']), $otherToken), 'INVOICE_ALREADY_BOUND');
});

flow_test('forged, mismatched, test, and unknown purchases are rejected without side effects', function (FlowContext $ctx): void {
    $invoice = $ctx->newInvoice('nafdex_shop_monthly', 'prod', 'biz-forged');
    $before = count($ctx->webhooks());

    $wrongInvoice = $ctx->playPurchase($invoice, null, ['obfuscatedExternalAccountId' => 'another-invoice']);
    T::throws(GooglePlayException::class, fn() => $ctx->verify($invoice, $wrongInvoice), 'INVOICE_BINDING_MISMATCH');

    $wrongProduct = $ctx->playPurchase($invoice, 'nafdex_shop_annual');
    T::throws(GooglePlayException::class, fn() => $ctx->verify($invoice, $wrongProduct), 'PRODUCT_MISMATCH');

    $testPurchase = $ctx->playPurchase($invoice, null, ['testPurchaseContext' => ['fopType' => 'TEST']]);
    T::throws(GooglePlayException::class, fn() => $ctx->verify($invoice, $testPurchase), 'TEST_PURCHASE_REJECTED');

    T::throws(GooglePlayException::class, fn() => $ctx->verify($invoice, 'token-that-google-does-not-know'), 'TOKEN_INVALID');
    T::throws(GooglePlayException::class, fn() => $ctx->verify($invoice, $testPurchase, 'nafdex_shop_annual'), 'PRODUCT_MISMATCH');
    T::throws(GooglePlayException::class, fn() => $ctx->verify($invoice, ''), 'INVALID_REQUEST');

    T::same('initiated', $ctx->invoice($invoice['id'])['status']);
    T::same($before, count($ctx->webhooks()));
    T::same([], array_values(array_intersect($ctx->google->consumed, [$wrongInvoice, $wrongProduct, $testPurchase])), 'rejected purchases must never be consumed');
    T::same(0, count($ctx->q('SELECT * FROM hekta_google_play_purchases WHERE invoice_id = ?', [$invoice['id']])));
});

flow_test('test purchases are accepted for test invoices and by explicit override', function (FlowContext $ctx): void {
    $testInvoice = $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-test-ok');
    $token = $ctx->playPurchase($testInvoice, null, ['testPurchaseContext' => ['fopType' => 'TEST']]);
    T::same('completed', $ctx->verify($testInvoice, $token)['status']);
    T::same(1, (int) $ctx->purchaseRow($token)['is_test']);

    $prodInvoice = $ctx->newInvoice('nafdex_shop_monthly', 'prod', 'biz-test-override');
    $prodToken = $ctx->playPurchase($prodInvoice, null, ['testPurchaseContext' => ['fopType' => 'TEST']]);
    putenv('GOOGLE_PLAY_ALLOW_TEST_PURCHASES=true');
    try {
        T::same('completed', $ctx->verify($prodInvoice, $prodToken)['status']);
    } finally {
        putenv('GOOGLE_PLAY_ALLOW_TEST_PURCHASES=false');
    }
});

flow_test('pending purchases grant nothing and can complete later', function (FlowContext $ctx): void {
    $invoice = $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-pending');
    $token = $ctx->playPurchase($invoice, null, ['purchaseStateContext' => ['purchaseState' => 'PENDING']]);
    $result = $ctx->verify($invoice, $token);
    T::same('pending', $result['status']);
    T::same(null, $ctx->purchaseRow($token));
    T::same('initiated', $ctx->invoice($invoice['id'])['status']);
    T::same([], $ctx->webhooksFor($invoice['id'], 'payment.completed'));

    $ctx->google->purchases[$token]['purchaseStateContext']['purchaseState'] = 'PURCHASED';
    T::same('completed', $ctx->verify($ctx->invoice($invoice['id']), $token)['status']);
});

flow_test('cancelled invoices cannot be paid and google invoices are not paid through pesapal', function (FlowContext $ctx): void {
    $invoice = $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-cancel');
    $ctx->orchestrator->cancelInvoice('com.sebuleni.nafdex', $invoice['id']);
    $token = $ctx->playPurchase($invoice);
    T::throws(GooglePlayException::class, fn() => $ctx->verify($ctx->invoice($invoice['id']), $token), 'INVOICE_NOT_PAYABLE');
    $fresh = $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-cancel-2');
    T::throws(RuntimeException::class, fn() => $ctx->orchestrator->initializeExisting('com.sebuleni.nafdex', $fresh['id']));
    T::same('initiated', $ctx->orchestrator->status('com.sebuleni.nafdex', $fresh['id'])['invoice']['status']);
});

flow_test('verification attempts are rate limited per invoice after repeated failures', function (FlowContext $ctx): void {
    $invoice = $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-rate');
    for ($i = 0; $i < 10; $i++) {
        T::throws(GooglePlayException::class, fn() => $ctx->verify($invoice, 'unknown-token-' . $i), 'TOKEN_INVALID');
    }
    $error = T::throws(GooglePlayException::class, fn() => $ctx->verify($invoice, 'unknown-token-x'), 'RATE_LIMITED');
    T::same(429, $error->httpStatus());
});

flow_test('a failing NafDex webhook leaves the purchase unconsumed until delivery succeeds', function (FlowContext $ctx): void {
    $invoice = $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-webhook-down');
    $token = $ctx->playPurchase($invoice);
    $ctx->setWebhookMode('fail');
    $result = $ctx->verify($invoice, $token);
    T::same('completed', $result['status']);
    T::same(false, $result['consumed']);
    T::same(null, $ctx->purchaseRow($token)['consumed_at']);
    T::same([], array_intersect($ctx->google->consumed, [$token]));
    T::same(1, count($ctx->q('SELECT * FROM hekta_webhook_retry_queue WHERE invoice_id = ?', [$invoice['id']])));

    $sweepWhileDown = $ctx->service->sweep(50);
    T::same(0, $sweepWhileDown['redispatched'], 'an attempted delivery must not be duplicated');
    T::same(null, $ctx->purchaseRow($token)['consumed_at']);

    $ctx->setWebhookMode('ok');
    $ctx->db->exec('UPDATE hekta_webhook_retry_queue SET next_attempt_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
    $retry = (new WebhookDispatcher($ctx->db))->processRetries(50);
    T::true($retry['succeeded'] >= 1);
    $sweep = $ctx->service->sweep(50);
    T::true($sweep['consumed'] >= 1);
    T::true($ctx->purchaseRow($token)['consumed_at'] !== null);
    T::same(1, count(array_keys($ctx->google->consumed, $token, true)));
});

flow_test('a failed consume is retried by the sweeper and reconciled when already consumed', function (FlowContext $ctx): void {
    $invoice = $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-consume-fail');
    $token = $ctx->playPurchase($invoice);
    $ctx->google->failConsume = true;
    $result = $ctx->verify($invoice, $token);
    T::same(false, $result['consumed']);
    T::true($ctx->purchaseRow($token)['last_error'] !== null);
    $ctx->google->failConsume = false;
    $ctx->service->sweep(50);
    T::true($ctx->purchaseRow($token)['consumed_at'] !== null);
    T::same(null, $ctx->purchaseRow($token)['last_error']);

    $invoice2 = $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-already-consumed');
    $token2 = $ctx->playPurchase($invoice2);
    $ctx->google->failConsume = true;
    $ctx->verify($invoice2, $token2);
    $ctx->google->purchases[$token2]['productLineItem'][0]['productOfferDetails']['consumptionState'] = 'CONSUMPTION_STATE_CONSUMED';
    $ctx->service->sweep(50);
    $ctx->google->failConsume = false;
    T::true($ctx->purchaseRow($token2)['consumed_at'] !== null, 'a purchase Google reports consumed is marked consumed');
});

flow_test('the sweeper completes a recorded purchase whose invoice completion was interrupted', function (FlowContext $ctx): void {
    $invoice = $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-interrupted');
    $token = $ctx->playPurchase($invoice);
    $vault = new TokenVault('integration-test-secret-key');
    $ctx->db->prepare('INSERT INTO hekta_google_play_purchases (purchase_token_hash, purchase_token_enc, order_id, product_id, invoice_id, app_id, package_name, purchase_state) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([TokenVault::hash($token), $vault->encrypt($token), 'GPA.INTERRUPTED', 'nafdex_shop_monthly', $invoice['id'], $invoice['app_id'], 'com.sebuleni.nafdex', 'PURCHASED']);
    $ctx->service->sweep(50);
    T::same('completed', $ctx->invoice($invoice['id'])['status']);
    T::same(1, count($ctx->webhooksFor($invoice['id'], 'payment.completed')));
    T::true($ctx->purchaseRow($token)['consumed_at'] !== null);
    $retryVerify = $ctx->verify($ctx->invoice($invoice['id']), $token);
    T::same('completed', $retryVerify['status']);
});

flow_test('a void refunds the invoice once and dispatches payment.refunded', function (FlowContext $ctx): void {
    $invoice = $ctx->newInvoice('nafdex_shop_annual', 'test', 'biz-refund');
    $token = $ctx->playPurchase($invoice);
    $ctx->verify($invoice, $token);

    T::same(['handled' => false, 'reason' => 'unknown_token'], $ctx->service->applyVoided('never-seen', 'x'));
    T::same(['handled' => true, 'reason' => 'refunded'], $ctx->service->applyVoided($token, 'rtdn_full'));
    T::same(['handled' => false, 'reason' => 'already_refunded'], $ctx->service->applyVoided($token, 'rtdn_full'));

    $row = $ctx->invoice($invoice['id']);
    T::same('refunded', $row['status']);
    T::true($row['refunded_at'] !== null);
    $hooks = $ctx->webhooksFor($invoice['id'], 'payment.refunded');
    T::same(1, count($hooks));
    T::same('biz-refund', $hooks[0]['body']['tenant_id']);
    T::same(12, $hooks[0]['body']['subscription_term_months']);
    T::true($ctx->purchaseRow($token)['refunded_at'] !== null);
    T::throws(GooglePlayException::class, fn() => $ctx->verify($ctx->invoice($invoice['id']), $token), 'INVOICE_NOT_PAYABLE');
});

flow_test('rtdn requires a valid push token, ignores duplicates, and applies voided purchases', function (FlowContext $ctx): void {
    $clock = time();
    $verifier = new PubSubJwtVerifier('https://pay.test/api/google-play/rtdn', 'push@proj.iam.gserviceaccount.com', fn() => ['kid-1' => $ctx->keys['public']], fn() => $clock);
    $rtdn = new GooglePlayRtdn($ctx->service, new PlayProductCatalog($ctx->db), $verifier);
    $encode = static fn(string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    $jwt = static function (array $claims) use ($ctx, $encode): string {
        $head = $encode(json_encode(['alg' => 'RS256', 'kid' => 'kid-1']));
        $body = $encode(json_encode($claims));
        openssl_sign($head . '.' . $body, $signature, $ctx->keys['resource'], OPENSSL_ALGO_SHA256);
        return 'Bearer ' . $head . '.' . $body . '.' . $encode($signature);
    };
    $claims = ['iss' => 'https://accounts.google.com', 'aud' => 'https://pay.test/api/google-play/rtdn', 'email' => 'push@proj.iam.gserviceaccount.com', 'email_verified' => true, 'exp' => $clock + 3000];
    $envelope = static fn(array $notification, string $messageId): string => json_encode(['message' => ['data' => base64_encode(json_encode($notification)), 'messageId' => $messageId], 'subscription' => 'projects/p/subscriptions/s']);

    $invoice = $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-rtdn');
    $token = $ctx->playPurchase($invoice);
    $ctx->verify($invoice, $token);
    $voided = ['version' => '1.0', 'packageName' => 'com.sebuleni.nafdex', 'eventTimeMillis' => '1', 'voidedPurchaseNotification' => ['purchaseToken' => $token, 'orderId' => 'GPA.X', 'productType' => 2, 'refundType' => 1]];

    T::same(401, $rtdn->handle('', $envelope($voided, 'msg-1'))['status']);
    T::same(401, $rtdn->handle($jwt(['aud' => 'https://evil.test'] + $claims), $envelope($voided, 'msg-1'))['status']);
    T::same('completed', $ctx->invoice($invoice['id'])['status']);
    T::same(400, $rtdn->handle($jwt($claims), 'not json')['status']);

    $first = $rtdn->handle($jwt($claims), $envelope($voided, 'msg-1'));
    T::same(200, $first['status']);
    T::same('refunded', $first['body']['outcome']);
    T::same('refunded', $ctx->invoice($invoice['id'])['status']);

    $duplicate = $rtdn->handle($jwt($claims), $envelope($voided, 'msg-1'));
    T::same(200, $duplicate['status']);
    T::same(true, $duplicate['body']['duplicate'] ?? false);
    T::same(1, count($ctx->webhooksFor($invoice['id'], 'payment.refunded')));

    $unknown = ['voidedPurchaseNotification' => ['purchaseToken' => 'never-seen', 'productType' => 2, 'refundType' => 1]] + $voided;
    T::same('unknown_token', $rtdn->handle($jwt($claims), $envelope($unknown, 'msg-2'))['body']['outcome']);
    $subscription = ['subscriptionNotification' => ['notificationType' => 3]] + array_diff_key($voided, ['voidedPurchaseNotification' => 1]);
    T::same('ignored', $rtdn->handle($jwt($claims), $envelope($subscription, 'msg-3'))['body']['outcome']);
    $foreign = ['packageName' => 'com.other.app'] + $voided;
    T::same('ignored_package', $rtdn->handle($jwt($claims), $envelope($foreign, 'msg-4'))['body']['outcome']);
    $partial = ['voidedPurchaseNotification' => ['purchaseToken' => $token, 'productType' => 2, 'refundType' => 2]] + $voided;
    T::same('ignored_voided_variant', $rtdn->handle($jwt($claims), $envelope($partial, 'msg-5'))['body']['outcome']);
    $test = ['version' => '1.0', 'packageName' => 'com.sebuleni.nafdex', 'testNotification' => ['version' => '1.0']];
    T::same('test_acknowledged', $rtdn->handle($jwt($claims), $envelope($test, 'msg-6'))['body']['outcome']);
    T::same(0, count($ctx->q("SELECT * FROM hekta_google_play_events WHERE payload LIKE ?", ['%' . $token . '%'])), 'purchase tokens must not be stored in event payloads');
});

flow_test('reconciliation applies voids that notifications missed and reports unknown ones', function (FlowContext $ctx): void {
    $invoice = $ctx->newInvoice('nafdex_shop_monthly', 'test', 'biz-reconcile');
    $token = $ctx->playPurchase($invoice);
    $ctx->verify($invoice, $token);
    $ctx->google->voided = [
        ['purchaseToken' => $token, 'orderId' => 'GPA.RECON', 'voidedReason' => 1],
        ['purchaseToken' => 'unverified-token', 'orderId' => 'GPA.UNKNOWN', 'voidedReason' => 1],
    ];
    $summary = $ctx->service->reconcile(1000, 2000);
    T::same(2, $summary['voided']);
    T::same(1, $summary['healed']);
    T::same(['GPA.UNKNOWN'], $summary['unknown_order_ids']);
    T::same('refunded', $ctx->invoice($invoice['id'])['status']);
    T::same(2000, $ctx->service->lastReconcileEndMillis());
    $again = $ctx->service->reconcile(1000, 3000);
    T::same(0, $again['healed']);
    T::same(1, $again['already_refunded']);
    $ctx->google->voided = [];
});
