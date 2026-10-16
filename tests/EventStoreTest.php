<?php

declare(strict_types=1);

namespace ZeroKyc\OpenCart\Tests;

use PHPUnit\Framework\TestCase;
use ZeroKyc\OpenCart\EventStore;
use ZeroKyc\OpenCart\Tests\Support\FakeDb;

final class EventStoreTest extends TestCase
{
    public function testAtomicClaimAndDuplicateRejection(): void
    {
        $db    = new FakeDb();
        $store = new EventStore($db, 'oc_');
        $store->install();

        self::assertFalse($store->has('evt_abc'));
        self::assertTrue($store->claim('evt_abc', 'inv_1', 'payment.confirmed'));
        self::assertTrue($store->has('evt_abc'));

        // Duplicate delivery must return false
        self::assertFalse($store->claim('evt_abc', 'inv_1', 'payment.confirmed'));

        $store->markProcessed('evt_abc');
        self::assertNotNull($db->events['evt_abc']['processed_at']);
    }

    public function testReleaseAllowsRetryWhenUnprocessed(): void
    {
        $db    = new FakeDb();
        $store = new EventStore($db, 'oc_');

        self::assertTrue($store->claim('evt_retry', 'inv_2', 'payment.confirmed'));
        $store->release('evt_retry');
        self::assertFalse($store->has('evt_retry'));
        self::assertTrue($store->claim('evt_retry', 'inv_2', 'payment.confirmed'));
    }

    public function testCleanupDeletesOldEvents(): void
    {
        $db    = new FakeDb();
        $store = new EventStore($db, 'oc_');

        $db->events['evt_old'] = [
            'event_id'     => 'evt_old',
            'invoice_id'   => 'inv_old',
            'event_type'   => 'payment.confirmed',
            'received_at'  => '2020-01-01 00:00:00',
            'processed_at' => '2020-01-01 00:00:05',
        ];
        $store->claim('evt_fresh', 'inv_fresh', 'payment.confirmed');

        $deleted = $store->cleanup(90);
        self::assertSame(1, $deleted);
        self::assertFalse($store->has('evt_old'));
        self::assertTrue($store->has('evt_fresh'));
    }
}
