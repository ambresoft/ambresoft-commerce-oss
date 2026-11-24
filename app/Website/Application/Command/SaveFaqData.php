<?php

namespace App\Website\Application\Command;

final readonly class SaveFaqData
{
    /** @param array<string, array{question: string, answer: string}> $translations */
    public function __construct(public string $type, public string $status, public int $position, public array $translations, public ?int $version = null) {}
}
