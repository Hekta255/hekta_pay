<?php

declare(strict_types=1);

namespace HektaPay\GooglePlay;

use PDO;
use PDOException;
use Throwable;

/**
 * Verifies Google Play purchases, binds each purchase token to one invoice, and keeps refunds in sync.
 */
final class GooglePlayService
{
    private const MAX_FAILURES_PER_WINDOW = 10;
    private const FAILURE_WINDOW_MINUTES = 10;
    private const UNFINISHED_ALERT_HOURS = 24;

    private PDO $db;
    private PlayApiClient $api;
    private PlayProductCatalog $catalog;
    private TokenVault $vault;
    private InvoiceLifecycle $lifecycle;

    public function __construct(PDO $db, PlayApiClient $api, PlayProductCatalog $catalog, TokenVault $vault, InvoiceLifecycle $lifecycle)
    {
        $this->db = $db;
        $this->api = $api;
        $this->catalog = $catalog;
        $this->vault = $vault;
        $this->lifecycle = $lifecycle;
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array{success:bool, status:string, consumed:bool}
     */
    public function verifyPurchase(array $invoice, string $purchaseToken, string $productId): array
    {
        $purchaseToken = trim($purchaseToken);
        $productId = trim($productId);
        if ($purchaseToken === '' || strlen($purchaseToken) > 4096 || $productId === '') {
            throw new GooglePlayException('INVALID_REQUEST', 'purchase_token and product_id are required.', 400);
        }
        if (($invoice['gateway'] ?? '') !== 'google_play') {
            throw new GooglePlayException('GATEWAY_MISMATCH', 'This invoice is not a Google Play invoice.', 409);
        }
        $status = (string) $invoice['status'];
        if (!in_array($status, ['initiated', 'completed'], true)) {
            throw new GooglePlayException('INVOICE_NOT_PAYABLE', 'This invoice can no longer be paid.', 409);
        }

        $metadata = self::decodeMetadata($invoice['metadata'] ?? null);
        $expectedProduct = (string) ($metadata['product_id'] ?? '');
        if ($expectedProduct === '' || !hash_equals($expectedProduct, $productId)) {
            throw new GooglePlayException('PRODUCT_MISMATCH', 'The product does not match the invoice.', 422);
        }
        $product = $this->catalog->find((string) $invoice['app_id'], $expectedProduct);
        if ($product === null) {
            throw new GooglePlayException('PRODUCT_UNKNOWN', 'The product is not available.', 422);
        }
        $this->assertNotRateLimited((string) $invoice['id']);

        $hash = TokenVault::hash($purchaseToken);
        $existing = $this->findPurchaseByHash($hash);
        if ($existing !== null && $existing['invoice_id'] !== $invoice['id']) {
            $this->recordFailure((string) $invoice['id'], 'TOKEN_REUSED');
            throw new GooglePlayException('TOKEN_REUSED', 'This purchase is already linked to another invoice.', 409);
        }
        if ($existing === null && $this->findPurchaseByInvoice((string) $invoice['id']) !== null) {
            $this->recordFailure((string) $invoice['id'], 'INVOICE_ALREADY_BOUND');
            throw new GooglePlayException('INVOICE_ALREADY_BOUND', 'This invoice is already linked to a different purchase.', 409);
        }
        if ($existing === null && $status === 'completed') {
            throw new GooglePlayException('INVOICE_NOT_PAYABLE', 'This invoice can no longer be paid.', 409);
        }

        if ($existing === null) {
            $purchase = $this->fetchPurchase((string) $product['package_name'], $purchaseToken, (string) $invoice['id']);
            $evaluation = PlayPurchaseVerifier::evaluate($purchase, [
                'product_id' => $expectedProduct,
                'invoice_id' => (string) $invoice['id'],
                'allow_test' => $this->allowsTestPurchases($invoice),
            ]);
            if ($evaluation['result'] === PlayPurchaseVerifier::PENDING) {
                return ['success' => true, 'status' => 'pending', 'consumed' => false];
            }
            if ($evaluation['result'] !== PlayPurchaseVerifier::OK) {
                $this->recordFailure((string) $invoice['id'], $evaluation['code']);
                throw new GooglePlayException($evaluation['code'], $evaluation['message'], 422);
            }
            $existing = $this->insertPurchase($invoice, $product, $purchaseToken, $hash, $purchase, $evaluation);
        }

        if ($status !== 'completed') {
            $this->lifecycle->completeExternalInvoice($invoice, [
                'status' => 'completed',
                'transaction_id' => (string) $existing['order_id'],
                'payment_method' => 'google_play',
                'raw' => [
                    'order_id' => $existing['order_id'],
                    'product_id' => $existing['product_id'],
                    'package_name' => $existing['package_name'],
                    'is_test' => (bool) $existing['is_test'],
                    'region_code' => $existing['region_code'],
                ],
            ]);
        }

        $fresh = $this->lifecycle->findInvoice((string) $invoice['app_id'], (string) $invoice['id']) ?? $invoice;
        $consumed = false;
        if (($fresh['status'] ?? '') === 'completed') {
            $consumed = $this->finalize($existing, $fresh);
        }
        return ['success' => true, 'status' => (string) ($fresh['status'] ?? $status), 'consumed' => $consumed];
    }

    /**
     * Consumes a verified purchase once NafDex has confirmed the entitlement.
     *
     * @param array<string,mixed> $purchase
     * @param array<string,mixed> $invoice
     */
    public function finalize(array $purchase, array $invoice): bool
    {
        if ($purchase['refunded_at'] !== null) {
            return false;
        }
        if ($purchase['consumed_at'] !== null) {
            return true;
        }
        if (!$this->webhookDelivered((string) $invoice['id'], 'payment.completed')) {
            return false;
        }
        if ($purchase['webhook_confirmed_at'] === null) {
            $this->db->prepare('UPDATE hekta_google_play_purchases SET webhook_confirmed_at = NOW() WHERE id = ? AND webhook_confirmed_at IS NULL')->execute([$purchase['id']]);
        }
        return $this->consume($purchase);
    }

    /**
     * Applies a Google void (refund or chargeback) to a known purchase.
     *
     * @return array{handled:bool, reason:string}
     */
    public function applyVoided(string $purchaseToken, ?string $refundType): array
    {
        $row = $this->findPurchaseByHash(TokenVault::hash($purchaseToken));
        if ($row === null) {
            return ['handled' => false, 'reason' => 'unknown_token'];
        }
        if ($row['refunded_at'] !== null) {
            return ['handled' => false, 'reason' => 'already_refunded'];
        }
        $invoice = $this->lifecycle->findInvoice((string) $row['app_id'], (string) $row['invoice_id']);
        if ($invoice !== null && $invoice['status'] === 'completed') {
            $this->lifecycle->refundExternalInvoice($invoice);
        }
        $this->db->prepare('UPDATE hekta_google_play_purchases SET refunded_at = NOW(), refund_type = ? WHERE id = ? AND refunded_at IS NULL')->execute([$refundType !== null ? substr($refundType, 0, 30) : null, $row['id']]);
        return ['handled' => true, 'reason' => 'refunded'];
    }

    /**
     * Finishes purchases that were verified but never consumed.
     *
     * @return array{checked:int, completed:int, redispatched:int, consumed:int, alerts:int}
     */
    public function sweep(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->query('SELECT *, TIMESTAMPDIFF(HOUR, created_at, NOW()) AS age_hours FROM hekta_google_play_purchases WHERE consumed_at IS NULL AND refunded_at IS NULL ORDER BY created_at ASC LIMIT ' . $limit);
        $rows = $stmt ? $stmt->fetchAll() : [];
        $stats = ['checked' => 0, 'completed' => 0, 'redispatched' => 0, 'consumed' => 0, 'alerts' => 0];
        foreach ($rows as $row) {
            $stats['checked']++;
            try {
                $invoice = $this->lifecycle->findInvoice((string) $row['app_id'], (string) $row['invoice_id']);
                if ($invoice === null) {
                    continue;
                }
                if ($invoice['status'] === 'initiated') {
                    $this->lifecycle->completeExternalInvoice($invoice, [
                        'status' => 'completed',
                        'transaction_id' => (string) $row['order_id'],
                        'payment_method' => 'google_play',
                        'raw' => ['order_id' => $row['order_id'], 'product_id' => $row['product_id'], 'swept' => true],
                    ]);
                    $stats['completed']++;
                    $invoice = $this->lifecycle->findInvoice((string) $row['app_id'], (string) $row['invoice_id']) ?? $invoice;
                }
                if ($invoice['status'] === 'completed') {
                    if (!$this->webhookLogged((string) $invoice['id'], 'payment.completed')) {
                        $this->lifecycle->dispatchInvoiceEvent($invoice, 'payment.completed');
                        $stats['redispatched']++;
                    }
                    if ($this->finalize($row, $invoice)) {
                        $stats['consumed']++;
                        continue;
                    }
                }
                if ((int) $row['age_hours'] >= self::UNFINISHED_ALERT_HOURS) {
                    $stats['alerts']++;
                    self::alert('Google Play purchase unfinished', 'Invoice ' . $row['invoice_id'] . ' (order ' . $row['order_id'] . ') has not been consumed after ' . $row['age_hours'] . ' hours. Google refunds unacknowledged purchases after three days.');
                }
            } catch (Throwable $error) {
                $this->db->prepare('UPDATE hekta_google_play_purchases SET last_error = ? WHERE id = ?')->execute([substr($error->getMessage(), 0, 500), $row['id']]);
                self::log('sweep_error', ['invoice_id' => $row['invoice_id'], 'error' => substr($error->getMessage(), 0, 200)]);
            }
        }
        return $stats;
    }

    /**
     * Compares Google's voided purchases with Hekta Pay records and applies any refund that was missed.
     *
     * @return array{window_start_ms:int, window_end_ms:int, voided:int, healed:int, already_refunded:int, unknown_order_ids:string[]}
     */
    public function reconcile(int $startMillis, int $endMillis): array
    {
        $summary = ['window_start_ms' => $startMillis, 'window_end_ms' => $endMillis, 'voided' => 0, 'healed' => 0, 'already_refunded' => 0, 'unknown_order_ids' => []];
        foreach ($this->catalog->packageNames() as $package) {
            $pageToken = null;
            do {
                $page = $this->api->listVoidedPurchases($package, $startMillis, $endMillis, $pageToken);
                foreach (($page['voidedPurchases'] ?? []) as $voided) {
                    if (!is_array($voided) || empty($voided['purchaseToken'])) {
                        continue;
                    }
                    $summary['voided']++;
                    $result = $this->applyVoided((string) $voided['purchaseToken'], 'voided_' . (string) ($voided['voidedReason'] ?? 'unknown'));
                    if ($result['reason'] === 'refunded') {
                        $summary['healed']++;
                    } elseif ($result['reason'] === 'already_refunded') {
                        $summary['already_refunded']++;
                    } else {
                        $summary['unknown_order_ids'][] = (string) ($voided['orderId'] ?? 'unknown');
                    }
                }
                $pageToken = isset($page['tokenPagination']['nextPageToken']) ? (string) $page['tokenPagination']['nextPageToken'] : null;
            } while ($pageToken !== null && $pageToken !== '');
        }
        $this->recordEvent('reconcile-' . bin2hex(random_bytes(8)), 'reconcile_run', null, null, null, $summary, true);
        if ($summary['healed'] > 0 || $summary['unknown_order_ids'] !== []) {
            self::alert('Google Play reconciliation found differences', json_encode($summary, JSON_UNESCAPED_SLASHES) ?: 'See reconcile log.');
        }
        return $summary;
    }

    public function lastReconcileEndMillis(): ?int
    {
        $stmt = $this->db->query("SELECT payload FROM hekta_google_play_events WHERE notification_type = 'reconcile_run' ORDER BY id DESC LIMIT 1");
        $payload = $stmt ? $stmt->fetchColumn() : false;
        if (!is_string($payload)) {
            return null;
        }
        $decoded = json_decode($payload, true);
        return is_array($decoded) && isset($decoded['window_end_ms']) ? (int) $decoded['window_end_ms'] : null;
    }

    /** @return array<string,mixed>|null */
    public function findPurchaseByHash(string $hash): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM hekta_google_play_purchases WHERE purchase_token_hash = ? LIMIT 1');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findPurchaseByInvoice(string $invoiceId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM hekta_google_play_purchases WHERE invoice_id = ? LIMIT 1');
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findPurchaseByOrder(string $orderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM hekta_google_play_purchases WHERE order_id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed> Live Google state for support tooling. */
    public function liveState(array $purchaseRow): array
    {
        return $this->api->getProductPurchase((string) $purchaseRow['package_name'], $this->vault->decrypt((string) $purchaseRow['purchase_token_enc']));
    }

    /** @param array<string,mixed> $payload */
    public function recordEvent(string $messageId, string $type, ?string $package, ?string $tokenHash, ?string $invoiceId, array $payload, bool $processed = false): bool
    {
        try {
            $this->db->prepare('INSERT INTO hekta_google_play_events (message_id, notification_type, package_name, token_hash, invoice_id, payload, processed_at) VALUES (?, ?, ?, ?, ?, ?, ' . ($processed ? 'NOW()' : 'NULL') . ')')
                ->execute([$messageId, $type, $package, $tokenHash, $invoiceId, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)]);
            return true;
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $error;
        }
    }

