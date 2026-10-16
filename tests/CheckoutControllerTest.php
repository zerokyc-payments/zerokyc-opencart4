<?php

declare(strict_types=1);

namespace ZeroKyc\OpenCart\Tests;

use ControllerExtensionPaymentZerokyc;
use PHPUnit\Framework\TestCase;
use ZeroKYC\Idempotency\IdempotencyKey;
use ZeroKyc\OpenCart\Tests\Support\FakeConfig;
use ZeroKyc\OpenCart\Tests\Support\FakeDb;
use ZeroKyc\OpenCart\Tests\Support\FakeHttpClient;
use ZeroKyc\OpenCart\Tests\Support\FakeLanguage;
use ZeroKyc\OpenCart\Tests\Support\FakeLoader;
use ZeroKyc\OpenCart\Tests\Support\FakeModelCheckoutOrder;
use ZeroKyc\OpenCart\Tests\Support\FakeRequest;
use ZeroKyc\OpenCart\Tests\Support\FakeResponse;
use ZeroKyc\OpenCart\Tests\Support\FakeSession;
use ZeroKyc\OpenCart\Tests\Support\FakeUrl;

final class CheckoutControllerTest extends TestCase
{
    /**
     * @param array<string, mixed> $configOverrides
     * @return array{
     *   controller: ControllerExtensionPaymentZerokyc,
     *   db: FakeDb,
     *   http: FakeHttpClient,
     *   response: FakeResponse,
     *   session: FakeSession,
     *   orderModel: FakeModelCheckoutOrder
     * }
     */
    private function createHarness(array $configOverrides = []): array
    {
        $db         = new FakeDb();
        $http       = new FakeHttpClient();
        $response   = new FakeResponse();
        $session    = new FakeSession();
        $request    = new FakeRequest();
        $orderModel = new FakeModelCheckoutOrder($db);

        $config = new FakeConfig(array_merge([
            'config_name'                  => 'Demo Shop',
            'config_currency'              => 'USD',
            'zerokyc_status'               => '1',
            'zerokyc_api_key'              => 'pk_test_demo_key_123',
            'zerokyc_webhook_secret'       => 'whsec_demo_secret_123',
            'zerokyc_payment_currency'     => 'USDT_TRON',
            'zerokyc_ttl_minutes'          => '30',
            'zerokyc_pending_status_id'    => '1',
            'zerokyc_confirming_status_id' => '15',
            'zerokyc_paid_status_id'       => '2',
            'zerokyc_underpaid_status_id'  => '10',
            'zerokyc_expired_status_id'    => '7',
        ], $configOverrides));

        $controller = new ControllerExtensionPaymentZerokyc([
            'db'                   => $db,
            'config'               => $config,
            'session'              => $session,
            'request'              => $request,
            'response'             => $response,
            'url'                  => new FakeUrl(),
            'language'             => new FakeLanguage(),
            'model_checkout_order' => $orderModel,
        ]);
        $controller->load = new FakeLoader($controller);
        $controller->setHttpClient($http);

        return [
            'controller' => $controller,
            'db'         => $db,
            'http'       => $http,
            'response'   => $response,
            'session'    => $session,
            'orderModel' => $orderModel,
        ];
    }

    public function testSendCreatesInvoiceAndMovesOrderToPending(): void
    {
        $h = $this->createHarness();
        $h['orderModel']->seedOrder(101, [
            'total'          => 49.95,
            'currency_code'  => 'USD',
            'currency_value' => 1.0,
        ]);
        $h['session']->data['order_id'] = 101;

        $h['http']->pushJson(201, [
            'id'               => 'inv_oc2_101',
            'status'           => 'pending',
            'amount'           => '49.95',
            'base_currency'    => 'USD',
            'payment_currency' => 'USDT_TRON',
            'crypto_amount'    => '50.00',
            'checkout_url'     => 'https://pay.zerokycpay.com/i/inv_oc2_101',
            'expires_at'       => gmdate('Y-m-d\TH:i:s\Z', time() + 1800),
        ]);

        $h['controller']->send();

        $json = $h['response']->json();
        self::assertNotNull($json);
        self::assertSame('https://pay.zerokycpay.com/i/inv_oc2_101', $json['redirect']);

        // Verify Idempotency-Key header and payload sent to ZeroKYC API
        self::assertCount(1, $h['http']->requests);
        $req = $h['http']->requests[0];
        self::assertSame('POST', $req['method']);
        self::assertSame(
            IdempotencyKey::make('opencart', 'order', '101:1'),
            $req['headers']['Idempotency-Key'] ?? null
        );
        $sentBody = json_decode((string) $req['body'], true);
        self::assertSame('49.95', $sentBody['amount']);
        self::assertSame('USD', $sentBody['base_currency']);
        self::assertSame('USDT_TRON', $sentBody['payment_currency']);
        self::assertSame('101', $sentBody['order_id']);
        self::assertSame('opencart4', $sentBody['metadata']['source'] ?? null);

        // Verify order status moved from 0 to pending (1)
        self::assertSame(1, $h['db']->orders[101]['order_status_id']);
        self::assertArrayHasKey('inv_oc2_101', $h['db']->invoices);
    }

