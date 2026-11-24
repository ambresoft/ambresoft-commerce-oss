<?php

namespace Ambresoft\Typesense\Domain;

use App\Search\Application\Command\SearchQuery;
use App\Search\Application\Command\SearchResult;

/**
 * Turns a validated CMS search into Typesense multi-search requests and the answer back into a CMS result.
 *
 * Every filter value is a number the CMS validated, so the filter expressions contain no user text. Visibility
 * (allowed categories, customer groups, hidden products, stock-only listings) applies to every request. Counts are
 * disjunctive: the main request counts the groups nobody filters on, and each active group gets a count-only request
 * without its own condition.
 *
 * Relevance: names in the visitor's language weigh most, then the default language and the codes (SKU, MPN, EAN —
 * exact, no typos), then the other languages' names, the brand, the category path and finally the descriptions.
 */
final class TypesenseQuery
{
    /** Facet values counted per field: enough for every brand or option a filter list shows. */
    public const FACET_VALUES = 250;

    /**
     * @param  list<string>  $indexed  Languages the live collection has fields for
     */
    public function __construct(private string $collection, private int $typos, private array $indexed) {}

    /**
     * @return array{searches: list<array<string, mixed>>, groups: list<string>}
     */
    public function build(SearchQuery $q): array
    {
        $languages = array_values(array_intersect($q->languages, $this->indexed)) ?: array_slice($this->indexed, 0, 1);
        // Typesense reads the query with the first field's tokenizer: a query written in a language's own script
        // (Greek, Cyrillic…) puts that language's fields first, so "κινητο" finds Greek names on any storefront.
        $script = array_values(array_filter($languages, fn (string $code): bool => TypesenseSchema::writtenIn($code, $q->text)));
        $languages = array_values(array_unique([...$script, ...$languages]));
        [$first, $default] = [$languages[0], $languages[1] ?? $languages[0]];
        $fields = [];
        $add = function (string $field, int $weight, int $typos, bool $prefix) use (&$fields): void {
            $fields[$field] ??= [$weight, $typos, $prefix];
        };
        $add('name_'.$first, 10, $this->typos, true);
        $add('codes', 9, 0, true);
        $add('name_'.$default, 8, $this->typos, true);
        foreach (array_slice($languages, 2) as $code) {
            $add('name_'.$code, 6, $this->typos, true);
        }
        foreach (array_unique([$first, $default]) as $code) {
            $add('brand_'.$code, 6, min(1, $this->typos), true);
        }
        foreach (array_unique([$first, $default]) as $code) {
            $add('category_'.$code, 4, min(1, $this->typos), $q->suggest);
        }
        foreach (array_unique([$first, $default]) as $code) {
            $add('text_'.$code, 2, min(1, $this->typos), $q->suggest);
        }
        $common = [
            'collection' => $this->collection, 'q' => $q->text,
            'query_by' => implode(',', array_keys($fields)),
            'query_by_weights' => implode(',', array_column($fields, 0)),
            'num_typos' => implode(',', array_column($fields, 1)),
            'prefix' => implode(',', array_map(fn (bool $p): string => $p ? 'true' : 'false', array_column($fields, 2))),
            'include_fields' => 'product_id', 'highlight_fields' => 'none',
        ];
        if ($q->groupVariations) {
            $common += ['group_by' => 'listing_key', 'group_limit' => 1];
        }
        $base = $this->visibility($q);
        $clauses = $this->clauses($q);
        $all = implode(' && ', [...$base, ...array_values($clauses)]);
        $main = [...$common, 'filter_by' => $all, 'sort_by' => $this->sort($q->sort), 'page' => max(1, $q->page), 'per_page' => max(1, min(250, $q->perPage))];
        $searches = [];
        $groups = [];
        if ($q->facets) {
            $own = [];
            foreach (array_keys($clauses) as $group) {
                $own = [...$own, ...$this->facetFields($group, $q)];
            }
            $fieldsToCount = array_values(array_diff($this->allFacetFields($q), $own));
            // A yes/no feature someone filters on is counted by its own request; the others share `flags`.
            $main += ['facet_by' => implode(',', $fieldsToCount), 'max_facet_values' => self::FACET_VALUES];
            $searches[] = $main;
            foreach ($clauses as $group => $clause) {
                $others = array_diff_key($clauses, [$group => true]);
                $searches[] = [...$common, 'filter_by' => implode(' && ', [...$base, ...array_values($others)]), 'per_page' => 0,
                    'facet_by' => implode(',', $this->facetFields($group, $q)), 'max_facet_values' => self::FACET_VALUES];
                $groups[] = $group;
            }
        } else {
            $searches[] = $main;
        }

        return ['searches' => array_map(fn (array $s): array => array_filter($s, fn ($v): bool => $v !== ''), $searches), 'groups' => $groups];
    }

