<?php

declare(strict_types=1);

namespace HektaPay\Payment;

interface PaymentDriverInterface
{
    public function createOrder(array $data): array;
    public function checkStatus(string $gatewayOrderId): array;
    public function mapStatus(string $gatewayStatus): string;
}
