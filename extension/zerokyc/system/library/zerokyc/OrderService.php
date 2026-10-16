<?php
/**
 * ZeroKYC Pay for OpenCart 2.3 — Order Status & Webhook Reconciliation Service.
 *
 * Applies verified ZeroKYC webhook events and polled Invoice snapshots to
 * OpenCart orders with ReplayGuard validation, optional server-side double-check,
 * idempotent status transitions, and deduplicated order history comments.
 */

declare(strict_types=1);

namespace ZeroKyc\OpenCart;

use Throwable;
use ZeroKYC\Idempotency\EventStoreInterface;
use ZeroKYC\Invoice\Invoice;
use ZeroKYC\Invoice\InvoiceStatus;
use ZeroKYC\Webhook\ReplayGuard;
use ZeroKYC\Webhook\WebhookEvent;
use ZeroKYC\ZeroKYC;

final class OrderService
{
    private OrderAdapterInterface $orders;
    private InvoiceMap $invoiceMap;
    private Settings $settings;
    private Logger $logger;
    private ?ZeroKYC $sdk;
    private EventStoreInterface $eventStore;

    public function __construct(
        OrderAdapterInterface $orders,
        InvoiceMap $invoiceMap,
        Settings $settings,
        Logger $logger,
        ?ZeroKYC $sdk = null,
        ?EventStoreInterface $eventStore = null
    ) {
        $this->orders     = $orders;
        $this->invoiceMap = $invoiceMap;
        $this->settings   = $settings;
        $this->logger     = $logger;
        $this->sdk        = $sdk;
        $this->eventStore = $eventStore ?? new class implements EventStoreInterface {
            public function has(string $eventId): bool
            {
                return false;
            }

            public function markProcessed(string $eventId): void
            {
            }
        };
    }

    /**
     * Processes a verified ZeroKYC webhook event.
     */
    public function handleEvent(WebhookEvent $event): void
    {
        $event     = $this->normalizeEvent($event);
        $invoiceId = (string) ($event->invoiceId ?? '');
        $orderId   = $this->resolveOrderId($event);

        if ($orderId === null || $orderId <= 0) {
            $this->logger->warning('Webhook received for unknown order', [
                'event_id'   => $event->id,
                'invoice_id' => $invoiceId,
                'type'       => $event->type,
            ]);
            return;
        }

        $order = $this->orders->getOrder($orderId);
        if ($order === null) {
            $this->logger->warning('Webhook order ID not found in OpenCart database', [
                'event_id'   => $event->id,
                'invoice_id' => $invoiceId,
                'order_id'   => $orderId,
            ]);
            return;
        }

        $latestRow         = $this->invoiceMap->findLatestForOrder($orderId);
        $invoiceRow        = ($invoiceId !== '' ? $this->invoiceMap->findByInvoiceId($invoiceId) : null) ?? $latestRow;
        $expectedInvoiceId = (string) ($latestRow['invoice_id'] ?? $invoiceId);

        switch ($event->type) {
            case 'payment.confirmed':
                $this->onPaymentConfirmed($orderId, $order, $event, $expectedInvoiceId, $invoiceRow);
                break;

            case 'payment.underpaid':
                $this->onPaymentUnderpaid($orderId, $order, $event);
                break;

            case 'payment.detected':
                $this->onPaymentDetected($orderId, $order, $event);
                break;

            case 'invoice.expired':
                $this->onInvoiceExpired($orderId, $order, $invoiceId);
                break;

            case 'invoice.canceled':
                $this->onInvoiceCanceled($orderId, $order, $invoiceId);
                break;

            default:
                $this->logger->debug('Ignoring unhandled webhook event type', [
                    'event_id' => $event->id,
                    'type'     => $event->type,
                ]);
                break;
        }
    }

