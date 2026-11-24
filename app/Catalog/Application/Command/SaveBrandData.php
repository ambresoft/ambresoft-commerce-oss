<?php

namespace App\Catalog\Application\Command;

use App\Shared\Application\Command\ImageUpload;

final readonly class SaveBrandData
{
    /** @param array<string,mixed> $fields
     * @param list<string> $stagedFiles Trusted upload IDs from the private file staging service. */
    public function __construct(public array $fields, public ?ImageUpload $image = null, public ?ImageUpload $bannerImage = null, public array $stagedFiles = []) {}
}
