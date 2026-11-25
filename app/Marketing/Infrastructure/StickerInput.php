<?php

namespace App\Marketing\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\StickerRules;
use App\Pricing\Domain\Repository\CurrencyRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StickerInput
{
    public const FIELDS = ['name', 'type', 'style', 'status', 'priority', 'match', 'placement', 'show_card', 'show_detail', 'show_desktop', 'show_tablet', 'show_mobile', 'is_filter', 'background', 'color', 'border_color', 'border_width', 'radius', 'padding', 'font_size', 'alignment', 'image_width', 'css_class'];

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        foreach (['starts_at', 'ends_at', 'image_media_id'] as $field) {
            if (($input[$field] ?? '') === '') {
                $input[$field] = null;
            }
        }
        foreach (['conditions', 'products'] as $field) {
            // Multipart omits empty lists; malformed non-list input is still rejected.
            $input[$field] ??= [];
        }
        Validator::make($input, ['conditions' => ['array', 'max:12'], 'conditions.*' => ['array']])->validate();
        $locales = app(LocaleCatalog::class)->active()->pluck('id', 'code');
        $rules = [
            'name' => ['required', 'string', 'max:128'],
            'type' => ['required', Rule::in(['manual', 'dynamic'])],
            'style' => ['required', Rule::in(['text', 'image'])],
            'status' => ['required', Rule::in(['active', 'disabled'])],
            'priority' => ['required', 'integer', 'between:0,99999'],
            'match' => ['required', Rule::in(['all', 'any'])],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i', ...($input['starts_at'] !== null ? ['after:starts_at'] : [])],
            'placement' => ['required', Rule::in(['image_top', 'image_bottom', 'above_price', 'below_price'])],
            'image_media_id' => ['nullable', 'integer', Rule::exists('media_files', 'id')->where(fn ($q) => $q->whereIn('id', DB::table('stickers')->whereNotNull('image_media_id')->select('image_media_id')))],
            'image' => ['nullable', 'file', 'max:5120'],
            'translations' => ['required', 'array:'.implode(',', $locales->keys()->all())],
            'conditions' => ['array', 'max:12', 'required_if:type,dynamic', 'prohibited_if:type,manual'],
            'conditions.*' => ['array:type,minimum,maximum,category_id,feature_id,option_id,include_descendants,boolean_value'],
            'conditions.*.type' => ['required', Rule::in(StickerRules::TYPES)],
            'conditions.*.minimum' => ['nullable', 'string', 'regex:/^\d{1,14}(?:\.\d{1,6})?$/D'],
            'conditions.*.maximum' => ['nullable', 'string', 'regex:/^\d{1,14}(?:\.\d{1,6})?$/D'],
            'conditions.*.category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('status', 'active')->whereNull('system_key')],
            'conditions.*.feature_id' => ['nullable', 'integer', Rule::exists('features', 'id')->where('status', 'active')->whereIn('type', ['single', 'multiple', 'number', 'boolean'])],
            'conditions.*.option_id' => ['nullable', 'integer', Rule::exists('feature_options', 'id')->where('status', 'active')],
            'conditions.*.include_descendants' => ['required', 'boolean'],
            'conditions.*.boolean_value' => ['nullable', 'boolean'],
            'products' => ['array', 'max:500', 'prohibited_if:type,dynamic'],
            'products.*' => ['integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'css_class' => ['present', 'nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_ -]*$/D'],
            'alignment' => ['required', Rule::in(['left', 'center', 'right'])],
        ];
        foreach (['background', 'color', 'border_color'] as $field) {
            $rules[$field] = ['required', 'string', 'regex:/^#[a-fA-F0-9]{6}$/D'];
        }
        foreach (['border_width' => [0, 4], 'radius' => [0, 48], 'padding' => [0, 24], 'font_size' => [10, 24], 'image_width' => [16, 160]] as $field => [$min, $max]) {
            $rules[$field] = ['required', 'integer', "between:$min,$max"];
        }
        foreach (['card', 'detail', 'desktop', 'tablet', 'mobile'] as $field) {
            $rules['show_'.$field] = ['required', 'boolean'];
        }
        $rules['is_filter'] = ['sometimes', 'boolean'];
        foreach ($locales as $code => $id) {
            $rules['translations.'.$code] = ['required', 'array:text,short_text,tooltip'];
            foreach (['text' => 200, 'short_text' => 80, 'tooltip' => 500] as $field => $max) {
                $rules['translations.'.$code.'.'.$field] = [$field === 'text' ? 'required' : 'nullable', 'string', 'max:'.$max];
                if (isset($input['translations'][$code][$field]) && is_string($input['translations'][$code][$field])) {
                    $input['translations'][$code][$field] = trim($input['translations'][$code][$field]);
                }
            }
        }
        foreach ($input['conditions'] as $n => $c) {
            foreach (['minimum', 'maximum', 'category_id', 'feature_id', 'option_id', 'boolean_value'] as $field) {
                if (($c[$field] ?? '') === '') {
                    $input['conditions'][$n][$field] = null;
                }
            }
        }
        $data = Validator::make($input, $rules)->validate();
        $scale = app(CurrencyRepository::class)->currentPrimary()->decimals;
        foreach ($data['conditions'] as $n => $c) {
            $type = $c['type'];
            $min = $c['minimum'] ?? null;
            $max = $c['maximum'] ?? null;
            if ($min !== null && $max !== null && bccomp($min, $max, 6) > 0) {
                $this->invalid("conditions.$n.maximum");
            }
            if (in_array($type, ['price', 'quantity', 'sales'], true) && $min === null && $max === null) {
                $this->invalid("conditions.$n.minimum");
            }
            foreach ([$min, $max] as $value) {
                if ($value === null) {
                    continue;
                }
                $decimals = in_array($type, ['price', 'discount_amount'], true) ? $scale : (in_array($type, ['quantity', 'sales', 'new'], true) ? 0 : 6);
                if (bccomp($value, bcadd($value, '0', $decimals), 6) !== 0 || ($type === 'discount_percent' && bccomp($value, '100', 6) > 0)) {
                    $this->invalid("conditions.$n.maximum");
                }
            }
            if ($type === 'category' && empty($c['category_id'])) {
                $this->invalid("conditions.$n.category_id");
            }
            if ($type === 'new' && ($max === null || bccomp($max, '1', 6) < 0 || bccomp($max, '3650', 6) > 0)) {
                $this->invalid("conditions.$n.maximum");
            }
            if ($type === 'feature') {
                $feature = empty($c['feature_id']) ? null : DB::table('features')->where('id', $c['feature_id'])->first();
                if ($feature === null || ($feature->type === 'number' && $min === null && $max === null) || ($feature->type === 'boolean' && ($c['boolean_value'] ?? null) === null)
                    || (in_array($feature->type, ['single', 'multiple'], true) && (empty($c['option_id']) || ! DB::table('feature_options')->where('id', $c['option_id'])->where('feature_id', $feature->id)->exists()))) {
                    $this->invalid("conditions.$n.feature_id");
                }
                if (($feature->type !== 'number' && ($min !== null || $max !== null)) || (! in_array($feature->type, ['single', 'multiple'], true) && ! empty($c['option_id'])) || ($feature->type !== 'boolean' && ($c['boolean_value'] ?? null) !== null)) {
                    $this->invalid("conditions.$n.feature_id");
                }
            } elseif ((! in_array($type, ['price', 'discount_amount', 'discount_percent', 'quantity', 'sales', 'new'], true) && ($min !== null || $max !== null)) || ! empty($c['feature_id']) || ! empty($c['option_id']) || ($c['boolean_value'] ?? null) !== null) {
                $this->invalid("conditions.$n.type");
            }
            if ($type !== 'category' && ! empty($c['category_id'])) {
                $this->invalid("conditions.$n.category_id");
            }
        }
        $data['css_class'] ??= '';

        return $data;
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => __('stickers.invalid_configuration')]);
    }
}