    /**
     * Reconciles an OpenCart order with a fresh Invoice snapshot fetched from ZeroKYC API.
     */
    public function applyInvoice(int $orderId, Invoice $invoice): void
    {
        $order = $this->orders->getOrder($orderId);
        if ($order === null) {
            return;
        }

        $txId    = $this->extractInvoiceTxId($invoice);
        $network = $this->extractInvoiceNetwork($invoice);
        $expAmt  = $this->extractInvoiceCryptoAmount($invoice);

        switch ($invoice->status) {
            case InvoiceStatus::PAID:
            case InvoiceStatus::OVERPAID:
                $asset  = (string) ($invoice->paidAsset ?? $invoice->raw['paid_currency'] ?? $invoice->paymentCurrency ?? '');
                $amount = (string) ($invoice->paidAmount ?? $expAmt ?? $invoice->amount);
                $over   = $invoice->status === InvoiceStatus::OVERPAID || strtolower($invoice->rawStatus) === 'overpaid';

                $this->completeOrderPayment(
                    $orderId,
                    $order,
                    $invoice->id,
                    $asset,
                    $amount,
                    $network,
                    $txId,
                    $over
                );
                break;

            case InvoiceStatus::UNDERPAID:
                if ($this->isAlreadyPaid($order)) {
                    return;
                }
                $noteKey = 'underpaid:' . $invoice->id;
                if ($this->invoiceMap->hasNoteBeenSent($invoice->id, $noteKey)) {
                    return;
                }
                $comment = sprintf(
                    'ZeroKYC Pay invoice %s is underpaid (received %s, expected %s %s). Manual review required.',
                    $invoice->id,
                    (string) ($invoice->paidAmount ?? '0'),
                    (string) ($expAmt ?? $invoice->amount),
                    (string) ($invoice->paidAsset ?? $invoice->raw['paid_currency'] ?? $invoice->paymentCurrency ?? $invoice->baseCurrency)
                );
                $this->orders->addOrderHistory($orderId, $this->settings->underpaidStatusId(), $comment, false);
                $this->invoiceMap->markNoteSent($invoice->id, $noteKey);
                break;

            case InvoiceStatus::CONFIRMING:
                if ($this->isAlreadyPaid($order)) {
                    return;
                }
                $noteKey = 'confirming:' . $invoice->id;
                if ($this->invoiceMap->hasNoteBeenSent($invoice->id, $noteKey)) {
                    return;
                }
                $txPart  = $txId !== '' ? sprintf(' (tx: %s)', $txId) : '';
                $comment = sprintf('ZeroKYC Pay transaction detected for invoice %s%s. Awaiting confirmations.', $invoice->id, $txPart);
                $this->orders->addOrderHistory($orderId, $this->settings->confirmingStatusId(), $comment, false);
                $this->invoiceMap->markNoteSent($invoice->id, $noteKey);
                break;

            case InvoiceStatus::EXPIRED:
                $this->onInvoiceExpired($orderId, $order, $invoice->id);
                break;

            case InvoiceStatus::CANCELLED:
            case InvoiceStatus::FAILED:
                $this->onInvoiceCanceled($orderId, $order, $invoice->id);
                break;

            case InvoiceStatus::PENDING:
            default:
                break;
        }
    }

