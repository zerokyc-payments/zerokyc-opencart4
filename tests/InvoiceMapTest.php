<?php

declare(strict_types=1);

namespace ZeroKyc\OpenCart\Tests;

use PHPUnit\Framework\TestCase;
use ZeroKyc\OpenCart\InvoiceMap;
use ZeroKyc\OpenCart\Tests\Support\FakeDb;

final class InvoiceMapTest extends TestCase
{
    public function testRecordLookupSequenceAndNotes(): void
    {
        $db  = new FakeDb();
        $map = new InvoiceMap($db, 'oc_');
        $map->install();

        self::assertSame(1, $map->nextAttemptSeq(50));

        $map->record('inv_50_1', 50, [
            'seq'           => 1,
            'checkout_url'  => 'https://pay.zerokycpay.com/i/inv_50_1',
            'amount_fiat'   => '100.00',
            'currency_fiat' => 'USD',
            'asset'         => 'USDT_TRON',
            'amount_crypto' => '100.12',
        ]);

        self::assertSame(50, $map->findOrderId('inv_50_1'));
        self::assertSame(2, $map->nextAttemptSeq(50));

        $map->record('inv_50_2', 50, [
            'seq'           => 2,
            'checkout_url'  => 'https://pay.zerokycpay.com/i/inv_50_2',
            'amount_fiat'   => '120.00',
            'currency_fiat' => 'USD',
            'asset'         => 'USDT_TRON',
            'amount_crypto' => '120.15',
        ]);

        $latest = $map->findLatestForOrder(50);
        self::assertNotNull($latest);
        self::assertSame('inv_50_2', $latest['invoice_id']);
        self::assertSame(3, $map->nextAttemptSeq(50));

        self::assertFalse($map->hasNoteBeenSent('inv_50_2', 'confirmed:inv_50_2'));
        $map->markNoteSent('inv_50_2', 'confirmed:inv_50_2');
        self::assertTrue($map->hasNoteBeenSent('inv_50_2', 'confirmed:inv_50_2'));

        $map->markPaid('inv_50_2', 'USDT_TRON', '120.15', '0xtx123');
        $updated = $map->findByInvoiceId('inv_50_2');
        self::assertNotNull($updated);
        self::assertSame('0xtx123', $updated['paid_txid']);
    }
}
