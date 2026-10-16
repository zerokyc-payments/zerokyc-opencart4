<?php
/**
 * ZeroKYC Pay for OpenCart 2.3 — Atomic Webhook Event Store.
 *
 * Implements ZeroKYC\Webhook\EventStoreInterface backed by `{DB_PREFIX}zerokyc_events`
 * with a primary key on `event_id` so concurrent webhook deliveries are
 * atomically deduplicated at the database level.
 */

declare(strict_types=1);

namespace ZeroKyc\OpenCart;

use ZeroKYC\Idempotency\EventStoreInterface;

final class EventStore implements EventStoreInterface
{
    /** @var object OpenCart \DB instance (query, escape, countAffected) */
    private object $db;

    private string $prefix;

    public function __construct(object $db, string $prefix = '')
    {
        $this->db     = $db;
        $this->prefix = $prefix !== '' ? $prefix : (defined('DB_PREFIX') ? (string) DB_PREFIX : 'oc_');
    }

    public function tableName(): string
    {
        return $this->prefix . 'zerokyc_events';
    }

    /**
     * Creates the `{DB_PREFIX}zerokyc_events` table on extension install.
     */
    public function install(): void
    {
        $table = $this->tableName();
        $sql   = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `event_id` VARCHAR(64) NOT NULL,
            `invoice_id` VARCHAR(64) NOT NULL DEFAULT '',
            `event_type` VARCHAR(48) NOT NULL DEFAULT '',
            `received_at` DATETIME NOT NULL,
            `processed_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`event_id`),
            KEY `idx_invoice_id` (`invoice_id`),
            KEY `idx_received_at` (`received_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $this->db->query($sql);
    }

    /**
     * Atomically claims an event ID using INSERT IGNORE.
     *
     * Returns true iff this process inserted the row for the first time.
     * Returns false if the event was already claimed by a prior or concurrent delivery.
     */
    public function claim(string $eventId, ?string $invoiceId = '', string $eventType = ''): bool
    {
        if ($eventId === '') {
            return false;
        }

        $table = $this->tableName();
        $now   = gmdate('Y-m-d H:i:s');

        $this->db->query(sprintf(
            "INSERT IGNORE INTO `%s` (`event_id`, `invoice_id`, `event_type`, `received_at`) VALUES ('%s', '%s', '%s', '%s')",
            $table,
            $this->db->escape($eventId),
            $this->db->escape((string) $invoiceId),
            $this->db->escape($eventType),
            $this->db->escape($now)
        ));

        return (int) $this->db->countAffected() === 1;
    }

    public function has(string $eventId): bool
    {
        if ($eventId === '') {
            return false;
        }

        $table  = $this->tableName();
        $result = $this->db->query(sprintf(
            "SELECT `event_id` FROM `%s` WHERE `event_id` = '%s' LIMIT 1",
            $table,
            $this->db->escape($eventId)
        ));

        return !empty($result->num_rows);
    }

    public function markProcessed(string $eventId): void
    {
        if ($eventId === '') {
            return;
        }

        $table = $this->tableName();
        $now   = gmdate('Y-m-d H:i:s');

        // Ensure the row exists if markProcessed() was called directly via SDK EventStoreInterface
        $this->db->query(sprintf(
            "INSERT IGNORE INTO `%s` (`event_id`, `invoice_id`, `event_type`, `received_at`, `processed_at`) VALUES ('%s', '', '', '%s', '%s')",
            $table,
            $this->db->escape($eventId),
            $this->db->escape($now),
            $this->db->escape($now)
        ));

        $this->db->query(sprintf(
            "UPDATE `%s` SET `processed_at` = '%s' WHERE `event_id` = '%s'",
            $table,
            $this->db->escape($now),
            $this->db->escape($eventId)
        ));
    }

    /**
     * Releases a claimed event row if downstream processing failed with an exception
     * so ZeroKYC's webhook retry can re-deliver it cleanly.
     */
    public function release(string $eventId): void
    {
        if ($eventId === '') {
            return;
        }

        $table = $this->tableName();
        $this->db->query(sprintf(
            "DELETE FROM `%s` WHERE `event_id` = '%s' AND `processed_at` IS NULL",
            $table,
            $this->db->escape($eventId)
        ));
    }

    /**
     * Deletes events older than $days days to keep the table bounded.
     */
    public function cleanup(int $days = 90): int
    {
        $days   = max(1, $days);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        $table  = $this->tableName();

        $this->db->query(sprintf(
            "DELETE FROM `%s` WHERE `received_at` < '%s'",
            $table,
            $this->db->escape($cutoff)
        ));

        return (int) $this->db->countAffected();
    }
}
