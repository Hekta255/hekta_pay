<?php

declare(strict_types=1);

namespace HektaPay\GooglePlay;

use JsonException;

/**
 * Thin client for the Google Play Developer API (androidpublisher v3).
 */
final class PlayApiClient
{
    private const BASE = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/';
    private const MAX_ATTEMPTS = 3;

    private ServiceAccountAuth $auth;
    private HttpTransport $transport;

    /** @var callable */
    private $sleeper;

    public function __construct(ServiceAccountAuth $auth, HttpTransport $transport, ?callable $sleeper = null)
    {
        $this->auth = $auth;
        $this->transport = $transport;
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    /** @return array<string,mixed> */
    public function getProductPurchase(string $packageName, string $purchaseToken): array
    {
        return $this->call('GET', 'purchases/productsv2/tokens/' . rawurlencode($purchaseToken), $packageName);
    }

    public function consume(string $packageName, string $productId, string $purchaseToken): void
    {
        $this->call('POST', 'purchases/products/' . rawurlencode($productId) . '/tokens/' . rawurlencode($purchaseToken) . ':consume', $packageName);
    }

    /** @return array<string,mixed> */
    public function listVoidedPurchases(string $packageName, int $startMillis, int $endMillis, ?string $pageToken = null): array
    {
        $query = ['startTime' => (string) $startMillis, 'endTime' => (string) $endMillis, 'type' => '0', 'maxResults' => '100'];
        if ($pageToken !== null && $pageToken !== '') {
            $query['token'] = $pageToken;
        }
        return $this->call('GET', 'purchases/voidedpurchases?' . http_build_query($query), $packageName);
    }

    /** @return array<string,mixed> */
    private function call(string $method, string $path, string $packageName): array
    {
        $url = self::BASE . rawurlencode($packageName) . '/' . $path;
        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->transport->request($method, $url, [
                    'Authorization: Bearer ' . $this->auth->accessToken(),
                    'Accept: application/json',
                    'Content-Length: 0',
                ], $method === 'POST' ? '' : null);
            } catch (PlayApiException $error) {
                $lastError = $error;
                if (!$error->isRetryable() || $attempt === self::MAX_ATTEMPTS) {
                    throw $error;
                }
                ($this->sleeper)(250 * $attempt);
                continue;
            } catch (\RuntimeException $error) {
                $lastError = new PlayApiException('Google Play API request failed: ' . $error->getMessage(), 0);
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw $lastError;
                }
                ($this->sleeper)(250 * $attempt);
                continue;
            }

            if ($response['status'] >= 200 && $response['status'] < 300) {
                if (trim($response['body']) === '') {
                    return [];
                }
                try {
                    $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $error) {
                    throw new PlayApiException('Google Play API returned invalid JSON.', $response['status']);
                }
                return is_array($decoded) ? $decoded : [];
            }

            $lastError = new PlayApiException(self::describe($response['status'], $response['body']), $response['status']);
            if (!$lastError->isRetryable() || $attempt === self::MAX_ATTEMPTS) {
                throw $lastError;
            }
            ($this->sleeper)(250 * $attempt);
        }
        throw $lastError ?? new PlayApiException('Google Play API request failed.', 0);
    }

    private static function describe(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
        return 'Google Play API returned HTTP ' . $status . ($message !== '' ? ': ' . substr($message, 0, 200) : '');
    }
}
