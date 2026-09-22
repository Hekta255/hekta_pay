<?php

declare(strict_types=1);

namespace HektaPay\Payment;

use HektaPay\Drivers\PesapalDriver;
use HektaPay\Services\WebhookDispatcher;
use PDO;
use RuntimeException;

final class PaymentOrchestrator
{
    public function __construct(private PDO $db, private WebhookDispatcher $webhooks) {}

    public function initialize(string $appId, array $data): array
    {
        $tenantId = trim((string) ($data['tenant_id'] ?? ''));
        $amount = (float) ($data['amount'] ?? 0);
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'USD')));
        $gateway = strtolower(trim((string) ($data['gateway'] ?? 'pesapal')));
        if ($tenantId === '' || $amount <= 0 || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('tenant_id, a positive amount, and a valid currency are required.');
        }
        if ($gateway !== 'pesapal') {
            throw new RuntimeException('Unsupported gateway: ' . $gateway);
        }

        $invoiceId = self::uuid();
        $metadata = isset($data['metadata']) ? json_encode($data['metadata'], JSON_THROW_ON_ERROR) : null;
        $stmt = $this->db->prepare('INSERT INTO hekta_invoices (id, app_id, tenant_id, amount, currency, status, gateway, metadata, invoice_type, billing_period, subscription_term_months, due_date) VALUES (?, ?, ?, ?, ?, "pending", ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$invoiceId, $appId, $tenantId, $amount, $currency, $gateway, $metadata, $data['invoice_type'] ?? 'subscription', $data['billing_period'] ?? null, (int) ($data['subscription_term_months'] ?? 1), $data['due_date'] ?? null]);

        $driver = $this->driver($gateway, (string) ($data['environment'] ?? getenv('APP_ENV') ?: 'testing'));
        try {
            $environment = (string) ($data['environment'] ?? getenv('APP_ENV') ?: 'testing');
            $prefix = $this->isProductionEnvironment($environment) ? 'PROD' : 'TEST';
            $order = $driver->createOrder([
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'currency' => $currency,
                'description' => (string) ($data['description'] ?? 'Hekta payment'),
                'callback_url' => $data['callback_url'] ?? getenv('PESAPAL_CALLBACK_URL_' . $prefix) ?: getenv('PESAPAL_CALLBACK_URL') ?: 'https://pay.sebuleni.com/payment-callback',
                'customer_email' => $data['customer']['email'] ?? null,
                'billing_address' => $data['billing_address'] ?? null,
            ]);
            $this->db->prepare('UPDATE hekta_invoices SET status = "initiated", gateway_order_id = ?, payment_url = ? WHERE id = ?')->execute([$order['gateway_order_id'], $order['payment_url'], $invoiceId]);
        } catch (\Throwable $error) {
            $this->db->prepare('UPDATE hekta_invoices SET status = "failed" WHERE id = ?')->execute([$invoiceId]);
            throw $error;
        }

        return ['success' => true, 'invoice' => $this->findInvoice($appId, $invoiceId), 'payment' => ['url' => $order['payment_url'], 'order_tracking_id' => $order['gateway_order_id']]];
    }

    public function status(string $appId, string $invoiceId): array
    {
        $invoice = $this->findInvoice($appId, $invoiceId);
        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }
        if ($invoice['status'] === 'initiated' && !empty($invoice['gateway_order_id'])) {
            $driver = $this->driver($invoice['gateway'], getenv('APP_ENV') ?: 'testing');
            $gatewayStatus = $driver->checkStatus($invoice['gateway_order_id']);
            $mapped = $driver->mapStatus($gatewayStatus['status']);
            if ($mapped !== 'pending') {
                $this->completeStatus($invoice, $mapped, $gatewayStatus);
                $invoice = $this->findInvoice($appId, $invoiceId);
            }
        }
        return ['success' => true, 'invoice' => $invoice];
    }

    public function listInvoices(string $appId, string $tenantId, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $countSql = 'SELECT COUNT(*) FROM hekta_invoices WHERE app_id = ? AND tenant_id = ?';
        $countParams = [$appId, $tenantId];
        if ($status !== null && $status !== '') {
            $countSql .= ' AND status = ?';
            $countParams[] = $status;
        }
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT * FROM hekta_invoices WHERE app_id = ? AND tenant_id = ?';
        $params = [$appId, $tenantId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return [
            'success' => true,
            'invoices' => $stmt->fetchAll(),
            'total' => $total,
            'has_more' => $offset + $limit < $total,
        ];
    }

    public function processIpn(string $gateway, array $data): array
    {
        $orderId = trim((string) ($data['OrderTrackingId'] ?? $data['order_tracking_id'] ?? ''));
        if ($orderId === '') {
            throw new RuntimeException('OrderTrackingId is required.');
        }
        $stmt = $this->db->prepare('SELECT * FROM hekta_invoices WHERE gateway = ? AND gateway_order_id = ? LIMIT 1');
        $stmt->execute([$gateway, $orderId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            throw new RuntimeException('Invoice not found for gateway order.');
        }
        $driver = $this->driver($gateway, getenv('APP_ENV') ?: 'testing');
        $gatewayStatus = $driver->checkStatus($orderId);
        $status = $driver->mapStatus($gatewayStatus['status']);
        $this->completeStatus($invoice, $status, $gatewayStatus);
        return ['success' => true, 'status' => $status];
    }

    public function listTransactions(string $appId, string $tenantId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM hekta_transactions WHERE app_id = ? AND tenant_id = ?');
        $countStmt->execute([$appId, $tenantId]);
        $total = (int) $countStmt->fetchColumn();
        $stmt = $this->db->prepare('SELECT * FROM hekta_transactions WHERE app_id = ? AND tenant_id = ? ORDER BY created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
        $stmt->execute([$appId, $tenantId]);
        return [
            'success' => true,
            'transactions' => $stmt->fetchAll(),
            'total' => $total,
            'has_more' => $offset + $limit < $total,
        ];
    }

    public function initializeExisting(string $appId, string $invoiceId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM hekta_invoices WHERE app_id = ? AND id = ? LIMIT 1');
        $stmt->execute([$appId, $invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }
        if (!in_array($invoice['status'], ['pending', 'initiated'], true)) {
            throw new RuntimeException('Only pending or initiated invoices can be paid.');
        }
        $driver = $this->driver($invoice['gateway'], getenv('APP_ENV') ?: 'testing');
        $order = $driver->createOrder([
            'invoice_id' => $invoice['id'],
            'amount' => (float) $invoice['amount'],
            'currency' => $invoice['currency'],
            'description' => 'Invoice payment',
            'callback_url' => getenv('PESAPAL_CALLBACK_URL') ?: 'https://pay.sebuleni.com/payment-callback',
            'customer_email' => null,
            'billing_address' => null,
        ]);
        $this->db->prepare('UPDATE hekta_invoices SET status = "initiated", gateway_order_id = ?, payment_url = ? WHERE id = ?')->execute([$order['gateway_order_id'], $order['payment_url'], $invoice['id']]);
        return [
            'success' => true,
            'invoice' => $this->findInvoice($appId, $invoice['id']),
            'payment' => ['url' => $order['payment_url'], 'order_tracking_id' => $order['gateway_order_id']],
        ];
    }

    public function cancelInvoice(string $appId, string $invoiceId): array
    {
        $stmt = $this->db->prepare('UPDATE hekta_invoices SET status = "cancelled" WHERE app_id = ? AND id = ? AND status IN ("pending", "initiated")');
        $stmt->execute([$appId, $invoiceId]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Invoice not found or cannot be cancelled.');
        }
        return ['success' => true, 'invoice' => $this->findInvoice($appId, $invoiceId)];
    }

    private function completeStatus(array $invoice, string $status, array $gatewayStatus): void
    {
        if ($invoice['status'] === $status || in_array($invoice['status'], ['completed', 'failed', 'cancelled'], true)) {
            return;
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE hekta_invoices SET status = ?, payment_method = ?, completed_at = CASE WHEN ? = "completed" THEN NOW() ELSE completed_at END WHERE id = ? AND status NOT IN ("completed", "failed", "cancelled")')->execute([$status, $gatewayStatus['payment_method'] ?? null, $status, $invoice['id']]);
            if ($status === 'completed') {
                $this->db->prepare('INSERT IGNORE INTO hekta_transactions (id, invoice_id, app_id, tenant_id, amount, currency, gateway, gateway_transaction_id, payment_method, metadata, transaction_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([self::uuid(), $invoice['id'], $invoice['app_id'], $invoice['tenant_id'], $invoice['amount'], $invoice['currency'], $invoice['gateway'], $gatewayStatus['transaction_id'] ?? $invoice['gateway_order_id'], $gatewayStatus['payment_method'] ?? 'unknown', $invoice['metadata'], json_encode($gatewayStatus['raw'] ?? $gatewayStatus)]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        if ($status === 'completed' || $status === 'failed' || $status === 'cancelled') {
            $credential = $this->credential($invoice['app_id']);
            if ($credential && !empty($credential['webhook_url']) && !empty($credential['webhook_secret'])) {
                $this->webhooks->dispatch($this->findInvoice($invoice['app_id'], $invoice['id']), $credential, 'payment.' . $status);
            }
        }
    }

    private function driver(string $gateway, string $environment): PaymentDriverInterface
    {
        if ($gateway !== 'pesapal') {
            throw new RuntimeException('Unsupported gateway: ' . $gateway);
        }
        $prefix = $this->isProductionEnvironment($environment) ? 'PROD' : 'TEST';
        $consumerKey = getenv('PESAPAL_CONSUMER_KEY_' . $prefix) ?: getenv('PESAPAL_CONSUMER_KEY') ?: '';
        $consumerSecret = getenv('PESAPAL_CONSUMER_SECRET_' . $prefix) ?: getenv('PESAPAL_CONSUMER_SECRET') ?: '';
        $baseUrl = $this->normalizePesapalBaseUrl(getenv('PESAPAL_BASE_URL_' . $prefix) ?: ($prefix === 'PROD' ? 'https://pay.pesapal.com/v3' : 'https://cybqa.pesapal.com/pesapalv3/api/'));
        $ipnId = getenv('PESAPAL_IPN_ID_' . $prefix) ?: '';

        return new PesapalDriver($baseUrl, $consumerKey, $consumerSecret, $ipnId);
    }

    private function isProductionEnvironment(string $environment): bool
    {
        return in_array(strtolower(trim($environment)), ['production', 'prod', 'live'], true);
    }

    private function normalizePesapalBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/') . '/';
        if (!preg_match('#/api/$#i', $baseUrl)) {
            $baseUrl .= 'api/';
        }
        return $baseUrl;
    }

    public function credential(string $appId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM hekta_app_credentials WHERE app_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$appId]);
        $credential = $stmt->fetch();
        return $credential ?: null;
    }

    public function callbackDetails(?string $orderTrackingId, ?string $merchantReference): ?array
    {
        $orderTrackingId = trim((string) $orderTrackingId);
        $merchantReference = trim((string) $merchantReference);
        if ($orderTrackingId === '' && $merchantReference === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT i.*, c.app_name FROM hekta_invoices i '
            . 'JOIN hekta_app_credentials c ON c.app_id = i.app_id '
            . 'WHERE (i.gateway_order_id = ? OR i.id = ?) LIMIT 1'
        );
        $stmt->execute([$orderTrackingId, $merchantReference]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            return null;
        }

        $metadata = is_string($invoice['metadata'])
            ? (json_decode($invoice['metadata'], true) ?: [])
            : (is_array($invoice['metadata']) ? $invoice['metadata'] : []);
        return [
            'invoice' => $invoice,
            'app_name' => (string) $invoice['app_name'],
            'return_url' => is_string($metadata['app_return_url'] ?? null)
                ? $metadata['app_return_url']
                : null,
        ];
    }

    private function findInvoice(string $appId, string $invoiceId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM hekta_invoices WHERE app_id = ? AND id = ? LIMIT 1');
        $stmt->execute([$appId, $invoiceId]);
        $invoice = $stmt->fetch();
        return $invoice ?: null;
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
