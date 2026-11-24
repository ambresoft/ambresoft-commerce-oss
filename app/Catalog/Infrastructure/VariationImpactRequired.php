<?php

namespace App\Catalog\Infrastructure;

use App\Audit\Domain\Contract\ExpectedPreview;
use RuntimeException;

/** A defining-feature change was previewed and rolled back; resubmit with the token to apply it. */
final class VariationImpactRequired extends RuntimeException implements ExpectedPreview
{
    /** @param array<string, mixed> $impact */
    public function __construct(public readonly array $impact, public readonly string $token)
    {
        parent::__construct('variations.confirm_impact');
    }
}
