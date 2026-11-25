<?php

namespace App\Settings\Infrastructure;

final class TaxIds
{
    /** A Greek tax identification number (ΑΦΜ): 9 digits, the last a check digit over the first 8. */
    public static function greek(string $value): bool
    {
        if (preg_match('/^[0-9]{9}$/D', $value) !== 1 || $value === '000000000') {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $value[$i] * 2 ** (8 - $i);
        }

        return $sum % 11 % 10 === (int) $value[8];
    }
}
