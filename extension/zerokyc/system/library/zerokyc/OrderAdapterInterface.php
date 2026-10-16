<?php
/**
 * ZeroKYC Pay for OpenCart 2.3 — Order Adapter Interface.
 *
 * Abstracts OpenCart's ModelCheckoutOrder operations for clean unit testing
 * and consistent status transitions across webhooks, checkout, and cron.
 */

declare(strict_types=1);

namespace ZeroKyc\OpenCart;

interface OrderAdapterInterface
{
    /**
     * Fetches an OpenCart order row by ID, or null if not found.
     *
     * Expected keys:
     * - order_id (int)
     * - order_status_id (int)
     * - total (float|string)
     * - currency_code (string)
     * - currency_value (float|string)
     *
     * @return array<string, mixed>|null
     */
    public function getOrder(int $orderId): ?array;

    /**
     * Appends an order history entry and updates the order status.
     */
    public function addOrderHistory(int $orderId, int $orderStatusId, string $comment = '', bool $notify = false): void;
}
