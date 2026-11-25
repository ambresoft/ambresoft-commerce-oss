<?php

namespace App\Catalog\Application\Command;

use App\Shared\Application\Command\ImageUpload;

final readonly class SaveFeatureData
{
    /**
     * $image is the landing-page image; $swatchImage is the separate variation Image swatch.
     *
     * @param  array<string, mixed>  $fields
     */
    public function __construct(public array $fields, public ?ImageUpload $image = null, public ?ImageUpload $swatchImage = null) {}
}
