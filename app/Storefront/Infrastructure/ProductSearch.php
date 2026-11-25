<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\PublicCatalog;
use App\Search\Application\Command\SearchQuery;
use App\Search\Application\Command\SearchResult;
use App\Search\Application\Contract\SearchProvider;
use App\Search\Application\Contract\SearchUnavailable;
use App\Search\Infrastructure\SearchIndex;
use App\Search\Infrastructure\SearchSettings;
use App\Search\Infrastructure\SearchTerms;
use App\Search\Infrastructure\SearchVisibility;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Storefront product search through the active search add-on: the full results page (the category listing's filters,
 * cards and pagination, fed with the engine's ranking and counts) and the header's suggestions.
 *
 * The visitor's query is parsed and validated by the CMS (CategoryListing::state()); the engine only ever receives
 * validated numbers and the text. Every hit is then read from the catalog with the visitor's own publication rules and
 * searchable categories before it is shown, in one query, so a document the index has not caught up with can never
 * reach the page. When the engine cannot answer, the page says so: an outage is never shown as "no results".
 */
final class ProductSearch
{
    public const MAX_TEXT = 100;

    /** Characters a query needs before it is searched (the search add-on's setting; 0 for any). */
    public function minimum(?SearchProvider $provider = null): int
    {
        $provider ??= app(SearchSettings::class)->provider();

        return $provider === null ? 0 : $provider->minimumLength(app(SearchSettings::class)->values($provider));
    }

    /** Collapsed, control-free text of at most MAX_TEXT characters ('' when there is nothing to search). */
    public function text(mixed $value): string
    {
        $text = is_string($value) ? (string) preg_replace('/[\p{C}]+/u', ' ', $value) : '';

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, self::MAX_TEXT);
    }

    /**
     * The search page's listing in the CategoryListing contract (page, state, facets, and the ranked page of IDs).
     * `page.search.state` is `results`, `no_query`, `too_short` (fewer characters than the store's minimum, given as
     * `min`), `unavailable`, `not_ready` or `degraded` (results shown, counts withheld while many visibility changes are
     * still being indexed).
     *
     * @param  array<string, mixed>  $query
     * @param  array{locale: string, customer: ?string}  $context
     * @param  array<string, bool>  $offered  The optional filter groups the filters block offers
     * @param  array<string, mixed>  $settings  The main content block's validated settings
     * @return array<string, mixed>
     */
    public function listing(array $query, array $context, array $offered, array $settings): array
    {
        $listings = app(CategoryListing::class);
        $text = $this->text($query['q'] ?? '');
        $state = [...$listings->state($query), 'shop' => [], 'members' => []];
        $empty = fn (string $status): array => ['page' => ['search' => ['query' => $text, 'state' => $status]], 'scope' => [], 'query' => $query, 'state' => $state, 'facets' => null];
        if ($text === '') {
            return $empty('no_query');
        }
        $provider = app(SearchSettings::class)->provider();
        if ($provider === null) {
            return $empty('unavailable');
        }
        $minimum = $this->minimum($provider);
        if (mb_strlen($text) < $minimum) {
            return [...$empty('too_short'), 'page' => ['search' => ['query' => $text, 'state' => 'too_short', 'min' => $minimum]]];
        }
        if (! app(SearchIndex::class)->built($provider)) {
            return $empty('not_ready');
        }
        $categories = app(SearchVisibility::class)->categories($context['customer']);
        $state['categories'] = array_values(array_intersect($state['categories'], $categories));
        $groups = ['shopping' => false, 'delivery' => ($offered['delivery'] ?? false) || $state['statuses'] !== [], 'rating' => ($offered['rating'] ?? false) || $state['ratings'] !== [],
            'stock' => ($offered['stock'] ?? false) || $state['in_stock'], 'category' => true];
        $sorts = $this->sorts();
        $default = in_array($settings['default_sort'] ?? '', $sorts, true) ? $settings['default_sort'] : 'relevance';
        $requested = is_string($query['sort'] ?? null) ? $query['sort'] : '';
        $sort = in_array($requested, $sorts, true) ? $requested : $default;
        $perPage = max(1, min(60, (int) ($settings['products_per_page'] ?? 24)));
        $page = is_string($query['page'] ?? null) && preg_match('/^[1-9][0-9]{0,4}$/D', $query['page']) === 1 ? (int) $query['page'] : 1;
        [$hidden, $overflow] = app(SearchVisibility::class)->hidden();
        $hidden = $this->hiddenVariations($hidden, $context['customer']);
        $search = fn (int $page): SearchQuery => new SearchQuery($text, $this->languages($context['locale']), $sort, $page, $perPage, $this->filters($state), $categories,
            app(PublicCatalog::class)->groups($context['customer']), $hidden, $this->inStockOnly(), ! $overflow, $this->features(), groupVariations: true);
        try {
            $result = $this->run($provider, $search($page));
            $last = max(1, (int) ceil($result->total / $perPage));
            if ($page > $last) {
                // A filter change can leave fewer pages: show the last one instead of an empty page.
                [$page, $result] = [$last, $this->run($provider, $search($last))];
            }
        } catch (SearchUnavailable $e) {
            Log::warning('search_unavailable', ['reason' => $e->reason, 'message' => mb_substr($e->getMessage(), 0, 300)]);

            return $empty($e->reason === 'not_initialized' ? 'not_ready' : 'unavailable');
        }
        $result = $this->groupedResult($result, $context['customer'], $categories);
        // A submitted search (the text alone, not a filter, sort or page change on its results) counts towards the
        // popular searches.
        if (array_diff(array_keys($query), ['q']) === []) {
            app(SearchTerms::class)->record($text, $result->total);
        }
        $facets = $overflow
            ? ['total' => $result->total, 'groups' => [], 'active' => []]
            : $listings->present(array_map(fn (array $row): object => (object) ($row + ['k1' => null, 'k2' => null, 'lo' => null, 'hi' => null]), $result->facets), $state, $context, $groups);

        return ['page' => ['search' => ['query' => $text, 'state' => $overflow ? 'degraded' : 'results']], 'scope' => [], 'query' => $query, 'state' => $state, 'facets' => $facets,
            'search' => ['ids' => $result->ids, 'page' => $page, 'last_page' => $last, 'sort' => $sort, 'default_sort' => $default, 'sorts' => $sorts, 'products' => $this->products($context['customer'], $categories)]];
    }

    /**
     * Ranked product IDs for a catalog reader, limited to the categories it may see. Too little text finds nothing.
     *
     * @param  list<string>  $categories
     *
     * @throws SearchUnavailable When no search engine can answer
     */
    public function ranked(string $text, string $locale, string $sort, int $page, int $perPage, array $categories, bool $inStock): SearchResult
    {
        $provider = app(SearchSettings::class)->provider();
        if ($provider === null || ! app(SearchIndex::class)->built($provider)) {
            throw new SearchUnavailable('unavailable');
        }
        $text = $this->text($text);
        if ($text === '' || mb_strlen($text) < $this->minimum($provider)) {
            return new SearchResult([], 0);
        }
        $state = [...app(CategoryListing::class)->state([]), 'in_stock' => $inStock];
        [$hidden] = app(SearchVisibility::class)->hidden();
        $hidden = $this->hiddenVariations($hidden, null);

        return $this->groupedResult($this->run($provider, new SearchQuery($text, $this->languages($locale), $sort, $page, $perPage, $this->filters($state), $categories, [], $hidden, $this->inStockOnly(), groupVariations: true)), null, $categories);
    }

    /**
     * Header suggestions: the best matches as small product cards, read from the catalog like any card.
     *
     * @param  array{locale: string, customer: ?string}  $context
     * @return array{state: string, items: list<array<string, mixed>>, total: int, currency_format: array<string, mixed>|null}
     */
    public function suggest(string $text, array $context, int $limit): array
    {
        $none = fn (string $state): array => ['state' => $state, 'items' => [], 'total' => 0, 'currency_format' => null];
        $provider = app(SearchSettings::class)->provider();
        if ($provider === null || ! app(SearchIndex::class)->built($provider)) {
            return $none('unavailable');
        }
        // Suggestions need at least one character, and the store's minimum.
        if ($text === '' || mb_strlen($text) < $this->minimum($provider) || $limit < 1) {
            return $none('results');
        }
        $categories = app(SearchVisibility::class)->categories($context['customer']);
        [$hidden] = app(SearchVisibility::class)->hidden();
        $hidden = $this->hiddenVariations($hidden, $context['customer']);
        try {
            $result = $this->run($provider, new SearchQuery($text, $this->languages($context['locale']), perPage: $limit, categories: $categories,
                groups: app(PublicCatalog::class)->groups($context['customer']), hidden: $hidden, inStockOnly: $this->inStockOnly(), suggest: true, groupVariations: true));
        } catch (SearchUnavailable $e) {
            Log::warning('search_unavailable', ['reason' => $e->reason, 'message' => mb_substr($e->getMessage(), 0, 300)]);

            return $none('unavailable');
        }
        $result = $this->groupedResult($result, $context['customer'], $categories);
        ['cards' => $cards, 'format' => $format] = app(BlockContent::class)->productCards($result->ids, $context, $this->products($context['customer'], $categories), 'search');
        $items = [];
        foreach ($result->ids as $id) {
            if (isset($cards[$id])) {
                $items[] = array_intersect_key($cards[$id], array_flip(['id', 'name', 'sku', 'url', 'image', 'price', 'price_from', 'base_price', 'category']));
            }
        }

        return ['state' => 'results', 'items' => $items, 'total' => $result->total, 'currency_format' => $format];
    }

    /** @return list<string> Sorts the shopper can choose; price sorts disappear while the store hides prices. */
    public function sorts(): array
    {
        $settings = app(GeneralSettings::class)->all();
        $sorts = $settings['hide_product_prices'] ? ['relevance', 'newest', 'rating'] : SearchQuery::SORTS;

        return $settings['reviews_enabled'] ? $sorts : array_values(array_diff($sorts, ['rating']));
    }

    /** @param list<string> $hidden
     * @return list<string> */
    private function hiddenVariations(array $hidden, ?string $customer): array
    {
        foreach (app(VariationListings::class)->representatives($customer, 'search') as $member => $chosen) {
            if ($chosen === null) {
                $hidden[] = (string) $member;
            }
        }

        return array_values(array_unique($hidden));
    }

    /** Resolve a matching member to its group's live sellable identity after filtering and engine pagination.
     * @param list<string> $categories */
    private function groupedResult(SearchResult $result, ?string $customer, array $categories): SearchResult
    {
        $visible = app(PublicCatalog::class)->listed($customer, null)->whereIn('products.category_id', $categories)->whereIn('products.id', $result->ids)->pluck('products.id')->map(strval(...))->all();
        $representatives = app(VariationListings::class)->representatives($customer, 'search');
        $ids = [];
        foreach ($result->ids as $id) {
            if (in_array($id, $visible, true)) {
                $chosen = array_key_exists($id, $representatives) ? $representatives[$id] : $id;
                if ($chosen !== null) {
                    $ids[] = $chosen;
                }
            }
        }

        return new SearchResult(array_values(array_unique($ids)), $result->total, $result->facets);
    }

    private function run(SearchProvider $provider, SearchQuery $query): SearchResult
    {
        return $provider->search($query, app(SearchSettings::class)->values($provider));
    }

    /** Products a hit may show as: published for this visitor and in a searchable category. */
    /** @param list<string> $categories */
    private function products(?string $customer, array $categories): Builder
    {
        return app(PublicCatalog::class)->listed($customer, 'search')->whereIn('products.category_id', array_map(intval(...), $categories ?: [0]));
    }

    /** @return list<string> The visitor's language first, then the default language, then the other active ones. */
    private function languages(string $locale): array
    {
        $all = app(SearchSettings::class)->languages();

        return array_values(array_unique([...(in_array($locale, $all, true) ? [$locale] : []), ...$all]));
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed> */
    private function filters(array $state): array
    {
        return ['price' => $state['price'], 'brands' => array_map(strval(...), array_keys($state['brands'])), 'categories' => $state['categories'], 'in_stock' => $state['in_stock'],
            'statuses' => $state['statuses'], 'ratings' => $state['ratings'], 'choices' => $state['choices'], 'ranges' => $state['ranges'], 'flags' => $state['flags']];
    }

    /** @return array{choice: list<string>, number: list<string>, boolean: list<string>} The active filterable features, to count. */
    private function features(): array
    {
        $features = ['choice' => [], 'number' => [], 'boolean' => []];
        foreach (DB::table('features')->where('filterable', true)->where('status', 'active')->orderBy('id')->get(['id', 'type']) as $f) {
            $kind = match ($f->type) {
                'number' => 'number', 'boolean' => 'boolean', 'single', 'multiple' => 'choice', default => null,
            };
            if ($kind !== null) {
                $features[$kind][] = (string) $f->id;
            }
        }

        return $features;
    }

    /** Listings may leave out products without stock (General settings); search follows them. */
    private function inStockOnly(): bool
    {
        $settings = app(GeneralSettings::class)->all();

        return (bool) $settings['hide_out_of_stock_listings'] && (bool) $settings['track_inventory'];
    }
}
