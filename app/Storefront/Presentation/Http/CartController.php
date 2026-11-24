<?php

namespace App\Storefront\Presentation\Http;

use App\Cart\Infrastructure\Carts;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Infrastructure\CartPage;
use App\Storefront\Infrastructure\CurrentCart;
use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutSettings;
use App\Storefront\Infrastructure\ProductPackage;
use App\Storefront\Infrastructure\StorefrontAccess;
use App\Storefront\Infrastructure\StorefrontSeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cart (/cart) for guests and customers: the Cart layout, whose protected main content block shows the lines and
 * the totals, and the JSON calls that change it. Each change answers with the whole cart as it now stands, so every
 * client shows the server's prices and rules.
 */
final class CartController
{
    public function __construct(private CurrentCart $current, private Carts $carts, private CartPage $pages) {}

    public function show(Request $request, LayoutRenderer $renderer): Response
    {
        abort_unless((bool) app(GeneralSettings::class)->all()['cart_enabled'], 404);
        if (($closed = StoreClosed::response($request)) !== null) {
            return $closed;
        }
        $locale = app()->getLocale();
        $customer = $this->current->customer($request);
        $data = $this->pages->data($this->current->find($request), ['locale' => $locale, 'customer' => $customer]);
        $seo = app(LayoutSettings::class)->seo('cart', $locale, ['title' => __('storefront.cart_title')]);
        // A private page: never indexed, whatever the layout setting says.
        View::share('storefrontSeo', [...$seo, 'indexable' => false]);
        $page = $renderer->page('cart', $locale, $customer, '/cart', ['cart' => $data]);
        $response = Inertia::render('Storefront/Cart', ['storefront' => $page, 'seo' => app(StorefrontSeo::class)->present($page)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** The cart as it stands, for the header's mini cart. */
    public function items(Request $request): JsonResponse
    {
        $this->open();

        return $this->reply($request, $this->current->find($request));
    }

    public function add(Request $request): JsonResponse
    {
        $this->open();
        $data = $request->validate(['product_id' => ['required', 'integer', 'min:1'], 'quantity' => ['sometimes', 'integer', 'min:1', 'max:'.Carts::MAX_QUANTITY]]);
        $cart = $this->current->findOrCreate($request);
        $quantity = $this->carts->add($cart, (string) $data['product_id'], (int) ($data['quantity'] ?? 1), $this->current->customer($request));

        return $this->reply($request, $cart, ['quantity' => $quantity]);
    }

    public function package(Request $request): JsonResponse
    {
        $this->open();
        $v = $request->validate(['product_id' => ['required', 'integer', 'min:1']]);
        $cart = $this->current->findOrCreate($request);
        app(ProductPackage::class)->add($cart, (string) $v['product_id'], $this->current->customer($request));

        return $this->reply($request, $cart);
    }

    public function update(Request $request, string $product): JsonResponse
    {
        $this->open();
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.Carts::MAX_QUANTITY]]);
        $cart = $this->current->find($request) ?? abort(404);
        $this->carts->set($cart, $product, (int) $data['quantity'], $this->current->customer($request));

        return $this->reply($request, $cart);
    }

    public function remove(Request $request, string $product): JsonResponse
    {
        $this->open();
        $cart = $this->current->find($request);
        if ($cart !== null) {
            $this->carts->remove($cart, $product);
        }

        return $this->reply($request, $cart);
    }

    /** Empties the cart (its coupon and gift card stay, as when the last line is removed). */
    public function clear(Request $request): JsonResponse
    {
        $this->open();
        $cart = $this->current->find($request);
        if ($cart !== null) {
            $this->carts->clear($cart);
        }

        return $this->reply($request, $cart);
    }

    /** A coupon or gift card code; the reply's cart says whether it applies and why not. */
    public function applyCode(Request $request): JsonResponse
    {
        $this->open();
        $this->codes();
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $cart = $this->current->find($request) ?? abort(404);
        $this->carts->applyCode($cart, $data['code']);

        return $this->reply($request, $cart);
    }

    public function removeCode(Request $request, string $kind): JsonResponse
    {
        $this->open();
        $this->codes();
        $cart = $this->current->find($request);
        if ($cart !== null) {
            $this->carts->removeCode($cart, $kind);
        }

        return $this->reply($request, $cart);
    }

    /** @param array<string,mixed> $extra */
    private function reply(Request $request, ?object $cart, array $extra = []): JsonResponse
    {
        $data = $this->pages->data($cart, ['locale' => app()->getLocale(), 'customer' => $this->current->customer($request)]);

        return response()->json(['count' => $data['count'], 'cart' => $data, ...$extra])->header('Cache-Control', 'private, no-store');
    }

    private function open(): void
    {
        $settings = app(GeneralSettings::class)->all();
        abort_unless(app(StorefrontAccess::class)->allowed(request()) && (bool) $settings['cart_enabled'], 404);
    }

    private function codes(): void
    {
        abort_unless((bool) app(GeneralSettings::class)->all()['cart_codes_enabled'], 404);
    }
}
