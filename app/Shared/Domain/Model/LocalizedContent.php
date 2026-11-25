<?php

namespace App\Shared\Domain\Model;

final readonly class LocalizedContent
{
    /** @param array<string, string|null> $fields Null means absent; empty string means intentionally empty. */
    public function __construct(public string $locale, public array $fields) {}
}
