<?php

namespace App\Catalog\Domain\Service;

final class ProductIdentifiers
{
    public static function isbn(string $value): bool
    {
        if (preg_match('/^(?:978|979)[0-9]{10}$/D', $value) === 1) {
            return Ean::valid($value);
        }
        if (preg_match('/^[0-9]{9}[0-9X]$/D', $value) !== 1) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += ($value[$i] === 'X' ? 10 : (int) $value[$i]) * (10 - $i);
        }

        return $sum % 11 === 0;
    }

    public static function upc(string $value): bool
    {
        return preg_match('/^[0-9]{12}$/D', $value) === 1 && Ean::valid('0'.$value);
    }
}
