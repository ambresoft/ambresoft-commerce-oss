<?php

namespace App\Order\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Customers cancelling their own order. Allowed while the order is in a status marked *Customer can cancel* (by
 * default Open and Processing, never once it ships) and nothing has been paid or is being paid, since the store has no
 * refunds yet. The order moves to the active `cancelled` status through the ordinary status change, so stock, coupons
 * and the status e-mail follow as when staff cancel it.
 */
final class OrderCancellation
{
    public const LINK_DAYS = 30;

    /** Payment states that mean money arrived or may still arrive. */
    private const PAID = ['captured', 'pending', 'processing'];

    public function __construct(private OrderAdministration $orders) {}

    public function allowed(string $orderId): bool
    {
        $order = DB::table('orders')->where('id', $orderId)->first(['id', 'status_id']);

        return $order !== null && $this->reason($order) === null;
    }

    /** Cancels the order; customer orders only for that customer. Repeating it on a cancelled order changes nothing. */
    public function cancel(string $orderId, ?string $customerId = null): void
    {
        DB::transaction(function () use ($orderId, $customerId): void {
            $order = DB::table('orders')->where('id', $orderId)->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))->lockForUpdate()->first(['id', 'status_id'])
                ?? abort(404);
            if ((string) $order->status_id === $this->target()) {
                return;
            }
            if (($reason = $this->reason($order)) !== null) {
                throw ValidationException::withMessages(['order' => __('orders.cancel_'.$reason)]);
            }
            $this->orders->changeStatus(null, $orderId, null, (string) $this->target(), __('orders.cancelled_by_customer'), 'customer');
        });
    }

    /** The signed link that lets the recipient of an order e-mail cancel the order (guests have no account). */
    public function url(string $orderId): string
    {
        return URL::temporarySignedRoute('orders.cancel', now()->addDays(self::LINK_DAYS), ['order' => $orderId]);
    }

    /** Why the order cannot be cancelled now (a translation key suffix), or null. */
    private function reason(object $order): ?string
    {
        $target = $this->target();
        if ($target === null || (string) $order->status_id === $target || ! DB::table('order_statuses')->where('id', $order->status_id)->value('customer_can_cancel')) {
            return 'not_allowed';
        }

        return DB::table('order_payments')->where('order_id', $order->id)->whereIn('state', self::PAID)->exists() ? 'paid' : null;
    }

    private function target(): ?string
    {
        $id = DB::table('order_statuses')->where('code', 'cancelled')->where('is_active', true)->value('id');

        return $id === null ? null : (string) $id;
    }
}