    /**
     * @param array<string, mixed>      $order
     * @param array<string, mixed>|null $invoiceRow
     */
    private function onPaymentConfirmed(
        int $orderId,
        array $order,
        WebhookEvent $event,
        string $expectedInvoiceId,
        ?array $invoiceRow
    ): void {
        $invoiceId         = (string) ($event->invoiceId ?? '');
        $expectedMinCrypto = null;
        $expectedAsset     = null;

        if ($invoiceRow !== null) {
            $rawCrypto = trim((string) ($invoiceRow['amount_crypto'] ?? ''));
            if ($rawCrypto !== '' && (float) $rawCrypto > 0) {
                $expectedMinCrypto = $rawCrypto;
            }
            $rawAsset = trim((string) ($invoiceRow['asset'] ?? ''));
            if ($rawAsset !== '' && strtolower($rawAsset) !== 'any') {
                $expectedAsset = $rawAsset;
            }
        }

        $guard = new ReplayGuard($this->eventStore);
        if (!$guard->matchesOrder($event, $expectedInvoiceId, $expectedMinCrypto, $expectedAsset)) {
            $this->logger->alert('ReplayGuard rejected payment.confirmed webhook', [
                'order_id'            => $orderId,
                'event_id'            => $event->id,
                'invoice_id'          => $invoiceId,
                'expected_invoice_id' => $expectedInvoiceId,
                'expected_min_crypto' => $expectedMinCrypto,
                'expected_asset'      => $expectedAsset,
                'received_amount'     => $event->paidAmount(),
                'received_asset'      => $event->paidAsset(),
            ]);

            $alertKey = 'replay_guard_fail:' . $event->id;
            if ($invoiceId !== '' && !$this->invoiceMap->hasNoteBeenSent($invoiceId, $alertKey)) {
                $comment = sprintf(
                    'ZeroKYC Pay ALERT: confirmed webhook (%s) did not match expected order constraints (invoice/amount/asset). Hold for manual review.',
                    $invoiceId
                );
                $this->orders->addOrderHistory($orderId, $this->settings->underpaidStatusId(), $comment, false);
                $this->invoiceMap->markNoteSent($invoiceId, $alertKey);
            }
            return;
        }

        $rawStatus = strtolower($this->extractEventStatus($event));
        $overpaid  = $rawStatus === 'overpaid';

        if ($this->settings->doubleCheck() && $invoiceId !== '') {
            $sdk = $this->sdk ?? $this->settings->buildSdk();
            try {
                $remote = $sdk->getInvoice($invoiceId);
            } catch (Throwable $e) {
                $this->logger->warning('Double-check GET /invoices failed; allowing webhook retry', [
                    'order_id'   => $orderId,
                    'invoice_id' => $invoiceId,
                    'error'      => $e->getMessage(),
                ]);
                throw $e;
            }

            if (!$remote->isPaid() && $remote->status !== InvoiceStatus::OVERPAID) {
                $this->logger->alert('Server double-check mismatch: webhook says confirmed but GET /invoices is not paid', [
                    'order_id'      => $orderId,
                    'invoice_id'    => $invoiceId,
                    'remote_status' => $remote->status->value,
                ]);

                $mismatchKey = 'doublecheck_fail:' . $event->id;
                if (!$this->invoiceMap->hasNoteBeenSent($invoiceId, $mismatchKey)) {
                    $comment = sprintf(
                        'ZeroKYC Pay ALERT: webhook reported payment confirmed for invoice %s, but server verification returned status "%s". Hold for manual review.',
                        $invoiceId,
                        $remote->status->value
                    );
                    $this->orders->addOrderHistory($orderId, $this->settings->underpaidStatusId(), $comment, false);
                    $this->invoiceMap->markNoteSent($invoiceId, $mismatchKey);
                }
                return;
            }

            if ($remote->status === InvoiceStatus::OVERPAID || strtolower($remote->rawStatus) === 'overpaid') {
                $overpaid = true;
            }
        }

        $asset   = (string) ($event->paidAsset() ?? ($invoiceRow['asset'] ?? ''));
        $amount  = (string) ($event->paidAmount() ?? $this->extractEventExpectedAmount($event) ?? ($invoiceRow['amount_crypto'] ?? ''));
        $network = (string) ($event->paidNetwork() ?? $event->data['network'] ?? $event->raw['network'] ?? '');
        $txId    = $this->extractEventTxHash($event);

        $this->completeOrderPayment($orderId, $order, $invoiceId, $asset, $amount, $network, $txId, $overpaid);
    }

    /**
     * @param array<string, mixed> $order
     */
    private function completeOrderPayment(
        int $orderId,
        array $order,
        string $invoiceId,
        string $asset,
        string $amount,
        string $network,
        string $txId,
        bool $overpaid
    ): void {
        $noteKey = 'confirmed:' . $invoiceId;
        $this->invoiceMap->markPaid($invoiceId, $asset, $amount, $txId);

        if ($this->isAlreadyPaid($order) && $this->invoiceMap->hasNoteBeenSent($invoiceId, $noteKey)) {
            return;
        }

        $parts = [sprintf('ZeroKYC Pay payment confirmed (invoice %s).', $invoiceId)];
        if ($amount !== '' || $asset !== '') {
            $parts[] = sprintf('Amount: %s %s.', trim($amount), trim($asset));
        }
        if ($network !== '') {
            $parts[] = sprintf('Network: %s.', $network);
        }
        if ($txId !== '') {
            $parts[] = sprintf('TX: %s.', $txId);
        }
        if ($overpaid) {
            $parts[] = 'Note: invoice was OVERPAID by the customer.';
        }

        $comment = implode(' ', $parts);
        $this->orders->addOrderHistory($orderId, $this->settings->paidStatusId(), $comment, true);
        $this->invoiceMap->markNoteSent($invoiceId, $noteKey);
    }

