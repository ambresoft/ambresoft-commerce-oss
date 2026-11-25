<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Domain\Service\Ean;
use App\Catalog\Domain\Service\ProductIdentifiers;
use App\Catalog\Domain\Service\ProductRules;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Pricing\Infrastructure\ProductSales;
use App\Shared\Infrastructure\Content\RichTextSanitizer;
use App\Website\Domain\Service\PageRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductInput
{
    public const TEXT = ['name', 'description', 'short_description', 'seo_title', 'seo_description', 'meta_keywords', 'slug'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $codes = app(LocaleCatalog::class)->active()->pluck('code')->all();
        // Prices are amounts in the primary currency, entered at its precision (0–6, within numeric(20,6)).
        $scale = app(CurrencyRepository::class)->currentPrimary()->decimals;
        $fraction = $scale === 0 ? '' : '(?:\.\d{1,'.$scale.'})?';
        $rules = [
            'brand_id' => ['sometimes', 'nullable', 'integer', Rule::exists('brands', 'id')],
            'similar_products' => ['sometimes', 'boolean'],
            'reviews_enabled' => ['sometimes', 'boolean'],
            // The same Open Graph object types as pages; empty falls back to the product default on save.
            'facebook_type' => ['sometimes', 'nullable', 'string', fn (string $attribute, mixed $value, \Closure $fail) => PageRules::facebookType((string) $value) ?: $fail(__('pages.choice_invalid'))],
            'exclude_from_sitemap' => ['sometimes', 'required', 'boolean'],
            // Frequently bought together, submitted like sticker_ids (the marker survives an empty selection).
            'companions_submitted' => ['sometimes', 'boolean'], 'companion_ids' => ['sometimes', 'array', 'max:20'], 'companion_ids.*' => ['integer', 'min:1'],
            'stickers_submitted' => ['sometimes', 'boolean'], 'sticker_ids' => ['sometimes', 'array', 'max:100'], 'sticker_ids.*' => ['integer', 'distinct'],
            'features' => ['sometimes', 'array', 'max:1000'], 'features.*' => ['array'],
            'feature_catalog_version' => ['sometimes', 'integer', 'min:1'], 'feature_confirmed' => ['sometimes', 'boolean'], 'feature_impact_token' => ['nullable', 'string', 'size:64'],
            'version' => ['required', 'integer', 'min:1'], 'sku' => ['required', 'string', 'max:200'],
            'ean' => ['bail', 'sometimes', 'nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! Ean::valid($value)) {
                    $fail(__('products.ean_invalid'));
                }
            }],
            'isbn' => ['bail', 'sometimes', 'nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! ProductIdentifiers::isbn($value)) {
                    $fail(__('products.isbn_invalid'));
                }
            }],
            'upc' => ['bail', 'sometimes', 'nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! ProductIdentifiers::upc($value)) {
                    $fail(__('products.upc_invalid'));
                }
            }],
            'mpn' => ['sometimes', 'nullable', 'string', 'max:200', 'not_regex:/[\x00-\x1f\x7f]/'],
            'returnable' => ['sometimes', 'required', 'boolean'],
            // Stock and ordering rules. Omitted fields keep their saved values; unset limits are NULL, never restrictive.
            'quantity' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
            'list_price' => ['sometimes', 'nullable', 'regex:/^\d{1,14}'.$fraction.'$/D'],
            'min_qty' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            'max_qty' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            'qty_step' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            // NULL (blank) means "use the general setting"; a value overrides it for this product.
            'in_stock_status_id' => ['sometimes', 'nullable', 'integer', Rule::exists('availability_statuses', 'id')],
            'out_of_stock_status_id' => ['sometimes', 'nullable', 'integer', Rule::exists('availability_statuses', 'id')],
            'out_of_stock_actions' => ['sometimes', 'nullable', Rule::in(ProductRules::OUT_OF_STOCK_ACTIONS)],
            // Tax assignment: present tax_ids (or the explicit marker, because multipart encoding drops empty arrays) replaces the set.
            'taxes_submitted' => ['sometimes', 'boolean'],
            'tax_ids' => ['sometimes', 'array', 'max:100'], 'tax_ids.*' => ['integer', 'min:1', 'distinct'],
            // Customer-group visibility, submitted like tax_ids: empty means every visitor.
            'faqs_submitted' => ['sometimes', 'boolean'],
            'faq_ids' => ['sometimes', 'array', 'max:100'], 'faq_ids.*' => ['integer', 'min:1', 'distinct'],
            'faq_drafts' => ['sometimes', 'array', 'max:10'], 'faq_drafts.*' => ['array:translations'], 'faq_drafts.*.translations' => ['required', 'array'],
            // Extra categories the product is also listed in (any non-system category; the main one is never repeated).
            'extra_categories_submitted' => ['sometimes', 'boolean'],
            'extra_category_ids' => ['sometimes', 'array', 'max:50'], 'extra_category_ids.*' => ['integer', 'min:1', 'distinct', Rule::exists('categories', 'id')->whereNull('system_key')],
            'customer_groups_submitted' => ['sometimes', 'boolean'],
            'customer_groups' => ['sometimes', 'array', 'max:100'], 'customer_groups.*' => ['integer', 'min:1', 'distinct'],
            // Shipping and release. A shipping cost is an amount in the primary currency, like the price.
            'free_shipping' => ['sometimes', 'required', 'boolean'],
            'shipping_cost' => ['sometimes', 'nullable', 'regex:/^\d{1,14}'.$fraction.'$/D'],
            'available_from' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:9999-12-31'],
            'box_min_items' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            'box_max_items' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            'type' => ['required', Rule::in(['physical', 'digital'])], 'status' => ['required', Rule::in(['active', 'disabled', 'hidden'])],
            'price' => ['required', 'regex:/^\d{1,14}'.$fraction.'$/D'],
            'category_id' => ['required', 'integer', 'min:1'],
            'translations' => ['required', 'array:'.implode(',', $codes)],
            'images' => ['sometimes', 'array', 'max:50'], 'images.*' => ['integer', 'min:1', 'distinct'],
            'primary_image' => ['nullable', 'integer', 'min:1'],
            'files' => ['sometimes', 'array', 'max:50'], 'files.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'files.*.display_name' => ['required', 'string', 'max:255', 'not_regex:/[\\\\\/\x00-\x1f]/'],
            'files.*.replacement' => ['nullable', 'integer', 'min:0', 'max:9'],
            // Purpose of each new upload, aligned with file_uploads; omitted follows the product type (older clients).
            'file_upload_purposes' => ['sometimes', 'array', 'max:10'], 'file_upload_purposes.*' => [Rule::in(['download', 'attachment'])],
        ];
        foreach (['weight', 'length', 'width', 'height'] as $field) {
            $rules[$field] = ['nullable', 'regex:/^\d{1,9}(?:\.\d{1,6})?$/D'];
        }
        foreach (['box_length', 'box_width', 'box_height'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'regex:/^\d{1,9}(?:\.\d{1,6})?$/D'];
        }
        foreach ($codes as $code) {
            $rules['translations.'.$code] = ['sometimes', 'array:'.implode(',', [...self::TEXT, 'regenerate_slug'])];
            foreach (self::TEXT as $field) {
                $rules['translations.'.$code.'.'.$field] = ['nullable', 'string', 'max:'.match ($field) {
                    'description' => 100000, 'short_description' => 5000, 'seo_description', 'meta_keywords' => 500, default => 200
                }];
            }
            $rules['translations.'.$code.'.regenerate_slug'] = ['sometimes', 'boolean'];
        }

        return [...$rules, ...app(ProductSales::class)->rules()];
    }

    /** @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function validate(array $fields): array
    {
        $fields['sku'] = trim((string) ($fields['sku'] ?? ''));

        foreach (['ean', 'mpn', 'isbn', 'upc'] as $field) {
            if (array_key_exists($field, $fields) && is_string($fields[$field])) {
                $fields[$field] = trim($fields[$field]);
                if ($fields[$field] === '') {
                    $fields[$field] = null;
                }
            }
        }

        if (isset($fields['isbn']) && is_string($fields['isbn'])) {
            $normalized = strtoupper(str_replace(['-', ' '], '', $fields['isbn']));
            $fields['isbn'] = $normalized === '' ? $fields['isbn'] : $normalized;
        }

        // Blank optional numbers mean "not configured".
        foreach (['brand_id', 'in_stock_status_id', 'out_of_stock_status_id', 'list_price', 'min_qty', 'max_qty', 'qty_step', 'out_of_stock_actions', 'shipping_cost', 'available_from', 'box_min_items', 'box_max_items', 'box_length', 'box_width', 'box_height'] as $field) {
            if (array_key_exists($field, $fields) && is_string($fields[$field]) && trim($fields[$field]) === '') {
                $fields[$field] = null;
            }
        }
        $validated = Validator::make($fields, $this->rules(), [], $this->attributes())->validate();
        foreach (['quantity', 'min_qty', 'max_qty', 'qty_step', 'box_min_items', 'box_max_items'] as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = (int) $validated[$field];
            }
        }
        // A zero item count per box means "no packaging constraint", stored as NULL.
        foreach (['box_min_items', 'box_max_items'] as $field) {
            if (($validated[$field] ?? null) === 0) {
                $validated[$field] = null;
            }
        }
        $rules = new ProductRules;
        if (! $rules->limitsConsistent($validated['min_qty'] ?? null, $validated['max_qty'] ?? null)) {
            throw ValidationException::withMessages(['max_qty' => __('products.max_qty_below_min')]);
        }
        if (! $rules->limitsConsistent($validated['box_min_items'] ?? null, $validated['box_max_items'] ?? null)) {
            throw ValidationException::withMessages(['box_max_items' => __('products.box_max_below_min')]);
        }

        return $validated;
    }

    /** Validates the resulting product, so a partial request cannot enable packaging without box dimensions. */
    public function assertPackaging(ProductRecord $p): void
    {
        if (! (new ProductRules)->packagingEnabled($p->box_min_items, $p->box_max_items)) {
            return;
        }
        $errors = [];
        foreach (['box_length', 'box_width', 'box_height'] as $field) {
            if ($p->getAttribute($field) === null || bccomp((string) $p->getAttribute($field), '0', 6) <= 0) {
                $errors[$field] = __('products.box_dimension_required', ['field' => __('products.'.$field)]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['exclude_from_sitemap' => __('products.exclude_from_sitemap'), 'in_stock_status_id' => __('availability.in_stock'), 'out_of_stock_status_id' => __('availability.out_of_stock'), 'quantity' => __('products.quantity'), 'list_price' => __('products.list_price_label'), 'min_qty' => __('products.min_qty'), 'max_qty' => __('products.max_qty'), 'qty_step' => __('products.qty_step'), 'out_of_stock_actions' => __('products.out_of_stock_actions'), 'shipping_cost' => __('products.shipping_cost'), 'available_from' => __('products.available_from'), 'box_min_items' => __('products.box_min_items'), 'box_max_items' => __('products.box_max_items'), 'box_length' => __('products.box_length'), 'box_width' => __('products.box_width'), 'box_height' => __('products.box_height'), 'customer_groups' => __('products.customer_groups')];
    }

    public function slug(string $name, int $localeId, string $productId, bool $generated, string $errorField = 'translations'): string
    {
        $base = Str::slug($name);
        if ($base === '' || (! $generated && strlen($base) > 200)) {
            throw ValidationException::withMessages([$errorField => __('products.invalid_slug')]);
        }
        $base = $generated ? substr($base, 0, 190) : $base;
        $candidate = $base;
        $suffix = 2;
        while (DB::table('product_translations')->where('locale_id', $localeId)->where('slug', $candidate)->where('product_id', '<>', $productId)->exists()) {
            if (! $generated) {
                throw ValidationException::withMessages([$errorField => __('products.slug_conflict')]);
            }
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    /** @param array<string, array<string, mixed>> $translations */
    public function translations(ProductRecord $record, array $translations): void
    {
        $locales = app(LocaleCatalog::class)->active()->pluck('id', 'code');
        $sanitizer = new RichTextSanitizer;
        foreach ($translations as $code => $fields) {
            $existing = $record->translations()->where('locale_id', $locales[$code])->first();
            $values = $existing?->only(self::TEXT) ?? array_fill_keys(self::TEXT, '');
            foreach (self::TEXT as $field) {
                if (array_key_exists($field, $fields)) {
                    $values[$field] = trim((string) ($fields[$field] ?? ''));
                }
            }
            $values['description'] = $sanitizer->sanitize($values['description']);
            $values['short_description'] = $sanitizer->sanitize($values['short_description'] ?? '');
            if (mb_strlen($values['short_description']) > 5000) {
                throw ValidationException::withMessages(['translations.'.$code.'.short_description' => __('validation.max.string', ['attribute' => __('products.short_description'), 'max' => 5000])]);
            }
            $manual = trim((string) ($fields['slug'] ?? ''));
            $generate = ($fields['regenerate_slug'] ?? false) || (($existing->slug ?? '') === '' && $manual === '');
            if ($generate && $values['name'] !== '') {
                $values['slug'] = $this->slug($values['name'], $locales[$code], (string) $record->id, true, 'translations.'.$code.'.slug');
            } elseif ($manual !== '') {
                $values['slug'] = $this->slug($manual, $locales[$code], (string) $record->id, false, 'translations.'.$code.'.slug');
            } else {
                $values['slug'] = $existing?->slug;
            }
            if ($existing === null && implode('', $values) === '') {
                continue;
            }
            $record->translations()->updateOrCreate(['locale_id' => $locales[$code]], $values);
        }
    }
}
