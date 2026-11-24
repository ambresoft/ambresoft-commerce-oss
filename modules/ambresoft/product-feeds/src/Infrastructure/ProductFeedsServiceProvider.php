<?php

namespace Ambresoft\ProductFeeds\Infrastructure;

use Ambresoft\ProductFeeds\Presentation\Http\ActiveProductFeeds;
use Ambresoft\ProductFeeds\Presentation\Http\ProductFeedController;
use Ambresoft\ProductFeeds\Presentation\Http\PublicFeedController;
use App\Authorization\Infrastructure\PermissionRegistry;
use App\Localization\Infrastructure\UiTranslations;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ProductFeedsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'cms-product-feeds');
        $this->app->make(UiTranslations::class)->addGroup('cms-product-feeds::ui');
        foreach (['view', 'manage'] as $ability) {
            $this->app->make(PermissionRegistry::class)->add('ambresoft.product-feeds.'.$ability, 'cms-product-feeds::ui.permission_'.$ability);
        }
        // Under the reserved /storefront segment, so SEO redirects can never claim these addresses.
        Route::middleware(['web', ActiveProductFeeds::class])->prefix('storefront/product-feeds')->name('module.ambresoft.product-feeds.public.')->group(function (): void {
            Route::get('/{feed}.xml', [PublicFeedController::class, 'show'])->whereUuid('feed')->middleware('throttle:60,1')->name('show');
            Route::get('/{feed}/generate', [PublicFeedController::class, 'generate'])->whereUuid('feed')->middleware('throttle:10,1')->name('generate');
        });
        Route::prefix('admin/addons/ambresoft/product-feeds')->middleware(['web', 'auth', 'auth.session', 'verified', 'can:access-admin', ActiveProductFeeds::class, 'can:ambresoft.product-feeds.view'])
            ->name('module.ambresoft.product-feeds.admin.')->group(function (): void {
                $c = ProductFeedController::class;
                Route::get('/', [$c, 'index'])->name('index');
                Route::get('/create', [$c, 'edit'])->middleware('can:ambresoft.product-feeds.manage')->name('create');
                Route::get('/{feed}/edit', [$c, 'edit'])->whereUuid('feed')->name('edit');
                Route::middleware('can:ambresoft.product-feeds.manage')->group(function () use ($c): void {
                    Route::post('/', [$c, 'save'])->name('store');
                    Route::put('/{feed}', [$c, 'save'])->whereUuid('feed')->name('update');
                    Route::delete('/{feed}', [$c, 'destroy'])->whereUuid('feed')->name('destroy');
                    Route::post('/{feed}/generate', [$c, 'generate'])->whereUuid('feed')->name('generate');
                });
            });
    }
}