    public function markEventProcessed(string $messageId): void
    {
        $this->db->prepare('UPDATE hekta_google_play_events SET processed_at = NOW() WHERE message_id = ?')->execute([$messageId]);
    }

    public function forgetEvent(string $messageId): void
    {
        $this->db->prepare('DELETE FROM hekta_google_play_events WHERE message_id = ?')->execute([$messageId]);
    }

    public static function log(string $phase, array $context = []): void
    {
        error_log('[hekta-pay][google-play] ' . json_encode(['phase' => $phase] + $context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    public static function alert(string $subject, string $body): void
    {
        self::log('alert', ['subject' => $subject, 'body' => substr($body, 0, 500)]);
        $to = (string) (getenv('GOOGLE_PLAY_ALERT_EMAIL') ?: '');
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            @mail($to, '[Hekta Pay] ' . $subject, $body);
        }
    }

    /** @param array<string,mixed> $invoice */
    private function allowsTestPurchases(array $invoice): bool
    {
        if (($invoice['environment'] ?? '') === 'test') {
            return true;
        }
        return filter_var(getenv('GOOGLE_PLAY_ALLOW_TEST_PURCHASES') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string,mixed> */
    private function fetchPurchase(string $package, string $token, string $invoiceId): array
    {
        try {
            return $this->api->getProductPurchase($package, $token);
        } catch (PlayApiException $error) {
            if ($error->isNotFound() || $error->httpStatus() === 400) {
                $this->recordFailure($invoiceId, 'TOKEN_INVALID');
                throw new GooglePlayException('TOKEN_INVALID', 'Google Play does not recognise this purchase.', 422);
            }
            self::log('google_unavailable', ['invoice_id' => $invoiceId, 'status' => $error->httpStatus(), 'error' => $error->getMessage()]);
            throw new GooglePlayException('GOOGLE_UNAVAILABLE', 'Google Play could not be reached. Try again shortly.', 503);
        }
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $product
     * @param array<string,mixed> $purchase
     * @param array<string,mixed> $evaluation
     * @return array<string,mixed>
     */
    private function insertPurchase(array $invoice, array $product, string $token, string $hash, array $purchase, array $evaluation): array
    {
        try {
            $this->db->prepare('INSERT INTO hekta_google_play_purchases (purchase_token_hash, purchase_token_enc, order_id, product_id, invoice_id, app_id, package_name, purchase_state, is_test, region_code, raw_snapshot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $hash,
                $this->vault->encrypt($token),
                $evaluation['order_id'],
                $product['product_id'],
                $invoice['id'],
                $invoice['app_id'],
                $product['package_name'],
                'PURCHASED',
                $evaluation['is_test'] ? 1 : 0,
                $evaluation['region_code'],
                json_encode($purchase, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            ]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
                throw $error;
            }
            $row = $this->findPurchaseByHash($hash);
            if ($row === null || $row['invoice_id'] !== $invoice['id']) {
                throw new GooglePlayException('TOKEN_REUSED', 'This purchase is already linked to another invoice.', 409);
            }
            return $row;
        }
        $row = $this->findPurchaseByHash($hash);
        if ($row === null) {
            throw new GooglePlayException('PURCHASE_NOT_RECORDED', 'The purchase could not be recorded.', 500);
        }
        return $row;
    }

    /** @param array<string,mixed> $purchase */
    private function consume(array $purchase): bool
    {
        try {
            $token = $this->vault->decrypt((string) $purchase['purchase_token_enc']);
            try {
                $this->api->consume((string) $purchase['package_name'], (string) $purchase['product_id'], $token);
            } catch (PlayApiException $error) {
                // An already consumed purchase makes consume fail; confirm the real state before reporting an error.
                $state = $this->api->getProductPurchase((string) $purchase['package_name'], $token);
                $isConsumed = false;
                foreach (($state['productLineItem'] ?? []) as $item) {
                    if (($item['productOfferDetails']['consumptionState'] ?? '') === 'CONSUMPTION_STATE_CONSUMED') {
                        $isConsumed = true;
                    }
                }
                if (!$isConsumed) {
                    throw $error;
                }
            }
            $this->db->prepare('UPDATE hekta_google_play_purchases SET consumed_at = NOW(), last_error = NULL WHERE id = ? AND consumed_at IS NULL')->execute([$purchase['id']]);
            self::log('consume', ['invoice_id' => $purchase['invoice_id'], 'order_id' => $purchase['order_id'], 'token' => substr((string) $purchase['purchase_token_hash'], 0, 8)]);
            return true;
        } catch (Throwable $error) {
            $this->db->prepare('UPDATE hekta_google_play_purchases SET last_error = ? WHERE id = ?')->execute([substr($error->getMessage(), 0, 500), $purchase['id']]);
            self::log('consume_failed', ['invoice_id' => $purchase['invoice_id'], 'error' => substr($error->getMessage(), 0, 200)]);
            return false;
        }
    }

    private function webhookDelivered(string $invoiceId, string $event): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM hekta_webhook_logs WHERE invoice_id = ? AND success = 1 AND JSON_UNQUOTE(JSON_EXTRACT(request_payload, '$.event')) = ?");
        $stmt->execute([$invoiceId, $event]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function webhookLogged(string $invoiceId, string $event): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM hekta_webhook_logs WHERE invoice_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(request_payload, '$.event')) = ?");
        $stmt->execute([$invoiceId, $event]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function assertNotRateLimited(string $invoiceId): void
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM hekta_google_play_events WHERE invoice_id = ? AND notification_type = 'verify_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL " . self::FAILURE_WINDOW_MINUTES . ' MINUTE)');
        $stmt->execute([$invoiceId]);
        if ((int) $stmt->fetchColumn() >= self::MAX_FAILURES_PER_WINDOW) {
            throw new GooglePlayException('RATE_LIMITED', 'Too many failed verification attempts. Try again later.', 429);
        }
    }

    private function recordFailure(string $invoiceId, string $code): void
    {
        self::log('verify_failed', ['invoice_id' => $invoiceId, 'code' => $code]);
        $this->recordEvent('verify-fail-' . bin2hex(random_bytes(12)), 'verify_failed', null, null, $invoiceId, ['code' => $code], true);
    }

    /** @return array<string,mixed> */
    private static function decodeMetadata($metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        $decoded = is_string($metadata) ? json_decode($metadata, true) : null;
        return is_array($decoded) ? $decoded : [];
    }
}
