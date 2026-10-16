<?php
/**
 * ZeroKYC Pay for OpenCart 2.3 — Invoice-to-Order Map & Metadata Store.
 *
 * Stores invoice-to-order mappings, attempt sequence counters, expected crypto/fiat
 * amounts, and sent order-history note keys in `{DB_PREFIX}zerokyc_invoices`.
 */

declare(strict_types=1);

namespace ZeroKyc\OpenCart;

final class InvoiceMap
{
    /** @var object OpenCart \DB instance */
    private object $db;

    private string $prefix;

    public function __construct(object $db, string $prefix = '')
    {
        $this->db     = $db;
        $this->prefix = $prefix !== '' ? $prefix : (defined('DB_PREFIX') ? (string) DB_PREFIX : 'oc_');
    }

    public function tableName(): string
    {
        return $this->prefix . 'zerokyc_invoices';
    }

    /**
     * Creates `{DB_PREFIX}zerokyc_invoices` on extension install.
     */
    public function install(): void
    {
        $table = $this->tableName();
        $sql   = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `invoice_id` VARCHAR(64) NOT NULL,
            `order_id` INT UNSIGNED NOT NULL,
            `seq` INT UNSIGNED NOT NULL DEFAULT 1,
            `checkout_url` TEXT NOT NULL,
            `amount_fiat` VARCHAR(32) NOT NULL DEFAULT '',
            `currency_fiat` VARCHAR(16) NOT NULL DEFAULT '',
            `asset` VARCHAR(32) NOT NULL DEFAULT '',
            `amount_crypto` VARCHAR(64) NOT NULL DEFAULT '',
            `paid_asset` VARCHAR(32) NOT NULL DEFAULT '',
            `paid_amount` VARCHAR(64) NOT NULL DEFAULT '',
            `paid_txid` VARCHAR(191) NOT NULL DEFAULT '',
            `notes_sent` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`invoice_id`),
            KEY `idx_order_id` (`order_id`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $this->db->query($sql);
    }

    /**
     * Records a newly created (or updated) invoice for an OpenCart order.
     *
     * @param array{
     *   seq?: int,
     *   checkout_url?: string,
     *   amount_fiat?: string,
     *   currency_fiat?: string,
     *   asset?: string,
     *   amount_crypto?: string
     * } $data
     */
    public function record(string $invoiceId, int $orderId, array $data = []): void
    {
        if ($invoiceId === '' || $orderId <= 0) {
            return;
        }

        $table         = $this->tableName();
        $seq           = isset($data['seq']) ? max(1, (int) $data['seq']) : 1;
        $checkoutUrl   = (string) ($data['checkout_url'] ?? '');
        $amountFiat    = (string) ($data['amount_fiat'] ?? '');
        $currencyFiat  = (string) ($data['currency_fiat'] ?? '');
        $asset         = (string) ($data['asset'] ?? '');
        $amountCrypto  = (string) ($data['amount_crypto'] ?? '');
        $now           = gmdate('Y-m-d H:i:s');

        $this->db->query(sprintf(
            "INSERT INTO `%s` (`invoice_id`, `order_id`, `seq`, `checkout_url`, `amount_fiat`, `currency_fiat`, `asset`, `amount_crypto`, `notes_sent`, `created_at`)
             VALUES ('%s', %d, %d, '%s', '%s', '%s', '%s', '%s', '[]', '%s')
             ON DUPLICATE KEY UPDATE
               `order_id` = VALUES(`order_id`),
               `seq` = VALUES(`seq`),
               `checkout_url` = VALUES(`checkout_url`),
               `amount_fiat` = VALUES(`amount_fiat`),
               `currency_fiat` = VALUES(`currency_fiat`),
               `asset` = VALUES(`asset`),
               `amount_crypto` = VALUES(`amount_crypto`)",
            $table,
            $this->db->escape($invoiceId),
            $orderId,
            $seq,
            $this->db->escape($checkoutUrl),
            $this->db->escape($amountFiat),
            $this->db->escape($currencyFiat),
            $this->db->escape($asset),
            $this->db->escape($amountCrypto),
            $this->db->escape($now)
        ));
    }

    /**
     * Resolves an OpenCart order ID by ZeroKYC invoice ID.
     */
    public function findOrderId(string $invoiceId): ?int
    {
        $row = $this->findByInvoiceId($invoiceId);
        if ($row === null) {
            return null;
        }
        return (int) $row['order_id'];
    }

