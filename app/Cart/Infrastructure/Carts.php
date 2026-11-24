<?php

namespace App\Cart\Infrastructure;

use App\Catalog\Domain\Service\ProductRules;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Marketing\Domain\Service\GiftCardRules;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shopping carts. A cart is identified everywhere by its public uuid; a signed-in customer has one cart, a guest one per
 * browser until signing in merges it into the customer's. Lines hold a product and a quantity only: prices are read
 * live when the cart is shown, so a cart is purchase intent, never a quote or a stock reservation. Every change is
 * checked on the server against what the visitor may see and buy (visibility, hidden prices, release date, minimum,
 * maximum and step quantities and stock).
 */
final class Carts
{
    public const MAX_LINES = 100;

    public const MAX_QUANTITY = 100000;

    public function byUuid(string $uuid): ?object
    {
        return Str::isUuid($uuid) ? DB::table('carts')->where('uuid', $uuid)->first() : null;
    }

    public function ofCustomer(string $customer): ?object
    {
        return DB::table('carts')->where('customer_id', $customer)->first();
    }

    /** A new cart: a guest's, or the customer's own (the existing one when another request created it first). */
    public function create(?string $customer): object
    {
        try {
            $id = DB::table('carts')->insertGetId(['uuid' => (string) Str::uuid(), 'customer_id' => $customer, 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('carts')->where('id', $id)->first();
        } catch (UniqueConstraintViolationException) {
            return $this->ofCustomer((string) $customer) ?? throw new \RuntimeException('Cart creation failed.');
        }
    }

    /**
     * Adds a quantity of a product (the line grows when it is already there). A quantity below the product's minimum or
     * off its step is raised to the nearest one it allows, so a product card can always send 1.
     *
     * @return int The line's quantity afterwards.
     */
    public function add(object $cart, string $product, int $quantity, ?string $customer): int
    {
        return DB::transaction(function () use ($cart, $product, $quantity, $customer): int {
            DB::table('carts')->where('id', $cart->id)->lockForUpdate()->first();
            $p = $this->purchasable($product, $customer);
            $current = (int) DB::table('cart_items')->where('cart_id', $cart->id)->where('product_id', $product)->value('quantity');
            if ($current === 0 && DB::table('cart_items')->where('cart_id', $cart->id)->count() >= self::MAX_LINES) {
                throw ValidationException::withMessages(['product' => __('storefront.cart_full', ['max' => self::MAX_LINES])]);
            }
            $next = $this->normalize($p, $current + max(1, $quantity));
            $this->assertQuantity($p, $next);
            $this->write($cart, $product, $next);

            return $next;
        });
    }

    /** Sets a line's quantity exactly as the visitor chose it. */
    public function set(object $cart, string $product, int $quantity, ?string $customer): int
    {
        return DB::transaction(function () use ($cart, $product, $quantity, $customer): int {
            DB::table('carts')->where('id', $cart->id)->lockForUpdate()->first();
            if (! DB::table('cart_items')->where('cart_id', $cart->id)->where('product_id', $product)->exists()) {
                throw ValidationException::withMessages(['product' => __('storefront.cart_unavailable')]);
            }
            $p = $this->purchasable($product, $customer);
            $this->assertQuantity($p, $quantity);
            $this->write($cart, $product, $quantity);

            return $quantity;
        });
    }

    public function remove(object $cart, string $product): void
    {
        if (DB::table('cart_items')->where('cart_id', $cart->id)->where('product_id', $product)->delete() > 0) {
            DB::table('carts')->where('id', $cart->id)->update(['updated_at' => now()]);
        }
    }

    public function clear(object $cart): void
    {
        if (DB::table('cart_items')->where('cart_id', $cart->id)->delete() > 0) {
            DB::table('carts')->where('id', $cart->id)->update(['updated_at' => now()]);
        }
    }

    /**
     * Moves a guest cart into a customer's cart when they sign in: quantities of the same product add up where the
     * product's rules allow it (otherwise the larger allowed one is kept), products they may not buy are left out, and
     * the guest cart is removed.
     */
    public function merge(object $guest, object $target, string $customer): void
    {
        DB::transaction(function () use ($guest, $target, $customer): void {
            $existing = DB::table('cart_items')->where('cart_id', $target->id)->pluck('quantity', 'product_id');
            foreach (DB::table('cart_items')->where('cart_id', $guest->id)->orderBy('id')->get(['product_id', 'quantity']) as $line) {
                $p = $this->row((string) $line->product_id, $customer);
                if ($p === null) {
                    continue;
                }
                $mine = (int) ($existing[$line->product_id] ?? 0);
                $candidates = array_filter([$mine + (int) $line->quantity, max($mine, (int) $line->quantity)], fn (int $q): bool => $this->problem($p, $q) === null);
                if ($candidates !== [] && ($mine > 0 || count($existing) < self::MAX_LINES)) {
                    $this->write($target, (string) $line->product_id, max($candidates));
                    $existing[$line->product_id] = max($candidates);
                }
            }
            // Codes the guest entered carry over unless the customer's cart already has its own.
            DB::table('carts')->where('id', $target->id)->update(['coupon_id' => $target->coupon_id ?? $guest->coupon_id, 'gift_card_id' => $target->gift_card_id ?? $guest->gift_card_id]);
            DB::table('carts')->where('id', $guest->id)->delete();
        });
    }

    /**
     * Remembers a coupon or gift card code (one of each). A new gift card replaces the old one; a new coupon does too,
     * unless *Allow only one coupon per order* is on, which refuses it until the current one is removed. Whether a code
     * applies, and by how much, is decided each time the cart is priced; nothing is reserved or redeemed here.
     */
    public function applyCode(object $cart, string $code): void
    {
        $number = GiftCardRules::normalize($code);
        if (GiftCardRules::isCode($number)) {
            $card = DB::table('gift_cards')->where('number', $number)->whereNull('deleted_at')->value('id');
            $values = ['gift_card_id' => $card ?? throw ValidationException::withMessages(['code' => __('giftcards.not_found')])];
        } else {
            $coupon = DB::table('coupons')->where('code', trim($code))->whereNull('deleted_at')->value('id');
            $values = ['coupon_id' => $coupon ?? throw ValidationException::withMessages(['code' => __('coupons.unavailable')])];
        }
        DB::transaction(function () use ($cart, $values): void {
            $current = DB::table('carts')->where('id', $cart->id)->lockForUpdate()->value('coupon_id');
            if (isset($values['coupon_id']) && $current !== null && (int) $current !== (int) $values['coupon_id'] && app(GeneralSettings::class)->all()['single_coupon']) {
                throw ValidationException::withMessages(['code' => __('storefront.cart_one_coupon')]);
            }
            DB::table('carts')->where('id', $cart->id)->update([...$values, 'updated_at' => now()]);
        });
    }

    /** @param 'coupon'|'gift_card' $kind */
    public function removeCode(object $cart, string $kind): void
    {
        DB::table('carts')->where('id', $cart->id)->update([$kind.'_id' => null, 'updated_at' => now()]);
    }

    /** Units in the cart that the visitor can still see (the header badge). */
    public function count(object $cart, ?string $customer): int
    {
        return (int) DB::table('cart_items')->where('cart_id', $cart->id)
            ->whereIn('product_id', app(PublicCatalog::class)->products($customer)->select('products.id'))->sum('quantity');
    }

    /**
     * Why this quantity of the product cannot be bought now, or null when it can.
     */
    public function problem(object $p, int $quantity): ?string
    {
        $settings = app(GeneralSettings::class)->all();
        $rules = new ProductRules;
        $min = $p->min_qty === null ? null : (int) $p->min_qty;
        $max = $p->max_qty === null ? null : (int) $p->max_qty;
        $step = $p->qty_step === null ? null : (int) $p->qty_step;
        if ($quantity > self::MAX_QUANTITY || ($max !== null && $quantity > $max)) {
            return __('storefront.cart_max', ['max' => min($max ?? self::MAX_QUANTITY, self::MAX_QUANTITY)]);
        }
        if ($quantity < max(1, $min ?? 1)) {
            return __('storefront.cart_min', ['min' => max(1, $min ?? 1)]);
        }
        if (! $rules->quantityAllowed($quantity, $min, $max, $step)) {
            return __('storefront.cart_step', ['step' => (int) $step]);
        }
        if (! $rules->stockAllows((int) $p->quantity, $rules->effectiveOutOfStockAction($p->out_of_stock_actions, $settings['out_of_stock_action']), $quantity, (bool) $settings['track_inventory'])) {
            return (int) $p->quantity > 0 ? __('storefront.cart_stock', ['count' => (int) $p->quantity]) : __('storefront.cart_out_of_stock');
        }

        return null;
    }

    /** The product's purchase rules when the visitor may buy it at all: visible, priced and released. */
    public function row(string $product, ?string $customer): ?object
    {
        $settings = app(GeneralSettings::class)->all();
        if ($settings['hide_product_prices']) {
            return null;
        }
        $p = app(PublicCatalog::class)->products($customer)->where('products.id', $product)->whereNotNull('products.price')
            ->first(['products.id', 'products.quantity', 'products.min_qty', 'products.max_qty', 'products.qty_step', 'products.out_of_stock_actions', 'products.available_from']);
        if ($p === null || ($p->available_from !== null && now()->timezone($settings['timezone'])->toDateString() < $p->available_from)) {
            return null;
        }

        return $p;
    }

    private function purchasable(string $product, ?string $customer): object
    {
        return $this->row($product, $customer) ?? throw ValidationException::withMessages(['product' => __('storefront.cart_unavailable')]);
    }

    private function assertQuantity(object $p, int $quantity): void
    {
        if (($problem = $this->problem($p, $quantity)) !== null) {
            throw ValidationException::withMessages(['quantity' => $problem]);
        }
    }

    /** The smallest quantity the product allows that is at least the requested one. */
    private function normalize(object $p, int $quantity): int
    {
        $min = $p->min_qty === null ? null : (int) $p->min_qty;
        $step = $p->qty_step === null ? null : (int) $p->qty_step;
        $quantity = max($quantity, $min ?? 1);
        if ($step !== null && $step > 0) {
            $base = $min ?? 0;
            $quantity = $base + (int) ceil(($quantity - $base) / $step) * $step;
        }

        return $quantity;
    }

    private function write(object $cart, string $product, int $quantity): void
    {
        DB::table('cart_items')->upsert([['cart_id' => $cart->id, 'product_id' => $product, 'quantity' => $quantity, 'created_at' => now(), 'updated_at' => now()]], ['cart_id', 'product_id'], ['quantity', 'updated_at']);
        DB::table('carts')->where('id', $cart->id)->update(['updated_at' => now()]);
    }
}
