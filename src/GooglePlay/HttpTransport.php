<?php

declare(strict_types=1);

namespace HektaPay\GooglePlay;

interface HttpTransport
{
    /**
     * @param string[] $headers Raw "Name: value" header lines.
     * @return array{status:int, body:string}
     * @throws \RuntimeException When the request cannot be completed at the transport level.
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 20): array;
}
