<?php

namespace App\Storefront\Infrastructure;

use App\Cart\Infrastructure\Carts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

/**
 * The storefront visitor's cart. A signed-in customer's cart belongs to their profile; a guest's is named by an
 * encrypted cookie holding its public uuid. When a guest with a cart signs in, that cart becomes the customer's (or is
 * merged into the one they already have) and the cookie is dropped. Browsing alone never creates a cart.
 */
final class CurrentCart
{
    public const COOKIE = 'cart';

    private const DAYS = 30;

    public function __construct(private Carts $carts) {}

    /** The visitor's active customer profile, if they are signed in as a customer. */
    public function customer(Request $request): ?string
    {
        $id = $request->user() === null ? null : DB::table('customers')->where('user_id', $request->user()->getAuthIdentifier())->where('status', 'active')->value('id');

        return $id === null ? null : (string) $id;
    }

    public function find(Request $request): ?object
    {
        $customer = $this->customer($request);
        $uuid = $request->cookie(self::COOKIE);
        $guest = is_string($uuid) ? $this->carts->byUuid($uuid) : null;
        // A cookie only ever names a guest cart; one that belongs to a customer is ignored.
        if ($guest !== null && $guest->customer_id !== null) {
            $guest = null;
        }
        if ($customer === null) {
            return $guest;
        }
        $own = $this->carts->ofCustomer($customer);
        if ($guest !== null) {
            if ($own === null) {
                DB::table('carts')->where('id', $guest->id)->whereNull('customer_id')->update(['customer_id' => $customer, 'updated_at' => now()]);
                $own = $this->carts->ofCustomer($customer);
            } else {
                $this->carts->merge($guest, $own, $customer);
            }
            Cookie::queue(Cookie::forget(self::COOKIE));
        }

        return $own;
    }

    /** The visitor's cart, created when they put the first product in it. */
    public function findOrCreate(Request $request): object
    {
        $cart = $this->find($request);
        if ($cart !== null) {
            if ($cart->customer_id === null) {
                $this->remember($cart);
            }

            return $cart;
        }
        $customer = $this->customer($request);
        $cart = $this->carts->create($customer);
        if ($customer === null) {
            $this->remember($cart);
        }

        return $cart;
    }

    /** Units in the visitor's cart, for the header. */
    public function count(Request $request): int
    {
        $cart = $this->find($request);

        return $cart === null ? 0 : $this->carts->count($cart, $this->customer($request));
    }

    /** The guest's cookie lasts a month from their last change. */
    private function remember(object $cart): void
    {
        Cookie::queue(Cookie::make(self::COOKIE, $cart->uuid, self::DAYS * 24 * 60, httpOnly: true, sameSite: 'lax'));
    }
}
