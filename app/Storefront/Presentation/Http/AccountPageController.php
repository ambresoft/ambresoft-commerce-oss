<?php

namespace App\Storefront\Presentation\Http;

use App\Customer\Presentation\Http\Controllers\AccountProfile;
use App\Order\Infrastructure\OrderEmailRenderer;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Infrastructure\AccountPage;
use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutSettings;
use App\Storefront\Infrastructure\StorefrontSeo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer's account pages (/account, /account/orders, …): the Account layout, whose protected main content block
 * shows the section the address names. Private pages, never indexed whatever the layout setting says.
 */
final class AccountPageController
{
    /** The same printable HTML as the invoice email, available only after the invoice event and only to its owner. */
    public function invoice(Request $request, string $order, OrderEmailRenderer $renderer): Response
    {
        if (($closed = StoreClosed::response($request)) !== null) {
            return $closed;
        }
        $customer = AccountProfile::of($request);
        $row = DB::table('orders')->where('id', $order)->where('customer_id', $customer->id)->first(['id', 'order_number']) ?? abort(404);
        abort_unless(DB::table('order_notifications')->where('order_id', $order)->where('kind', 'invoice')->exists(), 404);

        // shortcut: this is a live commercial view; serve the retained issued artifact when the fiscal addon is implemented.
        return response($renderer->invoicePage($order), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', 'order-summary-'.$row->order_number.'.html'),
            'Content-Security-Policy' => "default-src 'none'; img-src data: https:; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function __invoke(Request $request, LayoutRenderer $renderer, AccountPage $pages): Response
    {
        // Read by name: the order address segment would otherwise take the place of the section default.
        $section = (string) $request->route('section', 'profile');
        $order = $request->route('order');
        abort_unless(in_array($section, AccountPage::SECTIONS, true), 404);
        // Reviews switched off for the store take the customer's review list with them.
        abort_if($section === 'reviews' && ! app(GeneralSettings::class)->all()['reviews_enabled'], 404);
        if (($closed = StoreClosed::response($request)) !== null) {
            return $closed;
        }
        $locale = app()->getLocale();
        $profile = AccountProfile::of($request);
        $data = $pages->data($section, $profile, $request->user(), (int) $request->query('page', 1), $order);
        $seo = app(LayoutSettings::class)->seo('account', $locale, ['title' => __('account.title_'.$section)]);
        View::share('storefrontSeo', [...$seo, 'indexable' => false]);
        $path = match ($section) {
            'profile' => '/account',
            'order' => '/account/orders/'.$order,
            default => '/account/'.$section,
        };
        $page = $renderer->page('account', $locale, (string) $profile->id, $path, ['account' => $data]);
        $response = Inertia::render('Storefront/Account', ['storefront' => $page, 'seo' => app(StorefrontSeo::class)->present($page)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
