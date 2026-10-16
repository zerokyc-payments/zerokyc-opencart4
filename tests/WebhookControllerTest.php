<?php

declare(strict_types=1);

namespace ZeroKyc\OpenCart\Tests;

use ControllerExtensionPaymentZerokyc;
use PHPUnit\Framework\TestCase;
use ZeroKYC\Exception\NetworkException;
use ZeroKyc\OpenCart\InvoiceMap;
use ZeroKyc\OpenCart\Logger;
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

final class WebhookControllerTest extends TestCase
{
    private const SECRET = 'whsec_test_opencart2_secret';

    /**
     * @param array<string, mixed> $configOverrides
     * @return array{
     *   controller: ControllerExtensionPaymentZerokyc,
     *   db: FakeDb,
     *   http: FakeHttpClient,
     *   request: FakeRequest,
     *   response: FakeResponse,
     *   orderModel: FakeModelCheckoutOrder,
     *   logger: Logger
     * }
     */
    private function createHarness(array $configOverrides = []): array
    {
        $db         = new FakeDb();
        $http       = new FakeHttpClient();
        $request    = new FakeRequest();
        $response   = new FakeResponse();
        $orderModel = new FakeModelCheckoutOrder($db);
        $logger     = new Logger(null, true);

        $config = new FakeConfig(array_merge([
            'config_currency'              => 'USD',
            'zerokyc_status'               => '1',
            'zerokyc_api_key'              => 'pk_test_123456',
            'zerokyc_webhook_secret'       => self::SECRET,
            'zerokyc_payment_currency'     => 'USDT_TRON',
            'zerokyc_double_check'         => '1',
            'zerokyc_auto_cancel'          => '1',
            'zerokyc_debug'                => '1',
            'zerokyc_pending_status_id'    => '1',
            'zerokyc_confirming_status_id' => '15',
            'zerokyc_paid_status_id'       => '2',
            'zerokyc_underpaid_status_id'  => '10',
            'zerokyc_expired_status_id'    => '7',
        ], $configOverrides));

        $controller = new ControllerExtensionPaymentZerokyc([
            'db'                   => $db,
            'config'               => $config,
            'session'              => new FakeSession(),
            'request'              => $request,
            'response'             => $response,
            'url'                  => new FakeUrl(),
            'language'             => new FakeLanguage(),
            'model_checkout_order' => $orderModel,
        ]);
        $controller->load = new FakeLoader($controller);
        $controller->setHttpClient($http);
        $controller->setLogger($logger);

        return [
            'controller' => $controller,
            'db'         => $db,
            'http'       => $http,
            'request'    => $request,
            'response'   => $response,
            'orderModel' => $orderModel,
            'logger'     => $logger,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{body: string, sig: string, ts: int}
     */
    private function signPayload(array $payload, int $timestamp = 1788788073, string $secret = self::SECRET): array
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $mac  = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        return [
            'body' => $body,
            'sig'  => 't=' . $timestamp . ',v1=' . $mac,
            'ts'   => $timestamp,
        ];
    }

    public function testCallbackRejectsNonPostAndInvalidSignature(): void
    {
        $h = $this->createHarness();

        // 1. GET -> 405
        $h['request']->server['REQUEST_METHOD'] = 'GET';
        $h['controller']->callback();
        self::assertSame(405, $h['response']->httpStatus());

        // 2. POST with wrong signature -> 400
        $h = $this->createHarness();
        $h['request']->server['REQUEST_METHOD']       = 'POST';
        $h['request']->server['HTTP_X_ZKP_SIGNATURE'] = 't=1788788073,v1=' . str_repeat('a', 64);
        $h['controller']->setRawBodyOverride('{"id":"evt_bad","type":"payment.confirmed","invoice_id":"inv_1"}');
        $h['controller']->setNowOverride(1788788073);
        $h['controller']->callback();

        self::assertSame(400, $h['response']->httpStatus());
    }

    public function testHappyPathConfirmedWebhookWithDoubleCheckMarksOrderPaidAndDeduplicatesReplay(): void
    {
        $h = $this->createHarness();
        $h['orderModel']->seedOrder(200, [
            'order_status_id' => 1,
            'total'           => 50.00,
            'currency_code'   => 'USD',
        ]);

        $map = new InvoiceMap($h['db'], 'oc_');
        $map->record('inv_200', 200, [
            'seq'           => 1,
            'checkout_url'  => 'https://pay.zerokycpay.com/i/inv_200',
            'amount_fiat'   => '50.00',
            'currency_fiat' => 'USD',
            'asset'         => 'USDT_TRON',
            'amount_crypto' => '50.05',
        ]);

        // Server-side double-check response
        $h['http']->pushJson(200, [
            'id'               => 'inv_200',
            'status'           => 'confirmed',
            'amount'           => '50.00',
            'base_currency'    => 'USD',
            'payment_currency' => 'USDT_TRON',
            'crypto_amount'    => '50.05',
            'paid_amount'      => '50.05',
            'paid_asset'       => 'USDT_TRON',
            'tx_id'            => '0xconfirmed_hash',
        ]);

        $signed = $this->signPayload([
            'id'              => 'evt_ok_200',
            'type'            => 'payment.confirmed',
            'invoice_id'      => 'inv_200',
            'order_id'        => '200',
            'status'          => 'paid',
            'expected_amount' => '50.05',
            'received_amount' => '50.05',
            'asset'           => 'USDT_TRON',
            'network'         => 'TRON',
            'tx_hash'         => '0xconfirmed_hash',
        ]);

        $h['request']->server['REQUEST_METHOD']       = 'POST';
        $h['request']->server['HTTP_X_ZKP_SIGNATURE'] = $signed['sig'];
        $h['controller']->setRawBodyOverride($signed['body']);
        $h['controller']->setNowOverride($signed['ts']);

        $h['controller']->callback();

        self::assertSame(200, $h['response']->httpStatus());
        self::assertSame(['ok' => true], $h['response']->json());
        self::assertSame(2, $h['db']->orders[200]['order_status_id']);
        self::assertCount(1, $h['orderModel']->history);
        self::assertStringContainsString('0xconfirmed_hash', $h['orderModel']->history[0]['comment']);

        // Replay the exact same webhook event -> must return 200 duplicate:true without calling API or adding history
        $h['response']->headers = [];
        $h['controller']->callback();
        self::assertSame(200, $h['response']->httpStatus());
        self::assertSame(['ok' => true, 'duplicate' => true], $h['response']->json());
        self::assertCount(1, $h['orderModel']->history);
        self::assertCount(1, $h['http']->requests);
    }

    public function testReplayGuardRejectsAssetMismatchAndDoesNotCompleteOrder(): void
    {
        $h = $this->createHarness();
        $h['orderModel']->seedOrder(201, [
            'order_status_id' => 1,
            'total'           => 100.00,
            'currency_code'   => 'USD',
        ]);

        $map = new InvoiceMap($h['db'], 'oc_');
        $map->record('inv_201', 201, [
            'seq'           => 1,
            'checkout_url'  => 'https://pay.zerokycpay.com/i/inv_201',
            'amount_fiat'   => '100.00',
            'currency_fiat' => 'USD',
            'asset'         => 'USDT_TRON',
            'amount_crypto' => '100.00',
        ]);

        // Webhook claims paid in DOGE instead of pinned USDT_TRON
        $signed = $this->signPayload([
            'id'              => 'evt_mismatch_201',
            'type'            => 'payment.confirmed',
            'invoice_id'      => 'inv_201',
            'order_id'        => '201',
            'status'          => 'paid',
            'expected_amount' => '100.00',
            'received_amount' => '100.00',
            'asset'           => 'DOGE',
        ]);

        $h['request']->server['REQUEST_METHOD']       = 'POST';
        $h['request']->server['HTTP_X_ZKP_SIGNATURE'] = $signed['sig'];
        $h['controller']->setRawBodyOverride($signed['body']);
        $h['controller']->setNowOverride($signed['ts']);

        $h['controller']->callback();

        self::assertSame(200, $h['response']->httpStatus());
        // Order must NOT be marked paid (2); moved to underpaid/review status (10)
        self::assertSame(10, $h['db']->orders[201]['order_status_id']);
        self::assertNotEmpty($h['logger']->getRecords());
        self::assertSame('ALERT', $h['logger']->getRecords()[0]['level']);
    }

    public function testDoubleCheckNetworkFailureReleasesEventClaimAndReturns500ForRetry(): void
    {
        $h = $this->createHarness();
        $h['orderModel']->seedOrder(202, [
            'order_status_id' => 1,
            'total'           => 40.00,
            'currency_code'   => 'USD',
        ]);

        $map = new InvoiceMap($h['db'], 'oc_');
        $map->record('inv_202', 202, [
            'seq'           => 1,
            'checkout_url'  => 'https://pay.zerokycpay.com/i/inv_202',
            'amount_fiat'   => '40.00',
            'currency_fiat' => 'USD',
            'asset'         => 'USDT_TRON',
            'amount_crypto' => '40.00',
        ]);

        // SDK retries 2 times on NetworkException (1 initial + 2 retries = 3 requests)
        $h['http']->pushException(new NetworkException('Timeout 1'));
        $h['http']->pushException(new NetworkException('Timeout 2'));
        $h['http']->pushException(new NetworkException('Timeout 3'));

        $signed = $this->signPayload([
            'id'              => 'evt_netfail_202',
            'type'            => 'payment.confirmed',
            'invoice_id'      => 'inv_202',
            'order_id'        => '202',
            'status'          => 'paid',
            'received_amount' => '40.00',
            'asset'           => 'USDT_TRON',
        ]);

        $h['request']->server['REQUEST_METHOD']       = 'POST';
        $h['request']->server['HTTP_X_ZKP_SIGNATURE'] = $signed['sig'];
        $h['controller']->setRawBodyOverride($signed['body']);
        $h['controller']->setNowOverride($signed['ts']);

        $h['controller']->callback();

        self::assertSame(500, $h['response']->httpStatus());
        // Event claim was released so retry will succeed
        self::assertArrayNotHasKey('evt_netfail_202', $h['db']->events);

        // Now ZeroKYC retries delivery and GET /invoices succeeds
        $h['response']->headers = [];
        $h['http']->pushJson(200, [
            'id'            => 'inv_202',
            'status'        => 'confirmed',
            'amount'        => '40.00',
            'base_currency' => 'USD',
            'paid_amount'   => '40.00',
            'paid_asset'    => 'USDT_TRON',
        ]);

        $h['controller']->callback();
        self::assertSame(200, $h['response']->httpStatus());
        self::assertSame(2, $h['db']->orders[202]['order_status_id']);
    }
}
