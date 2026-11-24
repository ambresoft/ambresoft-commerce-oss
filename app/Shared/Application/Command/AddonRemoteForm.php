<?php

namespace App\Shared\Application\Command;

/** A section of an add-on's Settings page whose values belong to another system. Texts are final, already translated. */
final readonly class AddonRemoteForm
{
    /**
     * @param  list<AddonRemoteChoice>  $choices  Shown as cards: one is chosen, and its fields appear
     * @param  string  $selected  The value of the choice in use, or empty
     * @param  list<AddonPanelRow>  $rows  Status lines
     */
    public function __construct(public string $title, public string $hint, public array $choices, public string $selected = '', public array $rows = []) {}
}
