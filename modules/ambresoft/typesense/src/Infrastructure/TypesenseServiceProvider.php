<?php

namespace Ambresoft\Typesense\Infrastructure;

use Illuminate\Support\ServiceProvider;

/** Registered only while the add-on is enabled. The search provider itself is declared in composer.json. */
final class TypesenseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/typesense.php', 'typesense');
        $this->app->singleton(TypesenseClient::class, fn ($app): TypesenseClient => new TypesenseClient((array) $app['config']->get('typesense')));
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'cms-typesense');
    }
}
