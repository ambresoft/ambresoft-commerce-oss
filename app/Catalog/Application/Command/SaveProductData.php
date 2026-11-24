<?php

namespace App\Catalog\Application\Command;

final readonly class SaveProductData
{
    /** @param array<string, mixed> $fields */
    public function __construct(public int $version, public array $fields) {}
}
