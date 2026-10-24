<?php

declare(strict_types=1);

namespace HektaPay\GooglePlay;

use RuntimeException;

/**
 * Verifies the OIDC bearer token Google Cloud Pub/Sub attaches to push deliveries.
 */
final class PubSubJwtVerifier
{
    public const CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

    private string $audience;
    private string $serviceAccountEmail;

    /** @var callable */
    private $certProvider;

    /** @var callable */
    private $clock;

    /**
     * @param callable $certProvider Returns an array of key id => PEM certificate.
     */
    public function __construct(string $audience, string $serviceAccountEmail, callable $certProvider, ?callable $clock = null)
    {
        if ($audience === '' || $serviceAccountEmail === '') {
            throw new RuntimeException('Pub/Sub audience and service-account email are required.');
        }
        $this->audience = $audience;
        $this->serviceAccountEmail = $serviceAccountEmail;
        $this->certProvider = $certProvider;
        $this->clock = $clock ?? static fn(): int => time();
    }

    public static function fromEnvironment(HttpTransport $transport): self
    {
        $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hekta_pay_google_certs.json';
        $provider = static function () use ($transport, $cachePath): array {
            if (is_file($cachePath) && time() - (int) filemtime($cachePath) < 3600) {
                $cached = json_decode((string) file_get_contents($cachePath), true);
                if (is_array($cached) && $cached !== []) {
                    return $cached;
                }
            }
            $response = $transport->request('GET', self::CERTS_URL);
            $decoded = json_decode($response['body'], true);
            if ($response['status'] !== 200 || !is_array($decoded) || $decoded === []) {
                throw new RuntimeException('Unable to load Google signing certificates.');
            }
            @file_put_contents($cachePath, $response['body'], LOCK_EX);
            return $decoded;
        };
        return new self(
            (string) (getenv('GOOGLE_PLAY_RTDN_AUDIENCE') ?: ''),
            (string) (getenv('GOOGLE_PLAY_RTDN_SERVICE_ACCOUNT') ?: ''),
            $provider,
        );
    }

    /**
     * @return array<string,mixed> Verified claims.
     * @throws RuntimeException When the token is missing or invalid.
     */
    public function verify(string $authorizationHeader): array
    {
        if (stripos($authorizationHeader, 'Bearer ') !== 0) {
            throw new RuntimeException('Bearer token required.');
        }
        $jwt = trim(substr($authorizationHeader, 7));
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed token.');
        }
        $header = json_decode(self::base64UrlDecode($parts[0]), true);
        $claims = json_decode(self::base64UrlDecode($parts[1]), true);
        $signature = self::base64UrlDecode($parts[2]);
        if (!is_array($header) || !is_array($claims) || $signature === '') {
            throw new RuntimeException('Malformed token.');
        }
        if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            throw new RuntimeException('Unsupported token algorithm.');
        }

        $certs = ($this->certProvider)();
        $certificate = $certs[(string) $header['kid']] ?? null;
        if (!is_string($certificate)) {
            throw new RuntimeException('Unknown signing key.');
        }
        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false || openssl_verify($parts[0] . '.' . $parts[1], $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Invalid token signature.');
        }

        $now = (int) ($this->clock)();
        if (!in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new RuntimeException('Unexpected token issuer.');
        }
        if (($claims['aud'] ?? '') !== $this->audience) {
            throw new RuntimeException('Unexpected token audience.');
        }
        if (!isset($claims['exp']) || (int) $claims['exp'] + 60 < $now) {
            throw new RuntimeException('Token expired.');
        }
        if (isset($claims['iat']) && (int) $claims['iat'] - 300 > $now) {
            throw new RuntimeException('Token issued in the future.');
        }
        if (!hash_equals($this->serviceAccountEmail, (string) ($claims['email'] ?? '')) || ($claims['email_verified'] ?? false) !== true) {
            throw new RuntimeException('Unexpected token subject.');
        }
        return $claims;
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $decoded === false ? '' : $decoded;
    }
}
