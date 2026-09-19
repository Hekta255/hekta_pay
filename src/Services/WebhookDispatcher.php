<?php

declare(strict_types=1);

namespace HektaPay\Services;

use PDO;
use Throwable;

final class WebhookDispatcher
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function dispatch(array $invoice, array $credential, string $event): array
    {
        $eventId = self::uuid();
        $payload = [
            'event_id' => $eventId,
            'event' => $event,
            'invoice_id' => $invoice['id'],
            'order_tracking_id' => $invoice['gateway_order_id'],
            'tenant_id' => $invoice['tenant_id'],
            'amount' => $invoice['amount'],
            'currency' => $invoice['currency'],
            'gateway' => $invoice['gateway'],
            'payment_method' => $invoice['payment_method'],
            'metadata' => $invoice['metadata'] ? json_decode($invoice['metadata'], true) : null,
            'timestamp' => gmdate('c'),
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = gmdate('c');
        $headers = [
            'Content-Type: application/json',
            'X-Hekta-Event-Id: ' . $eventId,
            'X-Hekta-Timestamp: ' . $timestamp,
            'X-Hekta-Signature: ' . WebhookSigner::sign($timestamp, $body, (string) $credential['webhook_secret']),
        ];

        try {
            $result = $this->send((string) $credential['webhook_url'], $body, $headers);
            $success = $result['code'] >= 200 && $result['code'] < 300;
            $this->log($eventId, $invoice['id'], $credential['app_id'], $credential['webhook_url'], $body, $headers, $result['code'], $result['body'], $success, $success ? null : 'Webhook returned HTTP ' . $result['code']);
            if (!$success) {
                $this->queue($eventId, $invoice['id'], $credential['app_id'], 'Webhook returned HTTP ' . $result['code']);
            }
            return ['success' => $success, 'event_id' => $eventId, 'status_code' => $result['code'], 'queued' => !$success];
        } catch (Throwable $e) {
            $this->log($eventId, $invoice['id'], $credential['app_id'], $credential['webhook_url'], $body, $headers, null, null, false, $e->getMessage());
            $this->queue($eventId, $invoice['id'], $credential['app_id'], $e->getMessage());
            return ['success' => false, 'event_id' => $eventId, 'queued' => true];
        }
    }

    public function processRetries(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->query('SELECT q.*, i.gateway_order_id, i.tenant_id, i.amount, i.currency, i.gateway, i.payment_method, i.metadata, c.webhook_url, c.webhook_secret FROM hekta_webhook_retry_queue q JOIN hekta_invoices i ON i.id = q.invoice_id JOIN hekta_app_credentials c ON c.app_id = q.app_id WHERE q.next_attempt_at <= NOW() AND q.attempt_count <= q.max_attempts ORDER BY q.next_attempt_at ASC LIMIT ' . $limit);
        $processed = 0;
        $succeeded = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $processed++;
            $logStmt = $this->db->prepare('SELECT request_payload FROM hekta_webhook_logs WHERE event_id = ? LIMIT 1');
            $logStmt->execute([$row['event_id']]);
            $payload = json_decode((string) $logStmt->fetchColumn(), true);
            if (!is_array($payload)) {
                $this->failRetry($row, 'Original webhook payload is unavailable.');
                continue;
            }
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $timestamp = gmdate('c');
            $headers = [
                'Content-Type: application/json',
                'X-Hekta-Event-Id: ' . $row['event_id'],
                'X-Hekta-Timestamp: ' . $timestamp,
                'X-Hekta-Signature: ' . WebhookSigner::sign($timestamp, $body, (string) $row['webhook_secret']),
            ];
            try {
                $result = $this->send((string) $row['webhook_url'], $body, $headers);
                $success = $result['code'] >= 200 && $result['code'] < 300;
                $this->log($row['event_id'], $row['invoice_id'], $row['app_id'], $row['webhook_url'], $body, $headers, $result['code'], $result['body'], $success, $success ? null : 'Webhook returned HTTP ' . $result['code']);
                if ($success) {
                    $this->db->prepare('DELETE FROM hekta_webhook_retry_queue WHERE event_id = ?')->execute([$row['event_id']]);
                    $succeeded++;
                } else {
                    $this->failRetry($row, 'Webhook returned HTTP ' . $result['code']);
                }
            } catch (Throwable $error) {
                $this->log($row['event_id'], $row['invoice_id'], $row['app_id'], $row['webhook_url'], $body, $headers, null, null, false, $error->getMessage());
                $this->failRetry($row, $error->getMessage());
            }
        }
        return ['processed' => $processed, 'succeeded' => $succeeded];
    }

    private function send(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false || $error !== '') {
            throw new \RuntimeException($error ?: 'Webhook request failed.');
        }
        return ['code' => $code, 'body' => $response];
    }

    private function log(string $eventId, string $invoiceId, string $appId, string $url, string $body, array $headers, ?int $code, ?string $response, bool $success, ?string $error): void
    {
        $this->db->prepare('INSERT INTO hekta_webhook_logs (event_id, invoice_id, app_id, endpoint, request_payload, request_headers, response_code, response_body, success, error_message, last_attempt_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE attempt_number = attempt_number + 1, request_headers = VALUES(request_headers), response_code = VALUES(response_code), response_body = VALUES(response_body), success = VALUES(success), error_message = VALUES(error_message), last_attempt_at = NOW()')->execute([$eventId, $invoiceId, $appId, $url, $body, json_encode($headers), $code, $response, $success ? 1 : 0, $error]);
    }

    private function queue(string $eventId, string $invoiceId, string $appId, string $error): void
    {
        $this->db->prepare('INSERT INTO hekta_webhook_retry_queue (event_id, invoice_id, app_id, next_attempt_at, last_error) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 MINUTE), ?) ON DUPLICATE KEY UPDATE last_error = VALUES(last_error)')->execute([$eventId, $invoiceId, $appId, $error]);
    }

    private function failRetry(array $row, string $error): void
    {
        $attempt = (int) $row['attempt_count'];
        $delayMinutes = min(1440, 2 ** min(10, $attempt));
        $this->db->prepare('UPDATE hekta_webhook_retry_queue SET attempt_count = attempt_count + 1, next_attempt_at = DATE_ADD(NOW(), INTERVAL ' . $delayMinutes . ' MINUTE), last_error = ? WHERE event_id = ?')->execute([$error, $row['event_id']]);
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
