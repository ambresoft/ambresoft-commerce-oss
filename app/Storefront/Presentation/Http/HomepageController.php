<?php

namespace App\Storefront\Presentation\Http;

use App\Storefront\Infrastructure\BannerPreload;
use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutSettings;
use App\Storefront\Infrastructure\StorefrontSeo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class HomepageController
{
    public function __invoke(Request $request, LayoutRenderer $renderer): Response
    {
        if (($closed = StoreClosed::response($request)) !== null) {
            return $closed;
        }
        $customer = $request->user() === null ? null : DB::table('customers')->where('user_id', $request->user()->getAuthIdentifier())->where('status', 'active')->value('id');

        $page = $renderer->page('homepage', app()->getLocale(), $customer === null ? null : (string) $customer, '/'.$request->path());
        // The hero banner is requested with the HTML instead of after the scripts have run.
        // Page title, meta tags and the layout's head HTML (a page with its own SEO would pass it as the override).
        $seo = app(LayoutSettings::class)->seo('homepage', app()->getLocale());
        View::share('storefrontSeo', $seo);
        View::share('storefrontPreloads', app(BannerPreload::class)->links(json_decode(json_encode($page), true)));
        $response = Inertia::render('Storefront/Home', ['storefront' => $page, 'seo' => app(StorefrontSeo::class)->present($page)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        if (! $seo['indexable']) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
