<?php

namespace App\Tax\Application\Command;

final readonly class SaveTaxRateData
{
    public function __construct(public string $areaId, public string $type, public string $value) {}
}
