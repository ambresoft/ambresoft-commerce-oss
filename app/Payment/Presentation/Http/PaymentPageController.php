<?php

namespace App\Payment\Presentation\Http;

use App\Order\Infrastructure\OrderMoney;
use App\Payment\Application\Command\PaymentAmount;
use App\Payment\Application\Contract\RedirectPaymentMethod;
use App\Payment\Domain\Service\PaymentRules;
use App\Payment\Infrastructure\OrderPayments;
use App\Payment\Infrastructure\PaymentLinks;
use App\Payment\Infrastructure\PaymentSettings;
use App\Settings\Infrastructure\CompanySettings;
use App\Shared\Domain\Service\Decimal;
use App\Storefront\Presentation\Http\CheckoutController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * The customer payment page behind a signed link, in the order's language. It shows only the order number, the amount
 * due and the method, and offers the online payment. Returning from the provider confirms the payment server-side;
 * the page then reports the verified outcome. Server-rendered without scripts, under a strict content security policy.
 */
final class PaymentPageController
{
    /** Query parameters the provider appends to the signed return and cancel URLs. */
    private const PROVIDER_PARAMETERS = ['token', 'PayerID', 'session_id'];

    public function __construct(private PaymentSettings $settings, private PaymentLinks $links, private OrderMoney $money) {}

    public function show(string $order): Response
    {
        return $this->page($order, null);
    }

    public function start(OrderPayments $payments, string $order): RedirectResponse|Response
    {
        abort_unless(DB::table('orders')->where('id', $order)->exists(), 404);
        $result = $payments->start($order);
        if ($result['outcome'] === 'redirect' && isset($result['url'])) {
            return redirect()->away($result['url']);
        }

        return $this->page($order, match ($result['outcome']) {
            'paid' => 'paid', 'pending' => 'pending', 'unavailable' => 'unavailable', default => 'error',
        });
    }

    public function complete(Request $request, OrderPayments $payments, string $payment): RedirectResponse|Response
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(self::PROVIDER_PARAMETERS), 403);
        $state = $payments->complete($payment, $this->providerReference($request));
        abort_if($state === 'invalid', 404);
        // A verified (or provider-confirmed pending) payment ends on the same order confirmation as offline methods.
        if (in_array($state, ['captured', 'pending', 'processing'], true)) {
            return $this->confirmation($payment);
        }

        return $this->page($this->orderOf($payment), match ($state) {
            // `created`: the customer came back without approving at the provider; nothing was charged.
            'captured' => 'paid', 'pending', 'processing' => 'pending', 'failed', 'created' => 'failed', 'cancelled' => 'cancelled', 'unavailable' => 'unavailable', default => 'error',
        });
    }

    public function cancel(Request $request, OrderPayments $payments, string $payment): RedirectResponse|Response
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(self::PROVIDER_PARAMETERS), 403);
        $state = $payments->cancel($payment, $this->providerReference($request));
        abort_if($state === 'invalid', 404);

        return $state === 'captured' ? $this->confirmation($payment) : $this->page($this->orderOf($payment), 'cancelled');
    }

    private function confirmation(string $payment): RedirectResponse
    {
        return redirect()->to(URL::temporarySignedRoute('checkout.complete', now()->addDays(CheckoutController::COMPLETE_DAYS), ['order' => $this->orderOf($payment)]));
    }

    /** The provider's own reference of the attempt, as appended to the return URL (PayPal `token`, Stripe `session_id`). */
    private function providerReference(Request $request): string
    {
        $token = $request->query('token', $request->query('session_id', ''));

        return is_string($token) ? $token : '';
    }

    private function orderOf(string $payment): string
    {
        return (string) DB::table('order_payments')->where('id', $payment)->value('order_id');
    }

    private function page(string $orderId, ?string $outcome): Response
    {
        $order = DB::table('orders as o')->join('locales as l', 'l.id', '=', 'o.locale_id')->where('o.id', $orderId)
            ->first(['o.id', 'o.order_number', 'o.total', 'o.currency_code', 'o.currency_decimals', 'o.payment_method_id', 'o.payment_method_name', 'l.code as locale']) ?? abort(404);
        app()->setLocale($order->locale);
        $payments = DB::table('order_payments')->where('order_id', $orderId)->get(['state', 'amount'])->map(fn (object $p): array => ['state' => $p->state, 'amount' => Decimal::trim($p->amount)])->all();
        $status = PaymentRules::orderStatus($payments, $order->total);
        $outstanding = PaymentRules::outstanding($payments, $order->total);
        $row = $order->payment_method_id === null ? null : DB::table('payment_methods')->where('id', $order->payment_method_id)->first();
        $online = $row !== null && $this->settings->method($row) instanceof RedirectPaymentMethod && $this->settings->ownerEnabled($row)
            && $this->settings->available($row, new PaymentAmount($outstanding, $order->currency_code, (int) $order->currency_decimals));
        $state = match (true) {
            $status === 'paid' => 'paid',
            $status === 'pending' || DB::table('order_payments')->where('order_id', $orderId)->where('state', 'processing')->exists() => 'pending',
            ! $online => 'unavailable',
            default => 'ready',
        };
        $outcome ??= $state === 'ready' ? null : $state;
        $company = app(CompanySettings::class)->all();

        return response()->view('payments.page', [
            'locale' => $order->locale, 'store' => (string) ($company['name'] ?: config('app.name')), 'number' => $order->order_number,
            'amount' => $this->money->format($outstanding, $order->currency_code, (int) $order->currency_decimals),
            'method' => $order->payment_method_name, 'outcome' => $outcome, 'canPay' => $state === 'ready',
            'action' => $state === 'ready' ? $this->links->payUrl($orderId) : null,
        ])->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'self' https:; base-uri 'none'; frame-ancestors 'none'")
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
