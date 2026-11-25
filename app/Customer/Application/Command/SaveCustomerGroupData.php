<?php

namespace App\Customer\Application\Command;

final readonly class SaveCustomerGroupData
{
    /**
     * @param  array<string, string>  $names  Locale code => name; the default language is required.
     * @param  array<string, string>  $descriptions  Locale code => optional description.
     */
    public function __construct(public array $names, public array $descriptions, public string $status, public bool $default) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['names' => $this->names, 'descriptions' => $this->descriptions, 'status' => $this->status, 'is_default' => $this->default];
    }
}
