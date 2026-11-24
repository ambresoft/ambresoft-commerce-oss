<?php

namespace App\Catalog\Infrastructure\Persistence;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Application\Command\VariationGroupData;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Application\Contract\VariationGroupAdministration;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\FeatureQueries;
use App\Catalog\Infrastructure\ProductAudit;
use App\Catalog\Infrastructure\VariationGroupIntegrity;
use App\Catalog\Infrastructure\VariationImpactRequired;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use stdClass;

final class EloquentVariationGroupAdministration implements VariationGroupAdministration
{
    public function __construct(private VariationGroupIntegrity $integrity, private FeatureQueries $features) {}

    // Same Catalog advisory lock as product, feature and category mutations: combination checks are serialized.
    private function begin(string $actor, string ...$abilities): void
    {
        app(AuditContext::class)->actor($actor);
        app(CategoryHierarchy::class)->lock();
        $user = UserRecord::findOrFail($actor);
        foreach ($abilities as $ability) {
            Gate::forUser($user)->authorize($ability);
        }
    }

    /** @param array<string, string> $replace */
    private function fail(string $field, string $message, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => __($message, $replace)]);
    }

    private function group(string $id, int $version, string $field = 'version'): stdClass
    {
        $group = DB::table('variation_groups')->where('id', $id)->lockForUpdate()->first();
        if ($group === null) {
            $this->fail($field, 'variations.unavailable');
        }
        if ((int) $group->lock_version !== $version) {
            $this->fail($field, 'variations.stale');
        }

        return $group;
    }

    private function product(string $id, int $version, string $field = 'product'): ProductRecord
    {
        $product = ProductRecord::withTrashed()->lockForUpdate()->find($id);
        if ($product === null || $product->trashed()) {
            $this->fail($field, 'variations.product_unavailable');
        }
        if ($product->lock_version !== $version) {
            $this->fail($field, 'variations.product_stale', ['product' => $this->integrity->productLabel($id)]);
        }

        return $product;
    }

    private function touch(string $group, string $actor): void
    {
        DB::table('variation_groups')->where('id', $group)->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $context */
    private function audit(string $group, string $actor, string $action, array $context = []): void
    {
        DB::table('variation_group_audits')->insert(['group_id' => $group, 'actor_id' => $actor, 'action' => $action, 'context' => json_encode($context, JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
    }

    /** @param array<string, mixed> $fields
     * @return list<string>
     */
    private function featureInput(array $fields): array
    {
        $v = Validator::make($fields, ['features' => ['required', 'array', 'min:1', 'max:10'], 'features.*' => ['required', 'integer', 'min:1', 'distinct']])->validate();

        return array_map(strval(...), array_values($v['features']));
    }

    /** @param list<string> $features */
    private function storeFeatures(string $group, array $features): void
    {
        $labels = DB::table('variation_card_feature_translations')->where('group_id', $group)->whereIn('feature_id', $features)->get()->map(fn ($row) => (array) $row)->all();
        $shown = DB::table('variation_group_features')->where('group_id', $group)->where('show_on_card', true)->pluck('feature_id')->map(strval(...))->all();
        DB::table('variation_group_features')->where('group_id', $group)->delete();
        foreach ($features as $index => $feature) {
            DB::table('variation_group_features')->insert(['group_id' => $group, 'feature_id' => $feature, 'position' => $index + 1, 'show_on_card' => in_array($feature, $shown, true)]);
        }
        if ($labels !== []) {
            DB::table('variation_card_feature_translations')->insert($labels);
        }
    }

    private function join(string $group, string $product): void
    {
        DB::table('variation_group_members')->insert(['group_id' => $group, 'product_id' => $product, 'signature' => '0:'.$product, 'joined_at' => now()]);
        // Joining a group fulfils or replaces any Create-variation target.
        DB::table('variation_group_intents')->where('product_id', $product)->delete();
    }

    /** Removes the group and its configuration; member products are never touched. */
    private function dissolve(string $group, string $actor, string $action): void
    {
        $members = $this->integrity->memberIds($group);
        $this->audit($group, $actor, $action, ['name' => DB::table('variation_groups')->where('id', $group)->value('name'), 'members' => $members, 'features' => $this->integrity->featureIds($group)]);
        foreach ($members as $member) {
            app(ProductAudit::class)->record($member, $actor, 'variation_left', ['group' => $group, 'reason' => $action]);
        }
        DB::table('variation_groups')->where('id', $group)->delete();
    }

    public function suggestedName(ProductRecord $product): string
    {
        $name = trim((string) $product->translations()->where('locale_id', $this->features->defaultId())->value('name'));

        return mb_substr($name !== '' ? $name : __('products.untitled', ['id' => $product->id]), 0, 200);
    }

    public function create(string $actor, VariationGroupData $data): string
    {
        return app(AuditLogger::class)->operation('variation_group', $actor, function () use ($actor, $data): string {
            return DB::transaction(function () use ($actor, $data): string {
                $v = Validator::make($data->fields, ['product_id' => ['required', 'integer', 'min:1'], 'product_version' => ['required', 'integer', 'min:1'], 'name' => ['nullable', 'string', 'max:200']])->validate();
                $features = $this->featureInput($data->fields);
                $this->begin($actor, 'products.view', 'products.update');
                $product = $this->product((string) $v['product_id'], (int) $v['product_version'], 'product_id');
                if (($existing = $this->integrity->groupOf((string) $product->id)) !== null) {
                    $this->fail('product_id', 'variations.already_grouped', ['group' => $this->integrity->groupLabel($existing)]);
                }
                $name = trim((string) ($v['name'] ?? '')) ?: $this->suggestedName($product);
                $group = (string) DB::table('variation_groups')->insertGetId(['name' => $name, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
                $this->storeFeatures($group, $features);
                $this->join($group, (string) $product->id);
                // Nothing is committed unless the first member and every defining feature are valid.
                $problems = $this->integrity->problems($group);
                $this->integrity->throwIfInvalid($problems, 'variation_groups');
                $this->integrity->storeSignatures($group, $problems['signatures']);
                $this->audit($group, $actor, 'created', ['name' => $name, 'product' => (string) $product->id, 'features' => $features]);
                app(ProductAudit::class)->record((string) $product->id, $actor, 'variation_joined', ['group' => $group]);

                return $group;
            });
        });
    }

    public function listing(string $actor, string $group, VariationGroupData $data): void
    {
        app(AuditLogger::class)->operation('variation_group', $actor, function () use ($actor, $group, $data): void {
            DB::transaction(function () use ($actor, $group, $data): void {
                $rules = ['version' => ['required', 'integer', 'min:1'], 'single_listing' => ['sometimes', 'boolean'], 'single_carousel' => ['sometimes', 'boolean'], 'single_search' => ['sometimes', 'boolean'], 'view_product' => ['sometimes', 'boolean'], 'color_swatches' => ['sometimes', 'boolean'], 'clickable_colors' => ['sometimes', 'boolean'], 'card_features' => ['sometimes', 'array', 'max:10'], 'card_features.*' => ['integer', 'min:1', 'distinct']];
                $locales = app(LocaleCatalog::class)->active();
                $ids = $this->integrity->featureIds($group);
                $rules['card_labels'] = ['sometimes', 'array:'.implode(',', $ids), 'max:10'];
                foreach ($ids as $id) {
                    $rules['card_labels.'.$id] = ['sometimes', 'array:'.implode(',', $locales->pluck('code')->all())];
                    foreach ($locales as $locale) {
                        $rules['card_labels.'.$id.'.'.$locale->code] = ['nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f]/'];
                    }
                }
                $v = Validator::make($data->fields, $rules)->validate();
                $this->begin($actor, 'products.update');
                $this->group($group, (int) $v['version']);
                if (! array_key_exists('card_labels', $v) && ! array_key_exists('card_features', $v) && array_intersect_key($v, array_flip(['single_listing', 'single_carousel', 'single_search', 'view_product', 'color_swatches', 'clickable_colors'])) === []) {
                    $this->fail('single_listing', 'validation.required');
                }
                $settings = array_intersect_key($v, array_flip(['single_listing', 'single_carousel', 'single_search', 'view_product', 'color_swatches', 'clickable_colors']));
                if ($settings !== []) {
                    DB::table('variation_groups')->where('id', $group)->update($settings);
                }
                if (array_key_exists('card_features', $v)) {
                    $features = array_map(strval(...), $v['card_features']);
                    if (array_diff($features, $this->integrity->featureIds($group)) !== []) {
                        $this->fail('card_features', 'validation.in');
                    }
                    DB::table('variation_group_features')->where('group_id', $group)->update(['show_on_card' => false]);
                    DB::table('variation_group_features')->where('group_id', $group)->whereIn('feature_id', $features)->update(['show_on_card' => true]);
                }
                foreach ($v['card_labels'] ?? [] as $feature => $translations) {
                    foreach ($translations as $code => $name) {
                        $key = ['group_id' => $group, 'feature_id' => $feature, 'locale_id' => $locales->firstWhere('code', $code)->id];
                        $name = trim((string) $name);
                        if ($name === '') {
                            DB::table('variation_card_feature_translations')->where($key)->delete();
                        } else {
                            DB::table('variation_card_feature_translations')->updateOrInsert($key, ['name' => $name]);
                        }
                    }
                }
                $this->touch($group, $actor);
                $this->audit($group, $actor, 'listing_changed', array_diff_key($v, ['version' => true]));
            });
        });
    }

    public function rename(string $actor, string $group, VariationGroupData $data): void
    {
        app(AuditLogger::class)->operation('variation_group', $actor, function () use ($actor, $group, $data): void {
            DB::transaction(function () use ($actor, $group, $data): void {
                $locales = app(LocaleCatalog::class)->active();
                $rules = ['version' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:200'], 'translations' => ['sometimes', 'array:'.implode(',', $locales->pluck('code')->all())]];
                foreach ($locales as $locale) {
                    $rules['translations.'.$locale->code] = ['required_with:translations', 'array:name'];
                    $rules['translations.'.$locale->code.'.name'] = ['nullable', 'string', 'max:200', 'not_regex:/[\x00-\x1f\x7f]/'];
                }
                $v = Validator::make($data->fields, $rules)->validate();
                $this->begin($actor, 'products.update');
                $g = $this->group($group, (int) $v['version']);
                $name = trim($v['name']);
                if ($name === '') {
                    $this->fail('name', 'variations.name_required');
                }
                DB::table('variation_groups')->where('id', $group)->update(['name' => $name]);
                if (array_key_exists('translations', $v)) {
                    foreach ($locales as $locale) {
                        DB::table('variation_group_translations')->updateOrInsert(['group_id' => $group, 'locale_id' => $locale->id], ['name' => trim((string) ($v['translations'][$locale->code]['name'] ?? ''))]);
                    }
                }

                $this->touch($group, $actor);
                $this->audit($group, $actor, 'renamed', ['from' => $g->name, 'to' => $name]);
            });
        });
    }

    public function configure(string $actor, string $group, VariationGroupData $data): void
    {
        app(AuditLogger::class)->operation('variation_group', $actor, function () use ($actor, $group, $data): void {
            DB::transaction(function () use ($actor, $group, $data): void {
                $v = Validator::make($data->fields, ['version' => ['required', 'integer', 'min:1'], 'confirmed' => ['sometimes', 'boolean'], 'impact_token' => ['nullable', 'string', 'size:64']])->validate();
                $proposed = $this->featureInput($data->fields);
                $this->begin($actor, 'products.update');
                $g = $this->group($group, (int) $v['version']);
                $current = $this->integrity->featureIds($group);
                $sortedCurrent = $current;
                $sortedProposed = $proposed;
                sort($sortedCurrent);
                sort($sortedProposed);
                if ($sortedCurrent === $sortedProposed) {
                    $this->fail('features', 'variations.features_unchanged');
                }
                $members = $this->integrity->memberIds($group);
                $memberVersions = ProductRecord::whereIn('id', $members)->orderBy('id')->pluck('lock_version', 'id')->all();
                $this->storeFeatures($group, $proposed);
                // Evaluate every member against the proposed set; invalid values or collisions block the change.
                $problems = $this->integrity->problems($group);
                $this->integrity->throwIfInvalid($problems, 'features', $group);
                $impact = [
                    'members' => count($members),
                    'added' => array_values(array_map(fn (string $f): string => $this->integrity->featureLabel($f), array_diff($proposed, $current))),
                    'removed' => array_values(array_map(fn (string $f): string => $this->integrity->featureLabel($f), array_diff($current, $proposed))),
                    'combinations' => $this->combinationPreview($proposed, $members),
                ];
                $token = hash_hmac('sha256', json_encode([$group, $g->lock_version, $proposed, $memberVersions, $problems['signatures']], JSON_THROW_ON_ERROR), (string) config('app.key'));
                if (! ($v['confirmed'] ?? false) || ! hash_equals($token, (string) ($v['impact_token'] ?? ''))) {
                    throw new VariationImpactRequired($impact, $token);
                }
                $this->integrity->storeSignatures($group, $problems['signatures']);
                $this->touch($group, $actor);
                $this->audit($group, $actor, 'features_changed', ['from' => $current, 'to' => $proposed]);
            });
        });
    }

    /** @param list<string> $features
     * @param  list<string>  $members
     * @return list<array{product: string, values: string}>
     */
    private function combinationPreview(array $features, array $members): array
    {
        $labels = [];
        foreach ($this->features->definitions($features) as $definition) {
            foreach ($definition['options'] as $option) {
                $labels[$option['id']] = ($definition['name'] ?: $definition['code']).': '.$option['label'];
            }
        }
        $preview = [];
        foreach ($members as $member) {
            $choices = $this->integrity->choices($member, $features);
            $preview[] = ['product' => $this->integrity->productLabel($member), 'values' => implode(' / ', array_map(fn (string $f): string => $labels[$choices[$f][0]['option'] ?? ''] ?? '—', $features))];
        }

        return $preview;
    }

    public function reorder(string $actor, string $group, VariationGroupData $data): void
    {
        app(AuditLogger::class)->operation('variation_group', $actor, function () use ($actor, $group, $data): void {
            DB::transaction(function () use ($actor, $group, $data): void {
                $v = Validator::make($data->fields, ['version' => ['required', 'integer', 'min:1']])->validate();
                $order = $this->featureInput($data->fields);
                $this->begin($actor, 'products.update');
                $this->group($group, (int) $v['version']);
                $current = $this->integrity->featureIds($group);
                $sortedCurrent = $current;
                $sortedOrder = $order;
                sort($sortedCurrent);
                sort($sortedOrder);
                if ($sortedCurrent !== $sortedOrder) {
                    $this->fail('features', 'variations.order_complete');
                }
                // Presentation only: signatures are sorted by feature ID and stay unchanged.
                $this->storeFeatures($group, $order);
                $this->touch($group, $actor);
                $this->audit($group, $actor, 'reordered', ['from' => $current, 'to' => $order]);
            });
        });
    }

    public function add(string $actor, string $group, VariationGroupData $data): void
    {
        app(AuditLogger::class)->operation('variation_group', $actor, function () use ($actor, $group, $data): void {
            DB::transaction(function () use ($actor, $group, $data): void {
                $v = Validator::make($data->fields, ['version' => ['required', 'integer', 'min:1'], 'products' => ['required', 'array', 'min:1', 'max:50'], 'products.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'products.*.version' => ['required', 'integer', 'min:1']])->validate();
                $this->begin($actor, 'products.update');
                $this->group($group, (int) $v['version']);
                $errors = [];
                $ids = [];
                foreach ($v['products'] as $candidate) {
                    $id = (string) $candidate['id'];
                    try {
                        $this->product($id, (int) $candidate['version'], 'products.'.$id);
                    } catch (ValidationException $e) {
                        $errors += $e->errors();

                        continue;
                    }
                    if (($existing = $this->integrity->groupOf($id)) !== null) {
                        $errors['products.'.$id] = [__($existing === $group ? 'variations.already_member' : 'variations.use_transfer', ['group' => $this->integrity->groupLabel($existing)])];

                        continue;
                    }
                    $ids[] = $id;
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
                foreach ($ids as $id) {
                    $this->join($group, $id);
                }
                // The whole batch is validated together, including duplicates among the candidates.
                $problems = $this->integrity->problems($group);
                if ($problems['group'] !== [] || $problems['products'] !== []) {
                    $messages = [];
                    foreach ($problems['products'] as $product => $list) {
                        $key = in_array($product, $ids, true) ? 'products.'.$product : 'variation_groups';
                        foreach ($list as $message) {
                            $messages[$key][] = $key === 'variation_groups' ? $this->integrity->productLabel($product).' — '.$message : $message;
                        }
                    }
                    foreach ($problems['group'] as $message) {
                        $messages['variation_groups'][] = $message;
                    }
                    $errors = [];
                    foreach ($messages as $key => $list) {
                        foreach (array_values(array_unique($list)) as $index => $message) {
                            $errors[$index === 0 ? $key : $key.'.'.$index] = $message;
                        }
                    }
                    throw ValidationException::withMessages($errors);
                }
                $this->integrity->storeSignatures($group, $problems['signatures']);
                $this->touch($group, $actor);
                $this->audit($group, $actor, count($ids) > 1 ? 'members_added_bulk' : 'member_added', ['products' => $ids]);
                foreach ($ids as $id) {
                    app(ProductAudit::class)->record($id, $actor, 'variation_joined', ['group' => $group]);
                }
            });
        });
    }

    public function remove(string $actor, string $group, string $product, VariationGroupData $data): void
    {
        app(AuditLogger::class)->operation('variation_group', $actor, function () use ($actor, $group, $product, $data): void {
            DB::transaction(function () use ($actor, $group, $product, $data): void {
                $v = Validator::make($data->fields, ['version' => ['required', 'integer', 'min:1'], 'product_version' => ['required', 'integer', 'min:1']])->validate();
                $this->begin($actor, 'products.update');
                $this->group($group, (int) $v['version']);
                if ($this->integrity->groupOf($product) !== $group) {
                    $this->fail('product', 'variations.not_member');
                }
                $this->product($product, (int) $v['product_version']);
                // Only the relationship is removed; the product and all its data stay as they are.
                DB::table('variation_group_members')->where('group_id', $group)->where('product_id', $product)->delete();
                app(ProductAudit::class)->record($product, $actor, 'variation_left', ['group' => $group, 'reason' => 'removed']);
                if ($this->integrity->memberIds($group) === []) {
                    $this->audit($group, $actor, 'member_removed', ['product' => $product]);
                    $this->dissolve($group, $actor, 'auto_disbanded');

                    return;
                }
                $this->touch($group, $actor);
                $this->audit($group, $actor, 'member_removed', ['product' => $product]);
            });
        });
    }

    public function transfer(string $actor, string $group, string $product, VariationGroupData $data): void
    {
        app(AuditLogger::class)->operation('variation_group', $actor, function () use ($actor, $group, $product, $data): void {
            DB::transaction(function () use ($actor, $group, $product, $data): void {
                $v = Validator::make($data->fields, ['version' => ['required', 'integer', 'min:1'], 'product_version' => ['required', 'integer', 'min:1'], 'target' => ['required', 'integer', 'min:1'], 'target_version' => ['required', 'integer', 'min:1']])->validate();
                $target = (string) $v['target'];
                $this->begin($actor, 'products.update');
                if ($target === $group) {
                    $this->fail('target', 'variations.same_group');
                }
                // Lock in ID order so two opposite transfers cannot deadlock.
                foreach ($group < $target ? [[$group, $v['version'], 'version'], [$target, $v['target_version'], 'target']] : [[$target, $v['target_version'], 'target'], [$group, $v['version'], 'version']] as [$id, $version, $field]) {
                    $this->group((string) $id, (int) $version, $field);
                }
                if ($this->integrity->groupOf($product) !== $group) {
                    $this->fail('product', 'variations.not_member');
                }
                $this->product($product, (int) $v['product_version']);
                // Membership only: product fields and feature values are never changed by a transfer.
                DB::table('variation_group_members')->where('group_id', $group)->where('product_id', $product)->delete();
                $this->join($target, $product);
                $problems = $this->integrity->problems($target, null, $product);
                $this->integrity->throwIfInvalid($problems, 'target', $target);
                $this->integrity->storeSignatures($target, $problems['signatures']);
                $this->touch($target, $actor);
                $this->audit($target, $actor, 'member_transferred_in', ['product' => $product, 'from' => $group]);
                $this->audit($group, $actor, 'member_transferred_out', ['product' => $product, 'to' => $target]);
                app(ProductAudit::class)->record($product, $actor, 'variation_transferred', ['from' => $group, 'to' => $target]);
                if ($this->integrity->memberIds($group) === []) {
                    $this->dissolve($group, $actor, 'auto_disbanded');
                } else {
                    $this->touch($group, $actor);
                }
            });
        });
    }

    public function disband(string $actor, string $group, VariationGroupData $data): void
    {
        app(AuditLogger::class)->operation('variation_group', $actor, function () use ($actor, $group, $data): void {
            DB::transaction(function () use ($actor, $group, $data): void {
                $v = Validator::make($data->fields, ['version' => ['required', 'integer', 'min:1']])->validate();
                $this->begin($actor, 'products.update');
                $this->group($group, (int) $v['version']);
                $this->dissolve($group, $actor, 'disbanded');
            });
        });
    }

    public function createVariation(string $actor, string $group, VariationGroupData $data): string
    {
        return app(AuditLogger::class)->operation('variation_group', $actor, function () use ($actor, $group, $data): string {
            return DB::transaction(function () use ($actor, $group, $data): string {
                $v = Validator::make($data->fields, ['version' => ['required', 'integer', 'min:1'], 'source' => ['required', 'integer', 'min:1'], 'source_version' => ['required', 'integer', 'min:1'], 'options' => ['required', 'array'], 'options.*' => ['required', 'integer', 'min:1']])->validate();
                $this->begin($actor, 'products.view', 'products.create', 'products.update');
                $this->group($group, (int) $v['version']);
                $source = (string) $v['source'];
                if ($this->integrity->groupOf($source) !== $group) {
                    $this->fail('source', 'variations.not_member');
                }
                $features = $this->integrity->featureIds($group);
                $options = array_map(strval(...), $v['options']);
                $keys = array_map(strval(...), array_keys($options));
                sort($keys);
                $expected = $features;
                sort($expected);
                if ($keys !== $expected) {
                    $this->fail('options', 'variations.combination_incomplete');
                }
                if (($featureProblems = $this->integrity->featureProblems($features)) !== []) {
                    throw ValidationException::withMessages(['options' => array_values($featureProblems)]);
                }
                foreach ($options as $feature => $option) {
                    if (! DB::table('feature_options')->where('id', $option)->where('feature_id', $feature)->where('status', 'active')->exists()) {
                        $this->fail('options.'.$feature, 'variations.option_invalid', ['feature' => $this->integrity->featureLabel((string) $feature)]);
                    }
                }
                // Uniqueness is checked against the members' current values, not cached signatures.
                $signature = $this->integrity->signature(array_combine($keys, array_map(fn (string $k): string => $options[$k], $keys)));
                $current = $this->integrity->problems($group)['signatures'];
                if (($conflict = array_search($signature, $current, true)) !== false) {
                    $this->fail('options', 'variations.combination_reserved', ['product' => $this->integrity->productLabel((string) $conflict)]);
                }
                // Existing Phase 4 duplication: Disabled, SKU/EAN cleared, new slugs, copied media and compatible values.
                $copy = app(ProductAdministration::class)->duplicate($actor, $source, (int) $v['source_version']);
                foreach ($options as $feature => $option) {
                    $assignment = DB::table('product_feature_assignments')->where('product_id', $copy)->where('feature_id', $feature)->first();
                    if ($assignment === null) {
                        $id = DB::table('product_feature_assignments')->insertGetId(['product_id' => $copy, 'feature_id' => $feature, 'type' => 'single']);
                    } else {
                        $id = $assignment->id;
                        DB::table('product_feature_assignments')->where('id', $id)->update(['lock_version' => DB::raw('lock_version + 1'), 'is_applicable' => true]);
                    }
                    DB::table('product_feature_choices')->where('assignment_id', $id)->delete();
                    DB::table('product_feature_choices')->insert(['assignment_id' => $id, 'feature_id' => $feature, 'type' => 'single', 'option_id' => $option]);
                }
                // A target only: the copy stays standalone until a complete save passes every membership rule.
                DB::table('variation_group_intents')->insert(['product_id' => $copy, 'group_id' => $group]);
                $this->audit($group, $actor, 'variation_created', ['source' => $source, 'product' => $copy, 'combination' => $options]);
                app(ProductAudit::class)->record($copy, $actor, 'variation_prepared', ['group' => $group, 'combination' => $options]);

                return $copy;
            });
        });
    }

    public function joinIntended(string $actor, string $product): array
    {
        $group = DB::table('variation_group_intents')->where('product_id', $product)->value('group_id');
        if ($group === null) {
            return ['status' => 'none'];
        }
        $group = (string) $group;
        if ($this->integrity->groupOf($product) !== null) {
            DB::table('variation_group_intents')->where('product_id', $product)->delete();

            return ['status' => 'none'];
        }
        try {
            // Savepoint: a failed join leaves the saved standalone product untouched.
            DB::transaction(function () use ($group, $product): void {
                DB::table('variation_group_members')->insert(['group_id' => $group, 'product_id' => $product, 'signature' => '0:'.$product, 'joined_at' => now()]);
                $problems = $this->integrity->problems($group, null, $product);
                $problems['products'] = array_intersect_key($problems['products'], [$product => true]);
                $this->integrity->throwIfInvalid(['group' => $problems['group'], 'products' => $problems['products'], 'signatures' => []], 'variation_groups');
                $this->integrity->storeSignatures($group, [$product => $problems['signatures'][$product]]);
            });
        } catch (ValidationException $e) {
            return ['status' => 'pending', 'group' => $group, 'name' => $this->integrity->groupLabel($group), 'problems' => array_merge(...array_values($e->errors()))];
        }
        DB::table('variation_group_intents')->where('product_id', $product)->delete();
        $this->touch($group, $actor);
        $this->audit($group, $actor, 'member_added', ['products' => [$product], 'source' => 'create_variation']);
        app(ProductAudit::class)->record($product, $actor, 'variation_joined', ['group' => $group]);

        return ['status' => 'joined', 'group' => $group, 'name' => $this->integrity->groupLabel($group)];
    }

    public function detachForTrash(string $actor, string $product): void
    {
        DB::table('variation_group_intents')->where('product_id', $product)->delete();
        $group = $this->integrity->groupOf($product);
        if ($group === null) {
            return;
        }
        DB::table('variation_group_members')->where('product_id', $product)->delete();
        app(ProductAudit::class)->record($product, $actor, 'variation_left', ['group' => $group, 'reason' => 'trashed']);
        $this->audit($group, $actor, 'member_trashed', ['product' => $product]);
        if ($this->integrity->memberIds($group) === []) {
            $this->dissolve($group, $actor, 'auto_disbanded');
        } else {
            $this->touch($group, $actor);
        }
    }
}
