<?php

namespace App\Catalog\Application\Command;

final readonly class VariationGroupData
{
    /** @param array<string, mixed> $fields Request fields validated by the administration adapter. */
    public function __construct(public array $fields) {}
}
