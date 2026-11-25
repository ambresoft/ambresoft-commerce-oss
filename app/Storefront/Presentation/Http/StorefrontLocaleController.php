<?php

namespace App\Storefront\Presentation\Http;

use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Remembers the visitor's storefront language in the session; only active languages are accepted. */
final class StorefrontLocaleController
{
    public const SESSION_KEY = 'storefront_locale';

    public function __invoke(Request $request, string $code): RedirectResponse
    {
        abort_unless(app(LocaleCatalog::class)->active()->contains('code', $code), 404);
        $request->session()->put(self::SESSION_KEY, $code);
        // Return to the page the visitor came from, but only inside this site.
        $back = (string) $request->headers->get('referer', '');
        $path = parse_url($back, PHP_URL_PATH) ?: '/';
        $host = parse_url($back, PHP_URL_HOST);
        // A path starting with // or /\ is protocol-relative to browsers and would leave the site.
        $target = ($host === null || $host === $request->getHost()) && preg_match('~^/(?![/\\\\])~', $path) === 1 && ! str_starts_with($path, '/locale/') ? $path : '/';

        return redirect($target);
    }
}
