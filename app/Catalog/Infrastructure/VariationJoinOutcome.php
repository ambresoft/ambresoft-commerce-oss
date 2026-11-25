<?php

namespace App\Catalog\Infrastructure;

/** Request-scoped result of the Create-variation join attempted by the last product save. */
final class VariationJoinOutcome
{
    /** @var array{status: string, group?: string, name?: string, problems?: list<string>} */
    public array $outcome = ['status' => 'none'];
}
