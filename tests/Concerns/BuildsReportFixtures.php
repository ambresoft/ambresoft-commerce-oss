<?php

namespace Tests\Concerns;

use App\Payment\Infrastructure\PaymentSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A small store written straight into the tables (so every amount is exactly what the test says), in the Europe/Athens
 * timezone with "now" fixed at 2026-10-17 12:00. See ReportsTest for the hand-calculated expectations.
 */
trait BuildsReportFixtures
{
    /** @var array<string, int> */
    private array $ids = [];

    private int $orderSequence = 100000;

    /** @var array<string, string> */
    private array $skus = ['A' => 'PH-1', 'B' => 'LP-1', 'C' => 'BK-1'];

    private function fixtureStore(): void
    {
        DB::table('general_settings')->where('id', 1)->update(['timezone' => 'Europe/Athens', 'low_stock_threshold' => 3]);
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-10-17 12:00:00', 'Europe/Athens'));
        app(PaymentSettings::class)->ensure();
        $this->ids['cod'] = (int) DB::table('payment_methods')->where('code', 'cod')->value('id');
        $this->ids['bank'] = (int) DB::table('payment_methods')->where('code', 'bank_deposit')->value('id');
        $this->ids['courier'] = (int) DB::table('shipping_methods')->insertGetId(['calculation' => 'manual', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->ids['processing'] = (int) DB::table('order_statuses')->where('code', 'processing')->value('id');
        $this->ids['completed'] = (int) DB::table('order_statuses')->where('code', 'completed')->value('id');
        $this->ids['cancelled'] = (int) DB::table('order_statuses')->where('code', 'cancelled')->value('id');
        $this->ids['en'] = (int) DB::table('locales')->where('code', 'en')->value('id');

        // Categories: Electronics ▸ (Phones, Laptops), Books.
        $category = fn (string $name, ?int $parent): int => tap((int) DB::table('categories')->insertGetId(['parent_id' => $parent, 'position' => (int) DB::table('categories')->where('parent_id', $parent)->max('position') + 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]), fn (int $id) => DB::table('category_translations')->insert(['category_id' => $id, 'locale_id' => $this->ids['en'], 'name' => $name, 'slug' => strtolower($name)]));
        $this->ids['electronics'] = $category('Electronics', null);
        $this->ids['phones'] = $category('Phones', $this->ids['electronics']);
        $this->ids['laptops'] = $category('Laptops', $this->ids['electronics']);
        $this->ids['books'] = $category('Books', null);

        // Products: A phone (5 in stock), B laptop (0), C book (2), D never sold (10), E trashed, F digital.
        $this->product('A', 'PH-1', 'phones', 5);
        $this->product('B', 'LP-1', 'laptops', 0);
        $this->product('C', 'BK-1', 'books', 2);
        $this->product('D', 'NS-1', 'books', 10);
        $this->product('E', 'TR-1', 'books', 0, deleted: true);
        $this->product('F', 'DG-1', 'books', 0, type: 'digital');

        // Customers: c1 (bought in September and October), c2 (new in October), c3 (new, never bought).
        foreach (['c1' => '2026-09-01', 'c2' => '2026-10-03', 'c3' => '2026-10-04'] as $key => $day) {
            $this->ids[$key] = (int) DB::table('customers')->insertGetId(['email' => $key.'@example.test', 'first_name' => strtoupper($key), 'last_name' => 'Customer', 'created_at' => $day.' 10:00:00', 'updated_at' => $day.' 10:00:00']);
        }
        $this->ids['coupon'] = (int) DB::table('coupons')->insertGetId(['name' => 'Save ten', 'code' => 'SAVE10', 'type' => 'fixed', 'amount' => 10, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function product(string $key, string $sku, string $category, int $quantity, bool $deleted = false, string $type = 'physical'): void
    {
        $id = (int) DB::table('products')->insertGetId(['type' => $type, 'sku' => $sku, 'status' => 'active', 'price' => 50, 'category_id' => $this->ids[$category], 'quantity' => $quantity, 'out_of_stock_actions' => 'N', 'created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-01 10:00:00', 'deleted_at' => $deleted ? '2026-09-01 10:00:00' : null]);
        DB::table('product_translations')->insert(['product_id' => $id, 'locale_id' => $this->ids['en'], 'name' => 'Product '.$key, 'slug' => 'product-'.strtolower($key)]);
        $this->ids[$key] = $id;
    }

    /**
     * Writes an order, its lines and payments. Amounts are decimals; lines are [product key|null, name, quantity, unit price, discount].
     * Payments are [amount, local time] captured manual payments unless a state is given.
     *
     * @param  array<string, mixed>  $o
     * @param  list<array{0: ?string, 1: string, 2: int, 3: string, 4?: string}>  $lines
     * @param  list<array{0: string, 1: string, 2?: string}>  $payments
     */
    private function sale(string $created, array $lines, array $payments = [], array $o = []): int
    {
        $subtotal = '0';
        $discount = '0';
        foreach ($lines as $l) {
            $subtotal = bcadd($subtotal, bcmul((string) $l[2], $l[3], 6), 6);
            $discount = bcadd($discount, $l[4] ?? '0', 6);
        }
        $taxTotal = $o['tax_total'] ?? '0';
        $taxIncluded = $o['tax_included'] ?? '0';
        $shipping = $o['shipping'] ?? '0';
        $total = bcadd(bcadd(bcsub($subtotal, $discount, 6), bcsub($taxTotal, $taxIncluded, 6), 6), $shipping, 6);
        $method = $o['payment'] ?? null;
        $id = (int) DB::table('orders')->insertGetId([
            'order_number' => 'T'.(++$this->orderSequence), 'customer_id' => isset($o['customer']) ? $this->ids[$o['customer']] : null, 'status_id' => $this->ids[$o['status'] ?? 'processing'], 'locale_id' => $this->ids['en'],
            'first_name' => $o['first'] ?? 'Test', 'last_name' => $o['last'] ?? 'Buyer', 'email' => $o['email'] ?? 'buyer'.$this->orderSequence.'@example.test', 'currency_code' => $o['currency'] ?? 'EUR', 'currency_decimals' => 2,
            'subtotal' => $subtotal, 'discount_total' => $discount, 'tax_total' => $taxTotal, 'tax_included' => $taxIncluded, 'shipping_total' => $shipping, 'total' => $total, 'tax_method' => 'unit_price',
            'payment_method_id' => $method === null ? null : $this->ids[$method], 'payment_method_name' => $method === null ? null : ($method === 'cod' ? 'Cash on delivery' : 'Bank deposit'),
            'shipping_method_id' => isset($o['shipping_method']) ? $this->ids[$o['shipping_method']] : null, 'shipping_method_name' => isset($o['shipping_method']) ? 'Courier' : null,
            'coupon_id' => isset($o['coupon']) ? $this->ids['coupon'] : null, 'coupon_code' => isset($o['coupon']) ? 'SAVE10' : null,
            'created_at' => $this->at($created), 'updated_at' => $this->at($created),
        ]);
        foreach ($lines as $i => $l) {
            $line = bcmul((string) $l[2], $l[3], 6);
            DB::table('order_items')->insert(['order_id' => $id, 'product_id' => $l[0] === null ? null : $this->ids[$l[0]], 'position' => $i + 1, 'name' => $l[1], 'sku' => $l[0] === null ? null : $this->skus[$l[0]], 'quantity' => $l[2], 'catalog_price' => $l[3], 'unit_price' => $l[3], 'subtotal' => $line, 'discount_total' => $l[4] ?? '0', 'total' => bcsub($line, $l[4] ?? '0', 6)]);
        }
        foreach ($payments as $p) {
            DB::table('order_payments')->insert(['reference' => (string) Str::uuid(), 'order_id' => $id, 'payment_method_id' => $method === null ? null : $this->ids[$method], 'kind' => 'manual', 'state' => 'captured', 'amount' => $p[0], 'currency_code' => $o['currency'] ?? 'EUR', 'completed_at' => $this->at($p[1]), 'created_at' => $this->at($p[1]), 'updated_at' => $this->at($p[1])]);
        }

        return $id;
    }

    /** A local (Europe/Athens) wall-clock time as the UTC instant stored in the database. */
    private function at(string $local): string
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('Europe/Athens')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s+00');
    }

    /**
     * Brands as their lines were sold: phones (A) under Acme, the laptop (B) under Zeta, books (C) without a brand.
     * Product A has since moved to Zeta, which must not rewrite its past sales.
     */
    private function fixtureBrands(): void
    {
        foreach (['acme' => 'Acme', 'zeta' => 'Zeta'] as $code => $name) {
            $this->ids[$code] = (int) DB::table('brands')->insertGetId(['code' => $code, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('brand_translations')->insert(['brand_id' => $this->ids[$code], 'locale_id' => $this->ids['en'], 'name' => $name]);
        }
        DB::table('order_items')->where('product_id', $this->ids['A'])->update(['brand_id' => $this->ids['acme'], 'brand_name' => 'Acme']);
        DB::table('order_items')->where('product_id', $this->ids['B'])->update(['brand_id' => $this->ids['zeta'], 'brand_name' => 'Zeta']);
        DB::table('products')->where('id', $this->ids['A'])->update(['brand_id' => $this->ids['zeta']]);
    }

    /** The orders described in ReportsTest, in this order: O1 … O10. */
    private function fixtureOrders(): void
    {
        // O1: paid 5 Oct by c1, bank deposit + courier, coupon, discount 30, shipping 5, tax 24 (10 inside prices): total 289.
        $this->ids['O1'] = $this->sale('2026-10-05 10:00', [['A', 'Phone A', 2, '50', '10'], ['B', 'Laptop B', 1, '200', '20']], [['289', '2026-10-05 10:05']], ['customer' => 'c1', 'status' => 'completed', 'payment' => 'bank', 'shipping_method' => 'courier', 'coupon' => true, 'shipping' => '5', 'tax_total' => '24', 'tax_included' => '10']);
        // O2: paid 16 Oct 23:30 (still "yesterday" in Athens) by c2, cash on delivery.
        $this->ids['O2'] = $this->sale('2026-10-16 23:00', [['A', 'Phone A', 1, '50']], [['50', '2026-10-16 23:30']], ['customer' => 'c2', 'payment' => 'cod']);
        // O3: placed 10 Oct, cash on delivery not yet collected: not a sale, 35 outstanding.
        $this->ids['O3'] = $this->sale('2026-10-10 09:00', [['C', 'Book C', 1, '30']], [], ['payment' => 'cod', 'shipping' => '5']);
        // O4: paid 11 Oct then cancelled: money collected, no sale.
        $this->ids['O4'] = $this->sale('2026-10-11 09:00', [['B', 'Laptop B', 1, '200']], [['200', '2026-10-11 09:30']], ['status' => 'cancelled', 'payment' => 'bank']);
        // O5: 40 of 100 captured: partially paid, not a sale, 60 outstanding.
        $this->ids['O5'] = $this->sale('2026-10-12 09:00', [['A', 'Phone A', 2, '50']], [['40', '2026-10-12 09:10']], ['payment' => 'cod']);
        // O6: paid in two instalments (30 on 12 Oct, 50 on 14 Oct) plus an extra duplicate payment on 15 Oct: paid on the 14th.
        $this->ids['O6'] = $this->sale('2026-10-12 08:00', [['C', 'Book C', 2, '40']], [['30', '2026-10-12 08:30'], ['50', '2026-10-14 08:30'], ['80', '2026-10-15 08:30']], ['customer' => 'c1', 'payment' => 'cod']);
        // O7: a USD order, never mixed into the euro figures.
        $this->ids['O7'] = $this->sale('2026-10-06 09:00', [['A', 'Phone A', 1, '60']], [['60', '2026-10-06 09:05']], ['currency' => 'USD', 'payment' => 'bank']);
        // O8: the previous period (10 September): four phones by c1.
        $this->ids['O8'] = $this->sale('2026-09-10 09:00', [['A', 'Phone A', 4, '50']], [['200', '2026-09-10 09:05']], ['customer' => 'c1', 'payment' => 'bank']);
        // O9: a guest buys a book on 7 October.
        $this->ids['O9'] = $this->sale('2026-10-07 09:00', [['C', 'Book C', 1, '30']], [['30', '2026-10-07 09:05']], ['email' => 'guest@example.test', 'first' => 'Guest', 'last' => 'Buyer', 'payment' => 'cod']);
        // O10: paid exactly at local midnight, the first instant of 17 October.
        $this->ids['O10'] = $this->sale('2026-10-16 23:50', [['A', 'Phone A', 1, '50']], [['50', '2026-10-17 00:00']], ['customer' => 'c2', 'payment' => 'cod']);
        // Coupon usage: O1 redeemed it; a redemption on O3 was released.
        DB::table('coupon_redemptions')->insert([
            ['coupon_id' => $this->ids['coupon'], 'order_id' => $this->ids['O1'], 'customer_key' => 'c1', 'code' => 'SAVE10', 'discount' => '30', 'shipping_discount' => '0', 'currency_code' => 'EUR', 'currency_decimals' => 2, 'created_at' => $this->at('2026-10-05 10:00'), 'released_at' => null],
            ['coupon_id' => $this->ids['coupon'], 'order_id' => $this->ids['O3'], 'customer_key' => 'x', 'code' => 'SAVE10', 'discount' => '5', 'shipping_discount' => '0', 'currency_code' => 'EUR', 'currency_decimals' => 2, 'created_at' => $this->at('2026-10-10 09:00'), 'released_at' => $this->at('2026-10-10 10:00')],
        ]);
        // A failed online attempt on O3.
        DB::table('order_payments')->insert(['reference' => (string) Str::uuid(), 'order_id' => $this->ids['O3'], 'payment_method_id' => $this->ids['cod'], 'kind' => 'online', 'state' => 'failed', 'amount' => '35', 'currency_code' => 'EUR', 'completed_at' => $this->at('2026-10-10 09:20'), 'created_at' => $this->at('2026-10-10 09:15'), 'updated_at' => $this->at('2026-10-10 09:20')]);
    }
}