    /**
     * Returns the full invoice row for a given invoice ID, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function findByInvoiceId(string $invoiceId): ?array
    {
        if ($invoiceId === '') {
            return null;
        }

        $table  = $this->tableName();
        $result = $this->db->query(sprintf(
            "SELECT * FROM `%s` WHERE `invoice_id` = '%s' LIMIT 1",
            $table,
            $this->db->escape($invoiceId)
        ));

        if (empty($result->num_rows) || empty($result->row)) {
            return null;
        }

        return $result->row;
    }

    /**
     * Returns the most recent invoice row for an OpenCart order.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $table  = $this->tableName();
        $result = $this->db->query(sprintf(
            "SELECT * FROM `%s` WHERE `order_id` = %d ORDER BY `seq` DESC, `created_at` DESC LIMIT 1",
            $table,
            $orderId
        ));

        if (empty($result->num_rows) || empty($result->row)) {
            return null;
        }

        return $result->row;
    }

    /**
     * Computes the next monotonically increasing attempt sequence number for an order.
     */
    public function nextAttemptSeq(int $orderId): int
    {
        $latest = $this->findLatestForOrder($orderId);
        if ($latest === null) {
            return 1;
        }
        return max(1, (int) ($latest['seq'] ?? 0)) + 1;
    }

    /**
     * Stores confirmed payment audit details on the invoice record.
     */
    public function markPaid(string $invoiceId, string $paidAsset, string $paidAmount, string $txId): void
    {
        if ($invoiceId === '') {
            return;
        }

        $table = $this->tableName();
        $this->db->query(sprintf(
            "UPDATE `%s` SET `paid_asset` = '%s', `paid_amount` = '%s', `paid_txid` = '%s' WHERE `invoice_id` = '%s'",
            $table,
            $this->db->escape($paidAsset),
            $this->db->escape($paidAmount),
            $this->db->escape($txId),
            $this->db->escape($invoiceId)
        ));
    }

    /**
     * Checks whether a given order history note key has already been sent for this invoice.
     */
    public function hasNoteBeenSent(string $invoiceId, string $noteKey): bool
    {
        $row = $this->findByInvoiceId($invoiceId);
        if ($row === null) {
            return false;
        }

        $notes = $this->decodeNotes((string) ($row['notes_sent'] ?? '[]'));
        return in_array($noteKey, $notes, true);
    }

    /**
     * Records a note key so repeated webhooks/cron runs do not spam duplicate order comments.
     */
    public function markNoteSent(string $invoiceId, string $noteKey): void
    {
        $row = $this->findByInvoiceId($invoiceId);
        if ($row === null) {
            return;
        }

        $notes = $this->decodeNotes((string) ($row['notes_sent'] ?? '[]'));
        if (!in_array($noteKey, $notes, true)) {
            $notes[] = $noteKey;
        }

        $encoded = json_encode(array_values($notes), JSON_UNESCAPED_SLASHES);
        $table   = $this->tableName();

        $this->db->query(sprintf(
            "UPDATE `%s` SET `notes_sent` = '%s' WHERE `invoice_id` = '%s'",
            $table,
            $this->db->escape(is_string($encoded) ? $encoded : '[]'),
            $this->db->escape($invoiceId)
        ));
    }

    /**
     * Finds recent invoices (last $hoursBack hours) whose orders are still in an open/unconfirmed status.
     *
     * @param list<int> $openStatusIds Order status IDs considered open (e.g. [0, pending, confirming])
     * @return list<array{order_id: int, invoice_id: string}>
     */
    public function findOpenInvoices(array $openStatusIds, int $hoursBack = 24, int $limit = 50): array
    {
        $statusList = array_values(array_unique(array_map('intval', $openStatusIds)));
        if ($statusList === []) {
            $statusList = [0];
        }

        $cutoff     = gmdate('Y-m-d H:i:s', time() - (max(1, $hoursBack) * 3600));
        $limit      = max(1, min(200, $limit));
        $invTable   = $this->tableName();
        $orderTable = $this->prefix . 'order';
        $inClause   = implode(',', $statusList);

        $sql = sprintf(
            "SELECT i.`order_id`, i.`invoice_id`
             FROM `%s` i
             INNER JOIN `%s` o ON o.`order_id` = i.`order_id`
             WHERE i.`created_at` >= '%s'
               AND o.`order_status_id` IN (%s)
               AND i.`seq` = (
                   SELECT MAX(i2.`seq`) FROM `%s` i2 WHERE i2.`order_id` = i.`order_id`
               )
             ORDER BY i.`created_at` DESC
             LIMIT %d",
            $invTable,
            $orderTable,
            $this->db->escape($cutoff),
            $inClause,
            $invTable,
            $limit
        );

        $result = $this->db->query($sql);
        if (empty($result->rows) || !is_array($result->rows)) {
            return [];
        }

        $items = [];
        foreach ($result->rows as $row) {
            $items[] = [
                'order_id'   => (int) $row['order_id'],
                'invoice_id' => (string) $row['invoice_id'],
            ];
        }
        return $items;
    }

    /**
     * @return list<string>
     */
    private function decodeNotes(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_map('strval', $decoded));
    }
}
