<?php

namespace App\Tax\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Infrastructure\Geography\Countries;
use App\Tax\Application\Command\SaveRateAreaData;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class RateAreaInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function rules(array $input): array
    {
        $locales = app(LocaleCatalog::class);
        $codes = $locales->active()->pluck('code')->all();
        $default = $locales->defaultCode();
        $isDefault = ($input['is_default'] ?? false) === true;
        $rules = [
            'names' => ['required', 'array:'.implode(',', $codes)],
            'status' => ['required', Rule::in(['active', 'disabled'])],
            'is_default' => ['required', 'boolean'],
            // The all-countries fallback has no memberships; every other area needs at least one country.
            'countries' => $isDefault ? ['present', 'array', 'max:0'] : ['required', 'array', 'min:1', 'max:300'],
            'countries.*' => ['string', 'distinct', Rule::in(app(Countries::class)->codes())],
            'regions' => $isDefault ? ['present', 'array', 'max:0'] : ['present', 'array:'.implode(',', array_filter(array_map('strval', (array) ($input['countries'] ?? []))))],
            'regions.*' => ['array', 'max:200'],
            'regions.*.*' => ['string', 'distinct', 'regex:/^[A-Z0-9]{1,10}$/D'],
        ];
        foreach ($codes as $code) {
            $rules['names.'.$code] = $code === $default ? ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1f\x7f]/'] : ['nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1f\x7f]/'];
        }

        return $rules;
    }

    /** @param array<string, mixed> $input */
    public function data(array $input): SaveRateAreaData
    {
        $input = $this->normalized($input);
        $v = Validator::make($input, $this->rules($input), $this->messages())->validate();

        return $this->dto($v);
    }

    public function assertValid(SaveRateAreaData $data): void
    {
        Validator::make($data->toArray(), $this->rules($data->toArray()), $this->messages())->validate();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'names.*.required' => __('taxes.area_name_required'), 'names.*.max' => __('taxes.name_invalid'),
            'countries.required' => __('taxes.area_countries_required'), 'countries.min' => __('taxes.area_countries_required'), 'countries.max' => __('taxes.area_default_no_members'),
            'countries.*.in' => __('taxes.country_invalid'), 'regions.max' => __('taxes.area_default_no_members'),
            'regions.array' => __('taxes.region_country_missing'), 'regions.*.*.regex' => __('taxes.region_invalid'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalized(array $input): array
    {
        $upper = fn (mixed $value): mixed => is_string($value) ? strtoupper(trim($value)) : $value;
        $input['names'] = array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, is_array($input['names'] ?? null) ? $input['names'] : []);
        $input['is_default'] = filter_var($input['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $input['countries'] = array_map($upper, is_array($input['countries'] ?? null) ? array_values($input['countries']) : []);
        $regions = [];
        foreach (is_array($input['regions'] ?? null) ? $input['regions'] : [] as $country => $list) {
            // Accept a comma/space separated string from simple callers; a blank entry means no restriction.
            $items = is_string($list) ? preg_split('/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY) : (is_array($list) ? $list : []);
            $items = array_values(array_unique(array_filter(array_map($upper, $items), fn (mixed $item): bool => $item !== '')));
            if ($items !== []) {
                $regions[strtoupper((string) $country)] = $items;
            }
        }
        $input['regions'] = $regions;

        return $input;
    }

    /** @param array<string, mixed> $v */
    private function dto(array $v): SaveRateAreaData
    {
        $names = [];
        foreach ($v['names'] as $code => $name) {
            if (is_string($name) && trim($name) !== '') {
                $names[(string) $code] = trim($name);
            }
        }

        return new SaveRateAreaData($names, $v['status'], (bool) $v['is_default'], array_values($v['countries']), array_map(fn (array $list): array => array_values($list), $v['regions']));
    }
}
