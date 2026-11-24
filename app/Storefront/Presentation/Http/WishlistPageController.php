<?php

namespace App\Storefront\Presentation\Http;

use App\Customer\Presentation\Http\Controllers\AccountProfile;
use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutSettings;
use App\Storefront\Infrastructure\StorefrontSeo;
use App\Storefront\Infrastructure\WishlistPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** The customer's wishlist page (/wishlist): the Wishlist layout, whose protected main content block shows the lists. */
final class WishlistPageController
{
    public function __invoke(Request $request, LayoutRenderer $renderer, WishlistPage $pages): Response
    {
        if (($closed = StoreClosed::response($request)) !== null) {
            return $closed;
        }
        $locale = app()->getLocale();
        $customer = (string) AccountProfile::of($request)->id;
        $list = $request->query('list');
        $data = $pages->data(['locale' => $locale, 'customer' => $customer], is_string($list) ? $list : null);
        $seo = app(LayoutSettings::class)->seo('wishlist', $locale, ['title' => __('storefront.wishlist_title')]);
        // A private page: never indexed, whatever the layout setting says.
        View::share('storefrontSeo', [...$seo, 'indexable' => false]);
        $page = $renderer->page('wishlist', $locale, $customer, '/wishlist', ['wishlist' => $data]);
        $response = Inertia::render('Storefront/Wishlist', ['storefront' => $page, 'seo' => app(StorefrontSeo::class)->present($page)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
