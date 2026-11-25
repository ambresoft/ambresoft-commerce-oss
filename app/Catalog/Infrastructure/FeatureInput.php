<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Domain\Service\FeatureRules;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Infrastructure\Content\RichTextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FeatureInput
{
    public const TEXT = ['name', 'description'];

    /** Localized variant landing-page properties; the option label remains the variant name. */
    public const LANDING = ['description', 'seo_title', 'seo_description', 'meta_keywords', 'slug'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['sometimes', 'integer', 'min:1'], 'type' => ['required', Rule::in(FeatureRules::TYPES)],
            'code' => ['prohibited'], 'group_id' => ['nullable', 'integer', 'exists:feature_groups,id'],
            'unit' => ['nullable', 'string', 'max:60'], 'purpose' => ['sometimes', Rule::in(FeatureRules::PURPOSES)], 'variation_style' => ['sometimes', Rule::in(FeatureRules::VARIATION_STYLES)], 'required' => ['required', 'boolean'], 'filterable' => ['required', 'boolean'], 'comparison' => ['required', 'boolean'],
            'categories' => ['present', 'array', 'max:1000'], 'categories.*' => ['integer', 'distinct', 'exists:categories,id'],
            'impact_token' => ['nullable', 'string', 'size:64'], 'confirmed' => ['sometimes', 'boolean'],
            ...$this->translationRules(self::TEXT),
        ];
    }

    /** @param list<string> $fields
     * @return array<string, mixed>
     */
    public function translationRules(array $fields): array
    {
        $codes = app(LocaleCatalog::class)->active()->pluck('code')->all();
        $rules = ['translations' => ['present', 'array:'.implode(',', $codes)]];
        foreach ($codes as $code) {
            $rules['translations.'.$code] = ['sometimes', 'array:'.implode(',', [...$fields, 'suggest_slug'])];
            foreach ($fields as $field) {
                $rules['translations.'.$code.'.'.$field] = ['nullable', 'string', 'max:'.match ($field) {
                    'description' => 10000, 'seo_description' => 500, 'meta_keywords' => 1000, default => 200
                }];
            }
            $rules['translations.'.$code.'.suggest_slug'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validate(array $data): array
    {
        $v = Validator::make($data, $this->rules())->validate();
        if ($v['type'] === 'text' && $v['filterable']) {
            throw ValidationException::withMessages(['filterable' => __('features.text_filter')]);
        }
        if ($v['type'] !== 'number' && ! empty($v['unit'])) {
            throw ValidationException::withMessages(['unit' => __('features.number_unit')]);
        }
        if (isset($v['purpose']) && ! FeatureRules::supportsPurpose($v['type'], $v['purpose'])) {
            throw ValidationException::withMessages(['purpose' => __('features.purpose_single_only')]);
        }

        return $v;
    }

    public function code(string $name): string
    {
        $base = strtoupper(substr(Str::slug($name), 0, 180)) ?: 'FEATURE';
        $code = $base;
        $suffix = 2;
        while (DB::table('features')->where('code', $code)->exists()) {
            $code = $base.'-'.$suffix++;
        }

        return $code;
    }

    /** @param array<string, array<string, mixed>> $translations
     * @param  list<string>  $fields
     */
    public function translations(string $table, string $key, string $id, array $translations, array $fields): void
    {
        $locales = app(LocaleCatalog::class)->active()->pluck('id', 'code')->all();
        foreach ($translations as $code => $text) {
            $values = [];
            foreach ($fields as $field) {
                if (array_key_exists($field, $text)) {
                    $values[$field] = trim((string) ($text[$field] ?? ''));
                    if ($field === 'description') {
                        $values[$field] = (new RichTextSanitizer)->sanitize($values[$field]);
                    }
                }
            }
            if (in_array('slug', $fields, true) && (array_key_exists('slug', $values) || ! empty($text['suggest_slug']))) {
                $suggest = ! empty($text['suggest_slug']);
                $base = Str::slug($suggest ? ($values['name'] ?? $values['label'] ?? '') : $values['slug']);
                if (strlen($base) > 200) {
                    throw ValidationException::withMessages(['translations.'.$code.'.slug' => __('products.invalid_slug')]);
                }
                if ($base === '' && ($suggest || ($values['slug'] ?? '') !== '')) {
                    throw ValidationException::withMessages(['translations.'.$code.'.slug' => __('products.invalid_slug')]);
                }
                $slug = $base;
                $suffix = 2;
                while ($slug !== '' && DB::table($table)->where('locale_id', $locales[$code])->where('slug', $slug)->where($key, '<>', $id)->exists()) {
                    if (! $suggest) {
                        throw ValidationException::withMessages(['translations.'.$code.'.slug' => __('products.slug_conflict')]);
                    }
                    $slug = substr($base, 0, 180).'-'.$suffix++;
                }
                $values['slug'] = $slug === '' ? null : $slug;
            }
            DB::table($table)->updateOrInsert([$key => $id, 'locale_id' => $locales[$code]], $values);
        }
    }
}
