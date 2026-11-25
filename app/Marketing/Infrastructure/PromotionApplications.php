<?php

namespace App\Marketing\Infrastructure;

use Illuminate\Support\Facades\DB;

/** Order-owned snapshots. All callers hold the existing order/catalog transaction locks. */
final class PromotionApplications
{
    /** @param array<string, mixed> $quote */
    public function save(string $orderId, array $quote): void
    {
        $order = DB::table('orders')->where('id', $orderId)->firstOrFail();
        $items = DB::table('order_items')->where('order_id', $orderId)->orderBy('position')->get();
        $kept = [];
        foreach ($quote['promotions'] as $p) {
            $allocations = [];
            foreach ($p['allocations'] as $n => $amount) {
                $allocations[] = ['item_id' => (string) $items[$n]->id, 'product_id' => $items[$n]->product_id === null ? null : (string) $items[$n]->product_id, 'discount' => $amount];
            }
            DB::table('order_promotions')->updateOrInsert(['order_id' => $orderId, 'promotion_id' => $p['promotion_id']], [
                'name' => $p['name'], 'coupon_code' => $p['coupon_code'], 'benefit' => $p['benefit'], 'discount' => $p['discount'], 'shipping_discount' => $p['shipping_discount'],
                'allocations' => json_encode($allocations, JSON_THROW_ON_ERROR), 'currency_code' => $order->currency_code, 'currency_decimals' => $order->currency_decimals, 'applied_at' => now(), 'is_current' => true, 'released_at' => null,
            ]);
            $kept[] = $p['promotion_id'];
        }
        DB::table('order_promotions')->where('order_id', $orderId)->whereNotIn('promotion_id', $kept)->whereNull('released_at')->update(['released_at' => now(), 'is_current' => false]);
    }

    public function release(string $orderId, bool $release): void
    {
        DB::table('order_promotions')->where('order_id', $orderId)->where('is_current', true)->update(['released_at' => $release ? now() : null]);
    }
}
