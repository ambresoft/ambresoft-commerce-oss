<?php

namespace App\Shared\Application\Command;

use InvalidArgumentException;

/** A button on an add-on's Settings page. Texts are final, already translated. */
final readonly class AddonPanelAction
{
    /**
     * @param  string  $confirm  Asked before running; empty runs at once
     * @param  bool  $destructive  Shown as a dangerous action
     * @param  bool  $inForm  Shown beside the remote form instead of the status lines
     */
    public function __construct(public string $key, public string $label, public string $confirm = '', public bool $destructive = false, public bool $inForm = false)
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Invalid panel action: '.$key);
        }
    }
}
