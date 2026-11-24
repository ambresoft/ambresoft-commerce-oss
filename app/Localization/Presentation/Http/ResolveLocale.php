<?php

namespace App\Localization\Presentation\Http;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Identity\Presentation\Http\AuthArea;
use App\Localization\Infrastructure\LocaleCatalog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ResolveLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var UserRecord|null $user */
        $user = $request->user();
        $user = $user?->fresh();
        if ($user) {
            Auth::guard('web')->setUser($user);
        }
        $catalog = app(LocaleCatalog::class);
        app()->setLocale($catalog->forPreference($user?->preferred_locale_id));
        // Visitors choose their storefront language with the language switcher; the administration keeps staff preferences.
        $chosen = $request->hasSession() && ! $request->is('admin', 'admin/*') ? $request->session()->get('storefront_locale') : null;
        if (is_string($chosen) && $catalog->active()->contains('code', $chosen)) {
            app()->setLocale($chosen);
        }
        $default = $catalog->defaultCode();
        app('translator')->determineLocalesUsing(fn (array $locales): array => array_values(array_unique([$locales[0], $default, 'en'])));
        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route(AuthArea::route($request, 'login'));
        }

        return $next($request);
    }
}
