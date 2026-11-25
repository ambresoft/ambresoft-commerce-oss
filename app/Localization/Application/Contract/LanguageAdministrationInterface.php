<?php

namespace App\Localization\Application\Contract;

interface LanguageAdministrationInterface
{
    /** @param list<string> $active */
    public function configure(string $actorId, array $active, string $default): void;

    public function override(string $actorId, string $locale, string $key, ?string $value): void;
}
