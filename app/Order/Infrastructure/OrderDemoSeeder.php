<?php

namespace App\Order\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Order\Domain\Service\OrderStockRules;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One fictitious sample order per order status, created through the normal order service so each one has real
 * history, stock effects and a customer email (to the sample customers' reserved example.test addresses). Local and
 * testing only; tracked in the shared demo-record table, so reruns only add orders for statuses created since.
 */
final class OrderDemoSeeder
{
    public function __construct(private OrderAdministration $orders) {}

    /** @return int Orders created by this run. */
    public function run(string $actor): int
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Order demo seeding is restricted to local and testing environments.');
        }
        $products = DB::table('products')->whereNull('deleted_at')->whereNotNull('price')->orderByDesc('quantity')->orderBy('id')->get(['id', 'quantity']);
        if ($products->isEmpty()) {
            throw new RuntimeException('Order demo seeding needs at least one priced product.');
        }
        $customers = DB::table('customers')->where('status', 'active')->orderBy('id')->get();
        $statuses = DB::table('order_statuses')->orderBy('position')->orderBy('id')->get();
        $initial = $statuses->firstWhere('is_initial', true) ?? throw new RuntimeException('No initial order status exists.');
        $decrease = $statuses->first(fn (object $s): bool => $s->stock_action === OrderStockRules::DECREASE && $s->is_active);
        $created = 0;
        foreach ($statuses->values() as $n => $status) {
            $key = 'orders.status.'.$status->code;
            if (DB::table('feature_demo_records')->where('key', $key)->exists()) {
                continue;
            }
            $customer = $customers->isEmpty() ? null : $customers[$n % $customers->count()];
            $address = $customer === null ? null : DB::table('customer_addresses')->where('id', $customer->default_billing_address_id ?? 0)->first();
            // Statuses that take stock use the best-stocked products; a sample never oversells.
            $product = $products[$n % $products->count()];
            if ($status->stock_action !== OrderStockRules::NONE) {
                $product = $products->first(fn (object $p): bool => (int) $p->quantity > 0) ?? $product;
            }
            $id = DB::transaction(function () use ($actor, $customer, $address, $product, $initial, $status, $decrease): string {
                $id = $this->orders->create($actor, [
                    'customer_id' => $customer?->id, 'locale' => app(LocaleCatalog::class)->defaultCode(),
                    'first_name' => $customer->first_name ?? 'Sample', 'last_name' => $customer->last_name ?? 'Guest', 'email' => $customer->email ?? 'guest.sample@example.test', 'phone' => $customer->phone ?? null,
                    'billing' => $address === null ? ['first_name' => $customer->first_name ?? 'Sample', 'last_name' => $customer->last_name ?? 'Guest', 'line1' => 'Ermou 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR'] : ['line1' => trim($address->line1.' '.($address->street_number ?? ''))] + (array) $address,
                    'shipping_same' => true, 'items' => [['product_id' => (string) $product->id, 'quantity' => 1]], 'status_id' => (string) $initial->id,
                    'customer_note' => 'Sample order (demo data).', 'internal_note' => 'Created by cms:orders-demo.',
                ], 'system');
                // Restoring statuses are reached the realistic way: first taking stock, then giving it back.
                $path = $status->stock_action === OrderStockRules::RESTORE && $decrease !== null && $status->id !== $decrease->id ? [$decrease->id, $status->id] : [$status->id];
                foreach ($path as $target) {
                    $version = (int) DB::table('orders')->where('id', $id)->value('lock_version');
                    $this->orders->changeStatus($actor, $id, $version, (string) $target, 'Sample transition.', 'system');
                }
                DB::table('feature_demo_records')->insert(['key' => 'orders.status.'.$status->code, 'entity' => 'orders', 'entity_id' => $id]);

                return $id;
            });
            $created += $id !== '' ? 1 : 0;
        }

        return $created;
    }
}
