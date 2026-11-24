<?php

namespace App\Search\Infrastructure;

use App\Search\Application\Service\SearchProviderRegistry;
use App\Shared\Infrastructure\Modules\ModuleExtensionPoints;
use Illuminate\Support\ServiceProvider;

final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SearchProviderRegistry::class);
        // Per request: the visitor's searchable categories are read once.
        $this->app->scoped(SearchVisibility::class);
        // Published before the module host loads enabled add-ons (it is registered last).
        $this->app->singletonIf(ModuleExtensionPoints::class);
        $this->app->make(ModuleExtensionPoints::class)->add('search-providers', new SearchProviderExtensionPoint($this->app, $this->app->make(SearchProviderRegistry::class)));
    }
}
