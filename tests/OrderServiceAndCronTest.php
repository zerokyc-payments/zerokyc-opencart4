<?php

declare(strict_types=1);

namespace ZeroKyc\OpenCart\Tests;

use PHPUnit\Framework\TestCase;
use ZeroKYC\Webhook\WebhookEvent;
use ZeroKyc\OpenCart\Cron;
use ZeroKyc\OpenCart\EventStore;
use ZeroKyc\OpenCart\InvoiceMap;
use ZeroKyc\OpenCart\Logger;
use ZeroKyc\OpenCart\OpenCartOrderAdapter;
use ZeroKyc\OpenCart\OrderService;
use ZeroKyc\OpenCart\Settings;
use ZeroKyc\OpenCart\Tests\Support\FakeConfig;
use ZeroKyc\OpenCart\Tests\Support\FakeDb;
use ZeroKyc\OpenCart\Tests\Support\FakeHttpClient;
use ZeroKyc\OpenCart\Tests\Support\FakeModelCheckoutOrder;

final class OrderServiceAndCronTest extends TestCase
{
    public function testDetectedUnderpaidAndExpiredTransitions(): void
    {
        $db         = new FakeDb();
        $orderModel = new FakeModelCheckoutOrder($db);
        $invoiceMap = new InvoiceMap($db, 'oc_');
        $settings   = new Settings(new FakeConfig([
            'zerokyc_status'               => '1',
            'zerokyc_api_key'              => 'pk_test_123',
            'zerokyc_webhook_secret'       => 'whsec_123',
            'zerokyc_auto_cancel'          => '1',
            'zerokyc_double_check'         => '0',
            'zerokyc_pending_status_id'    => '1',
            'zerokyc_confirming_status_id' => '15',
            'zerokyc_paid_status_id'       => '2',
            'zerokyc_underpaid_status_id'  => '10',
            'zerokyc_expired_status_id'    => '7',
        ]));
        $logger  = new Logger(null, true);
        $service = new OrderService(new OpenCartOrderAdapter($orderModel), $invoiceMap, $settings, $logger);

        $orderModel->seedOrder(300, ['order_status_id' => 1]);
        $invoiceMap->record('inv_300', 300, ['seq' => 1, 'checkout_url' => 'https://pay.zerokycpay.com/i/inv_300']);

        // 1. payment.detected -> confirming (15)
        $service->handleEvent(WebhookEvent::fromArray([
            'id'         => 'evt_det_1',
            'type'       => 'payment.detected',
            'invoice_id' => 'inv_300',
            'tx_hash'    => '0xabc',
        ]));
        self::assertSame(15, $db->orders[300]['order_status_id']);

        // 2. payment.underpaid -> underpaid (10)
        $service->handleEvent(WebhookEvent::fromArray([
            'id'              => 'evt_und_1',
            'type'            => 'payment.underpaid',
            'invoice_id'      => 'inv_300',
            'received_amount' => '10.00',
            'expected_amount' => '25.00',
            'asset'           => 'USDT_TRON',
        ]));
        self::assertSame(10, $db->orders[300]['order_status_id']);

        // 3. invoice.expired on an order that is only pending (1) -> expired (7)
        $orderModel->seedOrder(301, ['order_status_id' => 1]);
        $invoiceMap->record('inv_301', 301, ['seq' => 1, 'checkout_url' => 'https://pay.zerokycpay.com/i/inv_301']);

        $service->handleEvent(WebhookEvent::fromArray([
            'id'         => 'evt_exp_1',
            'type'       => 'invoice.expired',
            'invoice_id' => 'inv_301',
        ]));
        self::assertSame(7, $db->orders[301]['order_status_id']);

        // 4. Paid order is NEVER downgraded by late expired or underpaid events
        $orderModel->seedOrder(302, ['order_status_id' => 2]);
        $invoiceMap->record('inv_302', 302, ['seq' => 1, 'checkout_url' => 'https://pay.zerokycpay.com/i/inv_302']);

        $service->handleEvent(WebhookEvent::fromArray([
            'id'         => 'evt_exp_paid',
            'type'       => 'invoice.expired',
            'invoice_id' => 'inv_302',
        ]));
        self::assertSame(2, $db->orders[302]['order_status_id']);
    }

    public function testCronPollsOpenInvoicesAndReconcilesPaidOrder(): void
    {
        $db         = new FakeDb();
        $http       = new FakeHttpClient();
        $orderModel = new FakeModelCheckoutOrder($db);
        $invoiceMap = new InvoiceMap($db, 'oc_');
        $eventStore = new EventStore($db, 'oc_');
        $settings   = new Settings(new FakeConfig([
            'zerokyc_status'               => '1',
            'zerokyc_api_key'              => 'pk_test_cron_key',
            'zerokyc_webhook_secret'       => 'whsec_cron_sec',
            'zerokyc_pending_status_id'    => '1',
            'zerokyc_confirming_status_id' => '15',
            'zerokyc_paid_status_id'       => '2',
            'zerokyc_underpaid_status_id'  => '10',
            'zerokyc_expired_status_id'    => '7',
        ]));
        $logger       = new Logger(null, true);
        $sdk          = $settings->buildSdk($http);
        $orderService = new OrderService(new OpenCartOrderAdapter($orderModel), $invoiceMap, $settings, $logger, $sdk);
        $cron         = new Cron($invoiceMap, $eventStore, $orderService, $settings, $logger);

        // Seed one open pending order (401) and one already paid order (402)
        $orderModel->seedOrder(401, ['order_status_id' => 1, 'total' => 19.99]);
        $invoiceMap->record('inv_401', 401, ['seq' => 1, 'checkout_url' => 'https://pay.zerokycpay.com/i/inv_401']);

        $orderModel->seedOrder(402, ['order_status_id' => 2, 'total' => 29.99]);
        $invoiceMap->record('inv_402', 402, ['seq' => 1, 'checkout_url' => 'https://pay.zerokycpay.com/i/inv_402']);

        $http->pushJson(200, [
            'id'            => 'inv_401',
            'status'        => 'overpaid',
            'amount'        => '19.99',
            'base_currency' => 'USD',
            'paid_amount'   => '21.00',
            'paid_currency' => 'USDT_TRON',
            'tx_id'         => '0xcron_tx',
        ]);

        $polled = $cron->poll($sdk);

        self::assertSame(1, $polled);
        self::assertSame(2, $db->orders[401]['order_status_id']);
        self::assertStringContainsString('OVERPAID', $orderModel->history[0]['comment']);
    }
}
