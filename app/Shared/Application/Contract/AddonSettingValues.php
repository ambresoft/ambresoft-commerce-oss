<?php

namespace App\Shared\Application\Contract;

/** Reads an add-on's saved settings (declared defaults for fields never saved). */
interface AddonSettingValues
{
    /** @return array<string, string> */
    public function values(string $addon): array;
}
