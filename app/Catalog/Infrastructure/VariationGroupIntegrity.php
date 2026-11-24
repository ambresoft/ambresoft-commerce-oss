<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Infrastructure\Persistence\ProductRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Variation Group rules, evaluated inside the caller's transaction under the
 * Catalog advisory lock. Every operation that can change a group, a member or
 * something a member depends on re-runs these checks before committing.
 */
final class VariationGroupIntegrity
{
    public function __construct(private FeatureQueries $features, private ProductState $state) {}

    /** @return list<string> Defining feature IDs in the group's selector order. */
    public function featureIds(string $group): array
    {
        return DB::table('variation_group_features')->where('group_id', $group)->orderBy('position')->pluck('feature_id')->map(strval(...))->all();
    }

    /** @return list<string> */
    public function memberIds(string $group): array
    {
        return DB::table('variation_group_members')->where('group_id', $group)->orderBy('joined_at')->orderBy('product_id')->pluck('product_id')->map(strval(...))->all();
    }

    public function groupOf(string $product): ?string
    {
        $group = DB::table('variation_group_members')->where('product_id', $product)->value('group_id');

        return $group === null ? null : (string) $group;
    }

    /** @return list<string> Groups that use the feature to define combinations. */
    public function groupsUsingFeature(string $feature): array
    {
        return DB::table('variation_group_features')->where('feature_id', $feature)->orderBy('group_id')->pluck('group_id')->map(strval(...))->all();
    }

    /** Eligibility problems of the definition itself (independent of categories), keyed by feature ID.
     * @param  list<string>  $features
     * @return array<string, string>
     */
    public function featureProblems(array $features): array
    {
        $rows = DB::table('features as f')->whereIn('f.id', $features)->select('f.id', 'f.type', 'f.purpose', 'f.status', 'f.code')->selectRaw($this->features->completeSql().' as complete')->get()->keyBy(fn ($row): string => (string) $row->id);
        $problems = [];
        foreach ($features as $id) {
            $f = $rows->get($id);
            $problem = match (true) {
                $f === null => 'variations.feature_missing',
                $f->purpose !== 'variations' || $f->type !== 'single' => 'variations.feature_not_variation',
                $f->status !== 'active' => 'variations.feature_archived',
                ! $f->complete => 'variations.feature_incomplete',
                default => null,
            };
            if ($problem !== null) {
                $problems[$id] = __($problem, ['feature' => $this->featureLabel($id)]);
            }
        }

        return $problems;
    }

    /** @param list<string> $features
     * @return list<string>
     */
    public function applicableFeatures(?string $category, array $features): array
    {
        if ($category === null || $features === []) {
            return [];
        }

        return DB::table('features as f')->whereIn('f.id', $features)->whereRaw($this->features->appliesSql('(SELECT id FROM categories WHERE id = ?)'), [$category])->pluck('f.id')->map(strval(...))->all();
    }

    /** Selected options per defining feature, from existing Phase 5 product assignments.
     * @param  list<string>  $features
     * @return array<string, list<array{option: string, status: string, applicable: bool}>>
     */
    public function choices(string $product, array $features): array
    {
        $rows = DB::table('product_feature_assignments as a')->join('product_feature_choices as c', 'c.assignment_id', '=', 'a.id')->join('feature_options as o', 'o.id', '=', 'c.option_id')
            ->where('a.product_id', $product)->whereIn('a.feature_id', $features)->orderBy('o.position')->orderBy('o.id')
            ->get(['a.feature_id', 'c.option_id', 'o.status', 'a.is_applicable']);
        $choices = [];
        foreach ($rows as $row) {
            $choices[(string) $row->feature_id][] = ['option' => (string) $row->option_id, 'status' => $row->status, 'applicable' => (bool) $row->is_applicable];
        }

        return $choices;
    }

    /** Stable combination signature: sorted by feature ID so selector order never changes it.
     * @param  array<string, string>  $combination  feature ID => option ID
     */
    public function signature(array $combination): string
    {
        ksort($combination, SORT_NUMERIC);

        return implode(',', array_map(fn ($feature, $option): string => $feature.':'.$option, array_keys($combination), $combination));
    }

