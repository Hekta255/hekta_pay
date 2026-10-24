<?php

declare(strict_types=1);

namespace HektaPay\GooglePlay;

use RuntimeException;

/**
 * Exchanges a Google service-account key for an OAuth access token without any Composer dependency.
 */
final class ServiceAccountAuth
{
    public const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private string $clientEmail;
    private string $privateKey;
    private HttpTransport $transport;
    private ?string $cachedToken = null;
    private int $cachedUntil = 0;

    /** @var callable */
    private $clock;

    /**
     * @param array<string,mixed> $credentials Decoded service-account JSON.
     */
    public function __construct(array $credentials, HttpTransport $transport, ?callable $clock = null)
    {
        $email = (string) ($credentials['client_email'] ?? '');
        $key = (string) ($credentials['private_key'] ?? '');
        if ($email === '' || strpos($key, 'BEGIN PRIVATE KEY') === false) {
            throw new RuntimeException('Service-account credentials must contain client_email and private_key.');
        }
        $this->clientEmail = $email;
        $this->privateKey = $key;
        $this->transport = $transport;
        $this->clock = $clock ?? static fn(): int => time();
    }

    public static function credentialsFromEnvironment(): ?array
    {
        $file = (string) (getenv('GOOGLE_PLAY_SERVICE_ACCOUNT_FILE') ?: '');
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            return null;
        }
        return $decoded;
    }

    public static function isConfigured(): bool
    {
        return self::credentialsFromEnvironment() !== null;
    }

    public function clientEmail(): string
    {
        return $this->clientEmail;
    }

    public function buildAssertion(): string
    {
        $now = (int) ($this->clock)();
        $header = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = self::base64Url(json_encode([
            'iss' => $this->clientEmail,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URI,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $signature = '';
        $key = openssl_pkey_get_private($this->privateKey);
        if ($key === false || !openssl_sign($header . '.' . $claims, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the Google service-account assertion.');
        }
        return $header . '.' . $claims . '.' . self::base64Url($signature);
    }

    public function accessToken(): string
    {
        $now = (int) ($this->clock)();
        if ($this->cachedToken !== null && $now < $this->cachedUntil - 60) {
            return $this->cachedToken;
        }
        $response = $this->transport->request(
            'POST',
            self::TOKEN_URI,
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->buildAssertion(),
            ]),
        );
        $decoded = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($decoded) || empty($decoded['access_token'])) {
            throw new PlayApiException('Google OAuth token exchange failed.', $response['status']);
        }
        $this->cachedToken = (string) $decoded['access_token'];
        $this->cachedUntil = $now + (int) ($decoded['expires_in'] ?? 3600);
        return $this->cachedToken;
    }

    public static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
