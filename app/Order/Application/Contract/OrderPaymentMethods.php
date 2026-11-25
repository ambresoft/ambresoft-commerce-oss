<?php

namespace App\Order\Application\Contract;

/**
 * The payment methods an order may use, answered by the Payment context. Orders store the chosen method and its
 * name at that time; payment records and payment status belong to Payment.
 */
interface OrderPaymentMethods
{
    /**
     * Methods staff can assign to a new or edited order in this currency.
     *
     * @return list<array{id: string, name: string}>
     */
    public function choices(string $currency, int $decimals, string $locale): array;

    /** The method's name in $locale when it can be assigned to an order with this total, otherwise null. */
    public function assignable(string $methodId, string $total, string $currency, int $decimals, string $locale): ?string;

    /** Whether recorded or in-progress payments fix the order's method. */
    public function locked(string $orderId): bool;
}
