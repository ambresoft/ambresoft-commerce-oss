<?php

namespace App\Shared\Application\Contract;

/** Whether an add-on is enabled right now. A provider outlives a disable in a long-running process, so its routes ask. */
interface AddonStatus
{
    public function isEnabled(string $name): bool;
}
