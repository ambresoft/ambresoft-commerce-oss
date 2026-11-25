<?php

namespace App\Pricing\Infrastructure;

use App\Pricing\Application\Command\SaveCurrencyData;
use App\Pricing\Domain\Model\Currency;
use App\Pricing\Domain\Service\PrimaryCurrencyChange;
use App\Shared\Domain\Service\Decimal;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use ResourceBundle;

/** One server rule set for browser input and direct Application callers. Separators travel as named tokens. */
final class CurrencyInput
{
    /** @var array<string, string> */
    public const THOUSANDS = ['comma' => ',', 'period' => '.', 'space' => "\u{00A0}", 'apostrophe' => "'", 'none' => ''];

    /** @var array<string, string> */
    public const DECIMAL = ['period' => '.', 'comma' => ','];

    /** @return array<string, mixed> */
    public function rules(bool $create): array
    {
        $digits = PrimaryCurrencyChange::RATE_INTEGER_DIGITS;
        $scale = PrimaryCurrencyChange::RATE_SCALE;

        return [
            'code' => $create ? ['required', 'string', 'regex:/^[A-Z]{3}$/D', function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::isIsoCode((string) $value)) {
                    $fail(__('currencies.code_unknown'));
                }
            }] : ['prohibited'],
            'name' => ['required', 'string', 'max:80'],
            'symbol' => ['required', 'string', 'max:10'],
            'symbol_position' => ['required', Rule::in(['before', 'after'])],
            'thousands_separator' => ['required', Rule::in(array_keys(self::THOUSANDS))],
            'decimal_separator' => ['required', Rule::in(array_keys(self::DECIMAL)), 'different:thousands_separator'],
            'decimals' => ['required', 'integer', 'min:0', 'max:'.Currency::MAX_DECIMALS],
            'rate' => ['required', 'string', 'regex:/^\d{1,'.$digits.'}(?:\.\d{1,'.$scale.'})?$/D', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && Decimal::isCanonical($value) && Decimal::compare($value, '0') <= 0) {
                    $fail(__('currencies.rate_invalid'));
                }
            }],
            'status' => ['required', Rule::in(['active', 'disabled'])],
        ];
    }

    /** @param array<string, mixed> $input */
    public function data(array $input, bool $create, string $existingCode = ''): SaveCurrencyData
    {
        if ($create && is_string($input['code'] ?? null)) {
            $input['code'] = strtoupper(trim($input['code']));
        }
        $v = Validator::make($input, $this->rules($create), $this->messages())->validate();

        return new SaveCurrencyData($create ? $v['code'] : $existingCode, trim($v['name']), trim($v['symbol']), $v['symbol_position'], self::THOUSANDS[$v['thousands_separator']], self::DECIMAL[$v['decimal_separator']], (int) $v['decimals'], Decimal::trim($v['rate']), $v['status']);
    }

    /** Revalidates a DTO from any caller with the same rules. */
    public function assertValid(SaveCurrencyData $d, bool $create): void
    {
        $input = ['name' => $d->name, 'symbol' => $d->symbol, 'symbol_position' => $d->symbolPosition, 'thousands_separator' => array_search($d->thousandsSeparator, self::THOUSANDS, true), 'decimal_separator' => array_search($d->decimalSeparator, self::DECIMAL, true), 'decimals' => $d->decimals, 'rate' => $d->rate, 'status' => $d->status];
        if ($create) {
            $input['code'] = $d->code;
        }
        Validator::make($input, [...$this->rules($create), 'name' => ['required', 'string', 'max:80', 'not_regex:/^\s|\s$/'], 'symbol' => ['required', 'string', 'max:10', 'not_regex:/^\s|\s$/']], $this->messages())->validate();
    }

    public static function isIsoCode(string $code): bool
    {
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
            return false;
        }
        $currencies = ResourceBundle::create('en', 'ICUDATA-curr')?->get('Currencies');

        // ICU's ISO 4217 table; without intl data, accept the validated three-letter shape.
        return $currencies === null || $currencies->get($code) !== null;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => __('currencies.code_invalid'), 'code.regex' => __('currencies.code_invalid'), 'code.prohibited' => __('currencies.code_immutable'),
            'name.*' => __('currencies.name_invalid'), 'symbol.*' => __('currencies.symbol_invalid'),
            'symbol_position.*' => __('currencies.position_invalid'), 'thousands_separator.*' => __('currencies.separator_invalid'),
            'decimal_separator.different' => __('currencies.separators_distinct'), 'decimal_separator.*' => __('currencies.separator_invalid'),
            'decimals.*' => __('currencies.decimals_invalid', ['max' => (string) Currency::MAX_DECIMALS]),
            'rate.required' => __('currencies.rate_invalid'), 'rate.string' => __('currencies.rate_invalid'), 'rate.regex' => __('currencies.rate_invalid'),
            'status.*' => __('currencies.status_invalid'),
        ];
    }
}
