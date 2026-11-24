<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Infrastructure\Coupons;
use App\Marketing\Infrastructure\PromotionQueries;
use App\Marketing\Infrastructure\Promotions;
use App\Order\Infrastructure\OrderAdministration;
use App\Order\Infrastructure\OrderPricing;
use App\Pricing\Application\Command\SaveCurrencyData;
use App\Pricing\Application\Contract\CurrencyAdministration;
use App\Pricing\Domain\Repository\CurrencyRepository;
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

final class PromotionTest extends TestCase
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
        $this->category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
    }

    private function definition(array $changes = []): array
    {
        return array_replace(['translations' => app(LocaleCatalog::class)->active()->mapWithKeys(fn ($l): array => [$l->code => ['name' => 'Automatic offer', 'description' => '']])->all(), 'status' => 'active', 'mode' => 'automatic', 'coupon_id' => null, 'starts_at' => null, 'ends_at' => null, 'priority' => '100', 'stop_other' => false, 'match' => 'all', 'benefit' => 'percentage', 'scope' => 'order', 'amount' => '20', 'all_products' => true, 'exclude_sale' => false, 'all_shipping' => true, 'conditions' => [], 'products' => [], 'categories' => [], 'shipping' => [], 'excluded_products' => [], 'excluded_categories' => [], 'excluded_shipping' => []], $changes);
    }

    private function promotion(array $changes = []): string
    {
        return app(Promotions::class)->save((string) $this->actor->id, null, $this->definition($changes));
    }

    private function condition(string $type, array $changes = []): array
    {
        return array_replace(['type' => $type, 'minimum' => null, 'maximum' => null, 'quantity' => null, 'quantity_scope' => 'cart', 'match' => 'any', 'products' => [], 'categories' => [], 'groups' => [], 'shipping' => []], $changes);
    }

    private function product(string $price = '100', ?string $category = null): ProductRecord
    {
        return ProductRecord::create(['sku' => uniqid('PROMO-'), 'category_id' => $category ?? $this->category->id, 'price' => $price, 'quantity' => 100, 'status' => 'active']);
    }

    private function shipping(): string
    {
        return (string) DB::table('shipping_methods')->insertGetId(['calculation' => 'manual', 'is_active' => true, 'free_shipping_applies' => true]);
    }

    private function quote(array $items, ?string $method = null, ?string $customer = null, ?string $coupon = null, bool $available = true): array
    {
        return app(OrderPricing::class)->quote($items, [], ['billing' => ['country_code' => 'GR'], 'shipping' => null], false, 'en', 2, $customer, false, $method === null ? null : ['id' => $method, 'amount' => '5', 'tax_ids' => [], 'available' => $available], $coupon, 'guest@example.test');
    }

    private function items(ProductRecord $p, int $quantity = 1): array
    {
        return [['product_id' => (string) $p->id, 'quantity' => $quantity]];
    }

    private function payload(ProductRecord $p, array $changes = []): array
    {
        return array_replace(['locale' => 'en', 'first_name' => 'Maria', 'last_name' => 'Test', 'email' => 'guest@example.test', 'billing' => ['first_name' => 'Maria', 'last_name' => 'Test', 'line1' => 'Ermou 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR'], 'shipping_same' => true, 'items' => $this->items($p), 'status_id' => (string) DB::table('order_statuses')->where('code', 'open')->value('id')], $changes);
    }

    private function coupon(array $changes = []): string
    {
        return app(Coupons::class)->save((string) $this->actor->id, null, array_replace(['name' => 'test', 'code' => 'test123', 'type' => 'percentage', 'amount' => '10', 'minimum' => '0', 'free_shipping' => false, 'login_required' => false, 'all_products' => true, 'starts_on' => null, 'ends_on' => null, 'total_limit' => null, 'customer_limit' => null, 'status' => 'active', 'product_ids' => [], 'category_ids' => []], $changes));
    }

    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            self::fail('Expected validation failure on '.$field);
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors(), json_encode($e->errors()));
        }
    }

    public function test_admin_crud_listing_status_permissions_and_audit(): void
    {
        $this->get('/admin/promotions/create')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Promotions/Form')->where('promotion', null));
        $this->post('/admin/promotions', $this->definition())->assertSessionHasNoErrors();
        $id = (string) DB::table('promotions')->sole()->id;
        $this->get('/admin/promotions/'.$id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p->where('promotion.mode', 'automatic'));
        $this->put('/admin/promotions/'.$id, $this->definition(['version' => 1, 'amount' => '30']))->assertSessionHasNoErrors();
        $this->put('/admin/promotions/'.$id, $this->definition(['version' => 1]))->assertSessionHasErrors('version');
        $this->get('/admin/promotions?search=offer&sort=name')->assertInertia(fn (Assert $p) => $p->where('promotions.total', 1)->where('promotions.data.0.amount', '30'));
        $this->patch('/admin/promotions/'.$id.'/status', ['version' => 2, 'status' => 'disabled'])->assertSessionHasNoErrors();
        $this->get('/admin/promotions?status=disabled')->assertInertia(fn (Assert $p) => $p->where('promotions.data.0.state', 'disabled'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'promotion.created', 'entity_id' => $id]);
        $viewer = $this->staff(['admin.access', 'promotions.view']);
        $this->flushSession();
        $this->actingAs($viewer)->get('/admin/promotions')->assertOk();
        $this->get('/admin/promotions/create')->assertForbidden();
        $this->patch('/admin/promotions/'.$id.'/status', ['version' => 3, 'status' => 'active'])->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(Promotions::class)->save((string) $viewer->id, null, $this->definition());
    }

    public function test_configuration_validation_and_required_selections(): void
    {
        foreach ([['amount' => '0'], ['amount' => '101'], ['amount' => '1e2'], ['benefit' => 'fixed', 'amount' => '1.001'], ['starts_at' => '2026-10-18T16:00', 'ends_at' => '2026-10-18T14:00'], ['mode' => 'coupon', 'benefit' => 'coupon', 'amount' => null], ['scope' => 'products', 'all_products' => false], ['benefit' => 'free_shipping', 'amount' => null, 'all_shipping' => false], ['conditions' => [$this->condition('products')]], ['conditions' => [$this->condition('subtotal', ['minimum' => '100', 'maximum' => '50'])]], ['conditions' => [$this->condition('quantity')]]] as $change) {
            $field = match (array_key_first($change)) {
                'starts_at' => 'ends_at', 'mode' => 'coupon_id', 'scope' => 'products', 'benefit' => ($change['benefit'] === 'fixed' ? 'amount' : 'shipping'), 'conditions' => 'conditions.0.'.match ($change['conditions'][0]['type']) {
                    'products' => 'products', 'subtotal' => 'minimum', default => 'quantity'
                }, default => 'amount'
            };
            $this->invalid(fn () => $this->promotion($change), $field);
        }
        $this->invalid(fn () => $this->promotion(['conditions' => [$this->condition('products', ['products' => ['99999999']])]]), 'conditions.0.products.0');
    }

    public function test_fifty_euro_free_shipping_and_hundred_euro_percentage_recalculate_for_guests(): void
    {
        $method = $this->shipping();
        $this->promotion(['benefit' => 'free_shipping', 'amount' => null, 'conditions' => [$this->condition('subtotal', ['minimum' => '50'])]]);
        $this->promotion(['conditions' => [$this->condition('subtotal', ['minimum' => '100'])]]);
        $p = $this->product('25');
        $a = $this->quote($this->items($p), $method);
        self::assertSame('30.00', $a['total']);
        $b = $this->quote($this->items($p, 2), $method);
        self::assertSame('5.00', $b['shipping_discount']);
        self::assertSame('50.00', $b['total']);
        $c = $this->quote($this->items($p, 4), $method);
        self::assertSame('20.00', $c['discount_total']);
        self::assertSame('80.00', $c['total']);
        self::assertCount(2, $c['promotions']);
        self::assertSame($c, $this->quote($this->items($p, 4), $method));
        self::assertSame('30.00', $this->quote($this->items($p), $method)['total']);
        self::assertSame(0, DB::table('order_promotions')->count());
    }

    public function test_product_presence_is_separate_from_benefit_target_and_all_products_match(): void
    {
        $trigger = $this->product('10');
        $target = $this->product('100');
        $id = $this->promotion(['scope' => 'products', 'all_products' => false, 'products' => [(string) $target->id], 'conditions' => [$this->condition('products', ['products' => [(string) $trigger->id, (string) $target->id], 'match' => 'all'])]]);
        self::assertSame('100.00', $this->quote($this->items($target))['total']);
        $items = [...$this->items($trigger), ...$this->items($target)];
        $q = $this->quote($items);
        self::assertSame('90.00', $q['total']);
        self::assertSame('0.00', $q['lines'][0]['discount_total']);
        DB::table('promotion_condition_products')->where('product_id', $trigger->id)->update(['product_id' => null]);
        self::assertSame('110.00', $this->quote($items)['total']);
        self::assertSame($id, app(PromotionQueries::class)->definitions([$id])[0]['id']);
    }

    public function test_category_descendants_quantity_and_unrelated_items(): void
    {
        $child = CategoryRecord::create(['parent_id' => $this->category->id, 'position' => 1, 'status' => 'active']);
        $other = CategoryRecord::create(['position' => 2, 'status' => 'active']);
        $a = $this->product('10', (string) $child->id);
        $b = $this->product('10', (string) $other->id);
        $this->promotion(['conditions' => [$this->condition('categories', ['categories' => [(string) $this->category->id]]), $this->condition('quantity', ['quantity' => '3', 'quantity_scope' => 'categories', 'categories' => [(string) $this->category->id]])]]);
        self::assertSame('0.00', $this->quote([...$this->items($a), ...$this->items($b, 4)])['discount_total']);
        self::assertSame('6.00', $this->quote($this->items($a, 3))['discount_total']);
        self::assertSame('0.00', $this->quote($this->items($b, 3))['discount_total']);
        // In the category as an extra category: targeted too.
        DB::table('product_categories')->insert(['product_id' => $b->id, 'category_id' => $child->id, 'position' => 1]);
        self::assertSame('6.00', $this->quote($this->items($b, 3))['discount_total']);
    }

    public function test_or_conditions_subtotal_range_total_quantity_and_targeted_product_quantity(): void
    {
        $p = $this->product('10');
        $id = $this->promotion(['match' => 'any', 'conditions' => [$this->condition('subtotal', ['minimum' => '50', 'maximum' => '100']), $this->condition('quantity', ['quantity' => '3'])]]);
        self::assertSame('0.00', $this->quote($this->items($p))['discount_total']);
        self::assertSame('6.00', $this->quote($this->items($p, 3))['discount_total']);
        self::assertSame('10.00', $this->quote($this->items($p, 5))['discount_total']);
        DB::table('promotions')->where('id', $id)->update(['match' => 'all']);
        self::assertSame('0.00', $this->quote($this->items($p, 3))['discount_total']);
        app(Promotions::class)->save((string) $this->actor->id, $id, $this->definition(['version' => 1, 'conditions' => [$this->condition('products', ['products' => [(string) $p->id]]), $this->condition('quantity', ['quantity' => '3', 'quantity_scope' => 'products', 'products' => [(string) $p->id]])]]));
        $other = $this->product('5');
        self::assertSame('0.00', $this->quote([...$this->items($p), ...$this->items($other, 5)])['discount_total']);
        self::assertSame('6.00', $this->quote($this->items($p, 3))['discount_total']);
    }

    public function test_active_group_memberships_multiple_groups_and_guests(): void
    {
        $group = (string) DB::table('customer_groups')->insertGetId(['status' => 'active', 'is_default' => true]);
        $other = (string) DB::table('customer_groups')->insertGetId(['status' => 'active']);
        $customer = (string) DB::table('customers')->insertGetId(['email' => 'customer@example.test', 'first_name' => 'Customer', 'last_name' => 'Test']);
        foreach ([$other, $group] as $g) {
            DB::table('customer_group_members')->insert(['customer_id' => $customer, 'group_id' => $g]);
        }
        $this->promotion(['conditions' => [$this->condition('groups', ['groups' => [$group]])]]);
        $p = $this->product();
        self::assertSame('0.00', $this->quote($this->items($p))['discount_total']);
        self::assertSame('20.00', $this->quote($this->items($p), customer: $customer)['discount_total']);
        DB::table('customer_groups')->where('id', $group)->update(['status' => 'disabled', 'is_default' => false]);
        self::assertSame('0.00', $this->quote($this->items($p), customer: $customer)['discount_total']);
    }

    public function test_shipping_conditions_and_targets_require_a_valid_eligible_quote(): void
    {
        $a = $this->shipping();
        $b = $this->shipping();
        $this->promotion(['benefit' => 'free_shipping', 'amount' => null, 'all_shipping' => false, 'shipping' => [$a], 'conditions' => [$this->condition('shipping', ['shipping' => [$a]])]]);
        $p = $this->product();
        self::assertSame('0.00', $this->quote($this->items($p))['shipping_discount']);
        self::assertSame('5.00', $this->quote($this->items($p), $a)['shipping_discount']);
        self::assertSame('0.00', $this->quote($this->items($p), $b)['shipping_discount']);
        self::assertSame('0.00', $this->quote($this->items($p), $a, available: false)['shipping_discount']);
        DB::table('shipping_methods')->where('id', $a)->update(['free_shipping_applies' => false]);
        self::assertSame('0.00', $this->quote($this->items($p), $a)['shipping_discount']);
    }

    public function test_two_hour_schedule_has_inclusive_start_exclusive_end_and_disabled_state(): void
    {
        DB::table('general_settings')->where('id', 1)->update(['timezone' => 'Europe/Athens']);
        $id = $this->promotion(['starts_at' => '2026-10-18T14:00', 'ends_at' => '2026-10-18T16:00']);
        $p = $this->product();
        foreach (['2026-10-18 10:59:59' => '0.00', '2026-10-18 11:00:00' => '20.00', '2026-10-18 12:59:59' => '20.00', '2026-10-18 13:00:00' => '0.00'] as $instant => $discount) {
            $this->travelTo(Carbon::parse($instant.' UTC'));
            self::assertSame($discount, $this->quote($this->items($p))['discount_total']);
        }
        $this->travelTo(Carbon::parse('2026-10-18 12:00:00 UTC'));
        DB::table('promotions')->where('id', $id)->update(['status' => 'disabled']);
        self::assertSame('0.00', $this->quote($this->items($p))['discount_total']);
    }

    public function test_order_discounts_compound_but_thresholds_use_one_original_subtotal(): void
    {
        $this->promotion(['priority' => '1', 'conditions' => [$this->condition('subtotal', ['minimum' => '100'])]]);
        $this->promotion(['priority' => '2', 'conditions' => [$this->condition('subtotal', ['minimum' => '100'])]]);
        $p = $this->product();
        self::assertSame('36.00', $this->quote($this->items($p))['discount_total']);
        $this->promotion(['priority' => '3', 'benefit' => 'fixed', 'amount' => '999']);
        $q = $this->quote($this->items($p));
        self::assertSame('100.00', $q['discount_total']);
        self::assertSame('0.00', $q['total']);
    }

    public function test_priority_ties_stop_only_after_a_real_benefit_and_compatible_shipping(): void
    {
        $p = $this->product();
        $this->promotion(['priority' => '0', 'stop_other' => true, 'benefit' => 'free_shipping', 'amount' => null]);
        $a = $this->promotion(['priority' => '1', 'stop_other' => true]);
        $b = $this->promotion(['priority' => '1', 'amount' => '10']);
        $this->promotion(['priority' => '2', 'amount' => '100']);
        $q = $this->quote($this->items($p));
        self::assertSame('28.00', $q['discount_total']);
        self::assertSame([$a, $b], array_column($q['promotions'], 'promotion_id'));
        $q = $this->quote($this->items($p), $this->shipping());
        self::assertSame('0.00', $q['discount_total']);
        self::assertSame('5.00', $q['shipping_discount']);
    }

    private function sale(ProductRecord $p, string $amount): string
    {
        return (string) DB::table('product_sales')->insertGetId(['product_id' => $p->id, 'kind' => 'percentage', 'amount' => $amount, 'minimum_quantity' => 1, 'priority' => 1, 'is_enabled' => true]);
    }

    public function test_product_line_discount_uses_better_original_price_without_stacking_and_exclusions(): void
    {
        $p = $this->product();
        $this->sale($p, '30');
        $id = $this->promotion(['scope' => 'products']);
        self::assertSame('70.00', $this->quote($this->items($p))['total']);
        DB::table('promotions')->where('id', $id)->update(['amount' => '40']);
        $q = $this->quote($this->items($p));
        self::assertSame('60.00', $q['total']);
        self::assertSame('100.00', $q['lines'][0]['unit_price']);
        self::assertNull($q['lines'][0]['sale_id']);
        DB::table('promotions')->where('id', $id)->update(['exclude_sale' => true]);
        self::assertSame('70.00', $this->quote($this->items($p))['total']);
        $this->promotion(['amount' => '10', 'priority' => '200']);
        self::assertSame('63.00', $this->quote($this->items($p))['total']);
    }

    public function test_fixed_product_benefits_exclusions_and_nonstacking_multiple_line_promotions(): void
    {
        $p = $this->product('10');
        $b = $this->product('10');
        $this->promotion(['benefit' => 'fixed', 'amount' => '3.33', 'scope' => 'products', 'excluded_products' => [(string) $b->id]]);
        $this->promotion(['scope' => 'products', 'amount' => '50', 'priority' => '200']);
        $q = $this->quote([...$this->items($p), ...$this->items($b)]);
        self::assertSame('8.33', $q['discount_total']);
        self::assertSame('6.67', $q['lines'][0]['total']);
        self::assertSame('5.00', $q['lines'][1]['total']);
    }

    public function test_existing_test123_coupon_and_linked_coupon_use_one_source_and_one_redemption(): void
    {
        $coupon = $this->coupon(['free_shipping' => true, 'total_limit' => 1]);
        $p = $this->product();
        self::assertSame('10.00', $this->quote($this->items($p), coupon: 'test123')['discount_total']);
        $this->promotion(['mode' => 'coupon', 'coupon_id' => $coupon, 'benefit' => 'coupon', 'amount' => null, 'conditions' => [$this->condition('subtotal', ['minimum' => '100'])]]);
        $this->promotion(['amount' => '10', 'priority' => '200']);
        $q = $this->quote($this->items($p), $this->shipping(), coupon: 'test123');
        self::assertSame('19.00', $q['discount_total']);
        self::assertSame('10.00', $q['coupon_discount']);
        self::assertSame('5.00', $q['shipping_discount']);
        $order = app(OrderAdministration::class)->create(null, $this->payload($p, ['coupon_code' => 'test123']), 'checkout');
        self::assertSame('10.000000', DB::table('coupon_redemptions')->sole()->discount);
        self::assertSame(1, DB::table('coupon_redemptions')->count());
        self::assertSame(2, DB::table('order_promotions')->where('order_id', $order)->count());
        $this->invalid(fn () => $this->quote($this->items($p), coupon: 'test123'), 'coupon_code');
    }

    public function test_linked_coupon_never_bypasses_its_dates_status_scope_limits_or_promotion(): void
    {
        $coupon = $this->coupon();
        $p = $this->product();
        $id = $this->promotion(['mode' => 'coupon', 'coupon_id' => $coupon, 'benefit' => 'coupon', 'amount' => null, 'conditions' => [$this->condition('products', ['products' => [(string) $p->id]])]]);
        $this->invalid(fn () => $this->quote($this->items($this->product()), coupon: 'test123'), 'coupon_code');
        foreach ([['status' => 'disabled'], ['ends_on' => '2000-01-01'], ['starts_on' => '2099-01-01'], ['minimum' => '101'], ['login_required' => true], ['all_products' => false]] as $change) {
            DB::table('coupons')->where('id', $coupon)->update($change);
            $this->invalid(fn () => $this->quote($this->items($p), coupon: 'test123'), 'coupon_code');
            DB::table('coupons')->where('id', $coupon)->update(['status' => 'active', 'ends_on' => null, 'starts_on' => null, 'minimum' => '0', 'login_required' => false, 'all_products' => true]);
        }
        DB::table('promotions')->where('id', $id)->update(['status' => 'disabled']);
        $this->invalid(fn () => $this->quote($this->items($p), coupon: 'test123'), 'coupon_code');
        $this->invalid(fn () => $this->quote($this->items($p), coupon: 'missing'), 'coupon_code');
    }

    public function test_order_quote_creation_snapshots_noop_edits_cancellation_and_repeated_checkout(): void
    {
        $id = $this->promotion();
        $p = $this->product();
        $payload = $this->payload($p, ['submission_key' => 'a7ee18c6-ad47-49b5-9128-36ed5f257098']);
        $this->post('/admin/orders/quote', $payload)->assertSessionHasNoErrors()->assertSessionHas('order_quote.total', '€80.00')->assertSessionHas('order_quote.promotions.0.name', 'Automatic offer');
        $orders = app(OrderAdministration::class);
        $order = $orders->create(null, $payload, 'checkout');
        self::assertSame($order, $orders->create(null, $payload, 'checkout'));
        self::assertSame(1, DB::table('orders')->count());
        $snapshot = DB::table('order_promotions')->sole();
        self::assertSame('20.000000', $snapshot->discount);
        self::assertSame('EUR', $snapshot->currency_code);
        self::assertSame('20.00', json_decode($snapshot->allocations, true)[0]['discount']);
        DB::table('promotions')->where('id', $id)->update(['amount' => '50', 'status' => 'disabled']);
        $this->get('/admin/orders/'.$order)->assertInertia(fn (Assert $p) => $p->where('order.promotions.0.discount', '€20.00')->where('order.totals.total', '€80.00'));
        $item = DB::table('order_items')->where('order_id', $order)->sole();
        $orders->update((string) $this->actor->id, $order, $this->payload($p, ['version' => 1, 'items' => [['item_id' => (string) $item->id, 'quantity' => 1]]]));
        self::assertSame('80.000000', DB::table('orders')->where('id', $order)->value('total'));
        $cancel = (string) DB::table('order_statuses')->where('stock_action', 'restore')->value('id');
        $open = (string) DB::table('order_statuses')->where('code', 'open')->value('id');
        $orders->changeStatus(null, $order, 2, $cancel, source: 'system');
        self::assertSame(0, DB::table('order_promotions')->whereNull('released_at')->count());
        $orders->changeStatus(null, $order, 3, $open, source: 'system');
        $orders->changeStatus(null, $order, 4, $open, source: 'system');
        self::assertSame(1, DB::table('order_promotions')->whereNull('released_at')->count());
        self::assertSame(1, DB::table('order_promotions')->count());
        $this->invalid(fn () => $orders->create(null, [...$payload, 'items' => $this->items($p, 2)], 'checkout'), 'submission_key');
    }

    public function test_repricing_original_inputs_removes_stale_line_promotions_and_does_not_double_subtract(): void
    {
        $id = $this->promotion(['scope' => 'products', 'conditions' => [$this->condition('quantity', ['quantity' => '2'])]]);
        $p = $this->product();
        $this->sale($p, '10');
        $orders = app(OrderAdministration::class);
        $order = $orders->create(null, $this->payload($p, ['items' => $this->items($p, 2)]));
        self::assertSame('160.000000', DB::table('orders')->where('id', $order)->value('total'));
        self::assertSame(0, DB::table('product_sale_allocations')->sum('quantity'));
        $item = DB::table('order_items')->where('order_id', $order)->sole();
        $orders->update((string) $this->actor->id, $order, $this->payload($p, ['version' => 1, 'items' => [['item_id' => (string) $item->id, 'quantity' => 1]]]));
        self::assertSame('90.000000', DB::table('orders')->where('id', $order)->value('total'));
        self::assertNotNull(DB::table('order_promotions')->sole()->released_at);
        self::assertSame('0.000000', DB::table('orders')->where('id', $order)->value('discount_total'));
        self::assertSame($id, (string) DB::table('order_promotions')->sole()->promotion_id);
    }

    public function test_tax_thresholds_discounts_shipping_and_rounding_use_existing_tax_pipeline(): void
    {
        $p = $this->product('124');
        $area = app(RateAreaAdministration::class)->create((string) $this->actor->id, new SaveRateAreaData(['en' => 'Greece'], 'active', false, ['GR']));
        $tax = app(TaxAdministration::class)->create((string) $this->actor->id, new SaveTaxData(['en' => 'VAT'], null, 0, 'active', true, 'billing', [new SaveTaxRateData($area, 'percent', '24')]));
        DB::table('product_taxes')->insert(['product_id' => $p->id, 'tax_id' => $tax]);
        $id = $this->promotion(['conditions' => [$this->condition('subtotal', ['minimum' => '100'])]]);
        $q = $this->quote($this->items($p));
        self::assertSame('24.80', $q['discount_total']);
        self::assertSame('19.20', $q['tax_included']);
        self::assertSame('99.20', $q['total']);
        DB::table('promotion_conditions')->where('promotion_id', $id)->update(['minimum' => '100.01']);
        self::assertSame('0.00', $this->quote($this->items($p))['discount_total']);
        DB::table('promotions')->where('id', $id)->update(['benefit' => 'fixed', 'amount' => '1']);
        DB::table('promotion_conditions')->where('promotion_id', $id)->update(['minimum' => null, 'maximum' => '1000']);
        $q = $this->quote($this->items($p, 3));
        self::assertSame('1.00', $q['discount_total']);
        self::assertSame('371.00', $q['total']);
        self::assertSame($q, $this->quote($this->items($p, 3)));
    }

    public function test_currency_conversion_updates_rules_but_never_order_snapshots(): void
    {
        $this->promotion(['benefit' => 'fixed', 'amount' => '1.25', 'conditions' => [$this->condition('subtotal', ['minimum' => '10', 'maximum' => '200'])]]);
        $p = $this->product();
        app(OrderAdministration::class)->create(null, $this->payload($p));
        $currencies = app(CurrencyAdministration::class);
        $usd = $currencies->create((string) $this->actor->id, new SaveCurrencyData('USD', 'Dollar', '$', 'before', ',', '.', 2, '2', 'active'));
        $currencies->makePrimary((string) $this->actor->id, $usd, 1, 'USD');
        self::assertSame('2.500000', DB::table('promotions')->sole()->amount);
        self::assertSame('20.000000', DB::table('promotion_conditions')->sole()->minimum);
        self::assertSame('400.000000', DB::table('promotion_conditions')->sole()->maximum);
        self::assertSame('1.250000', DB::table('order_promotions')->sole()->discount);
        self::assertSame('EUR', DB::table('order_promotions')->sole()->currency_code);
        $this->invalid(fn () => $currencies->update((string) $this->actor->id, $usd, 2, new SaveCurrencyData('USD', 'Dollar', '$', 'before', ',', '.', 0, '1', 'active')), 'decimals');
    }

    public function test_migration_preserves_all_existing_coupon_data_and_redemptions(): void
    {
        $this->coupon(['total_limit' => 5, 'customer_limit' => 2, 'free_shipping' => true]);
        app(OrderAdministration::class)->create(null, $this->payload($this->product(), ['coupon_code' => 'test123']));
        $before = json_encode([DB::table('coupons')->get(), DB::table('coupon_redemptions')->get()]);
        $selections = require database_path('migrations/2026_11_19_000100_add_deal_block_selections.php');
        $migration = require database_path('migrations/2026_10_25_000100_create_promotions.php');
        $selections->down();
        $migration->down();
        $migration->up();
        $selections->up();
        self::assertSame($before, json_encode([DB::table('coupons')->get(), DB::table('coupon_redemptions')->get()]));
        self::assertSame('10.000000', DB::table('orders')->sole()->coupon_discount);
    }

    public function test_linked_coupon_obeys_priority_compounds_once_and_does_not_consume_when_stopped(): void
    {
        $coupon = $this->coupon(['total_limit' => 1]);
        $this->promotion(['priority' => '1']);
        $this->promotion(['mode' => 'coupon', 'coupon_id' => $coupon, 'benefit' => 'coupon', 'amount' => null, 'priority' => '2']);
        $p = $this->product();
        $q = $this->quote($this->items($p), coupon: 'test123');
        self::assertSame('28.00', $q['discount_total']);
        self::assertSame('8.00', $q['coupon_discount']);
        $this->promotion(['priority' => '0', 'stop_other' => true, 'amount' => '5']);
        $q = $this->quote($this->items($p), coupon: 'test123');
        self::assertNull($q['coupon_id']);
        self::assertSame('0.00', $q['coupon_discount']);
        app(OrderAdministration::class)->create(null, $this->payload($p, ['coupon_code' => 'test123']));
        self::assertSame(0, DB::table('coupon_redemptions')->count());
    }

    public function test_repeat_checkout_with_coupon_snapshots_and_events_reserves_once(): void
    {
        $coupon = $this->coupon(['total_limit' => 1]);
        $this->promotion(['mode' => 'coupon', 'coupon_id' => $coupon, 'benefit' => 'coupon', 'amount' => null]);
        $payload = $this->payload($this->product(), ['coupon_code' => 'test123', 'submission_key' => 'a7ee18c6-ad47-49b5-9128-36ed5f257099']);
        $orders = app(OrderAdministration::class);
        $id = $orders->create(null, $payload, 'checkout');
        self::assertSame($id, $orders->create(null, $payload, 'checkout'));
        $open = (string) DB::table('order_statuses')->where('code', 'open')->value('id');
        self::assertFalse($orders->changeStatus(null, $id, 1, $open, source: 'system'));
        self::assertSame(1, DB::table('coupon_redemptions')->count());
        self::assertSame(1, DB::table('order_promotions')->count());
        self::assertSame('10.000000', DB::table('coupon_redemptions')->sole()->discount);
    }

    public function test_free_shipping_keeps_freight_surcharges_and_their_remaining_tax(): void
    {
        $p = $this->product('50');
        $this->promotion(['benefit' => 'free_shipping', 'amount' => null]);
        $method = $this->shipping();
        $area = app(RateAreaAdministration::class)->create((string) $this->actor->id, new SaveRateAreaData(['en' => 'Greece'], 'active', true, []));
        $tax = app(TaxAdministration::class)->create((string) $this->actor->id, new SaveTaxData(['en' => 'VAT'], null, 0, 'active', false, 'billing', [new SaveTaxRateData($area, 'percent', '24')]));
        $q = app(OrderPricing::class)->quote($this->items($p), [], ['billing' => ['country_code' => 'GR'], 'shipping' => null], false, 'en', 2, shipping: ['id' => $method, 'amount' => '8', 'discountable' => '5', 'tax_ids' => [$tax], 'available' => true]);
        self::assertSame('5.00', $q['shipping_discount']);
        self::assertSame('3.00', $q['shipping_total']);
        self::assertSame('0.72', $q['shipping_tax']['added']);
        self::assertSame('53.72', $q['total']);
        $this->coupon(['amount' => '0', 'free_shipping' => true]);
        $q = app(OrderPricing::class)->quote($this->items($p), [], ['billing' => ['country_code' => 'GR'], 'shipping' => null], false, 'en', 2, shipping: ['id' => $method, 'amount' => '8', 'discountable' => '5', 'tax_ids' => [$tax]], couponCode: 'test123', email: 'guest@example.test');
        self::assertSame('8.00', $q['shipping_discount']);
        self::assertSame('0.00', $q['shipping_tax']['added']);
    }

    public function test_real_shipping_quote_and_order_creation_have_identical_promotions_and_totals(): void
    {
        $area = app(RateAreaAdministration::class)->create((string) $this->actor->id, new SaveRateAreaData(['en' => 'World'], 'active', true, []));
        $this->post('/admin/shipping-methods', ['translations' => ['en' => ['name' => 'Courier', 'description' => '', 'delivery_time' => '']], 'calculation' => 'manual', 'is_active' => true, 'free_shipping_applies' => true, 'rates' => [['rate_area_id' => $area, 'weight_from' => '0', 'weight_to' => '', 'base' => '5', 'per_unit' => '0']]])->assertSessionHasNoErrors();
        $method = (string) DB::table('shipping_methods')->sole()->id;
        $p = $this->product('50');
        $p->update(['shipping_cost' => '2']);
        $this->promotion(['benefit' => 'free_shipping', 'amount' => null]);
        $payload = $this->payload($p, ['shipping_method_id' => $method]);
        $this->post('/admin/orders/quote', $payload)->assertSessionHasNoErrors()->assertSessionHas('order_quote.total', '€52.00')->assertSessionHas('order_quote.shipping_discount', '€5.00');
        $order = app(OrderAdministration::class)->create(null, $payload, 'checkout');
        self::assertSame('52.000000', DB::table('orders')->where('id', $order)->value('total'));
        self::assertSame('5.000000', DB::table('order_promotions')->sole()->shipping_discount);
        self::assertSame('2.000000', DB::table('orders')->where('id', $order)->value('shipping_total'));
    }

    public function test_deleted_benefit_targets_remain_tombstones_and_do_not_widen_discounts(): void
    {
        $p = $this->product('100');
        $id = $this->promotion(['scope' => 'products', 'all_products' => false, 'products' => [(string) $p->id]]);
        self::assertSame('20.00', $this->quote($this->items($p))['discount_total']);
        DB::table('products')->where('id', $p->id)->delete();
        self::assertNull(DB::table('promotion_products')->sole()->product_id);
        self::assertSame('0.00', $this->quote($this->items($this->product()))['discount_total']);
        self::assertNull(app(PromotionQueries::class)->definitions([$id])[0]['products'][0]);
    }

    public function test_a_fully_discounted_sale_line_does_not_grant_a_fake_benefit_or_stop_shipping(): void
    {
        $p = $this->product();
        $this->sale($p, '30');
        $orderDiscount = $this->promotion(['priority' => '1', 'amount' => '100']);
        $this->promotion(['priority' => '2', 'scope' => 'products', 'amount' => '40', 'stop_other' => true]);
        $shippingDiscount = $this->promotion(['priority' => '3', 'benefit' => 'free_shipping', 'amount' => null]);
        $q = $this->quote($this->items($p), $this->shipping());
        self::assertSame('70.00', $q['discount_total']);
        self::assertSame('0.00', $q['total']);
        self::assertSame([$orderDiscount, $shippingDiscount], array_column($q['promotions'], 'promotion_id'));
    }

    public function test_malformed_rule_payloads_return_validation_instead_of_server_errors(): void
    {
        $this->post('/admin/promotions', $this->definition(['conditions' => 'bad']))->assertSessionHasErrors('conditions');
        $this->post('/admin/promotions', $this->definition(['conditions' => ['bad']]))->assertSessionHasErrors('conditions.0');
    }

    public function test_zero_decimal_currency_uses_exact_minor_units_and_rejects_fractional_fixed_amounts(): void
    {
        DB::table('currencies')->where('is_primary', true)->update(['decimals' => 0]);
        app(CurrencyRepository::class)->forget();
        $this->invalid(fn () => $this->promotion(['benefit' => 'fixed', 'amount' => '0.5']), 'amount');
        $this->promotion(['amount' => '33.3333']);
        $p = $this->product('1');
        $q = app(OrderPricing::class)->quote($this->items($p, 3), [], ['billing' => ['country_code' => 'GR'], 'shipping' => null], false, 'en', 0);
        self::assertSame('1', $q['discount_total']);
        self::assertSame('2', $q['total']);
    }
}
