<?php

namespace Ambresoft\Sitemap\Infrastructure;

use Ambresoft\Sitemap\Presentation\Http\ActiveSitemap;
use Ambresoft\Sitemap\Presentation\Http\SitemapController;
use Ambresoft\Sitemap\Presentation\Http\XmlController;
use App\Admin\Navigation\AdminNavigation;
use App\Authorization\Infrastructure\PermissionRegistry;
use App\Localization\Infrastructure\UiTranslations;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SitemapServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'cms-sitemap');
        $this->app->make(UiTranslations::class)->addGroup('cms-sitemap::ui');
        foreach (['view', 'manage', 'publish'] as $ability) {
            $this->app->make(PermissionRegistry::class)->add('ambresoft.sitemap.'.$ability, 'cms-sitemap::ui.permission_'.$ability);
        }
        Route::middleware(['web', ActiveSitemap::class])->group(function (): void {
            Route::get('/sitemap.xml', [XmlController::class, 'index'])->name('module.ambresoft.sitemap.public.index');
            Route::get('/sitemap-{version}-{part}.xml', [XmlController::class, 'part'])->whereUuid('version')->whereNumber('part')->name('module.ambresoft.sitemap.public.part');
        });
        Route::prefix('admin/sitemap')->middleware(['web', 'auth', 'auth.session', 'verified', 'can:access-admin', ActiveSitemap::class, 'can:ambresoft.sitemap.view'])->name('module.ambresoft.sitemap.admin.')->group(function (): void {
            Route::get('/', [SitemapController::class, 'index'])->name('index');
            Route::middleware('can:ambresoft.sitemap.manage')->group(function (): void {
                Route::put('/settings', [SitemapController::class, 'settings'])->name('settings');
                Route::put('/draft', [SitemapController::class, 'draft'])->name('draft');
                Route::post('/generate', [SitemapController::class, 'generate'])->name('generate');
            });
            Route::post('/publish', [SitemapController::class, 'publish'])->middleware('can:ambresoft.sitemap.publish')->name('publish');
        });
        $this->app->make(AdminNavigation::class)->add('ambresoft.sitemap', 'cms-sitemap::ui.title', 'module.ambresoft.sitemap.admin.index', 'ambresoft.sitemap.view', 64, 'core.website');
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(fn () => $this->app->make(Sitemap::class)->dispatchDue())->everyMinute()->name('ambresoft-sitemap')->withoutOverlapping();
        });
    }
}
