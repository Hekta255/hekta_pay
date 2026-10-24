<?php

declare(strict_types=1);

namespace HektaPay\GooglePlay;

use RuntimeException;

final class PlayApiException extends RuntimeException
{
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus = 0)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function isNotFound(): bool
    {
        return $this->httpStatus === 404 || $this->httpStatus === 410;
    }

    public function isRetryable(): bool
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }
}
