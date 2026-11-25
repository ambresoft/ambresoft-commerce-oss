<?php

namespace App\Catalog\Domain\Service;

final class AvailabilityRules
{
    public const NAME_MAX = 250;

    public static function bucket(int $quantity, bool $trackInventory): string
    {
        return ! $trackInventory || $quantity > 0 ? 'in_stock' : 'out_of_stock';
    }
}
