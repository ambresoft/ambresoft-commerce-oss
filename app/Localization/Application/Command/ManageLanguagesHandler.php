<?php

namespace App\Localization\Application\Command;

use App\Localization\Application\Contract\LanguageAdministrationInterface;

final readonly class ManageLanguagesHandler
{
    public function __construct(private LanguageAdministrationInterface $languages) {}

    /** @param list<string> $active */
    public function configure(string $actorId, array $active, string $default): void
    {
        $this->languages->configure($actorId, $active, $default);
    }

    public function override(string $actorId, string $locale, string $key, ?string $value): void
    {
        $this->languages->override($actorId, $locale, $key, $value);
    }
}
