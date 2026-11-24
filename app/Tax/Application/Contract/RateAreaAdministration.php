<?php

namespace App\Tax\Application\Contract;

use App\Tax\Application\Command\SaveRateAreaData;

interface RateAreaAdministration
{
    public function create(string $actor, SaveRateAreaData $data): string;

    public function update(string $actor, string $id, int $version, SaveRateAreaData $data): void;

    /** Refused while any tax rate uses the area. */
    public function delete(string $actor, string $id, int $version): void;
}
