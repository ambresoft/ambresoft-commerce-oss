<?php

namespace App\Shipping\Application\Contract;

use App\Shipping\Application\Command\CarrierAccount;

/** Confirms an account's credentials with the carrier through a harmless read. */
interface VerifiesAccounts
{
    /**
     * @param  array<string, string>  $settings
     *
     * @throws ShippingProviderRejected when the carrier refuses the credentials
     * @throws ShippingProviderUnavailable when it cannot be reached
     */
    public function verify(CarrierAccount $account, array $settings): void;
}
