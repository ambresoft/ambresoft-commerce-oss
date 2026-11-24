<?php

namespace App\Customer\Presentation\Http;

use App\Settings\Infrastructure\GeneralSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Wishlist pages and endpoints do not exist while the merchant has switched wishlists off (General settings → Catalog). */
final class EnsureWishlistsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(GeneralSettings::class)->all()['wishlist_enabled'], 404);

        return $next($request);
    }
}
