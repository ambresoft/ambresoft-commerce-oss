<?php

namespace App\Shipping\Application\Contract;

use App\Shipping\Application\Command\CarrierAccount;
use App\Shipping\Application\Command\CarrierQuote;
use App\Shipping\Application\Command\RateRequest;

/** Real-time rates. Unsupported destinations or data return an unavailable quote; outages throw ShippingProviderUnavailable. */
interface QuotesRates
{
    /** @param array<string, string> $settings */
    public function quote(RateRequest $request, CarrierAccount $account, array $settings): CarrierQuote;
}
