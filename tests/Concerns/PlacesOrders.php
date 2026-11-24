<?php

namespace Tests\Concerns;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use Illuminate\Support\Facades\DB;

/** Minimal untaxed orders for payment tests, created through the real order write path. */
trait PlacesOrders
{
    private function orderProduct(string $price, int $quantity = 10): ProductRecord
    {
        $category = CategoryRecord::create(['parent_id' => null, 'position' => 1, 'status' => 'active']);
        $product = ProductRecord::create(['sku' => 'PAY-'.uniqid(), 'price' => $price, 'quantity' => $quantity, 'out_of_stock_actions' => 'N', 'category_id' => $category->id, 'status' => 'active']);
        $product->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => 'Lamp', 'slug' => 'lamp-'.$product->id]);

        return $product;
    }

    private function orderStatus(string $code): string
    {
        return (string) DB::table('order_statuses')->where('code', $code)->value('id');
    }

    private function methodId(string $code): string
    {
        return (string) DB::table('payment_methods')->where('code', $code)->value('id');
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function orderInput(string $productId, array $changes = []): array
    {
        return array_replace([
            'locale' => 'en', 'first_name' => 'Maria', 'last_name' => 'Example', 'email' => 'maria@example.test',
            'billing' => ['first_name' => 'Maria', 'last_name' => 'Example', 'line1' => 'Ermou 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR'],
            'shipping_same' => true, 'items' => [['product_id' => $productId, 'quantity' => 1]], 'status_id' => $this->orderStatus('open'),
        ], $changes);
    }
}
