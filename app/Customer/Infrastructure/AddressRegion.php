<?php

namespace App\Customer\Infrastructure;

use App\Geography\Application\Contract\ShippingGeography;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * An address region is chosen from the country's active regions (Geography → Regions) and is required when the country
 * has any; a country without regions takes none. The address's own saved, unchanged value is still accepted so older addresses and order snapshots stay
 * editable without silently changing their region (and order taxes).
 */
final class AddressRegion implements DataAwareRule, ValidationRule
{
    /** Runs on empty values too: a country with regions needs one. */
    public bool $implicit = true;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @param list<string> $keep saved "COUNTRY:CODE" values accepted unchanged */
    public function __construct(private string $prefix = '', private array $keep = []) {}

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $country = Arr::get($this->data, $this->prefix.'country_code');
        if ($value === null || $value === '') {
            if (is_string($country) && (app(ShippingGeography::class)->regionOptions()[$country] ?? []) !== []) {
                $fail(__('customers.region_required'));
            }

            return;
        }
        if (! is_string($value) || ! is_string($country)) {
            $fail(__('customers.region_invalid'));

            return;
        }
        if (in_array($country.':'.$value, $this->keep, true)) {
            return;
        }
        if (! in_array($value, array_column(app(ShippingGeography::class)->regionOptions()[$country] ?? [], 'code'), true)) {
            $fail(__('customers.region_invalid'));
        }
    }
}
