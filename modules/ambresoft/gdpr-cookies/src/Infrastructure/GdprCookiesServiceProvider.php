<?php

namespace Ambresoft\GdprCookies\Infrastructure;

use Ambresoft\GdprCookies\Presentation\Http\ActiveGdprCookies;
use Ambresoft\GdprCookies\Presentation\Http\ConsentController;
use Ambresoft\GdprCookies\Presentation\Http\GdprCookiesController;
use App\Authorization\Infrastructure\PermissionRegistry;
use App\Localization\Infrastructure\UiTranslations;
use App\Shared\Application\Contract\AddonStatus;
use App\Storefront\Application\Service\StorefrontRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class GdprCookiesServiceProvider extends ServiceProvider
{
    public const NAME = 'ambresoft/gdpr-cookies';

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'cms-gdpr');
        $this->app->make(UiTranslations::class)->addGroup('cms-gdpr::gdpr');
        // Scripts run in every visitor's browser, so changing them is its own capability (granted to nobody by default).
        foreach (['view', 'manage', 'scripts'] as $ability) {
            $this->app->make(PermissionRegistry::class)->add('ambresoft.gdpr-cookies.'.$ability, 'cms-gdpr::gdpr.permission_'.$ability);
        }
        $storefront = $this->app->make(StorefrontRegistry::class);
        $storefront->consentManager(self::NAME, fn (): array => $this->app->make(GdprCookies::class)->storefront(app()->getLocale()));
        $storefront->stylesheet(self::NAME, (string) file_get_contents(__DIR__.'/../../resources/banner.css'));

        Route::post('/storefront/gdpr-cookies/consents', [ConsentController::class, 'store'])->middleware(['web', ActiveGdprCookies::class, 'throttle:30,1'])->name('module.ambresoft.gdpr-cookies.public.consent');
        Route::prefix('admin/addons/ambresoft/gdpr-cookies')->middleware(['web', 'auth', 'auth.session', 'verified', 'can:access-admin', ActiveGdprCookies::class, 'can:ambresoft.gdpr-cookies.view'])
            ->name('module.ambresoft.gdpr-cookies.admin.')->group(function (): void {
                $c = GdprCookiesController::class;
                Route::get('/', [$c, 'index'])->name('index');
                Route::put('/', [$c, 'save'])->middleware('can:ambresoft.gdpr-cookies.manage')->name('save');
                Route::post('/renew', [$c, 'renew'])->middleware('can:ambresoft.gdpr-cookies.manage')->name('renew');
                Route::put('/scripts', [$c, 'scripts'])->middleware('can:ambresoft.gdpr-cookies.scripts')->name('scripts');
            });
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(function (): void {
                if ($this->app->make(AddonStatus::class)->isEnabled(self::NAME)) {
                    $this->app->make(GdprCookies::class)->purge();
                }
            })->dailyAt('04:10')->name('ambresoft-gdpr-cookies-purge');
        });
    }
}
