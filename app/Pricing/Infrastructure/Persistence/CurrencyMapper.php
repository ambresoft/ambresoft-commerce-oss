<?php

namespace App\Pricing\Infrastructure\Persistence;

use App\Pricing\Domain\Model\Currency;
use App\Shared\Domain\Service\Decimal;

final class CurrencyMapper
{
    /** @return array<string, mixed> Plain cacheable row (no PHP objects are unserialized from cache). */
    public function row(CurrencyRecord $record): array
    {
        return ['id' => (string) $record->id, ...$record->only(['code', 'name', 'symbol', 'symbol_position', 'thousands_separator', 'decimal_separator', 'decimals', 'is_primary', 'status', 'lock_version']), 'rate' => Decimal::trim((string) $record->rate)];
    }

    /** @param array<string, mixed> $row */
    public function toDomain(array $row): Currency
    {
        return new Currency((string) $row['id'], $row['code'], $row['name'], $row['symbol'], $row['symbol_position'], $row['thousands_separator'], $row['decimal_separator'], (int) $row['decimals'], $row['rate'], (bool) $row['is_primary'], $row['status'] === 'active', (int) $row['lock_version']);
    }
}
