<?php

namespace App\Customer\Infrastructure;

use App\Customer\Application\Command\SaveAddressData;
use App\Customer\Application\Command\SaveCustomerData;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Infrastructure\Geography\Countries;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * One server rule set for customer and address input from the browser and from direct Application callers.
 * Emails are normalized like staff identities (trimmed, lower case). A region is optional and, when given, one of the
 * country's active Geography regions (stored by its code, which tax rate areas match exactly); see AddressRegion.
 */
final class CustomerInput
{
    private const TEXT = 'not_regex:/[\x00-\x1f\x7f]/';

    private const PHONE = 'regex:/^\+?[0-9][0-9 ().\-]{2,39}$/D';

    public function phoneRule(): string
    {
        return app(GeneralSettings::class)->all()['phone_validation'] === 'digits'
            ? 'regex:/^[0-9]{3,40}$/D'
            : self::PHONE;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function rules(array $input, bool $create): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:100', self::TEXT],
            'last_name' => ['required', 'string', 'max:100', self::TEXT],
            'email' => ['required', 'string', 'max:254', 'email:rfc'],
            'phone' => ['nullable', 'string', 'max:40', $this->phoneRule()],
            'tax_exempt' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'group_ids' => ['sometimes', 'array', 'max:50'], 'group_ids.*' => ['integer', 'min:1', 'distinct'],
            'default_billing_address_id' => $create ? ['prohibited'] : ['sometimes', 'nullable', 'integer', 'min:1'],
            'default_shipping_address_id' => $create ? ['prohibited'] : ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
        if ($create && is_array($input['address'] ?? null)) {
            $rules['address'] = ['array'];
            $rules += $this->addressRules('address.');
        } else {
            $rules['address'] = $create ? ['nullable'] : ['prohibited'];
        }

        return $rules;
    }

    /**
     * @param  list<string>  $keep  saved "COUNTRY:CODE" regions accepted unchanged (the edited address or order)
     * @return array<string, mixed>
     */
    public function addressRules(string $prefix = '', array $keep = [], bool $delivery = true): array
    {
        return [
            $prefix.'label' => ['nullable', 'string', 'max:60', self::TEXT],
            $prefix.'first_name' => ['required', 'string', 'max:100', self::TEXT],
            $prefix.'last_name' => ['required', 'string', 'max:100', self::TEXT],
            $prefix.'company' => ['nullable', 'string', 'max:150', self::TEXT],
            $prefix.'line1' => ['required', 'string', 'max:200', self::TEXT],
            $prefix.'line2' => ['nullable', 'string', 'max:200', self::TEXT],
            $prefix.'city' => ['required', 'string', 'max:100', self::TEXT],
            $prefix.'postal_code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9 \-]{0,19}$/D'],
            $prefix.'country_code' => ['required', 'string', Rule::in(app(Countries::class)->codes())],
            $prefix.'region_code' => ['nullable', 'string', 'max:10', new AddressRegion($prefix, $keep)],
            $prefix.'phone' => ['nullable', 'string', 'max:40', $this->phoneRule()],
            ...($delivery ? [
                $prefix.'street_number' => ['nullable', 'string', 'max:20', self::TEXT],
                $prefix.'floor' => ['nullable', 'string', 'max:20', self::TEXT],
                $prefix.'bell_name' => ['nullable', 'string', 'max:100', self::TEXT],
                $prefix.'notes' => ['nullable', 'string', 'max:500', 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/'],
            ] : []),
        ];
    }

    /** @param array<string, mixed> $input */
    public function data(array $input, bool $create): SaveCustomerData
    {
        $input = $this->normalized($input);
        $v = Validator::make($input, $this->rules($input, $create), $this->messages())->validate();

        return new SaveCustomerData(
            $v['first_name'], $v['last_name'], $v['email'], $v['phone'] ?? null, (bool) $v['tax_exempt'], $v['notes'] ?? null,
            array_key_exists('group_ids', $v) ? array_map(strval(...), $v['group_ids']) : null,
            isset($v['default_billing_address_id']) ? (string) $v['default_billing_address_id'] : null,
            isset($v['default_shipping_address_id']) ? (string) $v['default_shipping_address_id'] : null,
            is_array($v['address'] ?? null) ? $this->addressDto($v['address']) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keep
     */
    public function address(array $input, array $keep = []): SaveAddressData
    {
        $input = $this->normalizedAddress($input);

        return $this->addressDto(Validator::make($input, $this->addressRules('', $keep), $this->messages())->validate());
    }

    public function assertValid(SaveCustomerData $data, bool $create): void
    {
        $input = $data->toArray();
        Validator::make($input, $this->rules($input, $create), $this->messages())->validate();
    }

    /** @param list<string> $keep */
    public function assertAddress(SaveAddressData $data, array $keep = []): void
    {
        Validator::make($data->toArray(), $this->addressRules('', $keep), $this->messages())->validate();
    }

    /**
     * The saved region of an address, as accepted-unchanged value for its next edit.
     *
     * @return list<string>
     */
    public function savedRegion(?object $address): array
    {
        return $address === null || $address->region_code === null ? [] : [$address->country_code.':'.$address->region_code];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = ['email.email' => __('customers.email_invalid'), 'phone.regex' => __('customers.phone_invalid')];
        foreach (['', 'address.'] as $prefix) {
            $messages[$prefix.'country_code.in'] = __('customers.country_invalid');
            $messages[$prefix.'region_code.regex'] = __('customers.region_invalid');
            $messages[$prefix.'postal_code.regex'] = __('customers.postal_code_invalid');
            $messages[$prefix.'phone.regex'] = __('customers.phone_invalid');
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalized(array $input): array
    {
        foreach (['first_name', 'last_name', 'phone', 'notes'] as $field) {
            $input[$field] = $this->clean($input[$field] ?? null);
        }
        $input['email'] = is_string($input['email'] ?? null) ? mb_strtolower(trim($input['email'])) : ($input['email'] ?? null);
        $input['tax_exempt'] = filter_var($input['tax_exempt'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $input['tax_exempt'];
        foreach (['default_billing_address_id', 'default_shipping_address_id'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] === '') {
                $input[$field] = null;
            }
        }
        if (is_array($input['address'] ?? null)) {
            $input['address'] = $this->normalizedAddress($input['address']);
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizedAddress(array $input): array
    {
        foreach (['label', 'first_name', 'last_name', 'company', 'line1', 'line2', 'city', 'phone', 'street_number', 'floor', 'bell_name', 'notes'] as $field) {
            $input[$field] = $this->clean($input[$field] ?? null);
        }
        foreach (['postal_code', 'country_code', 'region_code'] as $field) {
            $value = $this->clean($input[$field] ?? null);
            $input[$field] = is_string($value) ? strtoupper($value) : $value;
        }

        return $input;
    }

    private function clean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $v */
    private function addressDto(array $v): SaveAddressData
    {
        return new SaveAddressData($v['label'] ?? null, $v['first_name'], $v['last_name'], $v['company'] ?? null, $v['line1'], $v['line2'] ?? null, $v['city'], $v['postal_code'] ?? null, $v['country_code'], $v['region_code'] ?? null, $v['phone'] ?? null, $v['floor'] ?? null, $v['bell_name'] ?? null, $v['notes'] ?? null, $v['street_number'] ?? null);
    }
}
