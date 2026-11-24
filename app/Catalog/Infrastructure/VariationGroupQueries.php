<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read models for Products → Variation Groups and its product-list/editor integration. */
final class VariationGroupQueries
{
    public function __construct(private VariationGroupIntegrity $integrity, private FeatureQueries $features, private ProductQueries $products) {}

    private function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
    }

    /** @param list<string> $products
     * @return array<int|string, string> product ID => display name (current language, then default)
     */
    private function names(array $products): array
    {
        if ($products === []) {
            return [];
        }
        $default = $this->features->defaultId();
        $current = app(LocaleCatalog::class)->active()->firstWhere('code', app()->getLocale())->id ?? $default;
        $rows = DB::table('product_translations')->whereIn('product_id', $products)->whereIn('locale_id', [$current, $default])->get(['product_id', 'locale_id', 'name'])->groupBy('product_id');
        $names = [];
        foreach ($products as $id) {
            $byLocale = $rows->get($id, collect())->pluck('name', 'locale_id');
            $name = trim((string) ($byLocale[$current] ?? '')) ?: trim((string) ($byLocale[$default] ?? ''));
            $names[(string) $id] = $name !== '' ? $name : __('products.untitled', ['id' => $id]);
        }

        return $names;
    }

    /** @param list<string> $features
     * @return array<string, array<string, mixed>> feature ID => definition, in the given order
     */
    private function definitions(array $features): array
    {
        $byId = [];
        foreach ($this->features->definitions($features) as $definition) {
            $byId[$definition['id']] = $definition;
        }
        $ordered = [];
        foreach ($features as $feature) {
            if (isset($byId[$feature])) {
                $ordered[$feature] = $byId[$feature];
            }
        }

        return $ordered;
    }

    /** Correlated member subquery built onto the builder Laravel passes to whereExists. */
    /** @param list<array<string, mixed>> $options
     * @return array<string, mixed>|null
     */
    private function option(array $options, string $id): ?array
    {
        foreach ($options as $option) {
            if ($option['id'] === $id) {
                return $option;
            }
        }

        return null;
    }

    private function members(Builder $query): Builder
    {
        return $query->selectRaw('1')->from('variation_group_members as m')->join('products as p', 'p.id', '=', 'm.product_id')->whereColumn('m.group_id', 'g.id');
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters): array
    {
        $q = DB::table('variation_groups as g');
        if (($filters['search'] ?? '') !== '') {
            $term = $this->like($filters['search']);
            $q->where(fn ($w) => $w->where('g.name', 'ilike', $term)->orWhereExists(fn ($e) => $this->members($e)->where(fn ($m) => $m->where('p.sku', 'ilike', $term)
                ->orWhereExists(fn ($t) => $t->selectRaw('1')->from('product_translations as t')->whereColumn('t.product_id', 'p.id')->where('t.name', 'ilike', $term)))));
        }
        if (! empty($filters['category_id'])) {
            $q->whereExists(fn ($e) => $this->members($e)->where('p.category_id', $filters['category_id']));
        }
        if (! empty($filters['type'])) {
            $q->whereExists(fn ($e) => $this->members($e)->where('p.type', $filters['type']));
        }
        if (! empty($filters['feature_id'])) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('variation_group_features as gf')->whereColumn('gf.group_id', 'g.id')->where('gf.feature_id', $filters['feature_id']));
        }
        $count = '(SELECT count(*) FROM variation_group_members cm WHERE cm.group_id = g.id)';
        if (($filters['members'] ?? '') === 'single') {
            $q->whereRaw("$count = 1");
        } elseif (($filters['members'] ?? '') === 'multiple') {
            $q->whereRaw("$count > 1");
        }
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        match ($filters['sort'] ?? 'id') {
            'name' => $q->orderByRaw('lower(g.name) '.$direction),
            'members' => $q->orderByRaw("$count $direction"),
            default => null,
        };
        $page = $q->select('g.id', 'g.name', 'g.lock_version')->selectRaw("$count as member_count")
            ->selectRaw('(SELECT p.category_id FROM variation_group_members cm JOIN products p ON p.id = cm.product_id WHERE cm.group_id = g.id ORDER BY cm.joined_at, cm.product_id LIMIT 1) as category_id')
            ->selectRaw('(SELECT p.type FROM variation_group_members cm JOIN products p ON p.id = cm.product_id WHERE cm.group_id = g.id ORDER BY cm.joined_at, cm.product_id LIMIT 1) as type')
            ->orderBy('g.id', $direction)->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $ids = $page->getCollection()->pluck('id')->all();
        $groupFeatures = DB::table('variation_group_features')->whereIn('group_id', $ids)->orderBy('position')->get()->groupBy('group_id');
        $definitions = $this->definitions(array_values(array_unique($groupFeatures->flatten()->pluck('feature_id')->map(strval(...))->all())));
        $paths = app(CategoryQueries::class)->paths($page->getCollection()->pluck('category_id')->filter()->map(strval(...))->unique()->values()->all());
        $page->setCollection($page->getCollection()->map(fn ($g): array => [
            'id' => (string) $g->id,
            'name' => $g->name,
            'members' => (int) $g->member_count,
            'type' => $g->type,
            'category' => $g->category_id === null ? [] : ($paths[(string) $g->category_id] ?? []),
            'features' => $groupFeatures->get($g->id, collect())->map(fn ($f): string => ($d = $definitions[(string) $f->feature_id] ?? null) === null ? '#'.$f->feature_id : ($d['name'] ?: $d['code']))->values()->all(),
        ]));

        return $page->toArray();
    }

    /** @return list<array{id: string, name: string}> Features usable as defining features (for filters and the wizard). */
    public function variationFeatures(): array
    {
        $ids = DB::table('features as f')->where('purpose', 'variations')->where('type', 'single')->orderBy('id')->pluck('id')->map(strval(...))->all();

        return array_map(fn (array $d): array => ['id' => $d['id'], 'name' => $d['name'] ?: $d['code']], $this->features->definitions($ids));
    }

    /** @return array<string, mixed> */
    public function detail(string $group): array
    {
        $g = DB::table('variation_groups')->where('id', $group)->first();
        abort_if($g === null, 404);
        $featureIds = $this->integrity->featureIds($group);
        $definitions = $this->definitions($featureIds);
        $problems = $this->integrity->problems($group);
        $members = ProductRecord::withTrashed()->whereIn('id', $this->integrity->memberIds($group))->get();
        $names = $this->names($members->pluck('id')->map(strval(...))->all());
        $optionPosition = [];
        $optionLabel = [];
        foreach ($definitions as $definition) {
            foreach ($definition['options'] as $index => $option) {
                $optionPosition[$option['id']] = $index;
                $optionLabel[$option['id']] = $option['label'];
            }
        }
        $rows = $members->map(function (ProductRecord $p) use ($featureIds, $names, $optionLabel, $optionPosition, $problems): array {
            $choices = $this->integrity->choices((string) $p->id, $featureIds);
            $values = [];
            $sort = [];
            foreach ($featureIds as $feature) {
                $option = $choices[$feature][0]['option'] ?? null;
                $values[$feature] = $option === null ? null : ['id' => $option, 'label' => $optionLabel[$option] ?? '#'.$option];
                $sort[] = $option === null ? PHP_INT_MAX : ($optionPosition[$option] ?? PHP_INT_MAX);
            }

            return ['id' => (string) $p->id, 'name' => $names[(string) $p->id], 'sku' => $p->sku, 'status' => $p->status, 'version' => $p->lock_version, 'values' => (object) $values, 'problems' => $problems['products'][(string) $p->id] ?? [], 'sort' => [...$sort, (int) $p->id]];
        })->sort(fn (array $a, array $b): int => $a['sort'] <=> $b['sort'])->values()->map(function (array $row): array {
            unset($row['sort']);

            return $row;
        })->all();
        $reference = $members->sortBy('id')->first();
        $publiclyAvailable = $members->isEmpty() ? 0 : ProductRecord::whereIn('id', $members->pluck('id'))->where('status', 'active')->whereRaw($this->products->completenessSql(), [$this->products->defaultId()])->count();
        $intents = DB::table('variation_group_intents')->where('group_id', $group)->pluck('product_id')->map(strval(...))->all();
        $intentNames = $this->names($intents);

        return [
            'id' => (string) $g->id,
            'name' => $g->name,
            'version' => $g->lock_version,
            'translations' => (object) DB::table('variation_group_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.group_id', $group)->get(['l.code', 't.name'])->mapWithKeys(fn ($t): array => [$t->code => ['name' => $t->name]])->all(),
            'single_listing' => (bool) $g->single_listing,
            'single_carousel' => (bool) $g->single_carousel,
            'single_search' => (bool) $g->single_search,
            'view_product' => (bool) $g->view_product,
            'color_swatches' => (bool) $g->color_swatches,
            'clickable_colors' => (bool) $g->clickable_colors,
            'card_labels' => (object) DB::table('variation_card_feature_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.group_id', $group)->get(['t.feature_id', 'l.code', 't.name'])->groupBy('feature_id')->map(fn ($rows) => (object) $rows->pluck('name', 'code')->all())->all(),
            'card_features' => DB::table('variation_group_features')->where('group_id', $group)->where('show_on_card', true)->orderBy('position')->pluck('feature_id')->map(strval(...))->all(),
            'type' => $reference?->type,
            'category' => $reference?->category_id === null ? [] : (app(CategoryQueries::class)->paths([(string) $reference->category_id])[(string) $reference->category_id] ?? []),
            'features' => array_values(array_map(fn (array $d): array => ['id' => $d['id'], 'name' => $d['name'] ?: $d['code'], 'code' => $d['code'], 'style' => $d['variation_style'], 'options' => array_values(array_map(fn (array $o): array => ['id' => $o['id'], 'label' => $o['label'], 'status' => $o['status']], $d['options']))], $definitions)),
            'members' => $rows,
            'problems' => $problems['group'],
            'readiness' => ['total' => $members->count(), 'available' => $publiclyAvailable, 'eligible' => $publiclyAvailable >= 2],
            'pending' => array_map(fn (string $id): array => ['id' => $id, 'name' => $intentNames[$id]], $intents),
        ];
    }

    /** Standalone complete-or-not products in the group's category and type, with their eligibility.
     * @return list<array<string, mixed>>
     */
    public function candidates(string $group, string $search): array
    {
        $reference = ProductRecord::whereIn('id', $this->integrity->memberIds($group))->orderBy('id')->first();
        if ($reference === null) {
            return [];
        }
        $q = ProductRecord::query()->where('category_id', $reference->category_id)->where('type', $reference->type)
            ->whereNotExists(fn ($e) => $e->selectRaw('1')->from('variation_group_members as m')->whereColumn('m.product_id', 'products.id'));
        if ($search !== '') {
            $term = $this->like($search);
            $q->where(fn ($w) => $w->where('sku', 'ilike', $term)->orWhereHas('translations', fn ($t) => $t->where('name', 'ilike', $term)));
        }
        $products = $q->orderByDesc('updated_at')->orderByDesc('id')->limit(25)->get();
        $features = $this->integrity->featureIds($group);
        $signatures = $this->integrity->problems($group)['signatures'];
        $definitions = $this->definitions($features);
        $names = $this->names($products->pluck('id')->map(strval(...))->all());

        return $products->map(function (ProductRecord $p) use ($features, $reference, $signatures, $definitions, $names): array {
            ['problems' => $problems, 'combination' => $combination] = $this->integrity->memberProblems($p, $features, (string) $reference->category_id, $reference->type);
            if ($combination !== null && ($conflict = array_search($this->integrity->signature($combination), $signatures, true)) !== false) {
                $problems[] = __('variations.duplicate_combination', ['product' => $this->integrity->productLabel((string) $conflict)]);
            }
            $labels = [];
            foreach ($features as $feature) {
                $option = $combination[$feature] ?? null;
                $match = $option === null ? null : $this->option($definitions[$feature]['options'] ?? [], $option);
                $labels[] = $match['label'] ?? '—';
            }

            return ['id' => (string) $p->id, 'name' => $names[(string) $p->id], 'sku' => $p->sku, 'status' => $p->status, 'version' => $p->lock_version, 'values' => $labels, 'problems' => $problems];
        })->all();
    }

    /** Other groups sharing the category and type, as transfer targets.
     * @return list<array<string, mixed>>
     */
    public function transferTargets(string $group): array
    {
        $reference = ProductRecord::whereIn('id', $this->integrity->memberIds($group))->orderBy('id')->first();
        if ($reference === null) {
            return [];
        }

        return DB::table('variation_groups as g')->where('g.id', '<>', $group)
            ->whereExists(fn ($e) => $this->members($e)->where('p.category_id', $reference->category_id)->where('p.type', $reference->type))
            ->orderBy('g.name')->orderBy('g.id')->limit(100)->get(['g.id', 'g.name', 'g.lock_version'])
            ->map(fn ($g): array => ['id' => (string) $g->id, 'name' => $g->name, 'version' => $g->lock_version])->all();
    }

    /** Guided creation: product search and, once a product is chosen, its eligible defining features.
     * @return array<string, mixed>
     */
    public function creation(string $search, ?string $productId): array
    {
        $q = ProductRecord::query()->whereNotExists(fn ($e) => $e->selectRaw('1')->from('variation_group_members as m')->whereColumn('m.product_id', 'products.id'))
            ->whereRaw($this->products->completenessSql(), [$this->products->defaultId()]);
        if ($search !== '') {
            $term = $this->like($search);
            $q->where(fn ($w) => $w->where('sku', 'ilike', $term)->orWhereHas('translations', fn ($t) => $t->where('name', 'ilike', $term)));
        }
        $products = $q->orderByDesc('updated_at')->orderByDesc('id')->limit(25)->get();
        $names = $this->names($products->pluck('id')->map(strval(...))->all());
        $paths = app(CategoryQueries::class)->paths($products->pluck('category_id')->filter()->map(strval(...))->unique()->values()->all());
        $result = ['products' => $products->map(fn (ProductRecord $p): array => ['id' => (string) $p->id, 'name' => $names[(string) $p->id], 'sku' => $p->sku, 'type' => $p->type, 'category' => $paths[(string) $p->category_id] ?? []])->all(), 'selected' => null];
        $product = $productId === null ? null : ProductRecord::find($productId);
        if ($product === null) {
            return $result;
        }
        $candidates = array_map(fn (array $f): string => $f['id'], $this->variationFeatures());
        $applicable = $this->integrity->applicableFeatures($product->category_id === null ? null : (string) $product->category_id, $candidates);
        $ineligible = $this->integrity->featureProblems($applicable);
        $choices = $this->integrity->choices((string) $product->id, $applicable);
        $features = [];
        foreach ($this->definitions($applicable) as $id => $d) {
            $selected = $choices[$id] ?? [];
            $option = count($selected) === 1 && $selected[0]['status'] === 'active' && $selected[0]['applicable'] ? $this->option($d['options'], $selected[0]['option']) : null;
            $features[] = ['id' => $id, 'name' => $d['name'] ?: $d['code'], 'value' => $option['label'] ?? null, 'eligible' => ! isset($ineligible[$id]) && $option !== null, 'problem' => $ineligible[$id] ?? ($option === null ? __('variations.value_missing', ['feature' => $d['name'] ?: $d['code']]) : null)];
        }
        $productNames = $this->names([(string) $product->id]);
        $result['selected'] = [
            'id' => (string) $product->id, 'name' => $productNames[(string) $product->id], 'sku' => $product->sku, 'type' => $product->type, 'version' => $product->lock_version,
            'category' => $product->category_id === null ? [] : (app(CategoryQueries::class)->paths([(string) $product->category_id])[(string) $product->category_id] ?? []),
            'grouped' => $this->integrity->groupOf((string) $product->id) !== null,
            'problems' => array_values(app(ProductState::class)->requiredErrors($product)),
            // Default-language name (not the viewer's language), following the missing-translation convention.
            'suggested_name' => trim((string) $product->translations()->where('locale_id', $this->features->defaultId())->value('name')) ?: __('products.untitled', ['id' => $product->id]),
            'features' => $features,
        ];

        return $result;
    }

    /** @param list<string> $products
     * @return array<int|string, array{id: string, name: string}> keyed by product ID
     */
    public function groupsFor(array $products): array
    {
        return DB::table('variation_group_members as m')->join('variation_groups as g', 'g.id', '=', 'm.group_id')->whereIn('m.product_id', $products)->get(['m.product_id', 'g.id', 'g.name'])
            ->mapWithKeys(fn ($row): array => [(string) $row->product_id => ['id' => (string) $row->id, 'name' => $row->name]])->all();
    }

    /** Membership and pending Create-variation target shown in the product editor.
     * @return array<string, mixed>
     */
    public function editor(ProductRecord $product): array
    {
        $group = $this->groupsFor([(string) $product->id])[(string) $product->id] ?? null;
        $intent = DB::table('variation_group_intents as i')->join('variation_groups as g', 'g.id', '=', 'i.group_id')->where('i.product_id', $product->id)->first(['g.id', 'g.name']);
        $pending = null;
        if ($intent !== null && $group === null) {
            $features = $this->integrity->featureIds((string) $intent->id);
            $reference = ProductRecord::whereIn('id', $this->integrity->memberIds((string) $intent->id))->orderBy('id')->first();
            ['problems' => $problems] = $this->integrity->memberProblems($product, $features, $reference?->category_id === null ? null : (string) $reference->category_id, $reference?->type);
            $pending = ['id' => (string) $intent->id, 'name' => $intent->name, 'problems' => $problems];
        }

        if ($group !== null) {
            // Same read model as the group screen: siblings in defining-feature and shared option order.
            $detail = $this->detail($group['id']);
            $group['features'] = array_map(fn (array $f): array => ['id' => $f['id'], 'name' => $f['name']], $detail['features']);
            $group['members'] = array_map(fn (array $m): array => ['id' => $m['id'], 'name' => $m['name'], 'sku' => $m['sku'], 'status' => $m['status'], 'values' => $m['values'], 'current' => $m['id'] === (string) $product->id], $detail['members']);
            $group['readiness'] = $detail['readiness'];
        }

        return ['group' => $group, 'pending' => $pending];
    }
}
