<?php

namespace App\Storefront\Presentation\Http;

use App\Storefront\Infrastructure\CurrentCart;
use App\Storefront\Infrastructure\DirectoryPages;
use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutSettings;
use App\Storefront\Infrastructure\StorefrontSeo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class DirectoryController
{
    public function __invoke(Request $request, LayoutRenderer $renderer, DirectoryPages $directories): Response
    {
        if (($closed = StoreClosed::response($request)) !== null) {
            return $closed;
        }
        $kind = $request->route('directory');
        abort_unless(in_array($kind, ['sitemap', 'brands'], true), 404);
        $locale = app()->getLocale();
        $customer = app(CurrentCart::class)->customer($request);
        $data = $kind === 'sitemap' ? $directories->sitemap($locale, $customer) : $directories->brands($locale);
        $seo = app(LayoutSettings::class)->seo($kind, $locale);
        $seo['title'] = $seo['title'] ?: __('storefront.'.$kind.'_title');
        View::share('storefrontSeo', $seo);
        $page = $renderer->page($kind, $locale, $customer, '/'.$kind, ['directory' => $data]);
        $response = Inertia::render('Storefront/Page', ['storefront' => $page, 'seo' => app(StorefrontSeo::class)->present($page)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        if (! $seo['indexable']) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
