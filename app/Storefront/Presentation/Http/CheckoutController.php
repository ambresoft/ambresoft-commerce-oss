<?php

namespace App\Storefront\Presentation\Http;

use App\Payment\Infrastructure\OrderPayments;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Infrastructure\CartPage;
use App\Storefront\Infrastructure\Checkout;
use App\Storefront\Infrastructure\CheckoutOptions;
use App\Storefront\Infrastructure\CurrentCart;
use App\Storefront\Infrastructure\InvoiceVerification;
use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutSettings;
use App\Storefront\Infrastructure\StorefrontAccess;
use App\Storefront\Infrastructure\StorefrontSeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single-page checkout (/checkout) for guests and customers: the Checkout layout, whose protected main content block
 * holds the form; the live quote; placing the order; and the confirmation behind a signed link, so an order number
 * alone never shows an order.
 */
final class CheckoutController
{
    /** The session flag that brings a visitor back to checkout after signing in or registering from it. */
    public const RETURN = 'checkout.return';

    /** The only fields read from the browser; prices, charges and totals are always the server's. */
    private const INPUT = ['submission_key', 'email', 'first_name', 'last_name', 'phone', 'shipping_address_id', 'shipping', 'billing_same', 'billing_address_id', 'billing', 'save_address', 'shipping_method_id', 'payment_method_id', 'customer_note', 'accepted_pages', 'newsletter', 'invoice_requested', 'invoice_country_code', 'invoice_vat_number'];

    /** How long the signed confirmation link stays valid (also used after returning from an online payment). */
    public const COMPLETE_DAYS = 30;

    public function __construct(private CurrentCart $current, private Checkout $checkout) {}

    public function show(Request $request, LayoutRenderer $renderer): Response
    {
        abort_unless((bool) app(GeneralSettings::class)->all()['cart_enabled'], 404);
        if (($closed = StoreClosed::response($request)) !== null) {
            return $closed;
        }
        $locale = app()->getLocale();
        $customer = $this->current->customer($request);
        $data = $this->checkout->page($this->current->find($request), $customer, $locale);
        if ($data['cart']['lines'] === []) {
            return redirect('/cart');
        }

        return $this->render($request, $renderer, $customer, $data);
    }

    public function quote(Request $request): JsonResponse
    {
        $this->open();
        $locale = app()->getLocale();
        $customer = $this->current->customer($request);
        $cart = app(CartPage::class)->data($this->current->find($request), ['locale' => $locale, 'customer' => $customer]);

        return response()->json(['quote' => $this->checkout->quote($customer, $locale, $request->only(self::INPUT), $cart)])->header('Cache-Control', 'private, no-store');
    }

    public function verifyInvoice(Request $request, InvoiceVerification $verification): JsonResponse
    {
        $this->open();
        $customer = $this->current->customer($request);
        abort_unless(app(CheckoutOptions::class)->current(app()->getLocale(), $customer)['invoice'], 404);
        $cart = $this->current->find($request);
        abort_unless($cart !== null && DB::table('cart_items')->where('cart_id', $cart->id)->exists(), 404);

        return response()->json(['verification' => $verification->verify($request, $cart, $customer, $request->only(['country_code', 'vat_number']))])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): Response
    {
        $this->open();
        $cart = $this->current->find($request) ?? throw ValidationException::withMessages(['cart' => __('storefront.checkout_empty')]);
        $order = $this->checkout->place($request, $cart, $this->current->customer($request), app()->getLocale(), $request->only(self::INPUT));
        // Online methods go straight to the provider; coming back lands on the signed payment page, which verifies the
        // payment with the provider. Returning alone never marks an order paid. Offline methods show their instructions.
        $payment = app(OrderPayments::class)->start($order);
        if ($payment['outcome'] === 'redirect' && isset($payment['url'])) {
            return Inertia::location($payment['url']);
        }

        return redirect()->to(URL::temporarySignedRoute('checkout.complete', now()->addDays(self::COMPLETE_DAYS), ['order' => $order]));
    }

    public function complete(Request $request, LayoutRenderer $renderer, string $order): Response
    {
        return $this->render($request, $renderer, $this->current->customer($request), ['placed' => $this->checkout->confirmation($order)]);
    }

    /** Remembers, on the sign-in and registration pages, that the visitor came from checkout. */
    public static function remember(Request $request): void
    {
        if ($request->query('return') === 'checkout') {
            $request->session()->put(self::RETURN, true);
        }
    }

    /** After signing in or verifying a new account: whether to go back to checkout (asked once). */
    public static function returning(Request $request): bool
    {
        return (bool) $request->session()->pull(self::RETURN, false);
    }

    /** @param array<string,mixed> $data */
    private function render(Request $request, LayoutRenderer $renderer, ?string $customer, array $data): Response
    {
        $locale = app()->getLocale();
        $seo = app(LayoutSettings::class)->seo('checkout', $locale, ['title' => __('storefront.checkout_title')]);
        // A private page: never indexed, whatever the layout setting says.
        View::share('storefrontSeo', [...$seo, 'indexable' => false]);
        $page = $renderer->page('checkout', $locale, $customer, '/checkout', ['checkout' => $data]);
        $response = Inertia::render('Storefront/Checkout', ['storefront' => $page, 'seo' => app(StorefrontSeo::class)->present($page)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function open(): void
    {
        $settings = app(GeneralSettings::class)->all();
        abort_unless(app(StorefrontAccess::class)->allowed(request()) && (bool) $settings['cart_enabled'], 404);
    }
}
