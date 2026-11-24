<?php

namespace App\Localization\Infrastructure;

use App\Localization\Application\Contract\LanguageAdministrationInterface;
use Illuminate\Support\ServiceProvider;

final class LocalizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UiTranslations::class);
        $this->app->bind(LanguageAdministrationInterface::class, EloquentLanguageAdministration::class);
        $this->app->extend('translation.loader', fn ($loader) => new OverrideTranslationLoader($loader));
    }
}
