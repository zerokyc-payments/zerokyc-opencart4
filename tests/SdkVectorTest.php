<?php

declare(strict_types=1);

namespace ZeroKyc\OpenCart\Tests;

use PHPUnit\Framework\TestCase;
use ZeroKYC\Idempotency\IdempotencyKey;
use ZeroKYC\Webhook\ReplayGuard;
use ZeroKYC\Webhook\WebhookEvent;
use ZeroKYC\Webhook\WebhookVerifier;
use ZeroKyc\OpenCart\EventStore;
use ZeroKyc\OpenCart\Tests\Support\FakeDb;

final class SdkVectorTest extends TestCase
{
    public function testCrossSdkWebhookSignatureVector(): void
    {
        $secret = 'whsec_zkp_test_vector_2026';
        $body   = '{"id":"evt_test_001","type":"payment.confirmed","invoice_id":"inv_test_001"}';
        $sig    = 't=1788788073,v1=ade537fa13aec79a6d1648bd7f197872066c161676c389243ab5c6b13fea7f52';

        $verifier = new WebhookVerifier($secret, 300);
        $event    = $verifier->verify($body, $sig, 1788788073);

        self::assertSame('evt_test_001', $event->id);
        self::assertSame('payment.confirmed', $event->type);
        self::assertSame('inv_test_001', $event->invoiceId);
    }

    public function testIdempotencyKeyDeterministicGeneration(): void
    {
        $key1 = IdempotencyKey::make('opencart', 'order', '104:1');
        $key2 = IdempotencyKey::make('opencart', 'order', '104:1');
        $key3 = IdempotencyKey::make('opencart', 'order', '104:2');

        self::assertSame($key1, $key2);
        self::assertNotSame($key1, $key3);
        self::assertSame('zerokyc:opencart:order:104:1', $key1);
    }

    public function testReplayGuardMatchesOrderConstraints(): void
    {
        $event = WebhookEvent::fromArray([
            'id'         => 'evt_1',
            'type'       => 'payment.confirmed',
            'invoice_id' => 'inv_100',
            'data'       => [
                'amount' => '50.00',
                'asset'  => 'USDT_TRON',
            ],
        ]);

        $guard = new ReplayGuard(new EventStore(new FakeDb(), 'oc_'));

        self::assertTrue($guard->matchesOrder($event, 'inv_100', '50.00', 'USDT_TRON'));
        self::assertFalse($guard->matchesOrder($event, 'inv_999', '50.00', 'USDT_TRON'));
        self::assertFalse($guard->matchesOrder($event, 'inv_100', '55.00', 'USDT_TRON'));
        self::assertFalse($guard->matchesOrder($event, 'inv_100', '50.00', 'USDT_BSC'));
    }
}