    /**
     * @param  list<array<string, mixed>>  $results  One per request, in order
     * @param  list<string>  $groups  The active group each count-only request belongs to
     */
    public function parse(SearchQuery $q, array $results, array $groups): SearchResult
    {
        $main = $results[0];
        $hits = $q->groupVariations && isset($main['grouped_hits']) ? array_merge([], ...array_column($main['grouped_hits'], 'hits')) : ($main['hits'] ?? []);
        $ids = array_values(array_filter(array_map(fn (array $hit): string => (string) ($hit['document']['product_id'] ?? ''), $hits), fn (string $id): bool => $id !== ''));
        $total = (int) ($main['found'] ?? 0);
        if (! $q->facets) {
            return new SearchResult($ids, $total);
        }
        $rows = [['kind' => 'total', 'n' => $total]];
        $activeFlags = array_map(fn (string $g): string => substr($g, 1), array_filter($groups, fn (string $g): bool => str_starts_with($g, 'f') && in_array(substr($g, 1), $q->features['boolean'], true)));
        $rows = [...$rows, ...$this->rows($main, $q, $total, array_diff($q->features['boolean'], $activeFlags))];
        foreach ($groups as $n => $group) {
            $result = $results[$n + 1] ?? [];
            $flags = str_starts_with($group, 'f') && in_array(substr($group, 1), $q->features['boolean'], true) ? [substr($group, 1)] : [];
            $rows = [...$rows, ...$this->rows($result, $q, (int) ($result['found'] ?? 0), $flags)];
        }

        return new SearchResult($ids, $total, $rows);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int|string, string>  $flags  Yes/no features whose count this result provides
     * @return list<array<string, mixed>>
     */
    private function rows(array $result, SearchQuery $q, int $found, array $flags): array
    {
        $rows = [];
        foreach ($result['facet_counts'] ?? [] as $facet) {
            $field = (string) ($facet['field_name'] ?? '');
            $counts = $facet['counts'] ?? [];
            $stats = $facet['stats'] ?? [];
            if ($field === 'price') {
                $rows[] = $found > 0 && isset($stats['min'], $stats['max'])
                    ? ['kind' => 'price', 'n' => $found, 'lo' => TypesenseSchema::decimal($stats['min']), 'hi' => TypesenseSchema::decimal($stats['max'])]
                    : ['kind' => 'price', 'n' => 0];
            } elseif (in_array($field, ['brand_id', 'category_id', 'status_id', 'rating'], true)) {
                $kind = ['brand_id' => 'brand', 'category_id' => 'category', 'status_id' => 'status', 'rating' => 'rating'][$field];
                foreach ($counts as $c) {
                    $rows[] = ['kind' => $kind, 'k1' => (string) $c['value'], 'n' => (int) $c['count']];
                }
            } elseif ($field === 'in_stock') {
                foreach ($counts as $c) {
                    if ($c['value'] === 'true') {
                        $rows[] = ['kind' => 'stock', 'n' => (int) $c['count']];
                    }
                }
            } elseif ($field === 'flags') {
                foreach ($counts as $c) {
                    if (in_array((string) $c['value'], $flags, true)) {
                        $rows[] = ['kind' => 'boolean', 'k1' => (string) $c['value'], 'n' => (int) $c['count']];
                    }
                }
            } elseif (preg_match('/^opt_(\d+)$/D', $field, $m) === 1) {
                foreach ($counts as $c) {
                    $rows[] = ['kind' => 'choice', 'k1' => $m[1], 'k2' => (string) $c['value'], 'n' => (int) $c['count']];
                }
            } elseif (preg_match('/^num_(\d+)$/D', $field, $m) === 1 && $counts !== [] && isset($stats['min'], $stats['max'])) {
                $rows[] = ['kind' => 'number', 'k1' => $m[1], 'n' => array_sum(array_column($counts, 'count')), 'lo' => TypesenseSchema::decimal($stats['min']), 'hi' => TypesenseSchema::decimal($stats['max'])];
            }
        }

        return $rows;
    }

    /** @return list<string> Conditions every request carries: who may find what. */
    private function visibility(SearchQuery $q): array
    {
        $base = ['category_id:'.$this->ids($q->categories)];
        $base[] = $q->groups === [] ? 'public:true' : '(public:true || groups:'.$this->ids(array_map(strval(...), $q->groups)).')';
        if ($q->hidden !== []) {
            $base[] = 'product_id:!='.$this->ids($q->hidden);
        }
        if ($q->inStockOnly) {
            $base[] = 'in_stock:true';
        }

        return $base;
    }

    /** @return array<string, string> One condition per active filter group, keyed like the CMS's listing groups. */
    private function clauses(SearchQuery $q): array
    {
        $f = $q->filters;
        $clauses = [];
        $range = function (string $field, array $range): string {
            $parts = [];
            if ($range[0] !== null) {
                $parts[] = $field.':>='.TypesenseSchema::scaled($range[0], 'floor');
            }
            if ($range[1] !== null) {
                $parts[] = $field.':<='.TypesenseSchema::scaled($range[1], 'ceil');
            }

            return implode(' && ', $parts);
        };
        if (($f['price'] ?? [null, null]) !== [null, null]) {
            $clauses['price'] = $range('price', $f['price']);
        }
        if (($f['brands'] ?? []) !== []) {
            $clauses['brand'] = 'brand_id:'.$this->ids($f['brands']);
        }
        if (($f['categories'] ?? []) !== []) {
            $clauses['category'] = 'category_id:'.$this->ids($f['categories']);
        }
        if ($f['in_stock'] ?? false) {
            $clauses['stock'] = 'in_stock:true';
        }
        if (($f['statuses'] ?? []) !== []) {
            $clauses['status'] = 'status_id:'.$this->ids($f['statuses']);
        }
        if (($f['ratings'] ?? []) !== []) {
            $clauses['rating'] = 'rating:'.$this->ids(array_map(strval(...), $f['ratings']));
        }
        foreach ($f['choices'] ?? [] as $feature => $options) {
            $clauses['f'.$feature] = 'opt_'.(int) $feature.':'.$this->ids($options);
        }
        foreach ($f['ranges'] ?? [] as $feature => $bounds) {
            $clauses['f'.$feature] = $range('num_'.(int) $feature, $bounds);
        }
        foreach ($f['flags'] ?? [] as $feature) {
            $clauses['f'.$feature] = 'flags:='.(int) $feature;
        }

        return $clauses;
    }

    /** @return list<string> */
    private function allFacetFields(SearchQuery $q): array
    {
        $fields = ['price', 'brand_id', 'category_id', 'in_stock', 'status_id', 'rating'];
        foreach ($q->features['choice'] as $feature) {
            $fields[] = 'opt_'.(int) $feature;
        }
        foreach ($q->features['number'] as $feature) {
            $fields[] = 'num_'.(int) $feature;
        }
        if ($q->features['boolean'] !== []) {
            $fields[] = 'flags';
        }

        return $fields;
    }

    /** @return list<string> The facet fields a filter group's count-only request asks for. */
    private function facetFields(string $group, SearchQuery $q): array
    {
        if (str_starts_with($group, 'f')) {
            $feature = substr($group, 1);

            return match (true) {
                in_array($feature, $q->features['number'], true) => ['num_'.(int) $feature],
                in_array($feature, $q->features['boolean'], true) => ['flags'],
                default => ['opt_'.(int) $feature],
            };
        }

        return [['price' => 'price', 'brand' => 'brand_id', 'category' => 'category_id', 'stock' => 'in_stock', 'status' => 'status_id', 'rating' => 'rating'][$group]];
    }

    private function sort(string $sort): string
    {
        return match ($sort) {
            'price_asc' => 'price:asc,_text_match:desc,product_id:desc',
            'price_desc' => 'price:desc,_text_match:desc,product_id:desc',
            'newest' => 'created_at:desc,product_id:desc',
            'rating' => 'rating_tenths:desc,_text_match:desc,product_id:desc',
            default => '_text_match:desc,rating_tenths:desc,product_id:desc',
        };
    }

    /** @param list<string> $ids Validated numeric identifiers */
    private function ids(array $ids): string
    {
        return '['.implode(',', array_map(fn (string $id): string => (string) (int) $id, $ids)).']';
    }
}
