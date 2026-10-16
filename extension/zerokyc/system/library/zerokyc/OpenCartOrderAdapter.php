<?php
/**
 * ZeroKYC Pay for OpenCart 2.3 — Production OpenCart Order Adapter.
 */

declare(strict_types=1);

namespace ZeroKyc\OpenCart;

final class OpenCartOrderAdapter implements OrderAdapterInterface
{
    /** @var object OpenCart ModelCheckoutOrder instance */
    private object $checkoutOrderModel;

    public function __construct(object $checkoutOrderModel)
    {
        $this->checkoutOrderModel = $checkoutOrderModel;
    }

    public function getOrder(int $orderId): ?array
    {
        $order = $this->checkoutOrderModel->getOrder($orderId);
        if (!is_array($order) || empty($order['order_id'])) {
            return null;
        }
        return $order;
    }

    public function addOrderHistory(int $orderId, int $orderStatusId, string $comment = '', bool $notify = false): void
    {
        $this->checkoutOrderModel->addOrderHistory($orderId, $orderStatusId, $comment, $notify);
    }
}
