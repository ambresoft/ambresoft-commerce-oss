<?php

namespace App\Payment\Domain\Service;

use App\Shared\Domain\Service\Decimal;

/**
 * Payment state rules, independent of the order status. Attempts move created → processing → captured / pending /
 * failed / cancelled (pending → captured / failed); captured, failed and cancelled are final. An order is paid when
 * captured money covers its total, partially paid when some is captured, pending when a capture awaits the provider.
 */
final class PaymentRules
{
    public const STATES = ['created', 'processing', 'pending', 'captured', 'failed', 'cancelled'];

    /** Online attempts that are still waiting for the customer or being confirmed. */
    public const OPEN = ['created', 'processing'];

    public const ORDER_STATUSES = ['unpaid', 'pending', 'partially_paid', 'paid'];

    private const MOVES = [
        'created' => ['processing', 'pending', 'captured', 'failed', 'cancelled'],
        'processing' => ['created', 'pending', 'captured', 'failed', 'cancelled'],
        'pending' => ['captured', 'failed'],
        'captured' => [],
        'failed' => [],
        'cancelled' => [],
    ];

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::MOVES[$from] ?? [], true);
    }

    /** @param iterable<array{state: string, amount: string}> $payments */
    public static function captured(iterable $payments): string
    {
        $sum = '0';
        foreach ($payments as $payment) {
            if ($payment['state'] === 'captured') {
                $sum = Decimal::add($sum, $payment['amount']);
            }
        }

        return Decimal::trim($sum);
    }

    /** @param iterable<array{state: string, amount: string}> $payments */
    public static function outstanding(iterable $payments, string $total): string
    {
        $left = Decimal::subtract($total, self::captured($payments));

        return Decimal::compare($left, '0') > 0 ? Decimal::trim($left) : '0';
    }

    /** @param list<array{state: string, amount: string}> $payments */
    public static function orderStatus(array $payments, string $total): string
    {
        $captured = self::captured($payments);
        if (Decimal::compare($captured, '0') > 0) {
            return Decimal::compare($captured, $total) >= 0 ? 'paid' : 'partially_paid';
        }
        foreach ($payments as $payment) {
            if ($payment['state'] === 'pending') {
                return 'pending';
            }
        }

        return 'unpaid';
    }
}
