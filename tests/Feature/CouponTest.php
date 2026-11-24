<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Marketing\Infrastructure\CouponPricing;
use App\Marketing\Infrastructure\CouponQueries;
use App\Marketing\Infrastructure\Coupons;
use App\Order\Infrastructure\OrderAdministration;
use App\Order\Infrastructure\OrderPricing;
use App\Pricing\Application\Command\SaveCurrencyData;
use App\Pricing\Application\Contract\CurrencyAdministration;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CouponTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private CategoryRecord $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        $this->category = CategoryRecord::create(['parent_id' => null, 'position' => 1, 'status' => 'active']);
    }

    private function actor(): string
    {
        return (string) $this->actor->id;
    }

    /** @return array<string, mixed> */
    private function definition(array $changes = []): array
    {
        return array_replace(['name' => 'Summer offer', 'code' => 'SUMMER', 'type' => 'percentage', 'amount' => '10', 'minimum' => '', 'free_shipping' => false, 'login_required' => false, 'all_products' => true, 'starts_on' => '', 'ends_on' => '', 'total_limit' => '', 'customer_limit' => '', 'status' => 'active', 'product_ids' => [], 'category_ids' => []], $changes);
    }

    private function coupon(array $changes = []): string
    {
        return app(Coupons::class)->save($this->actor(), null, $this->definition($changes));
    }

    private function product(string $price = '10', ?string $category = null): ProductRecord
    {
        $p = ProductRecord::create(['sku' => 'SKU-'.uniqid(), 'price' => $price, 'quantity' => 100, 'category_id' => $category ?? $this->category->id, 'status' => 'active']);
        $p->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => 'Book '.$p->id, 'slug' => 'book-'.$p->id]);

        return $p;
    }

    /** @return array<string, mixed> */
    private function payload(ProductRecord $product, array $changes = []): array
    {
        return array_replace(['locale' => 'en', 'first_name' => 'Maria', 'last_name' => 'Example', 'email' => 'maria@example.test', 'billing' => ['first_name' => 'Maria', 'last_name' => 'Example', 'line1' => 'Ermou 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR'], 'shipping_same' => true, 'items' => [['product_id' => (string) $product->id, 'quantity' => 1]], 'status_id' => DB::table('order_statuses')->where('code', 'open')->value('id'), 'coupon_code' => 'SUMMER'], $changes);
    }

    private function invalid(callable $operation, string $field = 'coupon_code'): void
    {
        try {
            $operation();
            self::fail('Expected validation error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors(), json_encode($e->errors()));
        }
    }

    private function create(ProductRecord $product, array $changes = []): string
    {
        return app(OrderAdministration::class)->create($this->actor(), $this->payload($product, $changes));
    }

    private function vat(ProductRecord $p, bool $included = false): string
    {
        $area = app(RateAreaAdministration::class)->create($this->actor(), new SaveRateAreaData(['en' => 'Greece'], 'active', false, ['GR']));
        $tax = app(TaxAdministration::class)->create($this->actor(), new SaveTaxData(['en' => 'VAT'], null, 0, 'active', $included, 'billing', [new SaveTaxRateData($area, 'percent', '24')]));
        DB::table('product_taxes')->insert(['product_id' => $p->id, 'tax_id' => $tax]);

        return $tax;
    }

    public function test_admin_crud_history_and_optimistic_concurrency(): void
    {
        $this->get('/admin/coupons')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Coupons/Index')->where('filters.sort', 'name'));
        $this->post('/admin/coupons', $this->definition())->assertSessionHasNoErrors();
        $id = (string) DB::table('coupons')->sole()->id;
        $this->get('/admin/coupons/'.$id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Coupons/Form')->where('coupon.code', 'SUMMER')->where('history.total', 0));
        $this->put('/admin/coupons/'.$id, $this->definition(['version' => 1, 'amount' => '30', 'ends_on' => '2099-12-31']))->assertSessionHasNoErrors();
        $this->put('/admin/coupons/'.$id, $this->definition(['version' => 1]))->assertSessionHasErrors('version');
        $this->get('/admin/coupons?search=SUMMER')->assertInertia(fn (Assert $p) => $p->where('coupons.total', 1)->where('coupons.data.0.discount', '30%'));
        $this->delete('/admin/coupons/'.$id, ['version' => 2])->assertSessionHasNoErrors();
        $this->get('/admin/coupons')->assertInertia(fn (Assert $p) => $p->where('coupons.total', 0));
        $this->get('/admin/coupons/'.$id.'/edit')->assertOk();
        $this->post('/admin/coupons', $this->definition())->assertSessionHasErrors('code');
    }

    public function test_permissions_are_checked_for_direct_writes_and_http(): void
    {
        $id = $this->coupon();
        $viewer = $this->staff(['admin.access', 'coupons.view']);
        $this->actingAs($viewer)->get('/admin/coupons')->assertOk();
        $this->get('/admin/coupons/create')->assertForbidden();
        $this->put('/admin/coupons/'.$id, $this->definition(['version' => 1]))->assertForbidden();
        $this->delete('/admin/coupons/'.$id, ['version' => 1])->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(Coupons::class)->save((string) $viewer->id, null, $this->definition(['code' => 'NEW']));
    }

    public function test_validation_rejects_invalid_money_dates_targets_and_empty_benefits(): void
    {
        foreach ([['amount' => '101'], ['amount' => '-1'], ['amount' => '0'], ['amount' => '1e2'], ['starts_on' => '2026-12-01', 'ends_on' => '2026-11-01'], ['code' => 'bad code'], ['product_ids' => ['99999999']]] as $change) {
            $field = array_key_first($change) === 'starts_on' ? 'ends_on' : array_key_first($change);
            $field = $field === 'product_ids' ? 'product_ids.0' : $field;
            $this->invalid(fn () => $this->coupon($change), $field);
        }
        $this->coupon(['amount' => '0', 'free_shipping' => true, 'total_limit' => '0', 'customer_limit' => '0']);
        self::assertNull(DB::table('coupons')->sole()->total_limit);
    }

    public function test_discount_is_allocated_before_tax_and_quote_does_not_redeem(): void
    {
        $coupon = $this->coupon(['type' => 'fixed', 'amount' => '1']);
        $p = $this->product();
        $this->vat($p);
        $payload = $this->payload($p, ['items' => [['product_id' => (string) $p->id, 'quantity' => 3]]]);
        $this->post('/admin/orders/quote', $payload)->assertSessionHasNoErrors()->assertSessionHas('order_quote.coupon_code', 'SUMMER');
        self::assertSame(0, DB::table('coupon_redemptions')->count());
        $id = app(OrderAdministration::class)->create($this->actor(), $payload);
        $order = DB::table('orders')->where('id', $id)->sole();
        self::assertSame('1.000000', $order->discount_total);
        self::assertSame('29.000000', bcsub($order->subtotal, $order->discount_total, 6));
        self::assertSame($order->total, DB::table('order_items')->where('order_id', $id)->value('total'));
        self::assertSame(1, DB::table('coupon_redemptions')->whereNull('released_at')->count());
        self::assertSame(1, app(CouponQueries::class)->history($coupon)['total']);
        // A no-op save after expiry preserves the contracted price and its single usage.
        DB::table('coupons')->where('id', $coupon)->update(['ends_on' => '2000-01-01']);
        $item = DB::table('order_items')->where('order_id', $id)->sole();
        app(OrderAdministration::class)->update($this->actor(), $id, $this->payload($p, ['version' => 1, 'items' => [['item_id' => (string) $item->id, 'quantity' => 3]]]));
        self::assertSame($order->total, DB::table('orders')->where('id', $id)->value('total'));
        self::assertSame(1, DB::table('coupon_redemptions')->count());
        // Clearing the code restores original unit prices and releases the allowance.
        app(OrderAdministration::class)->update($this->actor(), $id, $this->payload($p, ['version' => 2, 'coupon_code' => '', 'items' => [['item_id' => (string) $item->id, 'quantity' => 3]]]));
        self::assertSame('0.000000', DB::table('orders')->where('id', $id)->value('discount_total'));
        self::assertSame(0, DB::table('coupon_redemptions')->whereNull('released_at')->count());
    }

    public function test_category_descendants_and_direct_products_form_a_union_and_deleted_targets_do_not_broaden_scope(): void
    {
        $child = CategoryRecord::create(['parent_id' => $this->category->id, 'position' => 1, 'status' => 'active']);
        $a = $this->product('10', (string) $child->id);
        $other = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $b = $this->product('20', (string) $other->id);
        $c = $this->product('30', (string) $other->id);
        $id = $this->coupon(['all_products' => false, 'category_ids' => [(string) $this->category->id], 'product_ids' => [(string) $b->id]]);
        $order = $this->create($a, ['items' => [['product_id' => (string) $a->id, 'quantity' => 1], ['product_id' => (string) $b->id, 'quantity' => 1], ['product_id' => (string) $c->id, 'quantity' => 1]]]);
        self::assertSame('3.000000', DB::table('orders')->where('id', $order)->value('discount_total'));
        // A product placed in the coupon's category as an extra category is eligible too.
        DB::table('product_categories')->insert(['product_id' => $c->id, 'category_id' => $child->id, 'position' => 1]);
        $order = $this->create($c, ['email' => 'extra@example.test']);
        self::assertSame('3.000000', DB::table('orders')->where('id', $order)->value('discount_total'));
        DB::table('product_categories')->where('product_id', $c->id)->delete();
        DB::table('coupon_products')->where('coupon_id', $id)->delete();
        DB::table('coupon_categories')->where('coupon_id', $id)->delete();
        $this->invalid(fn () => $this->create($c));
    }

    public function test_usage_limits_cancel_reactivation_and_guest_identity(): void
    {
        $this->coupon(['total_limit' => 1, 'customer_limit' => 1]);
        $p = $this->product();
        $id = $this->create($p);
        $this->invalid(fn () => $this->create($p, ['email' => 'someone@example.test']));
        $cancel = (string) DB::table('order_statuses')->where('stock_action', 'restore')->value('id');
        $open = (string) DB::table('order_statuses')->where('code', 'open')->value('id');
        app(OrderAdministration::class)->changeStatus($this->actor(), $id, 1, $cancel);
        $next = $this->create($p, ['email' => 'someone@example.test']);
        $this->invalid(fn () => app(OrderAdministration::class)->changeStatus($this->actor(), $id, 2, $open));
        self::assertSame((int) $cancel, (int) DB::table('orders')->where('id', $id)->value('status_id'));
        app(OrderAdministration::class)->changeStatus($this->actor(), $next, 1, $cancel);
        app(OrderAdministration::class)->changeStatus($this->actor(), $id, 2, $open);
        self::assertSame(1, DB::table('coupon_redemptions')->whereNull('released_at')->count());
        self::assertSame(app(CouponPricing::class)->customerKey(null, ' MARIA@Example.test '), app(CouponPricing::class)->customerKey(null, 'maria@example.test'));
    }

    public function test_minimum_excludes_included_tax_and_dates_are_in_store_timezone(): void
    {
        $id = $this->coupon(['minimum' => '100', 'starts_on' => '2026-10-17', 'ends_on' => '2026-10-17']);
        DB::table('general_settings')->where('id', 1)->update(['timezone' => 'Europe/Athens']);
        $this->travelTo(Carbon::parse('2026-10-16 22:30:00 UTC'));
        $p = $this->product('124');
        $this->vat($p, true);
        $this->create($p);
        DB::table('coupons')->where('id', $id)->update(['minimum' => '101']);
        $this->invalid(fn () => $this->create($p));
        DB::table('coupons')->where('id', $id)->update(['minimum' => '0']);
        $this->travelTo(Carbon::parse('2026-10-17 22:30:00 UTC'));
        $this->invalid(fn () => $this->create($p));
    }

    public function test_free_shipping_zeroes_shipping_and_its_tax_and_fixed_discount_is_capped(): void
    {
        $this->coupon(['type' => 'fixed', 'amount' => '999', 'free_shipping' => true]);
        $p = $this->product();
        $tax = $this->vat($p);
        $quote = app(OrderPricing::class)->quote([['product_id' => (string) $p->id, 'quantity' => 1]], [], ['billing' => ['country_code' => 'GR'], 'shipping' => null], false, 'en', 2, null, false, ['amount' => '5', 'tax_ids' => [$tax]], 'SUMMER', 'test@example.test');
        self::assertSame('10.00', $quote['discount_total']);
        self::assertSame('5.00', $quote['shipping_discount']);
        self::assertSame('0.00', $quote['shipping_total']);
        self::assertSame('0.00', $quote['total']);
    }

    public function test_registered_customer_requirement_and_per_email_limit_are_enforced(): void
    {
        $coupon = $this->coupon(['login_required' => true, 'customer_limit' => 1]);
        $p = $this->product();
        $this->invalid(fn () => $this->create($p));
        $customer = (string) DB::table('customers')->insertGetId(['email' => 'maria@example.test', 'first_name' => 'Maria', 'last_name' => 'Example', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->create($p, ['customer_id' => $customer]);
        $this->invalid(fn () => $this->create($p, ['customer_id' => $customer, 'email' => 'another@example.test']));
        DB::table('coupons')->where('id', $coupon)->update(['login_required' => false]);
        $this->invalid(fn () => $this->create($p, ['email' => ' MARIA@example.test ']));
        $this->create($p, ['email' => 'another@example.test']);
    }

    public function test_currency_conversion_changes_definitions_but_preserves_redemption_snapshots(): void
    {
        $coupon = $this->coupon(['type' => 'fixed', 'amount' => '1.25', 'minimum' => '5']);
        $product = $this->product();
        $order = $this->create($product);
        $currencies = app(CurrencyAdministration::class);
        $usd = $currencies->create($this->actor(), new SaveCurrencyData('USD', 'Dollar', '$', 'before', ',', '.', 2, '2', 'active'));
        $currencies->makePrimary($this->actor(), $usd, 1, 'USD');
        $row = DB::table('coupons')->where('id', $coupon)->sole();
        self::assertSame('2.500000', $row->amount);
        self::assertSame('10.000000', $row->minimum);
        self::assertSame(2, (int) $row->lock_version);
        self::assertSame('1.250000', DB::table('orders')->where('id', $order)->value('discount_total'));
        self::assertSame('EUR', DB::table('coupon_redemptions')->sole()->currency_code);
        $this->invalid(fn () => $currencies->update($this->actor(), $usd, 2, new SaveCurrencyData('USD', 'Dollar', '$', 'before', ',', '.', 0, '1', 'active')), 'decimals');
    }

    public function test_clearing_free_shipping_restores_original_charge_and_failed_save_consumes_nothing(): void
    {
        $this->coupon(['amount' => '0', 'free_shipping' => true, 'total_limit' => 1]);
        $area = app(RateAreaAdministration::class)->create($this->actor(), new SaveRateAreaData(['en' => 'World'], 'active', true, []));
        $this->post('/admin/shipping-methods', ['translations' => ['en' => ['name' => 'Courier', 'description' => '', 'delivery_time' => '']], 'calculation' => 'manual', 'is_active' => true, 'free_shipping_applies' => true, 'rates' => [['rate_area_id' => $area, 'weight_from' => '0', 'weight_to' => '', 'base' => '5', 'per_unit' => '0']]])->assertSessionHasNoErrors();
        $shipping = (string) DB::table('shipping_methods')->orderByDesc('id')->value('id');
        $product = $this->product();
        $this->invalid(fn () => $this->create($product, ['payment_method_id' => '999999']), 'payment_method_id');
        self::assertSame(0, DB::table('coupon_redemptions')->count());
        $order = $this->create($product, ['shipping_method_id' => $shipping]);
        self::assertSame('0.000000', DB::table('orders')->where('id', $order)->value('shipping_total'));
        $item = DB::table('order_items')->where('order_id', $order)->sole();
        app(OrderAdministration::class)->update($this->actor(), $order, $this->payload($product, ['version' => 1, 'coupon_code' => '', 'shipping_method_id' => $shipping, 'items' => [['item_id' => (string) $item->id, 'quantity' => 1]]]));
        self::assertSame('5.000000', DB::table('orders')->where('id', $order)->value('shipping_total'));
        self::assertSame(0, DB::table('coupon_redemptions')->whereNull('released_at')->count());
    }
}
