<?php

namespace App\Customer\Infrastructure;

use App\Customer\Application\Command\SaveCustomerGroupData;
use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CustomerGroupInput
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $locales = app(LocaleCatalog::class);
        $codes = $locales->active()->pluck('code')->all();
        $default = $locales->defaultCode();
        $rules = [
            'names' => ['required', 'array:'.implode(',', $codes)],
            'descriptions' => ['present', 'array:'.implode(',', $codes)],
            'status' => ['required', Rule::in(['active', 'disabled'])],
            'is_default' => ['required', 'boolean'],
        ];
        foreach ($codes as $code) {
            $rules['names.'.$code] = [$code === $default ? 'required' : 'nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1f\x7f]/'];
            $rules['descriptions.'.$code] = ['nullable', 'string', 'max:1000'];
        }

        return $rules;
    }

    /** @param array<string, mixed> $input */
    public function data(array $input): SaveCustomerGroupData
    {
        foreach (['names', 'descriptions'] as $key) {
            $input[$key] = array_map(fn (mixed $v): mixed => is_string($v) ? trim($v) : $v, is_array($input[$key] ?? null) ? $input[$key] : []);
        }
        $input['is_default'] = filter_var($input['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $v = Validator::make($input, $this->rules(), $this->messages())->validate();
        $filled = fn (array $values): array => array_filter(array_map(fn (mixed $value): string => trim((string) $value), $values), fn (string $value): bool => $value !== '');

        return new SaveCustomerGroupData($filled($v['names']), $filled($v['descriptions']), $v['status'], (bool) $v['is_default']);
    }

    public function assertValid(SaveCustomerGroupData $data): void
    {
        Validator::make($data->toArray(), $this->rules(), $this->messages())->validate();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['names.*.required' => __('customers.group_name_required'), 'names.*.max' => __('customers.group_name_invalid')];
    }
}
