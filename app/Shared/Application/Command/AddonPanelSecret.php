<?php

namespace App\Shared\Application\Command;

use SensitiveParameter;

/** A value shown once after an action, such as a new credential. Only the action's response carries it. */
final readonly class AddonPanelSecret
{
    /** @param string $hint What the administrator must do with it */
    public function __construct(public string $label, public string $hint, #[SensitiveParameter] public string $value) {}
}
