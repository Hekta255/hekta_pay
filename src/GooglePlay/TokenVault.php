<?php

declare(strict_types=1);

namespace HektaPay\GooglePlay;

use RuntimeException;

/**
 * Encrypts Google purchase tokens at rest. Tokens are secrets: they authorise Play API calls for a purchase.
 */
final class TokenVault
{
    private const CIPHER = 'aes-256-gcm';

    private string $key;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 16) {
            throw new RuntimeException('GOOGLE_PLAY_TOKEN_KEY must be at least 16 characters.');
        }
        $this->key = hash('sha256', $secret, true);
    }

    public static function fromEnvironment(): self
    {
        return new self((string) (getenv('GOOGLE_PLAY_TOKEN_KEY') ?: ''));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function encrypt(string $token): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt($token, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new RuntimeException('Unable to encrypt purchase token.');
        }
        return base64_encode($iv . $tag . $cipherText);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Stored purchase token is malformed.');
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('Unable to decrypt purchase token.');
        }
        return $plain;
    }
}
