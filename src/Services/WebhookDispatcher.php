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
            $this->log($eventId, $invoice['id'], $credential['app_id'], $credential['webhook_url'], $body, $headers, $result['code'], $result['body'], $result['code'] >= 200 && $result['code'] < 300, null);
            return ['success' => $result['code'] >= 200 && $result['code'] < 300, 'event_id' => $eventId, 'status_code' => $result['code']];
        } catch (Throwable $e) {
            $this->log($eventId, $invoice['id'], $credential['app_id'], $credential['webhook_url'], $body, $headers, null, null, false, $e->getMessage());
            $this->db->prepare('INSERT INTO hekta_webhook_retry_queue (event_id, invoice_id, app_id, next_attempt_at, last_error) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 MINUTE), ?)')->execute([$eventId, $invoice['id'], $credential['app_id'], $e->getMessage()]);
            return ['success' => false, 'event_id' => $eventId, 'queued' => true];
        }
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
        $this->db->prepare('INSERT INTO hekta_webhook_logs (event_id, invoice_id, app_id, endpoint, request_payload, request_headers, response_code, response_body, success, error_message, last_attempt_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')->execute([$eventId, $invoiceId, $appId, $url, $body, json_encode($headers), $code, $response, $success ? 1 : 0, $error]);
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