    /**
     * Problems for one product against a group configuration. Returns the
     * product's combination (feature => option) when its defining values are valid.
     *
     * @param  list<string>  $features
     * @return array{problems: list<string>, combination: array<string, string>|null}
     */
    public function memberProblems(ProductRecord $p, array $features, ?string $category, ?string $type): array
    {
        $problems = [];
        if ($p->trashed()) {
            $problems[] = __('variations.member_trashed');
        }
        $incomplete = $this->state->requiredErrors($p);
        if ($incomplete !== []) {
            $problems[] = __('variations.member_incomplete', ['fields' => implode(' ', array_unique(array_values($incomplete)))]);
        }
        $productCategory = $p->category_id === null ? null : (string) $p->category_id;
        if ($category !== null && $productCategory !== $category) {
            $problems[] = __('variations.category_mismatch', ['category' => $this->categoryLabel($category)]);
        }
        if ($type !== null && $p->type !== $type) {
            $problems[] = __('variations.type_mismatch', ['type' => __('products.'.$type)]);
        }
        $applicable = $this->applicableFeatures($productCategory, $features);
        $choices = $this->choices((string) $p->id, $features);
        $combination = [];
        foreach ($features as $feature) {
            $label = $this->featureLabel($feature);
            if (! in_array($feature, $applicable, true)) {
                $problems[] = __('variations.feature_inapplicable', ['feature' => $label]);

                continue;
            }
            $selected = $choices[$feature] ?? [];
            if (count($selected) !== 1 || ! $selected[0]['applicable']) {
                $problems[] = __('variations.value_missing', ['feature' => $label]);

                continue;
            }
            if ($selected[0]['status'] !== 'active') {
                $problems[] = __('variations.value_archived', ['feature' => $label]);

                continue;
            }
            $combination[$feature] = $selected[0]['option'];
        }

        return ['problems' => $problems, 'combination' => count($combination) === count($features) && $features !== [] ? $combination : null];
    }

    /**
     * Full validation of a group as currently stored (or with proposed defining
     * features). The reference category/type comes from members other than
     * $focus when possible, so the product being edited is the one reported.
     *
     * @param  list<string>|null  $features
     * @return array{group: list<string>, products: array<int|string, list<string>>, signatures: array<int|string, string>}
     */
    public function problems(string $group, ?array $features = null, ?string $focus = null): array
    {
        $features ??= $this->featureIds($group);
        // The focus product is evaluated last so a duplicate combination is reported on it, not on the existing member.
        $members = ProductRecord::withTrashed()->whereIn('id', $this->memberIds($group))->get()
            ->sortBy(fn (ProductRecord $p): array => [(string) $p->id === $focus ? 1 : 0, $p->id])
            ->keyBy(fn (ProductRecord $p): string => (string) $p->id);
        $result = ['group' => [], 'products' => [], 'signatures' => []];
        if ($features === []) {
            $result['group'][] = __('variations.features_required');
        }
        if ($members->isEmpty()) {
            $result['group'][] = __('variations.members_required');
        }
        foreach ($this->featureProblems($features) as $problem) {
            $result['group'][] = $problem;
        }
        $reference = $members->first(fn (ProductRecord $p): bool => (string) $p->id !== $focus) ?? $members->first();
        $category = $reference?->category_id === null ? null : (string) $reference->category_id;
        if ($reference !== null && ! $this->state->validCategory($category)) {
            $result['group'][] = __('variations.category_invalid');
        }
        $seen = [];
        foreach ($members as $id => $p) {
            ['problems' => $problems, 'combination' => $combination] = $this->memberProblems($p, $features, $category, $reference?->type);
            if ($combination !== null) {
                $signature = $this->signature($combination);
                if (isset($seen[$signature])) {
                    $problems[] = __('variations.duplicate_combination', ['product' => $this->productLabel($seen[$signature])]);
                } else {
                    $seen[$signature] = $id;
                    $result['signatures'][$id] = $signature;
                }
            }
            if ($problems !== []) {
                $result['products'][$id] = $problems;
            }
        }

        return $result;
    }

