<?php

declare(strict_types=1);

namespace HektaPay\GooglePlay;

use RuntimeException;

final class CurlTransport implements HttpTransport
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 20): array
    {
        if (strpos($url, 'https://') !== 0) {
            throw new RuntimeException('Only HTTPS requests are allowed.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialise cURL.');
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false || $error !== '') {
            throw new RuntimeException($error !== '' ? $error : 'HTTP request failed.');
        }
        return ['status' => $status, 'body' => (string) $response];
    }
}
