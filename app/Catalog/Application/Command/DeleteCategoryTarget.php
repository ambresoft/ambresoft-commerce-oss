<?php

namespace App\Catalog\Application\Command;

final readonly class DeleteCategoryTarget
{
    public function __construct(public string $id, public int $version) {}
}
