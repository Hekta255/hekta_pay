<?php

declare(strict_types=1);

namespace HektaPay\GooglePlay;

/**
 * The invoice operations GooglePlayService needs from the payment orchestrator.
 */
interface InvoiceLifecycle
{
    /** @return array<string,mixed>|null */
    public function findInvoice(string $appId, string $invoiceId): ?array;

    /**
     * Marks the invoice completed, writes the transaction, and dispatches the signed webhook.
     *
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $gatewayStatus
     * @return array<string,mixed>|null Webhook dispatch result, or null when nothing was dispatched.
     */
    public function completeExternalInvoice(array $invoice, array $gatewayStatus): ?array;

    /**
     * Marks a completed invoice refunded and dispatches `payment.refunded`.
     *
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>|null
     */
    public function refundExternalInvoice(array $invoice): ?array;

    /**
     * Dispatches a signed webhook event for the invoice again.
     *
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>|null
     */
    public function dispatchInvoiceEvent(array $invoice, string $event): ?array;
}
