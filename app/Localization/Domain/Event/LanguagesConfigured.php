<?php

namespace App\Localization\Domain\Event;

final readonly class LanguagesConfigured
{
    public function __construct(public string $actorId) {}
}
