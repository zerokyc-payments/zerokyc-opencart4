<?php
/**
 * ZeroKYC Pay for OpenCart 2.3 — Background Reconciliation Poller (Cron).
 *
 * Polls open orders from the last 24 hours in batches of 50 so stores behind
 * firewalls, Cloudflare rules, or local dev environments still converge on the
 * authoritative invoice status even if a webhook delivery was missed.
 */

declare(strict_types=1);

namespace ZeroKyc\OpenCart;

use Throwable;
use ZeroKYC\ZeroKYC;

final class Cron
{
    public const BATCH_SIZE = 50;
    public const LOOKBACK_HOURS = 24;

    private InvoiceMap $invoiceMap;
    private EventStore $eventStore;
    private OrderService $orderService;
    private Settings $settings;
    private Logger $logger;

    public function __construct(
        InvoiceMap $invoiceMap,
        EventStore $eventStore,
        OrderService $orderService,
        Settings $settings,
        Logger $logger
    ) {
        $this->invoiceMap   = $invoiceMap;
        $this->eventStore   = $eventStore;
        $this->orderService = $orderService;
        $this->settings     = $settings;
        $this->logger       = $logger;
    }

    /**
     * Polls up to 50 open invoices created within the last 24h and applies their status.
     *
     * @return int Number of invoices successfully polled
     */
    public function poll(?ZeroKYC $sdk = null): int
    {
        if (!$this->settings->isEnabled() || $this->settings->apiKey() === '') {
            return 0;
        }

        $client = $sdk ?? $this->settings->buildSdk();

        $openStatuses = array_values(array_unique([
            0,
            $this->settings->pendingStatusId(),
            $this->settings->confirmingStatusId(),
        ]));

        $openInvoices = $this->invoiceMap->findOpenInvoices(
            $openStatuses,
            self::LOOKBACK_HOURS,
            self::BATCH_SIZE
        );

        $polled = 0;
        foreach ($openInvoices as $entry) {
            $orderId   = (int) $entry['order_id'];
            $invoiceId = (string) $entry['invoice_id'];
            if ($orderId <= 0 || $invoiceId === '') {
                continue;
            }

            try {
                $invoice = $client->getInvoice($invoiceId);
                $this->orderService->applyInvoice($orderId, $invoice);
                $polled++;
            } catch (Throwable $e) {
                $this->logger->warning('Cron reconciliation failed for invoice', [
                    'order_id'   => $orderId,
                    'invoice_id' => $invoiceId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->eventStore->cleanup(90);
        } catch (Throwable $e) {
            $this->logger->debug('EventStore cleanup skipped', ['error' => $e->getMessage()]);
        }

        return $polled;
    }
}