    /** Throw a validation error listing every problem; otherwise store the current signatures. */
    public function assert(string $group, ?string $focus = null, string $field = 'variation_groups'): void
    {
        $problems = $this->problems($group, null, $focus);
        if ($focus !== null) {
            // A single-product edit is judged on its own values; other members' state is not its responsibility.
            $problems['products'] = array_intersect_key($problems['products'], [$focus => true]);
        }
        $this->throwIfInvalid($problems, $field, $group);
        $this->storeSignatures($group, $problems['signatures']);
    }

    /** @param array{group: list<string>, products: array<int|string, list<string>>, signatures: array<int|string, string>} $problems */
    public function throwIfInvalid(array $problems, string $field, ?string $group = null): void
    {
        if ($problems['group'] === [] && $problems['products'] === []) {
            return;
        }
        $messages = [];
        $prefix = $group === null ? '' : $this->groupLabel($group).': ';
        foreach ($problems['group'] as $message) {
            $messages[] = $prefix.$message;
        }
        foreach ($problems['products'] as $product => $list) {
            foreach ($list as $message) {
                $messages[] = $prefix.$this->productLabel($product).' — '.$message;
            }
        }
        // One key per problem: Inertia keeps only the first message of each key.
        $errors = [];
        foreach (array_values(array_unique($messages)) as $index => $message) {
            $errors[$index === 0 ? $field : $field.'.'.$index] = $message;
        }
        throw ValidationException::withMessages($errors);
    }

    /** @param array<int|string, string> $signatures keyed by product ID */
    public function storeSignatures(string $group, array $signatures): void
    {
        $current = DB::table('variation_group_members')->where('group_id', $group)->pluck('signature', 'product_id')->mapWithKeys(fn ($signature, $product): array => [(string) $product => $signature])->all();
        $changed = array_filter($signatures, fn (string $signature, string $product): bool => ($current[$product] ?? null) !== $signature, ARRAY_FILTER_USE_BOTH);
        // Two-step update so swapping combinations never trips the unique index midway.
        foreach (array_keys($changed) as $product) {
            DB::table('variation_group_members')->where('group_id', $group)->where('product_id', $product)->update(['signature' => '0:'.$product]);
        }
        foreach ($changed as $product => $signature) {
            DB::table('variation_group_members')->where('group_id', $group)->where('product_id', $product)->update(['signature' => $signature]);
        }
    }

    /** Re-validate every group that uses a feature (purpose, status, completeness, scope, options). */
    public function assertFeature(string $feature, string $field = 'variation_groups'): void
    {
        foreach ($this->groupsUsingFeature($feature) as $group) {
            $this->assert($group, null, $field);
        }
    }

    /** Groups whose members sit in any of the given categories.
     * @param  list<string>  $categories
     * @return list<string>
     */
    public function groupsInCategories(array $categories): array
    {
        if ($categories === []) {
            return [];
        }

        return DB::table('variation_group_members as m')->join('products as p', 'p.id', '=', 'm.product_id')->whereIn('p.category_id', $categories)->distinct()->orderBy('m.group_id')->pluck('m.group_id')->map(strval(...))->all();
    }

    public function productLabel(string $product): string
    {
        $row = DB::table('products as p')->leftJoin('product_translations as t', fn ($j) => $j->on('t.product_id', '=', 'p.id')->where('t.locale_id', $this->features->defaultId()))->where('p.id', $product)->first(['p.sku', 't.name']);
        $name = trim($row->name ?? '') !== '' ? $row->name : __('products.untitled', ['id' => $product]);

        return $row?->sku ? $name.' ('.$row->sku.')' : $name;
    }

    public function groupLabel(string $group): string
    {
        return DB::table('variation_groups')->where('id', $group)->value('name').' (#'.$group.')';
    }

    public function featureLabel(string $feature): string
    {
        $definition = $this->features->definitions([$feature])[0] ?? null;

        return $definition === null ? '#'.$feature : ($definition['name'] ?: $definition['code']);
    }

    public function categoryLabel(string $category): string
    {
        return implode(' › ', app(CategoryQueries::class)->paths([$category])[$category] ?? ['#'.$category]);
    }
}