    /**
     * @param array<string, mixed> $order
     */
    private function onPaymentUnderpaid(int $orderId, array $order, WebhookEvent $event): void
    {
        if ($this->isAlreadyPaid($order)) {
            return;
        }

        $invoiceId = (string) ($event->invoiceId ?? '');
        $noteKey   = 'underpaid:' . $invoiceId;
        if ($invoiceId !== '' && $this->invoiceMap->hasNoteBeenSent($invoiceId, $noteKey)) {
            return;
        }

        $comment = sprintf(
            'ZeroKYC Pay invoice %s is underpaid (received %s, expected %s %s). Manual review required.',
            $invoiceId,
            (string) ($event->paidAmount() ?? '0'),
            (string) ($this->extractEventExpectedAmount($event) ?? '?'),
            (string) ($event->paidAsset() ?? '')
        );

        $this->orders->addOrderHistory($orderId, $this->settings->underpaidStatusId(), $comment, false);
        if ($invoiceId !== '') {
            $this->invoiceMap->markNoteSent($invoiceId, $noteKey);
        }
    }

    /**
     * @param array<string, mixed> $order
     */
    private function onPaymentDetected(int $orderId, array $order, WebhookEvent $event): void
    {
        if ($this->isAlreadyPaid($order)) {
            return;
        }

        $invoiceId = (string) ($event->invoiceId ?? '');
        $txHash    = $this->extractEventTxHash($event);
        $noteKey   = 'detected:' . $invoiceId . ':' . $txHash;
        if ($invoiceId !== '' && $this->invoiceMap->hasNoteBeenSent($invoiceId, $noteKey)) {
            return;
        }

        $txPart  = $txHash !== '' ? sprintf(' (tx: %s)', $txHash) : '';
        $comment = sprintf(
            'ZeroKYC Pay transaction detected on-chain for invoice %s%s. Awaiting confirmations.',
            $invoiceId,
            $txPart
        );

        $this->orders->addOrderHistory($orderId, $this->settings->confirmingStatusId(), $comment, false);
        if ($invoiceId !== '') {
            $this->invoiceMap->markNoteSent($invoiceId, $noteKey);
        }
    }

    /**
     * @param array<string, mixed> $order
     */
    private function onInvoiceExpired(int $orderId, array $order, string $invoiceId): void
    {
        if (!$this->settings->autoCancel()) {
            return;
        }

        if (!$this->isOpenUnconfirmedOrder($order)) {
            return;
        }

        $noteKey = 'expired:' . $invoiceId;
        if ($invoiceId !== '' && $this->invoiceMap->hasNoteBeenSent($invoiceId, $noteKey)) {
            return;
        }

        $comment = sprintf('ZeroKYC Pay invoice %s expired without payment.', $invoiceId);
        $this->orders->addOrderHistory($orderId, $this->settings->expiredStatusId(), $comment, false);
        if ($invoiceId !== '') {
            $this->invoiceMap->markNoteSent($invoiceId, $noteKey);
        }
    }

    /**
     * @param array<string, mixed> $order
     */
    private function onInvoiceCanceled(int $orderId, array $order, string $invoiceId): void
    {
        if (!$this->isOpenUnconfirmedOrder($order)) {
            return;
        }

        $noteKey = 'canceled:' . $invoiceId;
        if ($invoiceId !== '' && $this->invoiceMap->hasNoteBeenSent($invoiceId, $noteKey)) {
            return;
        }

        $comment = sprintf('ZeroKYC Pay invoice %s was canceled.', $invoiceId);
        $this->orders->addOrderHistory($orderId, $this->settings->expiredStatusId(), $comment, false);
        if ($invoiceId !== '') {
            $this->invoiceMap->markNoteSent($invoiceId, $noteKey);
        }
    }

    private function resolveOrderId(WebhookEvent $event): ?int
    {
        $invoiceId = (string) ($event->invoiceId ?? '');
        if ($invoiceId !== '') {
            $mapped = $this->invoiceMap->findOrderId($invoiceId);
            if ($mapped !== null && $mapped > 0) {
                return $mapped;
            }
        }

        $metadata = is_array($event->data['metadata'] ?? null)
            ? $event->data['metadata']
            : (is_array($event->raw['metadata'] ?? null) ? $event->raw['metadata'] : []);

        if (isset($metadata['order_id']) && is_numeric((string) $metadata['order_id'])) {
            return (int) $metadata['order_id'];
        }

        $rawOrderId = $event->data['order_id'] ?? $event->raw['order_id'] ?? null;
        if ($rawOrderId !== null && is_numeric((string) $rawOrderId)) {
            return (int) $rawOrderId;
        }

        return null;
    }

