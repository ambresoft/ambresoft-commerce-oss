<?php

namespace App\Catalog\Domain\Service;

final class Ean
{
    public static function valid(string $value): bool
    {
        if (! preg_match('/^(?:[0-9]{8}|[0-9]{13})$/D', $value)) {
            return false;
        }
        $sum = 0;
        $weight = 3;
        for ($i = strlen($value) - 2; $i >= 0; $i--) {
            $sum += (int) $value[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - $sum % 10) % 10 === (int) $value[strlen($value) - 1];
    }
}
