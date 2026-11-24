<?php

namespace App\Customer\Infrastructure;

use App\Customer\Application\Contract\CustomerAdministration;
use App\Customer\Application\Contract\CustomerBlacklist;
use App\Customer\Application\Contract\CustomerBlacklistAdministration;
use App\Customer\Application\Contract\CustomerGroupAdministration;
use App\Customer\Infrastructure\Persistence\EloquentCustomerAdministration;
use App\Customer\Infrastructure\Persistence\EloquentCustomerBlacklist;
use App\Customer\Infrastructure\Persistence\EloquentCustomerGroupAdministration;
use Illuminate\Support\ServiceProvider;

final class CustomerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CustomerAdministration::class, EloquentCustomerAdministration::class);
        $this->app->bind(CustomerGroupAdministration::class, EloquentCustomerGroupAdministration::class);
        $this->app->bind(CustomerBlacklist::class, EloquentCustomerBlacklist::class);
        $this->app->bind(CustomerBlacklistAdministration::class, EloquentCustomerBlacklist::class);
    }
}
