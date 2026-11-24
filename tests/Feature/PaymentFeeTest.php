<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Order\Infrastructure\OrderAdministration;
use App\Order\Infrastructure\OrderPricing;
use App\Payment\Infrastructure\PaymentSettings;
use App\Shared\Domain\Service\Decimal;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

/**
 * A payment method's fee (e.g. cash on delivery) is an incidental expense of the goods (Greek VAT Code, Law 5144/2024
 * art. 24 par. 4(a)): it follows the VAT of the order's products, split by value when their rates differ.
 */
final class PaymentFeeTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private CategoryRecord $category;

    private string $area;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        $this->category = CategoryRecord::create(['parent_id' => null, 'position' => 1, 'status' => 'active']);
        $this->area = app(RateAreaAdministration::class)->create((string) $this->actor->id, new SaveRateAreaData(['en' => 'Greece'], 'active', false, ['GR']));
        app(PaymentSettings::class)->ensure();
        DB::table('payment_methods')->whereIn('code', ['cod', 'bank_deposit'])->update(['is_active' => true]);
    }

    private function tax(string $rate, bool $included = true): string
    {
        return app(TaxAdministration::class)->create((string) $this->actor->id, new SaveTaxData(['en' => 'VAT '.$rate], null, 0, 'active', $included, 'billing', [new SaveTaxRateData($this->area, 'percent', $rate)]));
    }

    private function product(string $price, ?string $tax = null): string
    {
        $p = ProductRecord::create(['sku' => 'FEE-'.uniqid(), 'price' => $price, 'quantity' => 100, 'category_id' => $this->category->id, 'status' => 'active']);
        $p->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => 'Item '.$p->id, 'slug' => 'item-'.$p->id]);
        if ($tax !== null) {
            DB::table('product_taxes')->insert(['product_id' => $p->id, 'tax_id' => $tax]);
        }

        return (string) $p->id;
    }

    /** @param list<string> $products
     * @return array<string, mixed> */
    private function quote(array $products, ?string $fee, bool $exempt = false): array
    {
        $billing = ['country_code' => 'GR'];

        return app(OrderPricing::class)->quote(array_map(fn (string $p): array => ['product_id' => $p, 'quantity' => 1], $products), [], ['billing' => $billing, 'shipping' => null], $exempt, 'en', 2, paymentFee: $fee);
    }

    /** @param array<string, mixed> $quote */
    private function taxAmount(array $quote, string $tax): string
    {
        return (string) (collect($quote['taxes'])->firstWhere('tax_id', $tax)['amount'] ?? '0');
    }

    public function test_the_fee_follows_the_vat_of_the_products_and_is_split_by_value_across_rates(): void
    {
        $standard = $this->tax('24');
        $reduced = $this->tax('13');
        $a = $this->product('100', $standard);
        $b = $this->product('100', $reduced);

        $without = $this->quote([$a, $b], null);
        $with = $this->quote([$a, $b], '2');
        self::assertSame('2.00', $with['payment_fee']);
        self::assertSame(Decimal::add($without['total'], '2.00'), $with['total']);
        // Prices include VAT (as in Greece): half of the fee follows each rate, its VAT inside the €2.
        self::assertSame('0.19', Decimal::subtract($this->taxAmount($with, $standard), $this->taxAmount($without, $standard)));
        self::assertSame('0.12', Decimal::subtract($this->taxAmount($with, $reduced), $this->taxAmount($without, $reduced)));
        self::assertSame(['included' => '0.31', 'added' => '0.00'], $with['payment_fee_tax']);
        self::assertSame(Decimal::add($without['tax_included'], '0.31'), $with['tax_included']);

        // Unequal values split unequally: 300 at 24% and 100 at 13% carry 1.50 and 0.50 of the fee.
        $c = $this->product('300', $standard);
        $split = $this->quote([$c, $b], '2');
        $base = $this->quote([$c, $b], null);
        self::assertSame('0.29', Decimal::subtract($this->taxAmount($split, $standard), $this->taxAmount($base, $standard)));
        self::assertSame('0.06', Decimal::subtract($this->taxAmount($split, $reduced), $this->taxAmount($base, $reduced)));
    }

    public function test_added_vat_untaxed_goods_and_exempt_customers(): void
    {
        $added = $this->tax('24', included: false);
        $taxed = $this->product('100', $added);
        // A tax added to prices is added to the fee too.
        $quote = $this->quote([$taxed], '2');
        self::assertSame(['included' => '0.00', 'added' => '0.48'], $quote['payment_fee_tax']);
        self::assertSame(Decimal::add($this->quote([$taxed], null)['total'], '2.48'), $quote['total']);
        // The share falling on untaxed goods carries no VAT.
        $plain = $this->product('100');
        self::assertSame(['included' => '0.00', 'added' => '0.24'], $this->quote([$taxed, $plain], '2')['payment_fee_tax']);
        self::assertSame(['included' => '0.00', 'added' => '0.00'], $this->quote([$plain], '2')['payment_fee_tax']);
        // An exempt customer pays the fee without VAT; no fee means nothing changes.
        self::assertSame(['included' => '0.00', 'added' => '0.00'], $this->quote([$taxed], '2', exempt: true)['payment_fee_tax']);
        self::assertSame('0.00', $this->quote([$taxed], null)['payment_fee']);
    }

    public function test_orders_are_charged_the_methods_fee_and_keep_it(): void
    {
        $vat = $this->tax('24');
        $product = $this->product('50', $vat);
        $cod = (string) DB::table('payment_methods')->where('code', 'cod')->value('id');
        $bank = (string) DB::table('payment_methods')->where('code', 'bank_deposit')->value('id');
        DB::table('payment_methods')->where('id', $cod)->update(['fee' => '2']);
        $payload = ['locale' => 'en', 'first_name' => 'Maria', 'last_name' => 'Example', 'email' => 'maria@example.test', 'billing' => ['first_name' => 'Maria', 'last_name' => 'Example', 'line1' => 'Ermou 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR'],
            'shipping_same' => true, 'items' => [['product_id' => $product, 'quantity' => 1]], 'status_id' => DB::table('order_statuses')->where('code', 'open')->value('id'), 'payment_method_id' => $cod];
        $orders = app(OrderAdministration::class);
        $id = $orders->create((string) $this->actor->id, $payload);
        $order = DB::table('orders')->where('id', $id)->first();
        self::assertSame(['2.000000', '52.000000'], [$order->payment_fee, $order->total]);
        // The fee's VAT is part of the order's VAT: 9.68 on the product and 0.39 on the fee, both inside the prices.
        self::assertSame('10.07', Decimal::round((string) DB::table('order_taxes')->where('order_id', $id)->value('amount'), 2));

        // A later change of the method's fee never re-prices the order; editing its lines keeps the fee it was charged.
        DB::table('payment_methods')->where('id', $cod)->update(['fee' => '5']);
        $edit = fn (array $changes) => $orders->update((string) $this->actor->id, $id, [...$payload, 'version' => (int) DB::table('orders')->where('id', $id)->value('lock_version'),
            'items' => [['item_id' => (string) DB::table('order_items')->where('order_id', $id)->value('id'), 'quantity' => 2]], ...$changes]);
        $edit([]);
        self::assertSame(['2.000000', '102.000000'], array_values((array) DB::table('orders')->where('id', $id)->first(['payment_fee', 'total'])));
        // Another method brings its own fee (none for the bank deposit).
        $edit(['payment_method_id' => $bank]);
        self::assertSame(['0.000000', '100.000000'], array_values((array) DB::table('orders')->where('id', $id)->first(['payment_fee', 'total'])));
    }

    public function test_staff_set_an_optional_fee_on_a_payment_method(): void
    {
        $save = function (array $changes) {
            $version = (int) DB::table('payment_methods')->where('code', 'cod')->value('lock_version');

            return $this->put('/admin/payment-methods/cod', [
                'version' => $version, 'is_active' => true, 'paid_status_id' => '',
                'translations' => ['en' => ['name' => 'Cash on delivery', 'description' => ''], 'el' => ['name' => 'Αντικαταβολή', 'description' => '']], ...$changes]);
        };
        $save(['fee' => '2.50'])->assertSessionHasNoErrors();
        self::assertSame('2.500000', DB::table('payment_methods')->where('code', 'cod')->value('fee'));
        $this->get('/admin/payment-methods/cod/edit')->assertInertia(fn ($page) => $page->where('method.fee', '2.5')->where('method.fee_currency', 'EUR'));
        foreach (['-1', '1.234', 'abc'] as $invalid) {
            $save(['fee' => $invalid])->assertSessionHasErrors('fee');
        }
        // Leaving it out keeps it; empty (or zero) removes it.
        $save([])->assertSessionHasNoErrors();
        self::assertSame('2.500000', DB::table('payment_methods')->where('code', 'cod')->value('fee'));
        $save(['fee' => ''])->assertSessionHasNoErrors();
        self::assertNull(DB::table('payment_methods')->where('code', 'cod')->value('fee'));
    }
}
