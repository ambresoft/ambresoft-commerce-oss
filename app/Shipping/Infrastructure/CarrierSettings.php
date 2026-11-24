<?php

namespace App\Shipping\Infrastructure;

use App\Shared\Infrastructure\Modules\AddonRuntime;
use App\Shared\Infrastructure\Settings\FieldValues;
use App\Shipping\Application\Command\CarrierAccount;
use App\Shipping\Application\Contract\ShippingProvider;
use App\Shipping\Application\Service\ShippingProviderRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Stored provider settings and carrier accounts. A provider is usable only while it is registered and its owner
 * (core or add-on) is enabled; an account is usable when every required field has a value. Credentials are decrypted
 * here, handed only to the provider that owns the account, and never returned to the browser.
 */
final class CarrierSettings
{
    public function __construct(private ShippingProviderRegistry $registry, private AddonRuntime $addons, private FieldValues $fields) {}

    /** The provider if it is registered and its owner is enabled right now. */
    public function provider(?string $id): ?ShippingProvider
    {
        if ($id === null || ! $this->registry->has($id)) {
            return null;
        }
        $owner = (string) $this->registry->owner($id);

        return $owner === ShippingProviderRegistry::CORE || $this->addons->isEnabled($owner) ? $this->registry->get($id) : null;
    }

    /** @return list<ShippingProvider> */
    public function providers(): array
    {
        return array_values(array_filter(array_map(fn (string $id): ?ShippingProvider => $this->provider($id), $this->registry->ids())));
    }

    public function owner(string $id): ?string
    {
        return $this->registry->owner($id);
    }

    public function settingsRow(string $provider): object
    {
        DB::table('shipping_carrier_settings')->insertOrIgnore(['provider' => $provider, 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('shipping_carrier_settings')->where('provider', $provider)->first();
    }

    /** @return array<string, string> */
    public function settings(ShippingProvider $provider): array
    {
        $row = $this->settingsRow($provider->id());

        return $this->fields->values($provider->settings(), json_decode((string) $row->settings, true) ?: [], $this->fields->decrypt($row->secrets, 'carrier:'.$provider->id()));
    }

    public function accountRow(?string $id): ?object
    {
        return $id === null || preg_match('/^[1-9]\d{0,18}$/D', $id) !== 1 ? null : DB::table('shipping_carrier_accounts')->where('id', $id)->first();
    }

    /** @return array<string, string> */
    public function accountValues(ShippingProvider $provider, object $row): array
    {
        return $this->fields->values($provider->accountFields(), json_decode((string) $row->settings, true) ?: [], $this->fields->decrypt($row->secrets, 'carrier-account:'.$row->id));
    }

    public function account(ShippingProvider $provider, object $row): CarrierAccount
    {
        return new CarrierAccount((string) $row->id, $row->name, $this->accountValues($provider, $row));
    }

    /** @return list<string> Missing required fields (translated) */
    public function accountIssues(ShippingProvider $provider, object $row): array
    {
        return array_map(fn (string $label): string => __('shipping.issue_required', ['field' => $label]), $this->fields->missing($provider->accountFields(), $this->accountValues($provider, $row)));
    }

    /** The usable account of a provider: it belongs to the provider and has every required value. */
    public function usableAccount(ShippingProvider $provider, ?string $accountId): ?CarrierAccount
    {
        $row = $this->accountRow($accountId);
        if ($row === null || $row->provider !== $provider->id() || $this->accountIssues($provider, $row) !== []) {
            return null;
        }

        return $this->account($provider, $row);
    }

    /** @return list<string> Missing required provider settings (translated) */
    public function settingsIssues(ShippingProvider $provider): array
    {
        return array_map(fn (string $label): string => __('shipping.issue_required', ['field' => $label]), $this->fields->missing($provider->settings(), $this->settings($provider)));
    }

    public function fieldValues(): FieldValues
    {
        return $this->fields;
    }
}
