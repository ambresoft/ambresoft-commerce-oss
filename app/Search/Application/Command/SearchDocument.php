<?php

namespace App\Search\Application\Command;

/**
 * One product as a search provider indexes it, built by the CMS from its own catalog records. Only what search needs:
 * texts to match, identifiers, and the values filters, facets and sorts use. Category visibility, search exclusion and
 * publication are decided by the CMS when searching, never stored here, so they apply without reindexing.
 *
 * Localized texts are keyed by language code and hold only real translations (no copied fallbacks, which would
 * distort relevance). Amounts are exact decimal strings.
 */
final readonly class SearchDocument
{
    /**
     * @param  array<string, string>  $names  Language => product name
     * @param  array<string, string>  $descriptions  Language => plain-text short description and description
     * @param  array<string, list<string>>  $categoryNames  Language => names of the category and its ancestors
     * @param  array<string, string>  $brandNames  Language => brand name
     * @param  list<string>  $codes  SKU, MPN, EAN, ISBN, UPC (exact identifiers)
     * @param  array<string, list<string>>  $options  Feature ID => chosen option IDs
     * @param  array<string, string>  $numbers  Feature ID => decimal value
     * @param  list<string>  $flags  IDs of yes/no features that are "yes"
     * @param  list<int>  $groups  Customer groups the product is restricted to; empty for everyone
     */
    public function __construct(
        public string $id,
        public array $names,
        public array $descriptions,
        public array $categoryNames,
        public array $brandNames,
        public array $codes,
        public string $categoryId,
        public ?string $brandId,
        public string $price,
        public bool $inStock,
        public ?string $availabilityStatusId,
        public ?int $ratingTenths,
        public array $options,
        public array $numbers,
        public array $flags,
        public array $groups,
        public int $createdAt,
        public ?string $listingGroup = null,
    ) {}
}