    public function testSendReusesLiveInvoiceWhenOrderUnchanged(): void
    {
        $h = $this->createHarness();
        $h['orderModel']->seedOrder(102, [
            'order_status_id' => 1,
            'total'           => 25.00,
            'currency_code'   => 'USD',
            'currency_value'  => 1.0,
        ]);
        $h['session']->data['order_id'] = 102;

        // First call creates invoice
        $h['http']->pushJson(201, [
            'id'               => 'inv_live_102',
            'status'           => 'pending',
            'amount'           => '25.00',
            'base_currency'    => 'USD',
            'payment_currency' => 'USDT_TRON',
            'crypto_amount'    => '25.05',
            'payment_url'      => 'https://pay.zerokycpay.com/i/inv_live_102',
            'expires_at'       => gmdate('Y-m-d\TH:i:s\Z', time() + 1200),
        ]);
        $h['controller']->send();

        // Second call checks GET /invoices/inv_live_102 and reuses it without POSTing a new invoice
        $h['http']->pushJson(200, [
            'id'               => 'inv_live_102',
            'status'           => 'pending',
            'amount'           => '25.00',
            'base_currency'    => 'USD',
            'payment_currency' => 'USDT_TRON',
            'crypto_amount'    => '25.05',
            'payment_url'      => 'https://pay.zerokycpay.com/i/inv_live_102',
            'expires_at'       => gmdate('Y-m-d\TH:i:s\Z', time() + 1100),
        ]);
        $h['controller']->send();

        $json = $h['response']->json();
        self::assertSame('https://pay.zerokycpay.com/i/inv_live_102', $json['redirect']);
        self::assertCount(2, $h['http']->requests);
        self::assertSame('GET', $h['http']->requests[1]['method']);
    }

    public function testSendCreatesNewInvoiceWithIncrementedSeqWhenSimpleCheckoutModifiesOrderTotal(): void
    {
        $h = $this->createHarness();
        $h['orderModel']->seedOrder(103, [
            'order_status_id' => 0,
            'total'           => 30.00,
            'currency_code'   => 'USD',
            'currency_value'  => 1.0,
        ]);
        $h['session']->data['order_id'] = 103;

        // First attempt at $30.00
        $h['http']->pushJson(201, [
            'id'               => 'inv_103_a',
            'status'           => 'pending',
            'amount'           => '30.00',
            'base_currency'    => 'USD',
            'payment_currency' => 'USDT_TRON',
            'crypto_amount'    => '30.00',
            'payment_url'      => 'https://pay.zerokycpay.com/i/inv_103_a',
            'expires_at'       => gmdate('Y-m-d\TH:i:s\Z', time() + 1800),
        ]);
        $h['controller']->send();

        // Buyer goes back in Simple Checkout, adds shipping or item on same order #103 -> total is now $45.00
        $h['db']->orders[103]['total'] = 45.00;

        $h['http']->pushJson(201, [
            'id'               => 'inv_103_b',
            'status'           => 'pending',
            'amount'           => '45.00',
            'base_currency'    => 'USD',
            'payment_currency' => 'USDT_TRON',
            'crypto_amount'    => '45.00',
            'payment_url'      => 'https://pay.zerokycpay.com/i/inv_103_b',
            'expires_at'       => gmdate('Y-m-d\TH:i:s\Z', time() + 1800),
        ]);
        $h['controller']->send();

        $json = $h['response']->json();
        self::assertSame('https://pay.zerokycpay.com/i/inv_103_b', $json['redirect']);
        self::assertCount(2, $h['http']->requests);
        self::assertSame('POST', $h['http']->requests[1]['method']);
        self::assertSame(
            IdempotencyKey::make('opencart', 'order', '103:2'),
            $h['http']->requests[1]['headers']['Idempotency-Key'] ?? null
        );
    }
}
