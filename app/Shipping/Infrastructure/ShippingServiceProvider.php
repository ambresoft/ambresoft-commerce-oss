<?php

namespace App\Shipping\Infrastructure;

use App\Order\Application\Contract\OrderShipping;
use App\Shared\Infrastructure\Modules\ModuleExtensionPoints;
use App\Shipping\Application\Service\ShippingProviderRegistry;
use Illuminate\Support\ServiceProvider;

final class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShippingProviderRegistry::class);
        $this->app->bind(OrderShipping::class, ShippingCharges::class);
        // Published before the module host loads enabled add-ons (it is registered last).
        $this->app->singletonIf(ModuleExtensionPoints::class);
        $this->app->make(ModuleExtensionPoints::class)->add('shipping-providers', new ShippingProviderExtensionPoint($this->app, $this->app->make(ShippingProviderRegistry::class)));
    }
}
