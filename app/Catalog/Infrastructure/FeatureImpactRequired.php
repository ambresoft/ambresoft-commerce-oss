<?php

namespace App\Catalog\Infrastructure;

use App\Audit\Domain\Contract\ExpectedPreview;
use RuntimeException;

final class FeatureImpactRequired extends RuntimeException implements ExpectedPreview
{
    /** @param array<string, int|string> $impact */
    public function __construct(public readonly array $impact, public readonly string $token)
    {
        parent::__construct('features.confirm_impact');
    }
}
