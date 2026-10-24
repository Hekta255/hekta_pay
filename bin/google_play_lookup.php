<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use HektaPay\Database\Connection;
use HektaPay\GooglePlay\GooglePlayException;
use HektaPay\Payment\PaymentOrchestrator;
use HektaPay\Services\WebhookDispatcher;

Connection::loadEnvironment();

$reference = $argv[1] ?? '';
$flags = array_slice($argv, 2);
if ($reference === '') {
    fwrite(STDERR, "Usage: php bin/google_play_lookup.php <invoice_id|google_order_id> [--live] [--sweep]\n");
    exit(2);
}

try {
    $db = Connection::create();
    $orchestrator = new PaymentOrchestrator($db, new WebhookDispatcher($db));
    $service = $orchestrator->googlePlay();

    $purchase = $service->findPurchaseByOrder($reference) ?? $service->findPurchaseByInvoice($reference);
    $invoiceId = $purchase['invoice_id'] ?? $reference;
    $invoiceStmt = $db->prepare('SELECT id, app_id, tenant_id, amount, currency, status, gateway, environment, gateway_order_id, invoice_type, subscription_term_months, completed_at, refunded_at, created_at FROM hekta_invoices WHERE id = ? LIMIT 1');
    $invoiceStmt->execute([$invoiceId]);
    $invoice = $invoiceStmt->fetch();

    $webhooks = $db->prepare('SELECT event_id, attempt_number, response_code, success, error_message, last_attempt_at FROM hekta_webhook_logs WHERE invoice_id = ? ORDER BY id');
    $webhooks->execute([$invoiceId]);

    $report = [
        'invoice' => $invoice ?: null,
        // The token and its ciphertext are never printed.
        'purchase' => $purchase === null ? null : array_diff_key($purchase, ['purchase_token_enc' => 1, 'purchase_token_hash' => 1, 'raw_snapshot' => 1]) + ['token_hash_prefix' => substr((string) $purchase['purchase_token_hash'], 0, 12)],
        'webhook_deliveries' => $webhooks->fetchAll(),
    ];
    if ($purchase !== null && in_array('--live', $flags, true)) {
        $live = $service->liveState($purchase);
        $report['google_live'] = [
            'purchaseState' => $live['purchaseStateContext']['purchaseState'] ?? null,
            'acknowledgementState' => $live['acknowledgementState'] ?? null,
            'consumptionState' => $live['productLineItem'][0]['productOfferDetails']['consumptionState'] ?? null,
            'orderId' => $live['orderId'] ?? null,
            'testPurchase' => isset($live['testPurchaseContext']),
        ];
    }
    if (in_array('--sweep', $flags, true)) {
        $report['sweep'] = $service->sweep(50);
    }
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (GooglePlayException $error) {
    fwrite(STDERR, $error->errorCode() . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
