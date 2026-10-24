<?php

declare(strict_types=1);

namespace HektaPay\GooglePlay;

use RuntimeException;
use Throwable;

/**
 * Handles Google Play real-time developer notifications delivered through a Pub/Sub push subscription.
 */
final class GooglePlayRtdn
{
    private GooglePlayService $service;
    private PlayProductCatalog $catalog;
    private PubSubJwtVerifier $verifier;

    public function __construct(GooglePlayService $service, PlayProductCatalog $catalog, PubSubJwtVerifier $verifier)
    {
        $this->service = $service;
        $this->catalog = $catalog;
        $this->verifier = $verifier;
    }

    /**
     * @return array{status:int, body:array<string,mixed>}
     */
    public function handle(string $authorizationHeader, string $rawBody): array
    {
        try {
            $this->verifier->verify($authorizationHeader);
        } catch (RuntimeException $error) {
            GooglePlayService::log('rtdn_rejected', ['reason' => $error->getMessage()]);
            return ['status' => 401, 'body' => ['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid push token.']]];
        }

        $envelope = json_decode($rawBody, true);
        $messageId = is_array($envelope) ? (string) ($envelope['message']['messageId'] ?? '') : '';
        $data = is_array($envelope) ? base64_decode((string) ($envelope['message']['data'] ?? ''), true) : false;
        $notification = $data !== false ? json_decode($data, true) : null;
        if ($messageId === '' || !is_array($notification)) {
            return ['status' => 400, 'body' => ['success' => false, 'error' => ['code' => 'INVALID_MESSAGE', 'message' => 'Malformed Pub/Sub message.']]];
        }

        $package = (string) ($notification['packageName'] ?? '');
        $type = 'unknown';
        $token = null;
        if (isset($notification['testNotification'])) {
            $type = 'test';
        } elseif (isset($notification['voidedPurchaseNotification'])) {
            $type = 'voided';
            $token = (string) ($notification['voidedPurchaseNotification']['purchaseToken'] ?? '');
        } elseif (isset($notification['oneTimeProductNotification'])) {
            $type = 'one_time_' . (int) ($notification['oneTimeProductNotification']['notificationType'] ?? 0);
            $token = (string) ($notification['oneTimeProductNotification']['purchaseToken'] ?? '');
        } elseif (isset($notification['subscriptionNotification'])) {
            $type = 'subscription';
        }
        $tokenHash = $token !== null && $token !== '' ? TokenVault::hash($token) : null;

        $isNew = $this->service->recordEvent($messageId, 'rtdn_' . $type, $package !== '' ? $package : null, $tokenHash, null, self::redact($notification));
        if (!$isNew) {
            return ['status' => 200, 'body' => ['success' => true, 'duplicate' => true]];
        }

        try {
            $outcome = $this->process($type, $package, $token, $notification);
            $this->service->markEventProcessed($messageId);
            GooglePlayService::log('rtdn_processed', ['type' => $type, 'outcome' => $outcome]);
            return ['status' => 200, 'body' => ['success' => true, 'outcome' => $outcome]];
        } catch (Throwable $error) {
            GooglePlayService::log('rtdn_failed', ['type' => $type, 'error' => substr($error->getMessage(), 0, 200)]);
            // Remove the dedupe row so Pub/Sub's retry is processed instead of treated as a duplicate.
            $this->service->forgetEvent($messageId);
            return ['status' => 500, 'body' => ['success' => false, 'error' => ['code' => 'RTDN_FAILED', 'message' => 'Notification could not be processed.']]];
        }
    }

    /** @param array<string,mixed> $notification */
    private function process(string $type, string $package, ?string $token, array $notification): string
    {
        if ($type === 'test') {
            return 'test_acknowledged';
        }
        if (!in_array($package, $this->catalog->packageNames(), true)) {
            return 'ignored_package';
        }
        if ($type === 'voided') {
            $voided = $notification['voidedPurchaseNotification'];
            if ((int) ($voided['productType'] ?? 0) !== 2 || (int) ($voided['refundType'] ?? 1) !== 1) {
                return 'ignored_voided_variant';
            }
            return $this->service->applyVoided((string) $token, 'rtdn_full')['reason'];
        }
        if ($type === 'one_time_1' && $token !== null && $token !== '') {
            $row = $this->service->findPurchaseByHash(TokenVault::hash($token));
            return $row === null ? 'awaiting_client_verification' : 'known_purchase';
        }
        return 'ignored';
    }

    /**
     * @param array<string,mixed> $notification
     * @return array<string,mixed>
     */
    private static function redact(array $notification): array
    {
        foreach (['oneTimeProductNotification', 'voidedPurchaseNotification'] as $key) {
            if (isset($notification[$key]['purchaseToken'])) {
                $notification[$key]['purchaseToken'] = substr(TokenVault::hash((string) $notification[$key]['purchaseToken']), 0, 12);
            }
        }
        return $notification;
    }
}
