<?php

namespace App\Order\Domain\Service;

/**
 * How order statuses move product stock. Each line remembers the units it has taken (deducted quantity), so every
 * adjustment is a difference against that record: re-saving, repeating a status or editing quantities never takes
 * stock twice, and a restore returns exactly what was taken.
 */
final class OrderStockRules
{
    public const NONE = 'none';

    public const DECREASE = 'decrease';

    public const RESTORE = 'restore';

    public const ACTIONS = [self::NONE, self::DECREASE, self::RESTORE];

    /** Whether the order holds stock after entering (or staying in) a status with this action. "none" keeps what it had. */
    public function holds(string $action, bool $held): bool
    {
        return $action === self::DECREASE || ($action === self::NONE && $held);
    }

    /** Units a line should have taken from stock in that state. */
    public function target(string $action, bool $held, int $quantity): int
    {
        return $this->holds($action, $held) ? $quantity : 0;
    }
}
