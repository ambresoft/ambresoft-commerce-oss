<?php

namespace App\Catalog\Infrastructure\Persistence;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Application\Command\SaveFeatureData;
use App\Catalog\Application\Contract\FeatureAdministration;
use App\Catalog\Domain\Service\FeatureRules;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\FeatureImpactRequired;
use App\Catalog\Infrastructure\FeatureInput;
use App\Catalog\Infrastructure\FeatureQueries;
use App\Catalog\Infrastructure\ProductFeatures;
use App\Catalog\Infrastructure\VariationGroupIntegrity;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Media\Infrastructure\SharedMediaDeletion;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

final class EloquentFeatureAdministration implements FeatureAdministration
{
    public function __construct(private FeatureInput $input, private FeatureQueries $queries, private PrivateImageStorage $images) {}

    private function authorize(string $actor, string $ability): void
    {
        app(AuditContext::class)->actor($actor);
        app(CategoryHierarchy::class)->lock();
        Gate::forUser(UserRecord::findOrFail($actor))->authorize($ability);
    }

    private function record(string $table, string $id, int $version): stdClass
    {
        $record = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        abort_if($record === null, 404);
        if ($record->lock_version !== $version) {
            $this->fail('version', 'features.stale');
        }

        return $record;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }

    private function editable(stdClass $record): void
    {
        if ($record->status === 'archived') {
            $this->fail('status', 'features.readonly');
        }
    }

    /** @param array<string, mixed> $data
     * @param  array<string, int|string>  $impact
     */
    private function confirm(array $data, array $impact, bool $required): void
    {
        if (! $required) {
            return;
        }
        unset($data['confirmed'], $data['impact_token']);
        // Bind confirmation to the exact submitted change and current product/configuration snapshot.
        $products = DB::table('products')->selectRaw('count(*) as n, COALESCE(sum(lock_version),0)::text as versions')->first();
        $token = hash_hmac('sha256', json_encode([$data, $impact, $this->queries->revision(), $products], JSON_THROW_ON_ERROR), (string) config('app.key'));
        $submitted = $this->confirmation;
        if (! ($submitted['confirmed'] ?? false) || ! hash_equals($token, $submitted['impact_token'] ?? '')) {
            throw new FeatureImpactRequired($impact, $token);
        }
    }

    /** @var array<string, mixed> */
    private array $confirmation = [];

