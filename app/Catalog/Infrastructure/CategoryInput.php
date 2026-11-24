<?php

namespace App\Catalog\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Infrastructure\Content\RichTextSanitizer;
use App\Website\Domain\Service\MenuRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CategoryInput
{
    /** Validate the resulting entity, not just the submitted translation patch.
     * @param  array<string, mixed>  $translations
     */
    public function assertComplete(array $translations): void
    {
        $locales = app(LocaleCatalog::class)->active();
        if ($locales->count() < 2) {
            return;
        }
        Validator::make(['translations' => $translations], $this->completenessRules($locales->pluck('code')->all()), [], $this->attributes())->validate();
    }

    /** @param list<string> $codes
     * @return array<string, list<string>>
     */
    public function completenessRules(array $codes): array
    {
        $rules = [];
        foreach ($codes as $code) {
            // Descriptions and age warnings remain optional in every enabled language.
            foreach (['name', 'seo_title', 'seo_description', 'slug', 'image_alt', 'meta_keywords'] as $field) {
                $rules['translations.'.$code.'.'.$field] = ['required', 'string'];
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $translations
     * @return array<string, mixed>
     */
    public function normalize(array $translations): array
    {
        foreach ($translations as $code => $fields) {
            if (! is_array($fields)) {
                continue;
            }
            if (isset($fields['name']) && is_string($fields['name'])) {
                $fields['name'] = trim($fields['name']);
            }
            if ((! isset($fields['slug']) || $fields['slug'] === '') && isset($fields['name']) && is_string($fields['name'])) {
                $fields['slug'] = Str::slug($fields['name']);
            } elseif (isset($fields['slug']) && is_string($fields['slug'])) {
                $fields['slug'] = Str::slug($fields['slug']);
            }
            foreach (['description', 'seo_title', 'seo_description', 'image_alt'] as $field) {
                $fields[$field] ??= '';
            }
            if (is_string($fields['description']) && mb_strlen($fields['description']) <= 20000) {
                $fields['description'] = (new RichTextSanitizer)->sanitize($fields['description']);
            }
            foreach (['meta_keywords', 'age_warning_message'] as $field) {
                if (array_key_exists($field, $fields)) {
                    $fields[$field] ??= '';
                }
            }
            $translations[$code] = $fields;
        }

        return $translations;
    }

    /** @return array<string, list<mixed>> */
    public function rules(?string $categoryId): array
    {
        $locales = app(LocaleCatalog::class)->active();
        $rules = [
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('system_key')],
            'position' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'status' => ['required', Rule::in(['active', 'disabled'])],
            'version' => [$categoryId === null ? 'nullable' : 'required', 'integer', 'min:1'],
            'remove_image' => ['required', 'boolean'],
            'remove_block_image' => ['sometimes', 'boolean'],
            'remove_menu_icon' => ['sometimes', 'boolean'],
            'remove_banner_image' => ['sometimes', 'boolean'],
            'banner_link' => ['sometimes', 'nullable', 'string', 'max:2048', fn (string $attribute, mixed $value, \Closure $fail) => ($value === null || $value === '' || MenuRules::safeUrl((string) $value)) ?: $fail(__('categories.banner_link_invalid'))],
            'exclude_from_discovery' => ['sometimes', 'required', 'boolean'],
            'exclude_from_sitemap' => ['sometimes', 'required', 'boolean'],
            'is_landing_page' => ['sometimes', 'required', 'boolean'],
            // Multipart encoding drops an empty list, so the editor also sends this marker to clear the restriction.
            'faqs_submitted' => ['sometimes', 'boolean'],
            'faq_ids' => ['sometimes', 'array', 'max:100'], 'faq_ids.*' => ['integer', 'min:1', 'distinct'],
            'faq_drafts' => ['sometimes', 'array', 'max:10'], 'faq_drafts.*' => ['array:translations'], 'faq_drafts.*.translations' => ['required', 'array'],
            'customer_groups_submitted' => ['sometimes', 'boolean'],
            'customer_groups' => ['sometimes', 'array', 'max:100'],
            'customer_groups.*' => ['integer', 'min:1', 'distinct', Rule::exists('customer_groups', 'id')],
            'age_verification' => ['sometimes', 'required_with:age_limit', 'boolean'],
            'age_limit' => ['required_if:age_verification,true,1', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            'translations' => ['present', 'array:'.implode(',', $locales->pluck('code')->all())],
        ];
        foreach ($locales as $locale) {
            $prefix = 'translations.'.$locale->code;
            $rules[$prefix] = [$categoryId === null && $locale->code === app(LocaleCatalog::class)->defaultCode() ? 'required' : 'sometimes', 'array:name,description,seo_title,seo_description,slug,image_alt,meta_keywords,age_warning_message', 'required_array_keys:name,slug'];
            foreach (['name' => 200, 'slug' => 200, 'seo_title' => 200, 'seo_description' => 500, 'image_alt' => 200, 'description' => 20000, 'meta_keywords' => 1000, 'age_warning_message' => 2000] as $field => $max) {
                $rules[$prefix.'.'.$field] = ['required_with:'.$prefix, $field === 'name' || $field === 'slug' ? 'filled' : 'nullable', 'string', 'max:'.$max];
                if ($field !== 'name' && $field !== 'slug') {
                    $rules[$prefix.'.'.$field][0] = 'sometimes';
                }
            }
            $rules[$prefix.'.slug'][] = 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';
            $rules[$prefix.'.slug'][] = Rule::unique('category_translations', 'slug')->where('locale_id', $locale->id)->ignore($categoryId, 'category_id');
        }

        return $rules;
    }

    /** CSV may create using any active locale; each populated row still needs its name/slug.
     * @return array<string, list<mixed>>
     */
    public function importRules(?string $categoryId): array
    {
        $rules = $this->rules($categoryId);
        if ($categoryId === null) {
            $rules['translations'][] = 'min:1';
            foreach (app(LocaleCatalog::class)->active() as $locale) {
                $rules['translations.'.$locale->code][0] = 'sometimes';
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $attributes = [];
        foreach (['parent_id' => 'parent', 'position' => 'position', 'status' => 'status', 'version' => 'version', 'translations' => 'translations', 'remove_image' => 'remove_image', 'image' => 'image', 'block_image' => 'block_image', 'menu_icon' => 'menu_icon', 'banner_image' => 'banner_image', 'banner_link' => 'banner_link', 'age_verification' => 'age_verification', 'age_limit' => 'age_limit', 'exclude_from_discovery' => 'exclude_from_discovery', 'exclude_from_sitemap' => 'exclude_from_sitemap', 'is_landing_page' => 'is_landing_page', 'customer_groups' => 'customer_groups'] as $field => $key) {
            $attributes[$field] = __('categories.'.$key);
        }
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            foreach (['name', 'description', 'seo_title', 'seo_description', 'slug', 'image_alt', 'meta_keywords', 'age_warning_message'] as $field) {
                $attributes['translations.'.$locale->code.'.'.$field] = $locale->native_name.' — '.__('categories.'.$field);
            }
        }

        return $attributes;
    }
}
