<?php

namespace App\Shipping\Application\Contract;

use App\Shared\Application\Command\SettingField;

/**
 * A carrier integration registered by core or by an add-on (manifest contribution `shipping-providers`). The base
 * contract declares identity, provider-wide settings and the fields of a carrier account; everything else is an
 * optional capability interface (QuotesRates, CreatesShipments, PrintsLabels, TracksShipments, CancelsShipments,
 * ManifestsShipments, VerifiesAccounts, MapsOrderStatuses). The CMS stores settings and accounts (secrets encrypted)
 * and renders their editors; providers never write order or shipment data themselves.
 */
interface ShippingProvider
{
    /** Stable identifier stored with methods, accounts and shipments, e.g. `swift`. */
    public function id(): string;

    /** Display name shown to administrators (not translated), e.g. the carrier's brand name. */
    public function name(): string;

    /** @return list<SettingField> Provider-wide settings */
    public function settings(): array;

    /**
     * @param  array<string, string>  $values
     * @return array<string, string> Field key => localized message
     */
    public function validateSettings(array $values): array;

    /** @return list<SettingField> Fields of one carrier account (credentials are `secret`/`credential` fields) */
    public function accountFields(): array;

    /**
     * @param  array<string, string>  $values
     * @return array<string, string> Field key => localized message
     */
    public function validateAccount(array $values): array;
}
