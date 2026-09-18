<?php

declare(strict_types=1);

namespace HektaPay\Services;

final class WebhookSigner
{
    public static function sign(string $timestamp, string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . $payload, $secret);
    }

    public static function verify(string $timestamp, string $payload, string $signature, string $secret, int $tolerance = 300): bool
    {
        $time = strtotime($timestamp);
        if ($time === false || abs(time() - $time) > $tolerance) {
            return false;
        }
        return hash_equals(self::sign($timestamp, $payload, $secret), $signature);
    }
}
