<?php

namespace App\Http\Middleware;

use App\Authorization\Infrastructure\PermissionRegistry;
use App\Identity\Presentation\Http\AuthArea;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Localization\Infrastructure\UiTranslations;
use App\Search\Infrastructure\SearchRecovery;
use App\Settings\Infrastructure\AiSettings;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Infrastructure\Modules\AddonRuntime;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Infrastructure\StoreBrand;
use App\Storefront\Infrastructure\StorefrontConsent;
use App\Storefront\Infrastructure\StorefrontDocumentPalette;
use App\Storefront\Infrastructure\StorefrontTagManager;
use App\Storefront\Infrastructure\StorefrontWidgets;
use App\Website\Domain\Service\FaqRules;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function handle(Request $request, Closure $next): Response
    {
        // A tag container loaded on a public page must not keep running on a private one (A147).
        if (app(StorefrontTagManager::class)->needsFullLoad($request)) {
            return Inertia::location($request->fullUrl());
        }

        return parent::handle($request, $next);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $stylesheets = fn (): array => AuthArea::admin($request) ? [] : array_filter(
            app(StorefrontRegistry::class)->stylesheets(),
            fn (string $addon): bool => app(AddonRuntime::class)->isLoaded($addon) && app(AddonRuntime::class)->isEnabled($addon),
            ARRAY_FILTER_USE_KEY,
        );

        if ($request->routeIs('foundation')) {
            Inertia::flushShared();
            $catalog = app(LocaleCatalog::class);
            $strings = __('storefront');
            $accountLinks = collect(['account.menu_title', 'account.nav_profile', 'account.nav_orders', 'account.nav_addresses', 'account.nav_reviews', 'account.nav_newsletters', 'account.nav_password', 'auth.sign_in', 'auth.create_link', 'navigation.sign_out'])
                ->mapWithKeys(fn (string $key): array => [$key => __($key)])->all();

            return [...parent::share($request), 'storefrontStylesheets' => Inertia::always($stylesheets), 'storefrontWidgets' => Inertia::always(fn (): array => app(StorefrontWidgets::class)->current()), 'storefrontTagManager' => Inertia::always(fn (): ?array => app(StorefrontTagManager::class)->current($request)), 'storefrontAnalytics' => Inertia::always(fn (): ?array => app(StorefrontTagManager::class)->analytics($request)), 'storefrontConsent' => Inertia::always(fn (): ?array => app(StorefrontConsent::class)->current($request)), 'storefrontPageLinks' => Inertia::always(fn (): array => app(StorefrontWidgets::class)->pageLinks()), 'storefrontDocumentPalette' => Inertia::always(fn () => app(StorefrontDocumentPalette::class)->current()), 'app' => ['name' => config('app.name')],
                'i18n' => ['locale' => app()->getLocale(), 'direction' => $catalog->active()->firstWhere('code', app()->getLocale())->direction ?? 'ltr', 'strings' => [...collect(is_array($strings) ? $strings : [])->mapWithKeys(fn ($value, $key) => ['storefront.'.$key => $value])->all(), ...collect(__('reels'))->mapWithKeys(fn ($value, $key) => ['reels.'.$key => $value])->all(), ...$accountLinks, 'settings.closed_title' => __('settings.closed_title')], 'locales' => $catalog->active()->map(fn ($l) => ['code' => $l->code, 'name' => $l->native_name])->all()]];
        }

        return [
            ...parent::share($request),
            'searchHealth' => Inertia::always(fn () => AuthArea::admin($request) && $user !== null && Gate::forUser($user)->allows('search.manage') ? app(SearchRecovery::class)->status() : null),
            'storefrontStylesheets' => Inertia::always($stylesheets), 'storefrontWidgets' => Inertia::always(fn (): array => app(StorefrontWidgets::class)->current()), 'storefrontTagManager' => Inertia::always(fn (): ?array => app(StorefrontTagManager::class)->current($request)), 'storefrontAnalytics' => Inertia::always(fn (): ?array => app(StorefrontTagManager::class)->analytics($request)), 'storefrontConsent' => Inertia::always(fn (): ?array => app(StorefrontConsent::class)->current($request)), 'storefrontDocumentPalette' => Inertia::always(fn () => app(StorefrontDocumentPalette::class)->current()),
            'faqFieldRules' => ['question' => ['required' => true, 'maxLength' => FaqRules::QUESTION_MAX], 'answer' => ['required' => true, 'maxLength' => FaqRules::ANSWER_MAX]],
            'faqAiReady' => function () use ($user): bool {
                if ($user === null || Gate::forUser($user)->denies('faqs.generate')) {
                    return false;
                }
                $settings = app(AiSettings::class)->all();

                return $settings['enabled'] && $settings['has_key'];
            },
            'preferences' => fn () => array_intersect_key(app(GeneralSettings::class)->all(), array_flip(['date_format', 'time_format', 'timezone', 'calendar_date_format', 'calendar_week_start', 'warn_unsaved_changes', 'show_menu_descriptions', 'notification_duration'])),
            'app' => ['name' => config('app.name')],
            'auth' => [
                'user' => $user ? ['id' => (string) $user->getAuthIdentifier(), 'name' => $user->name, 'email' => $user->email, 'emailVerified' => $user->hasVerifiedEmail()] : null,
                'canAccessAdmin' => $user && Gate::forUser($user)->allows('access-admin'),
            ],
            'flash' => ['status' => fn () => $request->session()->get('status')],
            'capabilities' => $user ? collect(app(PermissionRegistry::class)->all())->map(fn (string $label, string $ability): bool => Gate::forUser($user)->allows($ability))->all() : [],
            'i18n' => [
                'locale' => app()->getLocale(),
                'direction' => app(LocaleCatalog::class)->active()->firstWhere('code', app()->getLocale())->direction ?? 'ltr',
                'strings' => app(UiTranslations::class)->forLocale(app()->getLocale()),
                'locales' => app(LocaleCatalog::class)->active()->map(fn ($locale): array => ['code' => $locale->code, 'name' => $locale->native_name])->all(),
            ],
            // The auth pages serve both areas; each link resolves to the current area's route.
            'authArea' => AuthArea::admin($request) ? 'admin' : 'customer',
            // Customer-facing pages wear the store's logo and colors (the staff pages keep the administration look).
            'storeBrand' => fn () => AuthArea::admin($request) ? null : app(StoreBrand::class)->all(),
            'links' => [...collect(AuthArea::LINKS)->mapWithKeys(fn (string $name): array => [$name => route(AuthArea::route($request, $name), [], false)])->all(),
                'admin.dashboard' => route('admin.dashboard', [], false), 'access.pending' => route('admin.access.pending', [], false), 'admin.login' => route('admin.login', [], false), 'account' => route('account', [], false)],
        ];
    }
}