    /** @param array<string, mixed> $context */
    private function audit(string $actor, string $entity, string $id, string $action, array $context = []): void
    {
        DB::table('feature_audits')->insert(['actor_id' => $actor, 'entity' => $entity, 'entity_id' => $id, 'action' => $action, 'context' => json_encode($context, JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
        DB::table('feature_catalog_state')->where('id', 1)->increment('version');
    }

    private function complete(string $id): bool
    {
        return DB::table('features as f')->where('f.id', $id)->whereRaw($this->queries->completeSql())->exists();
    }

    public function save(string $actor, ?string $id, SaveFeatureData $data): string
    {
        return app(AuditLogger::class)->operation('feature', $actor, function () use ($actor, $id, $data): string {
            return DB::transaction(function () use ($actor, $id, $data): string {
                $this->authorize($actor, $id === null ? 'features.create' : 'features.update');
                $v = $this->input->validate($data->fields);
                $this->confirmation = $v;
                $old = $id === null ? null : $this->record('features', $id, (int) ($v['version'] ?? 0));
                if ($old !== null) {
                    $this->editable($old);
                    if ($old->type !== $v['type']) {
                        $this->fail('type', 'features.immutable');
                    }
                }
                $previousMissing = $id === null ? 0 : $this->queries->missing()->where('f.id', $id)->count();
                $group = (string) ($v['group_id'] ?? DB::table('feature_groups')->where('system_key', 'general')->value('id'));
                $purpose = $v['purpose'] ?? $old->purpose ?? 'standard';
                if ($purpose === 'landing_pages' && $old?->purpose !== $purpose && app(GeneralSettings::class)->all()['brand_landing_pages']) {
                    $this->fail('purpose', 'features.brand_landing_pages_enabled');
                }
                // Selector style is shared feature configuration; it is kept while another purpose is active.
                $style = $v['variation_style'] ?? $old->variation_style ?? 'text';
                if ($old !== null && $old->purpose === 'variations' && $purpose !== 'variations' && ($groups = app(VariationGroupIntegrity::class)->groupsUsingFeature($id)) !== []) {
                    $this->fail('purpose', __('variations.purpose_in_use', ['groups' => implode(', ', array_map(app(VariationGroupIntegrity::class)->groupLabel(...), $groups))]));
                }
                $values = ['type' => $v['type'], 'purpose' => $purpose, 'variation_style' => $style, 'group_id' => $group, 'required' => $v['required'], 'filterable' => $v['filterable'], 'comparison' => $v['comparison'], 'unit' => trim($v['unit'] ?? '') ?: null, 'updated_at' => now()];
                if ($old === null || (string) $old->group_id !== $group) {
                    $values['position'] = (int) DB::table('features')->where('group_id', $group)->max('position') + 1;
                }
                $unitCount = $old !== null && $old->unit !== $values['unit'] ? DB::table('product_feature_assignments')->where('feature_id', $id)->whereNotNull('number_value')->count() : 0;
                if ($id === null) {
                    $name = $v['translations'][app(LocaleCatalog::class)->defaultCode()]['name'] ?? '';
                    $id = (string) DB::table('features')->insertGetId([...$values, 'code' => $this->input->code($name), 'created_at' => now()]);
                } else {
                    DB::table('features')->where('id', $id)->update([...$values, 'lock_version' => DB::raw('lock_version + 1')]);
                }
                $this->input->translations('feature_translations', 'feature_id', $id, $v['translations'], FeatureInput::TEXT);
                DB::table('feature_categories')->where('feature_id', $id)->delete();
                foreach ($v['categories'] as $category) {
                    DB::table('feature_categories')->insert(['feature_id' => $id, 'category_id' => $category]);
                }
                $deactivated = DB::table('product_feature_assignments as a')->join('products as p', 'p.id', '=', 'a.product_id')->where('a.feature_id', $id)->where('a.is_applicable', true)->whereRaw('NOT '.$this->queries->appliesSql('p.category_id', 'a.feature_id'))->count();
                app(ProductFeatures::class)->reconcile(feature: $id);
                $missing = $this->queries->missing()->where('f.id', $id)->count();
                $impact = ['unit_products' => $unitCount, 'inactive_products' => $deactivated, 'missing_products' => $missing];
                $purposeChanged = $old !== null && $old->purpose !== $purpose;
                // Hidden variant metadata is preserved, but the administrator must acknowledge it becomes unavailable.
                if ($purposeChanged && $old->purpose === 'landing_pages') {
                    $impact['landing_variants'] = $this->queries->landingMetadataCount($id);
                }
                // Scope, completeness or definition changes must keep every group using this feature valid.
                if ($old !== null) {
                    app(VariationGroupIntegrity::class)->assertFeature($id);
                }
                $requiredChange = $v['required'] && ($old === null || ! $old->required);
                $this->confirm(['target' => $old?->id, ...$v], $impact, $unitCount > 0 || $deactivated > 0 || (($requiredChange || $missing > $previousMissing) && $missing > 0) || ($impact['landing_variants'] ?? 0) > 0);
                $this->audit($actor, 'feature', $id, $old === null ? 'created' : 'updated', ['code' => DB::table('features')->where('id', $id)->value('code'), 'previous' => $old === null ? null : (array) $old, 'settings' => $values, 'impact' => $impact]);
                if ($purposeChanged) {
                    $this->audit($actor, 'feature', $id, 'purpose_changed', ['from' => $old->purpose, 'to' => $purpose, 'landing_variants' => $this->queries->landingMetadataCount($id)]);
                }
                if ($old !== null && $old->variation_style !== $style) {
                    $this->audit($actor, 'feature', $id, 'variation_style_changed', ['from' => $old->variation_style, 'to' => $style, 'groups' => app(VariationGroupIntegrity::class)->groupsUsingFeature($id)]);
                }

                return $id;
            });

        });
    }

    public function action(string $actor, string $id, SaveFeatureData $data): void
    {
        app(AuditLogger::class)->operation('feature', $actor, function () use ($actor, $id, $data): void {
            DB::transaction(function () use ($actor, $id, $data): void {
                $v = Validator::make($data->fields, ['version' => ['required', 'integer', 'min:1'], 'action' => ['required', Rule::in(['activate', 'archive', 'restore', 'delete'])], 'confirmed' => ['sometimes', 'boolean'], 'impact_token' => ['nullable', 'string', 'size:64']])->validate();
                $this->authorize($actor, $v['action'] === 'delete' ? 'features.delete' : 'features.status');
                $this->confirmation = $v;
                $f = $this->record('features', $id, $v['version']);
                $count = DB::table('product_feature_assignments')->where('feature_id', $id)->count();
                if (in_array($v['action'], ['archive', 'delete'], true) && ($groups = app(VariationGroupIntegrity::class)->groupsUsingFeature($id)) !== []) {
                    $this->fail('feature', __('variations.feature_in_use', ['groups' => implode(', ', array_map(app(VariationGroupIntegrity::class)->groupLabel(...), $groups))]));
                }
                if ($v['action'] === 'delete' && $count > 0) {
                    $this->fail('feature', 'features.assigned_delete');
                }
                if ($v['action'] === 'restore' && $f->status !== 'archived') {
                    $this->fail('status', 'features.stale');
                }
                if (in_array($v['action'], ['activate', 'restore'], true) && ! $this->complete($id)) {
                    $this->fail('feature', 'features.incomplete_block');
                }
                if ($v['action'] === 'activate') {
                    $this->editable($f);
                }
                if ($v['action'] === 'archive') {
                    $this->editable($f);
                }
                // Preview restored required rules without committing until confirmation succeeds.
                if ($v['action'] === 'restore') {
                    DB::table('features')->where('id', $id)->update(['status' => 'active']);
                }
                $impact = ['assigned_products' => $count, 'missing_products' => $this->queries->missing()->where('f.id', $id)->count()];
                $this->confirm([...$v, 'id' => $id], $impact, in_array($v['action'], ['archive', 'delete'], true) || ($v['action'] === 'restore' && array_sum($impact) > 0));
                if ($v['action'] === 'delete') {
                    $media = [...DB::table('feature_options')->where('feature_id', $id)->whereNotNull('landing_image_media_id')->pluck('landing_image_media_id')->map(strval(...))->all(), ...DB::table('feature_options')->where('feature_id', $id)->whereNotNull('swatch_image_media_id')->pluck('swatch_image_media_id')->map(strval(...))->all()];
                    DB::table('features')->where('id', $id)->delete();
                    $this->releaseImages($media);
                } else {
                    DB::table('features')->where('id', $id)->update(['status' => $v['action'] === 'archive' ? 'archived' : 'active', 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
                    app(ProductFeatures::class)->reconcile(feature: $id);
                }
                $this->audit($actor, 'feature', $id, $v['action'], $impact);
            });

        });
    }

    public function option(string $actor, string $feature, ?string $id, SaveFeatureData $data): string
    {
        return app(AuditLogger::class)->operation('feature', $actor, function () use ($actor, $feature, $id, $data): string {
            $stored = null;
            $swatch = null;
            try {
                return DB::transaction(function () use ($actor, $feature, $id, $data, &$stored, &$swatch): string {
                    $this->authorize($actor, 'features.update');
                    // Read under the Catalog lock; the version check below rejects a stale purpose snapshot.
                    $purpose = DB::table('features')->where('id', $feature)->value('purpose');
                    $landing = $purpose === 'landing_pages' && ! app(GeneralSettings::class)->all()['brand_landing_pages'];
                    // Swatch data belongs to the variation purpose; it is kept (hidden) under other purposes.
                    $variations = $purpose === 'variations';
                    $fields = $landing ? ['label', ...FeatureInput::LANDING] : ['label'];
                    $v = Validator::make($data->fields, ['version' => ['sometimes', 'integer', 'min:1'], 'feature_version' => ['required', 'integer', 'min:1'], 'action' => ['required', Rule::in(['save', 'archive', 'restore', 'delete'])], 'confirmed' => ['sometimes', 'boolean'], 'impact_token' => ['nullable', 'string', 'size:64'], 'remove_image' => $landing ? ['sometimes', 'boolean'] : ['prohibited'], 'swatch_color' => $variations ? ['sometimes', 'nullable', 'string', 'max:20'] : ['prohibited'], 'remove_swatch_image' => $variations ? ['sometimes', 'boolean'] : ['prohibited'], ...$this->input->translationRules($fields)])->validate();
                    $this->confirmation = $v;
                    $f = $this->record('features', $feature, $v['feature_version']);
                    $this->editable($f);
                    if (! in_array($f->type, ['single', 'multiple'], true)) {
                        $this->fail('type', 'features.choice_only');
                    }
                    if ($data->image !== null && (! $landing || $v['action'] !== 'save')) {
                        $this->fail('image', 'features.landing_unavailable');
                    }
                    if ($data->image !== null && ! empty($v['remove_image'])) {
                        $this->fail('image', 'features.image_conflict');
                    }
                    if ($data->swatchImage !== null && (! $variations || $v['action'] !== 'save')) {
                        $this->fail('swatch_image', 'features.swatch_unavailable');
                    }
                    if ($data->swatchImage !== null && ! empty($v['remove_swatch_image'])) {
                        $this->fail('swatch_image', 'features.image_conflict');
                    }
                    $o = $id === null ? null : $this->record('feature_options', $id, (int) ($v['version'] ?? 0));
                    if ($o !== null && (string) $o->feature_id !== $feature) {
                        $this->fail('option', 'features.invalid_option');
                    }
                    if ($o === null && $v['action'] !== 'save') {
                        $this->fail('action', 'features.invalid_option');
                    }
                    $count = $id === null ? 0 : DB::table('product_feature_choices')->where('option_id', $id)->count();
                    $context = ['feature' => $feature, 'assigned_products' => $count];
                    if ($v['action'] === 'save') {
                        if ($o !== null) {
                            $this->editable($o);
                        }
                        if ($id === null) {
                            $id = (string) DB::table('feature_options')->insertGetId(['feature_id' => $feature, 'position' => (int) DB::table('feature_options')->where('feature_id', $feature)->max('position') + 1]);
                        } else {
                            DB::table('feature_options')->where('id', $id)->increment('lock_version');
                        }
                        $before = $this->optionTexts($id, $fields);
                        $this->input->translations('feature_option_translations', 'option_id', $id, $v['translations'], $fields);
                        $changed = [];
                        foreach ($this->optionTexts($id, $fields) as $locale => $text) {
                            $diff = array_keys(array_diff_assoc($text, $before[$locale] ?? array_fill_keys($fields, '')));
                            if ($diff !== []) {
                                $changed[$locale] = $diff;
                            }
                        }
                        $context['changed'] = $changed;
                        $previousImage = $o?->landing_image_media_id === null ? null : (string) $o->landing_image_media_id;
                        if ($data->image !== null || (! empty($v['remove_image']) && $previousImage !== null)) {
                            $image = null;
                            if ($data->image !== null) {
                                $stored = $this->images->store($data->image);
                                $image = (string) MediaFileRecord::create($stored)->id;
                            }
                            DB::table('feature_options')->where('id', $id)->update(['landing_image_media_id' => $image]);
                            $this->releaseImages($previousImage === null ? [] : [$previousImage]);
                            $context['image'] = $image === null ? 'removed' : ($previousImage === null ? 'added' : 'replaced');
                        }
                        if (array_key_exists('swatch_color', $v)) {
                            $color = trim((string) ($v['swatch_color'] ?? '')) === '' ? null : FeatureRules::swatchColor((string) $v['swatch_color']);
                            if ($color === null && trim((string) ($v['swatch_color'] ?? '')) !== '') {
                                $this->fail('swatch_color', 'features.swatch_color_invalid');
                            }
                            if ($color !== ($o?->swatch_color)) {
                                DB::table('feature_options')->where('id', $id)->update(['swatch_color' => $color]);
                                $context['swatch_color'] = ['from' => $o?->swatch_color, 'to' => $color];
                            }
                        }
                        $previousSwatch = $o?->swatch_image_media_id === null ? null : (string) $o->swatch_image_media_id;
                        if ($data->swatchImage !== null || (! empty($v['remove_swatch_image']) && $previousSwatch !== null)) {
                            $image = null;
                            if ($data->swatchImage !== null) {
                                $swatch = $this->images->store($data->swatchImage);
                                $image = (string) MediaFileRecord::create($swatch)->id;
                            }
                            DB::table('feature_options')->where('id', $id)->update(['swatch_image_media_id' => $image]);
                            $this->releaseImages($previousSwatch === null ? [] : [$previousSwatch]);
                            $context['swatch_image'] = $image === null ? 'removed' : ($previousSwatch === null ? 'added' : 'replaced');
                        }
                    } else {
                        if ($v['action'] === 'delete' && $count > 0) {
                            $this->fail('option', 'features.assigned_delete');
                        }
                        if ($v['action'] === 'restore') {
                            if ($o->status !== 'archived' || ! $this->complete($feature) || ! DB::table('feature_option_translations')->where('option_id', $id)->where('locale_id', $this->queries->defaultId())->whereRaw('length(btrim(label)) > 0')->exists()) {
                                $this->fail('option', 'features.option_restore_block');
                            }
                        } elseif ($v['action'] === 'archive') {
                            $this->editable($o);
                        }
                        $this->confirm([...$v, 'id' => $id, 'feature' => $feature], ['assigned_products' => $count], $v['action'] === 'delete' || $count > 0);
                        if ($v['action'] === 'delete') {
                            DB::table('feature_options')->where('id', $id)->delete();
                            $this->releaseImages(array_values(array_filter([$o->landing_image_media_id === null ? null : (string) $o->landing_image_media_id, $o->swatch_image_media_id === null ? null : (string) $o->swatch_image_media_id])));
                        } else {
                            DB::table('feature_options')->where('id', $id)->update(['status' => $v['action'] === 'archive' ? 'archived' : 'active', 'lock_version' => DB::raw('lock_version + 1')]);
                        }
                    }
                    DB::table('features')->where('id', $feature)->increment('lock_version');
                    // Archiving a used defining option or blanking labels must not invalidate any group.
                    app(VariationGroupIntegrity::class)->assertFeature($feature, 'option');
                    $this->audit($actor, 'option', $id, $v['action'], $context);

                    return $id;
                });
            } catch (Throwable $failure) {
                foreach ([$stored, $swatch] as $file) {
                    if ($file !== null && ! MediaFileRecord::where('storage_key', $file['storage_key'])->exists()) {
                        $this->images->discard($file['storage_key']);
                    }
                }
                throw $failure;
            }

        });
    }

    /** @param list<string> $fields
     * @return array<string, array<string, string>>
     */
    private function optionTexts(string $option, array $fields): array
    {
        $locales = app(LocaleCatalog::class)->active()->pluck('code', 'id')->all();
        $texts = [];
        foreach (DB::table('feature_option_translations')->where('option_id', $option)->get() as $row) {
            if (isset($locales[$row->locale_id])) {
                $texts[$locales[$row->locale_id]] = array_map(fn ($value): string => (string) $value, array_intersect_key((array) $row, array_flip($fields)));
            }
        }

        return $texts;
    }

    /** Delete detached variant images only when no other owner still references the shared media file.
     * @param  list<string>  $ids
     */
    private function releaseImages(array $ids): void
    {
        foreach (MediaFileRecord::whereIn('id', $ids)->lockForUpdate()->get() as $record) {
            if (app(SharedMediaDeletion::class)->referenced((string) $record->id)) {
                continue;
            }
            $disk = $record->disk;
            $key = $record->storage_key;
            $record->delete();
            DB::afterCommit(fn () => Storage::disk($disk)->delete($key));
        }
    }

    public function group(string $actor, ?string $id, SaveFeatureData $data): string
    {
        return app(AuditLogger::class)->operation('feature', $actor, function () use ($actor, $id, $data): string {
            return DB::transaction(function () use ($actor, $id, $data): string {
                $this->authorize($actor, 'features.groups');
                $v = Validator::make($data->fields, ['version' => ['sometimes', 'integer', 'min:1'], 'action' => ['required', Rule::in(['save', 'delete'])], 'confirmed' => ['sometimes', 'boolean'], 'impact_token' => ['nullable', 'string', 'size:64'], ...$this->input->translationRules(['name', 'description'])])->validate();
                $this->confirmation = $v;
                $g = $id === null ? null : $this->record('feature_groups', $id, (int) ($v['version'] ?? 0));
                if ($g !== null && $g->system_key !== null) {
                    $this->fail('group', 'features.general_protected');
                }
                if ($v['action'] === 'delete') {
                    abort_if($id === null, 404);
                    $features = DB::table('features')->where('group_id', $id)->orderBy('position')->orderBy('id')->get();
                    $this->confirm([...$v, 'id' => $id], ['moved_features' => $features->count()], true);
                    $general = DB::table('feature_groups')->where('system_key', 'general')->value('id');
                    $position = (int) DB::table('features')->where('group_id', $general)->max('position');
                    foreach ($features as $f) {
                        DB::table('features')->where('id', $f->id)->update(['group_id' => $general, 'position' => ++$position, 'lock_version' => DB::raw('lock_version + 1')]);
                    }
                    DB::table('feature_groups')->where('id', $id)->delete();
                } else {
                    if ($id === null) {
                        $id = (string) DB::table('feature_groups')->insertGetId(['position' => (int) DB::table('feature_groups')->max('position') + 1]);
                    } else {
                        DB::table('feature_groups')->where('id', $id)->increment('lock_version');
                    }
                    $this->input->translations('feature_group_translations', 'group_id', $id, $v['translations'], ['name', 'description']);
                    if (! DB::table('feature_group_translations')->where('group_id', $id)->where('locale_id', $this->queries->defaultId())->whereRaw('length(btrim(name)) > 0')->exists()) {
                        $this->fail('translations', 'features.name_required');
                    }
                }
                $this->audit($actor, 'group', $id, $v['action']);

                return $id;
            });

        });
    }

    public function reorder(string $actor, SaveFeatureData $data): void
    {
        app(AuditLogger::class)->operation('feature', $actor, function () use ($actor, $data): void {
            DB::transaction(function () use ($actor, $data): void {
                $this->authorize($actor, 'features.order');
                $v = Validator::make($data->fields, ['catalog_version' => ['required', 'integer'], 'groups' => ['required', 'array'], 'groups.*.id' => ['required', 'integer', 'distinct'], 'groups.*.features' => ['present', 'array'], 'groups.*.features.*' => ['integer']])->validate();
                if ((int) $v['catalog_version'] !== $this->queries->revision()) {
                    $this->fail('version', 'features.stale');
                }
                $groups = array_map(fn ($g): string => (string) $g['id'], $v['groups']);
                $features = array_map(strval(...), array_merge(...array_column($v['groups'], 'features')));
                $allGroups = DB::table('feature_groups')->pluck('id')->map(strval(...))->all();
                $allFeatures = DB::table('features')->pluck('id')->map(strval(...))->all();
                sort($groups);
                sort($features);
                sort($allGroups);
                sort($allFeatures);
                if ($groups !== $allGroups || $features !== $allFeatures) {
                    $this->fail('groups', 'features.full_order');
                }
                foreach ($v['groups'] as $position => $group) {
                    DB::table('feature_groups')->where('id', $group['id'])->update(['position' => $position + 1, 'lock_version' => DB::raw('lock_version + 1')]);
                    foreach ($group['features'] as $index => $feature) {
                        $f = DB::table('features')->where('id', $feature)->first();
                        if ($f->status === 'archived' && (string) $f->group_id !== (string) $group['id']) {
                            $this->fail('groups', 'features.readonly');
                        }
                        DB::table('features')->where('id', $feature)->update(['group_id' => $group['id'], 'position' => $index + 1, 'lock_version' => DB::raw('lock_version + 1')]);
                    }
                }
                $this->audit($actor, 'ordering', '1', 'reordered', ['groups' => $v['groups']]);
            });

        });
    }
}
