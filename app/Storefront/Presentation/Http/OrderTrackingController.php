<?php

namespace App\Storefront\Presentation\Http;

use App\Storefront\Infrastructure\CurrentCart;
use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutSettings;
use App\Storefront\Infrastructure\OrderTracking;
use App\Storefront\Infrastructure\StorefrontSeo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** The order tracking page (/order-status): the Order tracking layout, whose main content block holds the lookup. */
final class OrderTrackingController
{
    /** The session key that brings a customer back to the order they asked about after signing in. */
    public const RETURN_NUMBER = 'order_tracking.number';

    public function __invoke(Request $request, LayoutRenderer $renderer, OrderTracking $tracking): Response
    {
        if (($closed = StoreClosed::response($request)) !== null) {
            return $closed;
        }
        $locale = app()->getLocale();
        $customer = app(CurrentCart::class)->customer($request);
        $number = trim((string) $request->query('number', ''));
        $result = $number === '' ? null : $tracking->lookup($number, $customer);
        if (($result['state'] ?? null) === 'login') {
            $request->session()->put(self::RETURN_NUMBER, $number);
        }
        $seo = app(LayoutSettings::class)->seo('order_tracking', $locale, ['title' => __('storefront.tracking_title')]);
        View::share('storefrontSeo', [...$seo, 'indexable' => false]);
        $page = $renderer->page('order_tracking', $locale, $customer, '/order-status', ['tracking' => ['number' => mb_substr($number, 0, 40), ...($result ?? ['state' => null, 'order' => null])]]);
        $response = Inertia::render('Storefront/OrderTracking', ['storefront' => $page, 'seo' => app(StorefrontSeo::class)->present($page)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
