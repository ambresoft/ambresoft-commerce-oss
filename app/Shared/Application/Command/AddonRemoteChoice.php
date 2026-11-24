<?php

namespace App\Shared\Application\Command;

/** One card of an add-on's remote form, with the fields it reveals. Texts are final, already translated. */
final readonly class AddonRemoteChoice
{
    /**
     * @param  list<SettingField>  $fields  Their labels and hints are translation keys, like any setting
     * @param  array<string, string>  $values  Current values. For a secret field, any value only means one is set
     * @param  string  $guide  Longer help shown under the fields; empty for none
     * @param  string  $icon  A `data:image/` URI for the card; anything else shows none
     */
    public function __construct(public string $value, public string $label, public string $description, public array $fields, public array $values = [], public string $guide = '', public string $icon = '') {}
}
