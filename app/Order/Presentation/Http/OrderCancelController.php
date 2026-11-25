<?php

namespace App\Order\Presentation\Http;

use App\Order\Infrastructure\OrderCancellation;
use App\Settings\Infrastructure\CompanySettings;
use App\Storefront\Infrastructure\CurrentCart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customers cancelling their own order: from the account's order page, or from the signed link in an order e-mail
 * (guests have no account). The link opens a confirmation page; only its form cancels, so mail scanners that follow
 * links never cancel anything. Server-rendered without scripts, under a strict content security policy.
 */
final class OrderCancelController
{
    public function __construct(private OrderCancellation $cancellation) {}

    public function account(Request $request, CurrentCart $current, string $order): RedirectResponse
    {
        $this->cancellation->cancel($order, $current->customer($request) ?? abort(403));

        return back()->with('status', __('orders.cancel_done'));
    }

    public function show(string $order): Response
    {
        return $this->page($order, null);
    }

    public function cancel(string $order): Response
    {
        try {
            $this->cancellation->cancel($order);
            $outcome = 'done';
        } catch (ValidationException $e) {
            $outcome = (string) collect($e->errors())->flatten()->first();
        }

        return $this->page($order, $outcome);
    }

    private function page(string $orderId, ?string $outcome): Response
    {
        $order = DB::table('orders as o')->join('locales as l', 'l.id', '=', 'o.locale_id')->leftJoin('order_statuses as s', 's.id', '=', 'o.status_id')
            ->where('o.id', $orderId)->first(['o.id', 'o.order_number', 's.code as status', 'l.code as locale']) ?? abort(404);
        app()->setLocale($order->locale);
        $company = app(CompanySettings::class)->all();

        return response()->view('orders.cancel', [
            'locale' => $order->locale, 'store' => (string) ($company['name'] ?: config('app.name')), 'number' => $order->order_number,
            'cancelled' => $order->status === 'cancelled', 'allowed' => $this->cancellation->allowed($orderId),
            'message' => $outcome === 'done' ? null : $outcome,
        ])->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
