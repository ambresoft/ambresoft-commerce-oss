<?php

namespace App\Shared\Domain\Contract;

use App\Shared\Domain\Model\LocalizedContent;

interface Translatable
{
    /** Return the exact locale only; no hidden fallback in persistence. */
    public function translation(string $locale): ?LocalizedContent;
}
