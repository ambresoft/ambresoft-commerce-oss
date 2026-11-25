<?php

namespace App\Marketing\Infrastructure;

use Illuminate\Support\Facades\DB;

/** Called only inside the order transaction, after the catalog advisory lock and order lock. */
final class CouponRedemptions
{
    public function sync(string $orderId, bool $released): void
    {
        $order = DB::table('orders')->where('id', $orderId)->firstOrFail();
        $active = DB::table('coupon_redemptions')->where('order_id', $orderId)->whereNull('released_at')->first();
        $key = app(CouponPricing::class)->customerKey($order->customer_id === null ? null : (string) $order->customer_id, $order->email);
        if ($active !== null && ($released || (string) $active->coupon_id !== (string) $order->coupon_id || $active->customer_key !== $key)) {
            DB::table('coupon_redemptions')->where('id', $active->id)->update(['released_at' => now()]);
            $active = null;
        }
        if ($released || $order->coupon_id === null) {
            return;
        }
        // Reactivation keeps the original monetary snapshot, but must reacquire a slot. Expiry/disabling does not
        // rewrite an existing order's contracted price. Definitions remain available after archival.
        $coupon = DB::table('coupons')->where('id', $order->coupon_id)->lockForUpdate()->firstOrFail();
        if ($active === null) {
            app(CouponPricing::class)->capacity($coupon, $key, $orderId);
        }
        $row = ['discount' => $order->coupon_discount, 'shipping_discount' => $order->coupon_shipping_discount, 'code' => $order->coupon_code];
        if ($active !== null) {
            DB::table('coupon_redemptions')->where('id', $active->id)->update($row);
        } else {
            DB::table('coupon_redemptions')->insert([...$row, 'coupon_id' => $coupon->id, 'order_id' => $orderId, 'customer_key' => $key, 'currency_code' => $order->currency_code, 'currency_decimals' => $order->currency_decimals, 'created_at' => now()]);
        }
    }
}
