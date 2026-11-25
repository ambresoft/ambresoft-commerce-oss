<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Domain\Service\FeatureRules;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ProductFeatures
{
    public function __construct(private FeatureQueries $queries) {}

    /** @return array<string, mixed> */
    public function editor(ProductRecord $product): array
    {
        $ids = DB::table('features as f')->crossJoin('products as p')->where('p.id', $product->id)->where(function ($q): void {
            $q->where(fn ($q) => $q->where('f.status', 'active')->whereRaw($this->queries->completeSql())->whereRaw($this->queries->appliesSql()))
                ->orWhereExists(fn ($a) => $a->selectRaw('1')->from('product_feature_assignments as a')->whereColumn('a.feature_id', 'f.id')->whereColumn('a.product_id', 'p.id'));
        })->pluck('f.id')->map(strval(...))->all();
        $values = $this->values((string) $product->id);
        $definitions = $this->queries->definitions($ids);
        foreach ($definitions as &$f) {
            $f['historical'] = isset($values[$f['id']]) && ! $values[$f['id']]['applicable'];
            $f['readonly'] = $f['historical'] || $f['status'] === 'archived' || $f['incomplete'];
        }

        return ['definitions' => $definitions, 'values' => (object) $values, 'groups' => $this->queries->groups(), 'catalog_version' => $this->queries->revision(), 'missing' => $product->status === 'active' ? $this->queries->missingForProduct((string) $product->id) : []];
    }

    /** @return array<string, array<string, mixed>> */
    public function values(string $product): array
    {
        $assignments = DB::table('product_feature_assignments')->where('product_id', $product)->get();
        $texts = DB::table('product_feature_texts')->whereIn('assignment_id', $assignments->pluck('id'))->get()->groupBy('assignment_id');
        $choices = DB::table('product_feature_choices')->whereIn('assignment_id', $assignments->pluck('id'))->get()->groupBy('assignment_id');
        $locales = app(LocaleCatalog::class)->active()->pluck('code', 'id')->all();
        $values = [];
        foreach ($assignments as $a) {
            $localized = [];
            foreach ($texts->get($a->id, collect()) as $text) {
                if (isset($locales[$text->locale_id])) {
                    $localized[$locales[$text->locale_id]] = $text->value;
                }
            }
            $values[(string) $a->feature_id] = ['version' => $a->lock_version, 'applicable' => $a->is_applicable, 'number' => $a->number_value ?? '', 'boolean' => $a->boolean_value === null ? '' : ($a->boolean_value ? '1' : '0'), 'texts' => (object) $localized, 'options' => $choices->get($a->id, collect())->pluck('option_id')->map(strval(...))->all()];
        }

        return $values;
    }

    /** @param array<string, mixed> $fields */
    public function save(ProductRecord $product, array $fields, string $actor, bool $categoryChanged): void
    {
        if (array_key_exists('features', $fields) && (int) ($fields['feature_catalog_version'] ?? 0) !== $this->queries->revision()) {
            throw ValidationException::withMessages(['version' => __('features.stale')]);
        }
        $deactivated = DB::table('product_feature_assignments as a')->join('products as p', 'p.id', '=', 'a.product_id')->where('a.product_id', $product->id)->where('a.is_applicable', true)->whereRaw('NOT '.$this->queries->appliesSql('p.category_id', 'a.feature_id'))->count();
        if ($categoryChanged && $deactivated > 0) {
            $token = hash_hmac('sha256', json_encode([$product->id, $product->lock_version, $product->category_id, $this->queries->revision(), $deactivated], JSON_THROW_ON_ERROR), (string) config('app.key'));
            if (! ($fields['feature_confirmed'] ?? false) || ! hash_equals($token, $fields['feature_impact_token'] ?? '')) {
                throw new FeatureImpactRequired(['inactive_products' => 1, 'inactive_values' => $deactivated], $token);
            }
        }
        $this->reconcile(product: (string) $product->id, bumpProduct: false);
        $old = $this->values((string) $product->id);
        foreach (array_keys($fields['features'] ?? []) as $featureId) {
            if (filter_var($featureId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw ValidationException::withMessages(['features' => __('features.invalid_value')]);
            }
        }
        $definitions = [];
        foreach ($this->queries->definitions(array_map(strval(...), array_keys($fields['features'] ?? []))) as $definition) {
            $definitions[$definition['id']] = $definition;
        }
        $locales = app(LocaleCatalog::class)->active()->pluck('id', 'code')->all();
        foreach ($fields['features'] ?? [] as $id => $value) {
            $f = $definitions[$id] ?? null;
            if ($f === null || $f['status'] === 'archived' || $f['incomplete']) {
                throw ValidationException::withMessages(['features.'.$id => __('features.readonly')]);
            }
            $applicable = DB::table('features as f')->crossJoin('products as p')->where('f.id', $id)->where('p.id', $product->id)->whereRaw($this->queries->appliesSql())->exists();
            if (! $applicable && $categoryChanged && isset($old[$id])) {
                continue; // Preserve the previous value as inactive history after confirmed category change.
            }
            if (! $applicable) {
                throw ValidationException::withMessages(['features.'.$id => __('features.inapplicable')]);
            }
            if ((int) ($value['version'] ?? 0) !== (int) ($old[$id]['version'] ?? 0)) {
                throw ValidationException::withMessages(['version' => __('features.stale')]);
            }
            $validator = Validator::make($value, ['version' => ['required', 'integer', 'min:0'], 'number' => ['nullable', 'string', 'regex:/^-?\d{1,14}(?:\.\d{1,6})?$/D'], 'boolean' => ['nullable', 'in:0,1'], 'options' => ['sometimes', 'array'], 'options.*' => ['integer', 'min:1', 'distinct'], 'texts' => ['sometimes', 'array:'.implode(',', array_keys($locales))], 'texts.*' => ['nullable', 'string', 'max:10000']]);
            if ($validator->fails()) {
                $errors = [];
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors['features.'.$id.'.'.$field] = $messages;
                }
                throw ValidationException::withMessages($errors);
            }
            $v = $validator->validated();
            $number = ($v['number'] ?? '') === '' ? null : $v['number'];
            $boolean = ($v['boolean'] ?? '') === '' || ($v['boolean'] ?? null) === null ? null : (string) $v['boolean'] === '1';
            $options = array_map(strval(...), $v['options'] ?? []);
            $textValues = $v['texts'] ?? [];
            if (($f['type'] !== 'number' && $number !== null) || ($f['type'] !== 'boolean' && $boolean !== null) || (! in_array($f['type'], ['single', 'multiple'], true) && $options !== []) || ($f['type'] !== 'text' && $textValues !== []) || ($f['type'] === 'single' && count($options) > 1)) {
                throw ValidationException::withMessages(['features.'.$id => __('features.invalid_value')]);
            }
            $allowed = array_column($f['options'], null, 'id');
            foreach ($options as $option) {
                $o = $allowed[$option] ?? null;
                if ($o === null || ($o['status'] === 'archived' && ! in_array($option, $old[$id]['options'] ?? [], true))) {
                    throw ValidationException::withMessages(['features.'.$id => __('features.invalid_option')]);
                }
            }
            // Optional empty features do not create fabricated assignments. Existing identities survive clearing.
            if (! isset($old[$id]) && ! FeatureRules::filled($f['type'], ['texts' => $textValues, 'number' => $number, 'boolean' => $boolean, 'options' => $options], app(LocaleCatalog::class)->defaultCode()) && array_filter($textValues, fn ($text) => trim($text ?? '') !== '') === []) {
                continue;
            }
            $a = DB::table('product_feature_assignments')->where('product_id', $product->id)->where('feature_id', $id)->first();
            if ($a === null) {
                $assignment = DB::table('product_feature_assignments')->insertGetId(['product_id' => $product->id, 'feature_id' => $id, 'type' => $f['type'], 'number_value' => $number, 'boolean_value' => $boolean]);
            } else {
                $assignment = $a->id;
                DB::table('product_feature_assignments')->where('id', $assignment)->update(['number_value' => $number, 'boolean_value' => $boolean, 'lock_version' => DB::raw('lock_version + 1')]);
            }
            foreach ($textValues as $code => $text) {
                DB::table('product_feature_texts')->updateOrInsert(['assignment_id' => $assignment, 'locale_id' => $locales[$code]], ['value' => trim($text ?? '')]);
            }
            DB::table('product_feature_choices')->where('assignment_id', $assignment)->delete();
            foreach ($options as $option) {
                DB::table('product_feature_choices')->insert(['assignment_id' => $assignment, 'feature_id' => $id, 'type' => $f['type'], 'option_id' => $option]);
            }
        }
        if (array_key_exists('features', $fields) || $categoryChanged) {
            app(ProductAudit::class)->record((string) $product->id, $actor, 'features_changed', ['features' => array_keys($fields['features'] ?? []), 'inactive_values' => $deactivated]);
        }
    }

    public function reconcile(?string $feature = null, ?string $product = null, bool $bumpProduct = true): void
    {
        $q = DB::table('product_feature_assignments as a')->join('products as p', 'p.id', '=', 'a.product_id')->select('a.id', 'a.product_id')->selectRaw($this->queries->appliesSql('p.category_id', 'a.feature_id').' as applicable')->whereRaw('a.is_applicable IS DISTINCT FROM '.$this->queries->appliesSql('p.category_id', 'a.feature_id'));
        if ($feature !== null) {
            $q->where('a.feature_id', $feature);
        }
        if ($product !== null) {
            $q->where('a.product_id', $product);
        }
        $q->orderBy('a.id')->chunkById(200, function ($rows) use ($bumpProduct): void {
            foreach ($rows as $row) {
                DB::table('product_feature_assignments')->where('id', $row->id)->update(['is_applicable' => $row->applicable, 'lock_version' => DB::raw('lock_version + 1')]);
                if ($bumpProduct) {
                    DB::table('products')->where('id', $row->product_id)->increment('lock_version');
                }
            }
        }, 'a.id', 'id');
    }

    public function assertRequired(ProductRecord $product): void
    {
        if (($missing = $this->queries->missingForProduct((string) $product->id)) !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_map(fn ($id) => 'features.'.$id, $missing), __('features.required_missing')));
        }
    }

    public function duplicate(string $source, ProductRecord $copy): void
    {
        $rows = DB::table('product_feature_assignments as a')->crossJoin('products as p')->where('a.product_id', $source)->where('p.id', $copy->id)->whereRaw($this->queries->appliesSql('p.category_id', 'a.feature_id'))->select('a.*')->get();
        foreach ($rows as $a) {
            $id = DB::table('product_feature_assignments')->insertGetId(['product_id' => $copy->id, 'feature_id' => $a->feature_id, 'type' => $a->type, 'is_applicable' => true, 'number_value' => $a->number_value, 'boolean_value' => $a->boolean_value]);
            foreach (DB::table('product_feature_texts')->where('assignment_id', $a->id)->get() as $t) {
                DB::table('product_feature_texts')->insert(['assignment_id' => $id, 'locale_id' => $t->locale_id, 'value' => $t->value]);
            }
            foreach (DB::table('product_feature_choices')->where('assignment_id', $a->id)->get() as $c) {
                DB::table('product_feature_choices')->insert(['assignment_id' => $id, 'feature_id' => $c->feature_id, 'type' => $c->type, 'option_id' => $c->option_id]);
            }
        }
    }
}
