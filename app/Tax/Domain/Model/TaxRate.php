<?php

namespace App\Tax\Domain\Model;

/** One tax's rate in one rate area. A percentage is an exact fraction (0.24 = 24%); a fixed rate is a primary-currency amount. */
final readonly class TaxRate
{
    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    public function __construct(public string $areaId, public string $type, public string $value) {}

    public static function percent(string $areaId, string $fraction): self
    {
        return new self($areaId, self::PERCENT, $fraction);
    }

    public static function fixed(string $areaId, string $amount): self
    {
        return new self($areaId, self::FIXED, $amount);
    }

    public function isPercent(): bool
    {
        return $this->type === self::PERCENT;
    }
}
