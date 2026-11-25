<?php

namespace App\Shared\Application\Contract;

use App\Shared\Application\Command\SettingField;

/**
 * An add-on's own merchant settings (manifest contribution `settings`). The CMS stores them (secrets encrypted, never
 * sent to the browser), renders the editor behind the add-on's Settings button and hands the values back through
 * AddonSettingValues.
 */
interface AddonSettings
{
    /** @return list<SettingField> */
    public function settings(): array;

    /**
     * Rules across fields, after the CMS has checked each field's type and range.
     *
     * @param  array<string, string>  $values
     * @return array<string, string> Field key => localized message
     */
    public function validateSettings(array $values): array;
}
