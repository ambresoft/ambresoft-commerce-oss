<?php

namespace App\Shared\Infrastructure\Modules;

use App\Shared\Application\Contract\AddonSettingValues;
use App\Shared\Application\Contract\AddonStatus;
use Illuminate\Support\ServiceProvider;

/**
 * Host loader for add-ons. Registered after every core provider, so the extension points core contexts publish exist
 * before enabled add-ons register their contributions.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(ModuleExtensionPoints::class);
        $this->app->singleton(AddonCatalog::class);
        $this->app->singleton(AddonRuntime::class);
        $this->app->bind(AddonStatus::class, AddonRuntime::class);
        $this->app->singleton(AddonManager::class);
        $this->app->singleton(AddonSettingsStore::class);
        $this->app->bind(AddonSettingValues::class, AddonSettingsStore::class);
        $this->app->make(ModuleExtensionPoints::class)->add('settings', new AddonSettingsExtensionPoint($this->app));
        $this->app->make(AddonRuntime::class)->boot();
    }
}
