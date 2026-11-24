<?php

namespace App\Shared\Application\Command;

// Trusted upload adapter supplies its temporary file, never a browser-supplied path.
final readonly class ImageUpload
{
    public function __construct(public string $temporaryPath) {}
}
