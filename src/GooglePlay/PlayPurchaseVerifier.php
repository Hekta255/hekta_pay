<?php

declare(strict_types=1);

namespace HektaPay\GooglePlay;

/**
 * Pure validation of a Google ProductPurchaseV2 payload against what the invoice expects.
 */
final class PlayPurchaseVerifier
{
    public const OK = 'ok';
    public const PENDING = 'pending';
    public const REJECTED = 'rejected';

    /**
     * @param array<string,mixed> $purchase ProductPurchaseV2 response.
     * @param array{product_id:string, invoice_id:string, allow_test:bool} $expected
     * @return array{result:string, code:string, message:string, order_id:?string, is_test:bool, region_code:?string, acknowledged:bool, consumed:bool}
     */
    public static function evaluate(array $purchase, array $expected): array
    {
        $orderId = isset($purchase['orderId']) && $purchase['orderId'] !== '' ? (string) $purchase['orderId'] : null;
        $isTest = isset($purchase['testPurchaseContext']) && is_array($purchase['testPurchaseContext']);
        $region = isset($purchase['regionCode']) && $purchase['regionCode'] !== '' ? strtoupper((string) $purchase['regionCode']) : null;
        $acknowledged = ($purchase['acknowledgementState'] ?? '') === 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED';
        $lineItems = isset($purchase['productLineItem']) && is_array($purchase['productLineItem']) ? array_values($purchase['productLineItem']) : [];
        $consumed = false;
        foreach ($lineItems as $item) {
            if (($item['productOfferDetails']['consumptionState'] ?? '') === 'CONSUMPTION_STATE_CONSUMED') {
                $consumed = true;
            }
        }

        $fail = static function (string $result, string $code, string $message) use ($orderId, $isTest, $region, $acknowledged, $consumed): array {
            return [
                'result' => $result,
                'code' => $code,
                'message' => $message,
                'order_id' => $orderId,
                'is_test' => $isTest,
                'region_code' => $region,
                'acknowledged' => $acknowledged,
                'consumed' => $consumed,
            ];
        };

        $state = (string) ($purchase['purchaseStateContext']['purchaseState'] ?? '');
        if ($state === 'PENDING') {
            return $fail(self::PENDING, 'PURCHASE_PENDING', 'The purchase is still pending with Google Play.');
        }
        if ($state !== 'PURCHASED') {
            return $fail(self::REJECTED, 'PURCHASE_NOT_COMPLETED', 'The purchase is not in a purchased state.');
        }
        if (count($lineItems) !== 1) {
            return $fail(self::REJECTED, 'PRODUCT_MISMATCH', 'The purchase must contain exactly one product.');
        }
        $item = $lineItems[0];
        if (!is_array($item) || (string) ($item['productId'] ?? '') !== $expected['product_id']) {
            return $fail(self::REJECTED, 'PRODUCT_MISMATCH', 'The purchased product does not match the invoice.');
        }
        $quantity = (int) ($item['productOfferDetails']['quantity'] ?? 1);
        if ($quantity !== 1) {
            return $fail(self::REJECTED, 'QUANTITY_INVALID', 'Only single-quantity purchases are supported.');
        }
        if ($isTest && !$expected['allow_test']) {
            return $fail(self::REJECTED, 'TEST_PURCHASE_REJECTED', 'Test purchases are not accepted in production.');
        }
        $accountId = (string) ($purchase['obfuscatedExternalAccountId'] ?? '');
        $profileId = (string) ($purchase['obfuscatedExternalProfileId'] ?? '');
        if (!hash_equals($expected['invoice_id'], $accountId) && !hash_equals($expected['invoice_id'], $profileId)) {
            return $fail(self::REJECTED, 'INVOICE_BINDING_MISMATCH', 'The purchase was not made for this invoice.');
        }
        if ($orderId === null) {
            return $fail(self::REJECTED, 'ORDER_MISSING', 'The purchase has no Google order.');
        }

        return $fail(self::OK, 'OK', 'Purchase verified.');
    }
}
