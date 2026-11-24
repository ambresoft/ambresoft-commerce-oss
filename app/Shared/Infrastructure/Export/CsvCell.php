<?php

namespace App\Shared\Infrastructure\Export;

use DateTimeInterface;

final class CsvCell
{
    /** Booleans match the import vocabulary; dates are ISO; formulas are neutralised so spreadsheets never execute them. */
    public static function text(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            default => (string) $value,
        };

        return preg_match('/^[\s]*[=+@-]/u', $text) === 1 ? "'".$text : $text;
    }
}