    /**
     * Normalizes flat/top-level webhook fields into $event->data so ReplayGuard
     * and WebhookEvent helpers work uniformly across both nested and flat payloads.
     */
    private function normalizeEvent(WebhookEvent $event): WebhookEvent
    {
        $data = $event->data;
        $raw  = $event->raw;

        if (!isset($data['amount']) && !isset($data['amount_paid'])) {
            $amt = $data['received_amount'] ?? $raw['received_amount'] ?? $raw['amount_paid'] ?? $raw['amount'] ?? null;
            if ($amt !== null && $amt !== '') {
                $data['amount'] = (string) $amt;
            }
        }

        if (!isset($data['asset'])) {
            $asset = $raw['asset'] ?? null;
            if ($asset !== null && $asset !== '') {
                $data['asset'] = (string) $asset;
            }
        }

        return new WebhookEvent(
            id: $event->id,
            type: $event->type,
            invoiceId: $event->invoiceId,
            data: $data,
            raw: $raw,
        );
    }

    private function extractEventTxHash(WebhookEvent $event): string
    {
        $option = is_array($event->data['option'] ?? null) ? $event->data['option'] : [];
        return (string) (
            $option['tx_hash']
            ?? $option['txid']
            ?? $event->data['tx_hash']
            ?? $event->data['txid']
            ?? $event->raw['tx_hash']
            ?? $event->raw['txid']
            ?? ''
        );
    }

    private function extractEventExpectedAmount(WebhookEvent $event): ?string
    {
        $option = is_array($event->data['option'] ?? null) ? $event->data['option'] : [];
        $val    = $option['expected_amount']
            ?? $option['amount']
            ?? $event->data['expected_amount']
            ?? $event->data['amount_expected']
            ?? $event->raw['expected_amount']
            ?? null;
        return ($val !== null && $val !== '') ? (string) $val : null;
    }

    private function extractEventStatus(WebhookEvent $event): string
    {
        return (string) ($event->data['status'] ?? $event->raw['status'] ?? '');
    }

    private function extractInvoiceTxId(Invoice $invoice): string
    {
        $obs = $invoice->observations[0] ?? null;
        if (is_array($obs)) {
            $obsTx = $obs['tx_hash'] ?? $obs['txid'] ?? $obs['tx_id'] ?? null;
            if ($obsTx !== null && $obsTx !== '') {
                return (string) $obsTx;
            }
        }
        return (string) ($invoice->raw['tx_id'] ?? $invoice->raw['tx_hash'] ?? $invoice->raw['txid'] ?? '');
    }

    private function extractInvoiceNetwork(Invoice $invoice): string
    {
        $obs = $invoice->observations[0] ?? null;
        if (is_array($obs) && !empty($obs['network'])) {
            return (string) $obs['network'];
        }
        return (string) ($invoice->raw['network'] ?? '');
    }

    private function extractInvoiceCryptoAmount(Invoice $invoice): ?string
    {
        if ($invoice->paymentCurrency !== '' && $invoice->paymentCurrency !== 'any') {
            $opt = $invoice->option($invoice->paymentCurrency);
            if ($opt !== null && isset($opt['amount']) && (string) $opt['amount'] !== '') {
                return (string) $opt['amount'];
            }
        }
        if (isset($invoice->options[0]['amount']) && (string) $invoice->options[0]['amount'] !== '') {
            return (string) $invoice->options[0]['amount'];
        }
        $rawCrypto = $invoice->raw['crypto_amount'] ?? $invoice->raw['amount_crypto'] ?? null;
        return ($rawCrypto !== null && $rawCrypto !== '') ? (string) $rawCrypto : null;
    }

    /**
     * @param array<string, mixed> $order
     */
    private function isAlreadyPaid(array $order): bool
    {
        return (int) ($order['order_status_id'] ?? 0) === $this->settings->paidStatusId();
    }

    /**
     * Returns true if the order is still unconfirmed (status 0 or pendingStatusId).
     * Never expires an order that is already confirming, underpaid, or paid.
     *
     * @param array<string, mixed> $order
     */
    private function isOpenUnconfirmedOrder(array $order): bool
    {
        $statusId = (int) ($order['order_status_id'] ?? 0);
        if ($statusId === $this->settings->paidStatusId()) {
            return false;
        }
        return in_array($statusId, [0, $this->settings->pendingStatusId()], true);
    }
}
