<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Order\Application\Contract\OrderShipping;
use App\Payment\Infrastructure\PaymentSettings;
use App\Pricing\Application\Command\SaveCurrencyData;
use App\Pricing\Application\Contract\CurrencyAdministration;
use App\Pricing\Infrastructure\Persistence\CurrencyRecord;
use App\Shared\Domain\Service\Decimal;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

/** Shipping methods: administration, manual rates by area and weight, conditions, and the charge stored on orders. */
final class ShippingMethodsTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private string $greece;

    private string $world;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        $areas = app(RateAreaAdministration::class);
        $this->greece = $areas->create((string) $this->actor->id, new SaveRateAreaData(['en' => 'Greece'], 'active', false, ['GR'], []));
        $this->world = $areas->create((string) $this->actor->id, new SaveRateAreaData(['en' => 'World'], 'active', true, [], []));
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function input(array $changes = []): array
    {
        return array_replace([
            'translations' => ['en' => ['name' => 'Courier', 'description' => 'Door to door', 'delivery_time' => '1–2 days']],
            'calculation' => 'manual', 'is_active' => true, 'free_shipping_applies' => true,
            'rates' => [
                ['rate_area_id' => $this->greece, 'weight_from' => '0', 'weight_to' => '5', 'base' => '3.50', 'per_unit' => '1.20'],
                ['rate_area_id' => $this->greece, 'weight_from' => '5', 'weight_to' => '', 'base' => '10', 'per_unit' => '0.50'],
                ['rate_area_id' => $this->world, 'weight_from' => '0', 'weight_to' => '', 'base' => '20', 'per_unit' => '0'],
            ],
        ], $changes);
    }

    /** @param array<string, mixed> $changes */
    private function method(array $changes = []): string
    {
        $this->post('/admin/shipping-methods', $this->input($changes))->assertSessionHasNoErrors();

        return (string) DB::table('shipping_methods')->orderByDesc('id')->value('id');
    }

    private function version(string $id): int
    {
        return (int) DB::table('shipping_methods')->where('id', $id)->value('lock_version');
    }

    private function product(string $weight, bool $free = false, ?string $freight = null): string
    {
        $product = $this->orderProduct('10.00');
        $product->update(['weight' => $weight, 'free_shipping' => $free, 'shipping_cost' => $freight]);

        return (string) $product->id;
    }

    /**
     * Asks the order port for the charge, like the order editor and the save path do.
     *
     * @param  list<array{0: string, 1: int}>  $lines  product ID and quantity
     * @return array<string, mixed>
     */
    private function quote(string $method, array $lines, string $country = 'GR', ?string $customer = null, ?string $payment = null): array
    {
        return app(OrderShipping::class)->quote($method, [
            'lines' => array_map(fn (array $l): array => ['product_id' => $l[0], 'quantity' => $l[1]], $lines),
            'address' => ['country_code' => $country, 'city' => 'Athens', 'postal_code' => '10563', 'line1' => 'Ermou 1', 'region_code' => null],
            'currency' => 'EUR', 'decimals' => 2, 'customer_id' => $customer, 'payment_method_id' => $payment, 'subtotal' => '10',
        ], 'en');
    }

    /** @param array<string, mixed> $changes */
    private function placeOrder(string $product, array $changes = []): TestResponse
    {
        return $this->post('/admin/orders', $this->orderInput($product, $changes));
    }

    /** A real 1×1 PNG (the test image needs no GD). */
    private function png(string $name = 'image.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII='));
    }

    public function test_methods_are_listed_enabled_reordered_and_only_unused_ones_deleted(): void
    {
        // A manual method cannot be enabled without rates; brackets of one area may not overlap.
        $this->post('/admin/shipping-methods', $this->input(['rates' => []]))->assertSessionHasErrors('is_active');
        $this->post('/admin/shipping-methods', $this->input(['rates' => [
            ['rate_area_id' => $this->greece, 'weight_from' => '0', 'weight_to' => '', 'base' => '1', 'per_unit' => '0'],
            ['rate_area_id' => $this->greece, 'weight_from' => '2', 'weight_to' => '4', 'base' => '1', 'per_unit' => '0'],
        ]]))->assertSessionHasErrors('rates.1.weight_from');
        $this->post('/admin/shipping-methods', $this->input(['rates' => [['rate_area_id' => $this->greece, 'weight_from' => '3', 'weight_to' => '1', 'base' => '1.005', 'per_unit' => '0']]]))
            ->assertSessionHasErrors(['rates.0.weight_to', 'rates.0.base']);
        $this->post('/admin/shipping-methods', $this->input(['translations' => ['en' => ['name' => '']]]))->assertSessionHasErrors('translations.en.name');
        $this->post('/admin/shipping-methods', $this->input(['calculation' => 'carrier', 'rates' => []]))->assertSessionHasErrors('provider');
        $this->post('/admin/shipping-methods', $this->input(['min_weight' => '5', 'max_weight' => '2']))->assertSessionHasErrors('max_weight');
        self::assertSame(0, DB::table('shipping_methods')->count());

        $courier = $this->method();
        $pickup = $this->method(['translations' => ['en' => ['name' => 'Store pickup']], 'is_active' => false, 'rates' => [['rate_area_id' => $this->world, 'base' => '0']]]);
        self::assertTrue((bool) DB::table('shipping_methods')->where('id', $courier)->value('is_active'));
        self::assertSame(3, DB::table('shipping_method_rates')->where('method_id', $courier)->count());
        $this->get('/admin/shipping-methods?search=pick')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/ShippingMethods/Index')
            ->has('methods.data', 1)->where('methods.data.0.name', 'Store pickup')->where('methods.data.0.active', false));
        $this->get('/admin/shipping-methods?status=active')->assertInertia(fn (Assert $page) => $page->has('methods.data', 1)->where('methods.data.0.id', $courier));
        $this->get('/admin/shipping-methods/'.$courier.'/edit')->assertInertia(fn (Assert $page) => $page->component('Admin/ShippingMethods/Form')
            ->where('method.translations.en.delivery_time', '1–2 days')->has('method.rates', 3)->where('method.rates.0.base', '3.50'));

        // Enabling, reordering and stale versions.
        $this->patch('/admin/shipping-methods/'.$pickup.'/active', ['version' => $this->version($pickup) + 1, 'active' => true])->assertSessionHasErrors('version');
        $this->patch('/admin/shipping-methods/'.$pickup.'/active', ['version' => $this->version($pickup), 'active' => true])->assertSessionHasNoErrors();
        $this->post('/admin/shipping-methods/order', ['ids' => [$pickup, $courier]])->assertSessionHasNoErrors();
        self::assertSame([$pickup, $courier], DB::table('shipping_methods')->orderBy('position')->pluck('id')->map(strval(...))->all());
        $this->post('/admin/shipping-methods/order', ['ids' => [$pickup]])->assertSessionHasErrors('ids');

        // A method used by an order is kept (disable it instead); an unused one can be deleted.
        $this->placeOrder($this->product('1'), ['shipping_method_id' => $courier])->assertSessionHasNoErrors();
        $this->delete('/admin/shipping-methods/'.$courier, ['version' => $this->version($courier)])->assertSessionHasErrors('method');
        $this->delete('/admin/shipping-methods/'.$pickup, ['version' => $this->version($pickup)])->assertSessionHasNoErrors();
        self::assertSame([$courier], DB::table('shipping_methods')->pluck('id')->map(strval(...))->all());

        // Staff without the manage permission can read the list but not change it.
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'shipping-methods.view']));
        $this->get('/admin/shipping-methods')->assertOk();
        $this->get('/admin/shipping-methods/create')->assertForbidden();
        $this->post('/admin/shipping-methods', $this->input())->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff())->get('/admin/shipping-methods')->assertForbidden();
    }

    public function test_a_method_can_have_an_optional_image(): void
    {
        Storage::fake('local');
        $this->post('/admin/shipping-methods', [...$this->input(), 'image' => UploadedFile::fake()->createWithContent('logo.png', 'not an image')])->assertSessionHasErrors('image');
        self::assertSame([0, 0], [DB::table('shipping_methods')->count(), DB::table('media_files')->count()]);
        self::assertSame([], Storage::disk('local')->allFiles());

        // Created together with its image (multipart).
        $method = $this->method(['image' => $this->png('acs.png')]);
        $media = DB::table('shipping_methods')->where('id', $method)->value('image_media_id');
        self::assertNotNull($media);
        $this->get('/admin/shipping-methods/'.$method.'/edit')->assertInertia(fn (Assert $page) => $page->where('method.image.meta.width', 1));
        $this->get('/admin/shipping-methods')->assertInertia(fn (Assert $page) => $page->where('methods.data.0.image_url', '/admin/shipping-methods/'.$method.'/image?v='.$media));
        $this->get('/admin/shipping-methods/'.$method.'/image')->assertOk()->assertHeader('Content-Type', 'image/png');

        // Replaced through the method-spoofed multipart update; a plain save keeps it; removal clears it.
        $this->post('/admin/shipping-methods/'.$method, [...$this->input(), '_method' => 'put', 'version' => $this->version($method), 'image' => $this->png('new.png')])->assertSessionHasNoErrors();
        $replaced = DB::table('shipping_methods')->where('id', $method)->value('image_media_id');
        self::assertNotSame($media, $replaced);
        $this->put('/admin/shipping-methods/'.$method, [...$this->input(), 'version' => $this->version($method)])->assertSessionHasNoErrors();
        self::assertSame($replaced, DB::table('shipping_methods')->where('id', $method)->value('image_media_id'));
        $this->put('/admin/shipping-methods/'.$method, [...$this->input(), 'version' => $this->version($method), 'remove_image' => true])->assertSessionHasNoErrors();
        self::assertNull(DB::table('shipping_methods')->where('id', $method)->value('image_media_id'));
        $this->get('/admin/shipping-methods/'.$method.'/image')->assertNotFound();
    }

    public function test_manual_rates_use_the_most_specific_area_and_the_bracket_of_the_weight(): void
    {
        $method = $this->method();
        $light = $this->product('2.5');
        // Greece, 2.5 kg in [0, 5): 3.50 + 1.20 × 2.5.
        self::assertSame('6.5', Decimal::trim($this->quote($method, [[$light, 1]])['amount']));
        // Greece, 5 kg starts the next bracket: 10 + 0.50 × (5 − 5).
        self::assertSame('10', Decimal::trim($this->quote($method, [[$light, 2]])['amount']));
        // 12 kg: 10 + 0.50 × 7.
        self::assertSame('13.5', Decimal::trim($this->quote($method, [[$this->product('12'), 1]])['amount']));
        // Elsewhere the default area applies.
        $world = $this->quote($method, [[$light, 1]], 'FR');
        self::assertSame('20', Decimal::trim($world['amount']));
        self::assertSame('World', $world['details']['rate']['area_name']);

        // Without a matching area the method is not offered (and says why).
        $greekOnly = $this->method(['rates' => [['rate_area_id' => $this->greece, 'weight_from' => '0', 'weight_to' => '', 'base' => '4', 'per_unit' => '0']]]);
        $none = $this->quote($greekOnly, [[$light, 1]], 'FR');
        self::assertFalse($none['available']);
        self::assertSame('no_rate', $none['reason']);
        self::assertSame(__('shipping.reason_no_rate'), $none['message']);
    }

    public function test_free_shipping_products_and_per_product_shipping_costs(): void
    {
        $method = $this->method();
        $free = $this->product('1', true, '2.00');
        $paid = $this->product('1', false, '1.00');
        // The free product adds neither weight nor its own cost: 3.50 + 1.20 × 1 + 1.00.
        self::assertSame('5.7', Decimal::trim($this->quote($method, [[$free, 1], [$paid, 1]])['amount']));
        // An order of only free-shipping products ships free.
        $only = $this->quote($method, [[$free, 3]]);
        self::assertSame('0', Decimal::trim($only['amount']));
        self::assertTrue($only['details']['free_shipping_only']);

        // A method that ignores free shipping charges everything: 3.50 + 1.20 × 2 + 2.00 + 1.00.
        $all = $this->method(['free_shipping_applies' => false]);
        self::assertSame('8.9', Decimal::trim($this->quote($all, [[$free, 1], [$paid, 1]])['amount']));
    }

    public function test_weight_limits_customer_groups_and_payment_restrictions(): void
    {
        $product = $this->product('2');
        $limited = $this->method(['min_weight' => '1', 'max_weight' => '3']);
        self::assertSame(['1.000000', '3.000000'], [DB::table('shipping_methods')->where('id', $limited)->value('min_weight'), DB::table('shipping_methods')->where('id', $limited)->value('max_weight')]);
        self::assertTrue($this->quote($limited, [[$product, 1]])['available']);
        self::assertSame('weight_limit', $this->quote($limited, [[$product, 2]])['reason']);

        // Customer group restriction: guests and other customers do not get the method.
        $group = (string) DB::table('customer_groups')->insertGetId(['status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_group_translations')->insert(['group_id' => $group, 'locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => 'Wholesale']);
        $wholesale = $this->method(['customer_group_ids' => [$group]]);
        self::assertSame('customer_group', $this->quote($wholesale, [[$product, 1]])['reason']);

        // Payment restriction: the order cannot combine the method with another payment method.
        $this->get('/admin/payment-methods')->assertOk();
        DB::table('payment_methods')->update(['is_active' => true]);
        $cod = $this->methodId('cod');
        $codOnly = $this->method(['payment_method_ids' => [$cod]]);
        $this->placeOrder($product, ['shipping_method_id' => $codOnly, 'payment_method_id' => $this->methodId('bank_deposit')])->assertSessionHasErrors('payment_method_id');
        $this->placeOrder($product, ['shipping_method_id' => $codOnly, 'payment_method_id' => $cod])->assertSessionHasNoErrors();

        // Inactive methods are not offered for new orders.
        $this->patch('/admin/shipping-methods/'.$limited.'/active', ['version' => $this->version($limited), 'active' => false]);
        self::assertSame('inactive', $this->quote($limited, [[$product, 1]])['reason']);
        self::assertNotContains($limited, array_column(app(OrderShipping::class)->choices('en'), 'id'));
        $this->placeOrder($product, ['shipping_method_id' => $limited])->assertSessionHasErrors('shipping_method_id');
    }

    public function test_orders_store_the_charge_its_taxes_and_a_snapshot(): void
    {
        $vat = app(TaxAdministration::class)->create((string) $this->actor->id, new SaveTaxData(['en' => 'VAT'], null, 0, 'active', false, 'shipping', [new SaveTaxRateData($this->greece, 'percent', '24')]));
        $method = $this->method(['tax_ids' => [$vat]]);
        $product = $this->product('2.5');
        $this->placeOrder($product, ['shipping_method_id' => $method])->assertSessionHasNoErrors();
        $order = DB::table('orders')->orderByDesc('id')->first();
        // 3.50 + 1.20 × 2.5 = 6.50 shipping; 24% VAT on it (the product itself is untaxed).
        self::assertSame(['6.5', '1.56', '18.06'], [Decimal::trim($order->shipping_total), Decimal::trim($order->tax_total), Decimal::trim($order->total)]);
        self::assertSame('Courier', $order->shipping_method_name);
        self::assertSame('1.56', Decimal::trim((string) DB::table('order_taxes')->where('order_id', $order->id)->where('tax_id', $vat)->value('amount')));
        $details = json_decode((string) $order->shipping_details, true);
        self::assertSame(['manual', false, '2.5'], [$details['calculation'], $details['overridden'], $details['chargeable_weight']]);

        // The snapshot survives renaming the method; editing other order fields keeps the stored charge.
        $this->put('/admin/shipping-methods/'.$method, [...$this->input(['translations' => ['en' => ['name' => 'Renamed']], 'tax_ids' => [$vat]]), 'version' => $this->version($method)])->assertSessionHasNoErrors();
        $this->get('/admin/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('shipping.method.name', 'Courier')->where('shipping.charge', '€6.50'));
        $this->get('/admin/orders/'.$order->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('order.shipping_method_id', $method)->where('order.shipping_amount', ''));
    }

    public function test_staff_can_enter_the_charge_when_the_method_cannot_price_the_order(): void
    {
        $greekOnly = $this->method(['rates' => [['rate_area_id' => $this->greece, 'weight_from' => '0', 'weight_to' => '', 'base' => '4', 'per_unit' => '0']]]);
        $product = $this->product('1');
        $abroad = ['billing' => ['first_name' => 'Marie', 'last_name' => 'Example', 'line1' => 'Rue 1', 'city' => 'Paris', 'postal_code' => '75001', 'country_code' => 'FR']];
        $this->placeOrder($product, [...$abroad, 'shipping_method_id' => $greekOnly])->assertSessionHasErrors('shipping_method_id');
        $this->placeOrder($product, [...$abroad, 'shipping_amount' => '7'])->assertSessionHasErrors('shipping_method_id');
        $this->placeOrder($product, [...$abroad, 'shipping_method_id' => $greekOnly, 'shipping_amount' => '7.00'])->assertSessionHasNoErrors();
        $order = DB::table('orders')->orderByDesc('id')->first();
        self::assertSame('7', Decimal::trim($order->shipping_total));
        $details = json_decode((string) $order->shipping_details, true);
        self::assertTrue($details['overridden']);
        self::assertNull($details['calculated_amount']);
        self::assertSame('no_rate', $details['unavailable_reason']);
        $this->get('/admin/orders/'.$order->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('order.shipping_amount', '7.00'));
    }

    public function test_areas_used_by_shipping_methods_cannot_be_deleted_and_currency_changes_convert_rates(): void
    {
        $this->method(['rates' => [['rate_area_id' => $this->greece, 'weight_from' => '0', 'weight_to' => '', 'base' => '4.10', 'per_unit' => '1'], ['rate_area_id' => $this->world, 'base' => '0.05']]]);
        $version = (int) DB::table('rate_areas')->where('id', $this->greece)->value('lock_version');
        try {
            app(RateAreaAdministration::class)->delete((string) $this->actor->id, $this->greece, $version);
            self::fail('The area is used by a shipping method.');
        } catch (ValidationException $e) {
            self::assertSame(__('shipping.area_in_use', ['count' => 1]), $e->errors()['rate_area'][0]);
        }

        // Reducing the primary currency precision is refused while rates would be rounded.
        $currencies = app(CurrencyAdministration::class);
        $euro = CurrencyRecord::where('is_primary', true)->sole();
        try {
            $currencies->update((string) $this->actor->id, (string) $euro->id, $euro->lock_version, new SaveCurrencyData('EUR', 'Euro', '€', 'before', '.', ',', 1, '1', 'active'));
            self::fail('Rates would be rounded.');
        } catch (ValidationException $e) {
            self::assertSame(__('shipping.precision_in_use', ['decimals' => 1]), $e->errors()['decimals'][0]);
        }

        // A new primary currency re-denominates rates and surcharges like product prices; open editors become stale.
        $usd = CurrencyRecord::findOrFail($currencies->create((string) $this->actor->id, new SaveCurrencyData('USD', 'Dollar', '$', 'before', ',', '.', 2, '1.1', 'active')));
        $method = (string) DB::table('shipping_methods')->value('id');
        $version = $this->version($method);
        $currencies->makePrimary((string) $this->actor->id, (string) $usd->id, $usd->lock_version, 'USD');
        $rate = DB::table('shipping_method_rates')->where('method_id', $method)->where('rate_area_id', $this->greece)->first();
        self::assertSame(['4.51', '1.1'], [Decimal::trim($rate->base), Decimal::trim($rate->per_unit)]);
        self::assertSame($version + 1, $this->version($method));
    }

    public function test_each_method_saves_and_loads_its_own_optional_shipment_value_limit(): void
    {
        // Existing methods keep working with no limit.
        $plain = $this->method();
        self::assertSame([false, null], [(bool) DB::table('shipping_methods')->where('id', $plain)->value('value_limit_enabled'), DB::table('shipping_methods')->where('id', $plain)->value('max_shipment_value')]);
        $this->get('/admin/shipping-methods/'.$plain.'/edit')->assertInertia(fn (Assert $p) => $p->where('method.value_limit_enabled', false)->where('method.max_shipment_value', ''));

        // Different methods, different limits; zero is a real limit.
        $courier = $this->method(['value_limit_enabled' => true, 'max_shipment_value' => '500']);
        $local = $this->method(['translations' => ['en' => ['name' => 'Local', 'description' => '', 'delivery_time' => '']], 'value_limit_enabled' => true, 'max_shipment_value' => '0']);
        $this->get('/admin/shipping-methods/'.$courier.'/edit')->assertInertia(fn (Assert $p) => $p->where('method.value_limit_enabled', true)->where('method.max_shipment_value', '500.00'));
        $this->get('/admin/shipping-methods/'.$local.'/edit')->assertInertia(fn (Assert $p) => $p->where('method.max_shipment_value', '0.00'));

        // Switching it off keeps the amount; a save without the fields keeps both.
        $this->put('/admin/shipping-methods/'.$courier, $this->input(['version' => $this->version($courier), 'value_limit_enabled' => false, 'max_shipment_value' => '500']))->assertSessionHasNoErrors();
        $this->put('/admin/shipping-methods/'.$courier, $this->input(['version' => $this->version($courier)]))->assertSessionHasNoErrors();
        self::assertSame([false, '500.000000'], [(bool) DB::table('shipping_methods')->where('id', $courier)->value('value_limit_enabled'), DB::table('shipping_methods')->where('id', $courier)->value('max_shipment_value')]);

        // Negative, malformed or too precise amounts are refused; the database agrees.
        foreach (['-1', 'abc', '1.234', '1e3'] as $bad) {
            $this->put('/admin/shipping-methods/'.$courier, $this->input(['version' => $this->version($courier), 'value_limit_enabled' => true, 'max_shipment_value' => $bad]))->assertSessionHasErrors('max_shipment_value');
        }
        $this->expectException(QueryException::class);
        DB::table('shipping_methods')->where('id', $courier)->update(['max_shipment_value' => '-1']);
    }

    public function test_a_new_primary_currency_converts_shipment_value_and_cash_on_delivery_limits(): void
    {
        $courier = $this->method(['value_limit_enabled' => true, 'max_shipment_value' => '500']);
        $plain = $this->method();
        app(PaymentSettings::class)->ensure();
        $cod = (string) DB::table('payment_methods')->where('code', 'cod')->value('id');
        DB::table('payment_methods')->where('id', $cod)->update(['max_subtotal' => '500']);
        $currencies = app(CurrencyAdministration::class);
        $usd = CurrencyRecord::findOrFail($currencies->create((string) $this->actor->id, new SaveCurrencyData('USD', 'Dollar', '$', 'before', ',', '.', 2, '1.1', 'active')));
        $currencies->makePrimary((string) $this->actor->id, (string) $usd->id, $usd->lock_version, 'USD');
        self::assertSame('550', Decimal::trim(DB::table('shipping_methods')->where('id', $courier)->value('max_shipment_value')));
        self::assertNull(DB::table('shipping_methods')->where('id', $plain)->value('max_shipment_value'));
        self::assertSame('550', Decimal::trim(DB::table('payment_methods')->where('id', $cod)->value('max_subtotal')));
    }
}
