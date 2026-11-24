<?php

namespace App\Search\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Search\Application\Command\SearchDocument;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds search documents from the catalog's own records, a bounded batch at a time with a fixed number of queries.
 *
 * A product is indexed while it is published as a product: active, not deleted, with SKU, price, a name in the default
 * language and a real (non-system) category. Whether its category is visible, a leaf, or excluded from search is
 * decided when searching (SearchVisibility), so category changes never wait for reindexing.
 */
final class SearchDocuments
{
    /** Characters of description text per language; enough for relevance, small enough for the index. */
    private const DESCRIPTION = 2000;

    /** Products that belong in the index, in ID order, as a query over `products`. */
    public function eligible(): Builder
    {
        $default = (int) DB::table('locales')->where('is_default', true)->value('id');

        return DB::table('products')->where('products.status', 'active')->whereNull('products.deleted_at')->whereNotNull('products.sku')->whereNotNull('products.price')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('product_translations as pt')->whereColumn('pt.product_id', 'products.id')->where('pt.locale_id', $default)->whereRaw('length(btrim(pt.name)) > 0'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('categories as c')->whereColumn('c.id', 'products.category_id')->whereNull('c.system_key'));
    }

    /**
     * Documents for the given products; IDs without one (deleted or unpublished) are returned as `remove`.
     *
     * @param  list<string>  $ids
     * @return array{documents: list<SearchDocument>, remove: list<string>}
     */
    public function build(array $ids): array
    {
        $products = $ids === [] ? collect() : $this->eligible()->whereIn('products.id', $ids)->orderBy('products.id')
            ->get(['products.id', 'products.sku', 'products.mpn', 'products.ean', 'products.isbn', 'products.upc', 'products.price', 'products.quantity', 'products.category_id', 'products.brand_id', 'products.in_stock_status_id', 'products.out_of_stock_status_id', 'products.created_at', 'products.reviews_enabled']);
        $found = $products->pluck('id')->map(strval(...))->all();
        $remove = array_values(array_diff(array_map(strval(...), $ids), $found));
        if ($found === []) {
            return ['documents' => [], 'remove' => $remove];
        }
        // Only active languages: the index layout has fields for them alone.
        $codes = app(LocaleCatalog::class)->active()->pluck('code', 'id')->all();
        $texts = [];
        foreach (DB::table('product_translations')->whereIn('product_id', $found)->get(['product_id', 'locale_id', 'name', 'short_description', 'description']) as $t) {
            $code = $codes[$t->locale_id] ?? null;
            if ($code === null) {
                continue;
            }
            if (trim((string) $t->name) !== '') {
                $texts[$t->product_id]['names'][$code] = trim((string) $t->name);
            }
            $description = $this->plain(trim($t->short_description.' '.$t->description));
            if ($description !== '') {
                $texts[$t->product_id]['descriptions'][$code] = $description;
            }
        }
        $paths = $this->categoryPaths($products->pluck('category_id')->unique()->map(strval(...))->values()->all(), $codes);
        $brands = $this->brandNames($products->pluck('brand_id')->filter()->unique()->values()->all(), $codes);
        [$options, $numbers, $flags, $labels] = $this->features($found, $codes);
        $ratings = DB::table('product_reviews')->whereIn('product_id', $found)->where('status', 'approved')->groupBy('product_id')
            ->selectRaw('product_id, (20 * sum(rating) + count(*)) / (2 * count(*)) AS tenths')->pluck('tenths', 'product_id');
        $groups = DB::table('product_customer_groups')->whereIn('product_id', $found)->get(['product_id', 'group_id'])->groupBy('product_id');
        $tracked = (bool) app(GeneralSettings::class)->all()['track_inventory'];
        $defaults = DB::table('availability_statuses')->where(fn ($q) => $q->where('default_in_stock', true)->orWhere('default_out_of_stock', true))->get(['id', 'default_in_stock', 'default_out_of_stock']);
        $defaultIn = $defaults->firstWhere('default_in_stock', true)->id ?? null;
        $defaultOut = $defaults->firstWhere('default_out_of_stock', true)->id ?? null;

        $listingGroups = DB::table('variation_group_members as m')->join('variation_groups as g', 'g.id', '=', 'm.group_id')->where('g.single_search', true)->whereIn('m.product_id', $found)->pluck('g.id', 'm.product_id');
        $documents = [];
        foreach ($products as $p) {
            $id = (string) $p->id;
            // The availability label the product page shows (AvailabilityRules::bucket), with the store defaults.
            $stocked = ! $tracked || (int) $p->quantity > 0;
            $status = $stocked ? ($p->in_stock_status_id ?? $defaultIn) : ($p->out_of_stock_status_id ?? $defaultOut);
            $descriptions = $texts[$p->id]['descriptions'] ?? [];
            foreach ($labels[$id] ?? [] as $code => $words) {
                // Option labels (colour, material…) are matched with the description, never as a field of their own.
                $descriptions[$code] = mb_substr(trim(($descriptions[$code] ?? '').' '.implode(' ', $words)), 0, self::DESCRIPTION + 500);
            }
            $documents[] = new SearchDocument(
                id: $id,
                listingGroup: isset($listingGroups[$id]) ? (string) $listingGroups[$id] : null,
                names: $texts[$p->id]['names'] ?? [],
                descriptions: $descriptions,
                categoryNames: $paths[(string) $p->category_id] ?? [],
                brandNames: $p->brand_id === null ? [] : ($brands[(string) $p->brand_id] ?? []),
                codes: array_values(array_unique(array_filter(array_map(fn ($c): string => trim((string) $c), [$p->sku, $p->mpn, $p->ean, $p->isbn, $p->upc]), fn (string $c): bool => $c !== ''))),
                categoryId: (string) $p->category_id,
                brandId: $p->brand_id === null ? null : (string) $p->brand_id,
                price: Decimal::trim((string) $p->price),
                inStock: (int) $p->quantity > 0,
                availabilityStatusId: $status === null ? null : (string) $status,
                ratingTenths: $p->reviews_enabled && isset($ratings[$p->id]) ? (int) $ratings[$p->id] : null,
                options: $options[$id] ?? [],
                numbers: $numbers[$id] ?? [],
                flags: $flags[$id] ?? [],
                groups: ($groups[$p->id] ?? collect())->pluck('group_id')->map(intval(...))->sort()->values()->all(),
                createdAt: (int) strtotime((string) $p->created_at),
            );
        }

        return ['documents' => $documents, 'remove' => $remove];
    }

