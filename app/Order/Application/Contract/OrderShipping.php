<?php

namespace App\Order\Application\Contract;

/**
 * Shipping methods and charges for orders, answered by the Shipping context. Orders store the chosen method, its
 * name at that time, the charge (taxed through order pricing) and a calculation snapshot; shipments belong to Shipping.
 */
interface OrderShipping
{
    /**
     * Active methods staff can choose, with the payment methods each one allows (empty = any).
     *
     * @return list<array{id: string, name: string, calculation: string, payment_method_ids: list<string>}>
     */
    public function choices(string $locale): array;

    /**
     * The server-calculated charge of a method for an order. `current` allows recalculating the order's existing
     * method even if it was disabled since. Carrier quotes may call the carrier: call this outside transactions.
     *
     * @param  array{lines: list<array{product_id: ?string, quantity: int}>, address: ?array<string, mixed>, currency: string, decimals: int, customer_id: ?string, payment_method_id: ?string, subtotal: string}  $order
     * @return array{available: bool, reason: ?string, message: ?string, amount: string, tax_ids: list<string>, name: string, details: array<string, mixed>}
     */
    public function quote(string $methodId, array $order, string $locale, bool $current = false): array;

    /** Whether the method may be combined with the payment method (or with none). */
    public function allowsPayment(string $methodId, ?string $paymentMethodId): bool;
}
