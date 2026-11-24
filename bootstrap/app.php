<?php

use App\Audit\Presentation\Console\PruneAuditLogs;
use App\Audit\Presentation\Http\AuditRequestContext;
use App\Authorization\Presentation\Console\BootstrapAdministrator;
use App\Authorization\Presentation\Console\SetupAdministrator;
use App\Authorization\Presentation\Console\SyncPermissions;
use App\Catalog\Presentation\Console\DispatchProductCleanup;
use App\Catalog\Presentation\Console\PruneBrandImports;
use App\Catalog\Presentation\Console\PruneCategoryImports;
use App\Catalog\Presentation\Console\PruneProductImports;
use App\Catalog\Presentation\Console\SeedBrandDemo;
use App\Catalog\Presentation\Console\SeedFeatureDemo;
use App\Customer\Presentation\Console\SeedCustomerDemo;
use App\Geography\Presentation\Console\PruneRegionImports;
use App\Geography\Presentation\Console\SeedGreekRegions;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\KeepOutOfSearchEngines;
use App\Http\Middleware\PrivateBrowserResponse;
use App\Http\Middleware\RememberCurrentPage;
use App\Identity\Presentation\Http\AuthArea;
use App\Localization\Presentation\Http\ResolveLocale;
use App\Marketing\Presentation\Console\DispatchNewsletters;
use App\Marketing\Presentation\Console\SeedBannerDemo;
use App\Marketing\Presentation\Console\SeedNewsletterDemo;
use App\Order\Presentation\Console\SeedOrderDemo;
use App\Order\Presentation\Console\SendOrderEmailTests;
use App\Search\Infrastructure\Jobs\ReconcileSearchIndex;
use App\Search\Infrastructure\SearchIndex;
use App\Search\Infrastructure\SearchTerms;
use App\Search\Presentation\Console\CleanupSearchIndexes;
use App\Search\Presentation\Console\RebuildSearchIndexCommand;
use App\Search\Presentation\Console\ReconcileSearchIndexCommand;
use App\Search\Presentation\Console\SyncSearchIndex;
use App\Shared\Presentation\Console\SyncAddons;
use App\Shared\Presentation\Console\ValidateAddon;
use App\Storefront\Presentation\Console\SeedDemoStore;
use App\Tax\Presentation\Console\SeedTaxDemo;
use App\Website\Presentation\Console\SeedBlogDemo;
use App\Website\Presentation\Console\SeedFaqDemo;
use App\Website\Presentation\Console\SeedPageDemo;
use App\Website\Presentation\Http\ApplyRedirects;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', health: '/up', then: function (): void {
        Route::group([], base_path('routes/webhooks.php'));
    })
    ->withCommands([SeedBrandDemo::class, PruneAuditLogs::class, SeedFeatureDemo::class, SyncPermissions::class, BootstrapAdministrator::class, SetupAdministrator::class, PruneCategoryImports::class, PruneProductImports::class, PruneBrandImports::class, PruneRegionImports::class, DispatchProductCleanup::class, SeedTaxDemo::class, SeedCustomerDemo::class, SeedGreekRegions::class, SeedBannerDemo::class, SeedPageDemo::class, SeedBlogDemo::class, SeedDemoStore::class, SeedFaqDemo::class, SeedOrderDemo::class, SendOrderEmailTests::class, SyncAddons::class, ValidateAddon::class, DispatchNewsletters::class, SeedNewsletterDemo::class, SyncSearchIndex::class, RebuildSearchIndexCommand::class, ReconcileSearchIndexCommand::class, CleanupSearchIndexes::class])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('cms:category-import-prune')->hourly();
        $schedule->command('cms:product-import-prune')->hourly();
        $schedule->command('cms:brand-import-prune')->hourly();
        $schedule->command('cms:region-import-prune')->hourly();
        $schedule->command('cms:product-files-cleanup')->everyMinute()->withoutOverlapping();
        $schedule->command('cms:newsletters-dispatch')->everyMinute()->withoutOverlapping();
        // Catalog changes reach the search index within seconds; the daily reconciliation catches anything missed.
        $schedule->call(fn () => app(SearchIndex::class)->dispatchDue())->everyFiveSeconds()->name('search-sync')->withoutOverlapping();
        $schedule->job(new ReconcileSearchIndex)->dailyAt('03:40')->name('search-reconcile')->withoutOverlapping();
        $schedule->call(fn () => app(SearchTerms::class)->prune())->dailyAt('03:50')->name('search-terms-prune');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: [fn ($request) => $request->is('admin/code-editor/file')]);
        // Written by the storefront's gtag component (A148): the server only reads and validates it.
        // cms_consent is written by the cookie banner (A149) and read by the storefront and the server.
        $middleware->encryptCookies(except: ['cms_ga', 'cms_consent']);
        // SEO redirects run before routing, so old addresses move whether or not they would still answer.
        $middleware->append(ApplyRedirects::class);
        $middleware->web(prepend: [KeepOutOfSearchEngines::class]);
        $middleware->web(append: [RememberCurrentPage::class, PrivateBrowserResponse::class, AuditRequestContext::class, ResolveLocale::class, HandleInertiaRequests::class]);
        $middleware->appendToPriorityList(ShareErrorsFromSession::class, AuditRequestContext::class);
        $middleware->appendToPriorityList(AuditRequestContext::class, PreventRequestForgery::class);
        // Staff pages lead to the staff sign-in, everything else to the customer one (A99).
        $middleware->redirectGuestsTo(fn (Request $request): string => route(AuthArea::route($request, 'login'), [], false));
        $middleware->redirectUsersTo(fn (Request $request): string => route(AuthArea::admin($request) ? 'admin.verification.notice' : 'account', [], false));
        $middleware->trustHosts(subdomains: false);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token', 'api_key', 'ai_settings.api_key', 'checkout_settings.aade_username', 'checkout_settings.aade_password', 'checkout_settings.vatsense_key']);
        $exceptions->respond(function (Response $response): Response {
            if ($response->getStatusCode() >= 400 && ! request()->is('admin', 'admin/*')) {
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            }
            if (! in_array($response->getStatusCode(), [403, 419, 429], true)) {
                return $response;
            }

            $page = Inertia::render('Error', [
                'status' => $response->getStatusCode(),
                'retryAfter' => $response->headers->get('Retry-After'),
            ])->toResponse(request())->setStatusCode($response->getStatusCode());
            if ($response->headers->has('Retry-After')) {
                $page->headers->set('Retry-After', $response->headers->get('Retry-After'));
            }
            $page->headers->add(PrivateBrowserResponse::HEADERS);
            if (! request()->is('admin', 'admin/*')) {
                $page->headers->set('X-Robots-Tag', 'noindex, nofollow');
            }

            return $page;
        });
    })
    ->create();
