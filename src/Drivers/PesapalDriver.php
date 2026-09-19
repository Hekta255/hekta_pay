<?php

declare(strict_types=1);

namespace HektaPay\Drivers;

use HektaPay\Payment\PaymentDriverInterface;
use RuntimeException;

final class PesapalDriver implements PaymentDriverInterface
{
    private string $baseUrl;
    private string $consumerKey;
    private string $consumerSecret;
    private string $ipnId;
    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    public function __construct(string $baseUrl, string $consumerKey, string $consumerSecret, string $ipnId)
    {
        $this->baseUrl = $baseUrl;
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->ipnId = $ipnId;
    }

    public function createOrder(array $data): array
    {
        if ($this->ipnId === '') {
            throw new RuntimeException('Pesapal IPN ID is not configured for this environment.');
        }

        $response = $this->request('Transactions/SubmitOrderRequest', 'POST', [
            'id' => $data['invoice_id'],
            'currency' => $data['currency'],
            'amount' => (float) $data['amount'],
            'description' => $data['description'],
            'callback_url' => $data['callback_url'] ?? null,
            'notification_id' => $this->ipnId,
            'billing_address' => $data['billing_address'] ?? [
                'email_address' => $data['customer_email'] ?? null,
            ],
        ]);

        if (empty($response['order_tracking_id']) || empty($response['redirect_url'])) {
            $safeError = $response['error'] ?? $response['message'] ?? null;
            $detail = is_string($safeError) ? ': ' . $safeError : (is_array($safeError) ? ': ' . ($safeError['message'] ?? json_encode($safeError)) : '');
            $status = isset($response['status']) ? ' (status ' . $response['status'] . ')' : '';
            throw new RuntimeException('Pesapal rejected the order' . $status . $detail . '.');
        }

        return [
            'gateway_order_id' => (string) $response['order_tracking_id'],
            'payment_url' => (string) $response['redirect_url'],
        ];
    }

    public function checkStatus(string $gatewayOrderId): array
    {
        $response = $this->request('Transactions/GetTransactionStatus?orderTrackingId=' . rawurlencode($gatewayOrderId), 'GET');
        return [
            'status' => $response['payment_status_description'] ?? $response['status'] ?? 'PENDING',
            'payment_method' => $response['payment_method'] ?? null,
            'transaction_id' => $response['confirmation_code'] ?? $gatewayOrderId,
            'raw' => $response,
        ];
    }

    public function mapStatus(string $gatewayStatus): string
    {
        return match (strtoupper(trim($gatewayStatus))) {
            'COMPLETED', 'SUCCESSFUL' => 'completed',
            'FAILED', 'DECLINED' => 'failed',
            'CANCELLED', 'REVERSED', 'VOIDED' => 'cancelled',
            default => 'pending',
        };
    }

    private function request(string $path, string $method, ?array $payload = null): array
    {
        $ch = curl_init(rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/'));
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $this->getToken()];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $error !== '') {
            throw new RuntimeException('Pesapal request failed: ' . $error);
        }
        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new RuntimeException('Pesapal returned HTTP ' . $status . '.');
        }
        return $decoded;
    }

    private function getToken(): string
    {
        if ($this->token !== null && time() < $this->tokenExpiresAt) {
            return $this->token;
        }
        $ch = curl_init(rtrim($this->baseUrl, '/') . '/Auth/RequestToken');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['consumer_key' => $this->consumerKey, 'consumer_secret' => $this->consumerSecret], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($body === false || $error !== '' || $status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['token'])) {
            $safeResponse = is_array($decoded)
                ? ($decoded['error'] ?? $decoded['message'] ?? $decoded['error_description'] ?? null)
                : null;
            $detail = is_string($safeResponse) ? ': ' . $safeResponse : '';
            throw new RuntimeException('Pesapal authentication failed (HTTP ' . $status . ')' . $detail . '.');
        }
        $this->token = (string) $decoded['token'];
        $this->tokenExpiresAt = time() + 240;
        return $this->token;
    }
}
