<?php

namespace App\Shared\Application\Command;

/** A status line on an add-on's Settings page. Both texts are final, already translated. */
final readonly class AddonPanelRow
{
    public function __construct(public string $label, public string $value) {}
}
