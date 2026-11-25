<?php

namespace App\Search\Application\Command;

/**
 * A validated product search. Every value comes from the CMS's own parsing of the visitor's request (identifiers
 * checked against the catalog, ranges as decimals), never raw provider syntax.
 *
 * Filters of different groups combine with AND, the values of one group with OR. Facet counts of a group apply every
 * other group's filter but not its own (disjunctive facets), exactly as category listings count.
 *
 * Visibility is the CMS's: only products whose category is in `categories` may match (the visitor's visible, leaf,
 * not search-excluded categories), products restricted to customer groups match only for those `groups`, and
 * `hidden` products (changes not yet confirmed in the index) never match.
 */
final readonly class SearchQuery
{
    public const SORTS = ['relevance', 'price_asc', 'price_desc', 'newest', 'rating'];

    /**
     * @param  list<string>  $languages  Languages to match, the visitor's first, then the default language, then the rest
     * @param  array{price?: array{0: ?string, 1: ?string}, brands?: list<string>, categories?: list<string>, in_stock?: bool, statuses?: list<string>, ratings?: list<int>, choices?: array<string, list<string>>, ranges?: array<string, array{0: ?string, 1: ?string}>, flags?: list<string>}  $filters
     * @param  list<string>  $categories  Categories whose products may match (empty: none may)
     * @param  list<int>  $groups  The visitor's customer groups
     * @param  list<string>  $hidden  Products that must not match
     * @param  array{choice: list<string>, number: list<string>, boolean: list<string>}  $features  Filterable features to count
     */
    public function __construct(
        public string $text,
        public array $languages,
        public string $sort = 'relevance',
        public int $page = 1,
        public int $perPage = 24,
        public array $filters = [],
        public array $categories = [],
        public array $groups = [],
        public array $hidden = [],
        public bool $inStockOnly = false,
        public bool $facets = false,
        public array $features = ['choice' => [], 'number' => [], 'boolean' => []],
        public bool $suggest = false,
        public bool $groupVariations = false,
    ) {}
}
