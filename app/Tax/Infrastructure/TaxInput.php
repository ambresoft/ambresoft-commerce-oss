<?php

namespace App\Tax\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Shared\Domain\Service\Decimal;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Domain\Model\TaxDefinition;
use App\Tax\Domain\Model\TaxRate;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** One server rule set for browser input and direct Application callers. */
final class TaxInput
{
    public const MAX_RATES = 300;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function rules(array $input): array
    {
        $locales = app(LocaleCatalog::class);
        $codes = $locales->active()->pluck('code')->all();
        $default = $locales->defaultCode();
        $rules = [
            'names' => ['required', 'array:'.implode(',', $codes)],
            'registration_number' => ['nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f]/'],
            'priority' => ['required', 'integer', 'min:0', 'max:9999'],
            'status' => ['required', Rule::in(['active', 'disabled'])],
            'price_includes_tax' => ['required', 'boolean'],
            'address_basis' => ['required', Rule::in([TaxDefinition::SHIPPING, TaxDefinition::BILLING])],
            'rates' => ['present', 'array', 'max:'.self::MAX_RATES],
            'rates.*.area_id' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('rate_areas', 'id')],
            'rates.*.type' => ['required', Rule::in([TaxRate::PERCENT, TaxRate::FIXED])],
        ];
        foreach ($codes as $code) {
            $rules['names.'.$code] = $code === $default ? ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1f\x7f]/'] : ['nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1f\x7f]/'];
        }
        $scale = app(CurrencyRepository::class)->currentPrimary()->decimals;
        foreach (array_keys(is_array($input['rates'] ?? null) ? $input['rates'] : []) as $index) {
            $type = $input['rates'][$index]['type'] ?? null;
            $rules['rates.'.$index.'.value'] = $type === TaxRate::PERCENT
                // Up to four decimals of a percent (an exact fraction of at most six), 0–100%.
                ? ['bail', 'required', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/D', function (string $attribute, mixed $value, Closure $fail): void {
                    if (Decimal::compare((string) $value, '100') > 0) {
                        $fail(__('taxes.rate_percent_invalid'));
                    }
                }]
                : ['required', 'regex:/^\d{1,14}'.($scale === 0 ? '' : '(?:\.\d{1,'.$scale.'})?').'$/D'];
        }

        return $rules;
    }

    /** @param array<string, mixed> $input */
    public function data(array $input): SaveTaxData
    {
        $input = $this->normalized($input);
        $v = Validator::make($input, $this->rules($input), $this->messages())->validate();

        return $this->dto($v);
    }

    /** Revalidates a DTO from any caller with the same rules. */
    public function assertValid(SaveTaxData $data): void
    {
        Validator::make($data->toArray(), $this->rules($data->toArray()), $this->messages())->validate();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'names.*.required' => __('taxes.name_required'), 'names.*.max' => __('taxes.name_invalid'),
            'rates.*.area_id.exists' => __('taxes.rate_area_invalid'), 'rates.*.area_id.distinct' => __('taxes.rate_area_duplicate'),
            'rates.*.value.required' => __('taxes.rate_value_invalid'), 'rates.*.value.regex' => __('taxes.rate_value_invalid'),
            'rates.*.type.*' => __('taxes.rate_type_invalid'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalized(array $input): array
    {
        foreach (['names'] as $key) {
            if (is_array($input[$key] ?? null)) {
                $input[$key] = array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $input[$key]);
            }
        }
        $registration = $input['registration_number'] ?? null;
        $input['registration_number'] = is_string($registration) && trim($registration) !== '' ? trim($registration) : null;
        foreach ($input['rates'] ?? [] as $index => $rate) {
            if (is_array($rate) && isset($rate['value']) && (is_string($rate['value']) || is_numeric($rate['value']))) {
                $input['rates'][$index]['value'] = trim((string) $rate['value']);
            }
        }
        $input['rates'] ??= [];

        return $input;
    }

    /** @param array<string, mixed> $v */
    private function dto(array $v): SaveTaxData
    {
        $names = [];
        foreach ($v['names'] as $code => $name) {
            if (is_string($name) && trim($name) !== '') {
                $names[(string) $code] = trim($name);
            }
        }

        return new SaveTaxData(
            $names, $v['registration_number'] ?? null, (int) $v['priority'], $v['status'], (bool) $v['price_includes_tax'], $v['address_basis'],
            array_map(fn (array $r): SaveTaxRateData => new SaveTaxRateData((string) $r['area_id'], $r['type'], Decimal::trim((string) $r['value'])), array_values($v['rates'])),
        );
    }
}
