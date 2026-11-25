<?php

namespace App\Tax\Infrastructure;

use App\Tax\Application\Contract\CustomerTaxProfiles;
use App\Tax\Application\Contract\ProductTaxAssignment;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use App\Tax\Application\Contract\TaxConfiguration;
use App\Tax\Infrastructure\Persistence\EloquentRateAreaAdministration;
use App\Tax\Infrastructure\Persistence\EloquentTaxAdministration;
use Illuminate\Support\ServiceProvider;

final class TaxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TaxConfiguration::class, EloquentTaxConfiguration::class);
        $this->app->bind(TaxAdministration::class, EloquentTaxAdministration::class);
        $this->app->bind(RateAreaAdministration::class, EloquentRateAreaAdministration::class);
        $this->app->bind(ProductTaxAssignment::class, EloquentProductTaxAssignment::class);
        $this->app->bind(CustomerTaxProfiles::class, EloquentCustomerTaxProfiles::class);
    }
}
