<?php

namespace App\Search\Application\Command;

/**
 * What a provider found: product IDs in ranked order for the requested page, the number of matches and, when asked,
 * the facet counts as rows the CMS turns into its filter groups:
 *
 * - `['kind' => 'price', 'lo' => min, 'hi' => max, 'n' => count]` (decimal strings)
 * - `['kind' => 'brand'|'category'|'status'|'rating', 'k1' => id, 'n' => count]`
 * - `['kind' => 'stock', 'n' => count of products in stock]`
 * - `['kind' => 'choice', 'k1' => feature ID, 'k2' => option ID, 'n' => count]`
 * - `['kind' => 'number', 'k1' => feature ID, 'lo' => min, 'hi' => max, 'n' => count]`
 * - `['kind' => 'boolean', 'k1' => feature ID, 'n' => count]`
 */
final readonly class SearchResult
{
    /**
     * @param  list<string>  $ids
     * @param  list<array{kind: string, k1?: string|int|null, k2?: string|null, n: int, lo?: string|null, hi?: string|null}>  $facets
     */
    public function __construct(public array $ids, public int $total, public array $facets = []) {}
}
