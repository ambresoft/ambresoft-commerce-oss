<?php

namespace App\Order\Infrastructure;

use App\Catalog\Domain\Service\ProductRules;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies the difference between the units each line should hold and the units it has already taken. Product rows are
 * locked in ID order inside the caller's transaction, so concurrent orders cannot oversell or deadlock. Stock never
 * goes below zero: with backorders allowed only the available units are taken (the rest is not deducted); otherwise
 * the whole operation is refused. With inventory tracking switched off, orders do not move stock.
 */
final class OrderStock
{
    /**
     * @param  array<int|string, int>  $targets  Desired deducted quantity by order item ID.
     */
    public function apply(array $targets): void
    {
        if ($targets === [] || ! app(GeneralSettings::class)->all()['track_inventory']) {
            return;
        }
        $items = DB::table('order_items')->whereIn('id', array_keys($targets))->get(['id', 'product_id', 'name', 'deducted_quantity'])->keyBy('id');
        $products = DB::table('products')->whereIn('id', $items->pluck('product_id')->filter()->unique()->all())->orderBy('id')->lockForUpdate()->get(['id', 'quantity', 'out_of_stock_actions'])->keyBy('id');
        $general = app(GeneralSettings::class)->outOfStockAction();
        $rules = new ProductRules;
        $errors = [];
        foreach ($targets as $itemId => $target) {
            $item = $items[$itemId];
            $delta = $target - (int) $item->deducted_quantity;
            if ($delta === 0) {
                continue;
            }
            $product = $item->product_id === null ? null : $products[$item->product_id] ?? null;
            if ($product === null) {
                // Nothing to return stock to; a deleted product cannot give stock either.
                if ($delta > 0) {
                    $errors['items'] = __('orders.stock_product_missing', ['name' => $item->name]);

                    continue;
                }
                $taken = $delta;
            } elseif ($delta < 0) {
                $taken = $delta;
            } elseif ($rules->stockAllows((int) $product->quantity, $rules->effectiveOutOfStockAction($product->out_of_stock_actions, $general), $delta)) {
                $taken = min($delta, (int) $product->quantity);
            } else {
                $errors['stock_'.$itemId] = __('orders.stock_insufficient', ['name' => $item->name, 'available' => $product->quantity, 'requested' => $delta]);

                continue;
            }
            if ($product !== null && $taken !== 0) {
                DB::table('products')->where('id', $product->id)->update(['quantity' => DB::raw('quantity - '.(int) $taken), 'updated_at' => now(), 'lock_version' => DB::raw('lock_version + 1')]);
                $product->quantity -= $taken;
            }
            DB::table('order_items')->where('id', $itemId)->update(['deducted_quantity' => (int) $item->deducted_quantity + $taken]);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
