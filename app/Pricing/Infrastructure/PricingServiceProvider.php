<?php

namespace App\Pricing\Infrastructure;

use App\Pricing\Application\Contract\CurrencyAdministration;
use App\Pricing\Application\Service\CurrencyDeletionGuards;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Pricing\Infrastructure\Persistence\EloquentCurrencyAdministration;
use App\Pricing\Infrastructure\Persistence\EloquentCurrencyRepository;
use Illuminate\Support\ServiceProvider;

final class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CurrencyRepository::class, EloquentCurrencyRepository::class);
        $this->app->bind(CurrencyAdministration::class, EloquentCurrencyAdministration::class);
        $this->app->singleton(CurrencyDeletionGuards::class);
    }
}