    /**
     * Names of each category and its ancestors, root first, per language (real translations only).
     *
     * @param  list<string>  $categories
     * @param  array<int|string, string>  $codes
     * @return array<string, array<string, list<string>>>
     */
    private function categoryPaths(array $categories, array $codes): array
    {
        if ($categories === []) {
            return [];
        }
        $rows = DB::select('WITH RECURSIVE up AS (SELECT id AS leaf, id, parent_id, 0 AS depth FROM categories WHERE id IN ('.implode(',', array_map(intval(...), $categories)).')
            UNION ALL SELECT up.leaf, c.id, c.parent_id, up.depth + 1 FROM categories c JOIN up ON c.id = up.parent_id) SELECT leaf, id, depth FROM up ORDER BY leaf, depth DESC');
        $names = [];
        foreach (DB::table('category_translations')->whereIn('category_id', array_unique(array_map(fn ($r) => $r->id, $rows)))->get(['category_id', 'locale_id', 'name']) as $t) {
            if (isset($codes[$t->locale_id]) && trim((string) $t->name) !== '') {
                $names[$t->category_id][$codes[$t->locale_id]] = trim((string) $t->name);
            }
        }
        $paths = [];
        foreach ($rows as $r) {
            foreach ($names[$r->id] ?? [] as $code => $name) {
                $paths[(string) $r->leaf][$code][] = $name;
            }
        }

        return $paths;
    }

    /**
     * @param  list<int|string>  $brands
     * @param  array<int|string, string>  $codes
     * @return array<string, array<string, string>>
     */
    private function brandNames(array $brands, array $codes): array
    {
        $names = [];
        if ($brands === []) {
            return $names;
        }
        foreach (DB::table('brand_translations as t')->join('brands as b', 'b.id', '=', 't.brand_id')->where('b.status', 'active')->whereIn('t.brand_id', $brands)->get(['t.brand_id', 't.locale_id', 't.name']) as $t) {
            if (isset($codes[$t->locale_id]) && trim((string) $t->name) !== '') {
                $names[(string) $t->brand_id][$codes[$t->locale_id]] = trim((string) $t->name);
            }
        }

        return $names;
    }

    /**
     * Applicable feature values: chosen options, numbers and "yes" flags, plus option labels per language.
     *
     * @param  list<string>  $products
     * @param  array<int|string, string>  $codes
     * @return array{0: array<string, array<string, list<string>>>, 1: array<string, array<string, string>>, 2: array<string, list<string>>, 3: array<string, array<string, list<string>>>}
     */
    private function features(array $products, array $codes): array
    {
        $options = $numbers = $flags = $labels = [];
        $assignments = DB::table('product_feature_assignments')->whereIn('product_id', $products)->where('is_applicable', true)->get(['id', 'product_id', 'feature_id', 'number_value', 'boolean_value']);
        foreach ($assignments as $a) {
            if ($a->number_value !== null) {
                $numbers[(string) $a->product_id][(string) $a->feature_id] = Decimal::trim((string) $a->number_value);
            }
            if ($a->boolean_value) {
                $flags[(string) $a->product_id][] = (string) $a->feature_id;
            }
        }
        $choices = $assignments->isEmpty() ? collect() : DB::table('product_feature_choices as c')->join('product_feature_assignments as a', 'a.id', '=', 'c.assignment_id')
            ->whereIn('c.assignment_id', $assignments->pluck('id'))->get(['a.product_id', 'c.feature_id', 'c.option_id']);
        foreach ($choices as $c) {
            $options[(string) $c->product_id][(string) $c->feature_id][] = (string) $c->option_id;
        }
        $optionLabels = [];
        if ($choices->isNotEmpty()) {
            foreach (DB::table('feature_option_translations')->whereIn('option_id', $choices->pluck('option_id')->unique())->get(['option_id', 'locale_id', 'label']) as $t) {
                if (isset($codes[$t->locale_id]) && trim((string) $t->label) !== '') {
                    $optionLabels[$t->option_id][$codes[$t->locale_id]] = trim((string) $t->label);
                }
            }
        }
        foreach ($choices as $c) {
            foreach ($optionLabels[$c->option_id] ?? [] as $code => $label) {
                $labels[(string) $c->product_id][$code][] = $label;
            }
        }

        return [$options, $numbers, $flags, $labels];
    }

    private function plain(string $html): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<', '>'], [' <', '> '], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_substr($text, 0, self::DESCRIPTION);
    }
}
