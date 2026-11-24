<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Marketing\Infrastructure\Coupons;
use App\Order\Infrastructure\OrderAdministration;
use App\Order\Infrastructure\OrderEmailRenderer;
use App\Order\Infrastructure\OrderQueries;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class OrderDetailTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    private function tax(string $product, bool $included): void
    {
        $actor = (string) $this->actor->id;
        $area = app(RateAreaAdministration::class)->create($actor, new SaveRateAreaData(['en' => 'Greece'], 'active', false, ['GR']));
        $tax = app(TaxAdministration::class)->create($actor, new SaveTaxData(['en' => 'VAT'], null, 0, 'active', $included, 'billing', [new SaveTaxRateData($area, 'percent', '24')]));
        DB::table('product_taxes')->insert(['product_id' => $product, 'tax_id' => $tax]);
    }

    public function test_the_admin_distinguishes_included_tax_from_added_tax_and_projects_allocated_discounts(): void
    {
        $product = $this->orderProduct('124');
        $this->tax((string) $product->id, true);
        app(Coupons::class)->save((string) $this->actor->id, null, ['name' => 'Summer', 'code' => 'SUMMER', 'type' => 'percentage', 'amount' => '10', 'minimum' => '', 'free_shipping' => false, 'login_required' => false, 'all_products' => true, 'starts_on' => '', 'ends_on' => '', 'total_limit' => '', 'customer_limit' => '', 'status' => 'active', 'product_ids' => [], 'category_ids' => []]);
        $order = app(OrderAdministration::class)->create((string) $this->actor->id, $this->orderInput((string) $product->id, ['coupon_code' => 'SUMMER']));
        $this->get('/admin/orders/'.$order)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('order.totals.subtotal', '€124.00')->where('order.totals.discount', '€12.40')->where('order.totals.has_discount', true)
            ->where('order.totals.tax', '€21.60')->where('order.totals.tax_included', '€21.60')->where('order.totals.tax_added', '€0.00')->where('order.totals.has_added_tax', false)
            ->where('order.totals.total', '€111.60')->where('order.items.0.discount', '€12.40')->where('order.items.0.unit_net', '€90.00')->where('order.items.0.unit_gross', '€111.60'));
        $other = $this->orderProduct('100');
        // Use the existing tax definition with excluded VAT for the next order.
        DB::table('taxes')->update(['price_includes_tax' => false]);
        DB::table('product_taxes')->insert(['product_id' => $other->id, 'tax_id' => DB::table('taxes')->value('id')]);
        $added = app(OrderAdministration::class)->create((string) $this->actor->id, $this->orderInput((string) $other->id));
        $this->get('/admin/orders/'.$added)->assertInertia(fn (Assert $p) => $p->where('order.totals.has_discount', false)->where('order.items.0.discount', null)
            ->where('order.totals.has_added_tax', true)->where('order.totals.tax_added', '€24.00')->where('order.totals.tax_included', '€0.00')->where('order.totals.total', '€124.00'));
        // Opening either page never reprices a saved order from today's tax configuration.
        $this->get('/admin/orders/'.$order)->assertInertia(fn (Assert $p) => $p->where('order.totals.tax_included', '€21.60')->where('order.totals.tax_added', '€0.00'));
    }

    public function test_delivery_details_and_house_number_are_snapshotted_and_survive_unrelated_legacy_edits(): void
    {
        $product = $this->orderProduct('20');
        $input = $this->orderInput((string) $product->id);
        $input['billing'] = [...$input['billing'], 'line1' => 'Ermou', 'street_number' => '12', 'floor' => '0', 'bell_name' => 'Example', 'notes' => "Call first\nUse side door"];
        $order = app(OrderAdministration::class)->create((string) $this->actor->id, $input);
        $this->get('/admin/orders/'.$order)->assertInertia(fn (Assert $p) => $p->where('order.billing.line1', 'Ermou 12')->where('order.billing.floor', '0')->where('order.billing.bell_name', 'Example')->where('order.billing.notes', "Call first\nUse side door"));
        $edit = app(OrderQueries::class)->editor($order);
        self::assertSame('0', $edit['billing']['floor']);
        unset($edit['billing']['floor'], $edit['billing']['bell_name'], $edit['billing']['notes']);
        $edit['internal_note'] = 'Staff note';
        app(OrderAdministration::class)->update((string) $this->actor->id, $order, $edit);
        $address = DB::table('order_addresses')->where('order_id', $order)->sole();
        self::assertSame(['Ermou 12', '0', 'Example', "Call first\nUse side door"], [$address->line1, $address->floor, $address->bell_name, $address->notes]);
        self::assertStringContainsString('Floor: 0', app(OrderEmailRenderer::class)->invoicePage($order));
        // Explicit clearing is distinct from omitting a field in an older client.
        $edit = app(OrderQueries::class)->editor($order);
        $edit['billing']['notes'] = '';
        app(OrderAdministration::class)->update((string) $this->actor->id, $order, $edit);
        self::assertNull(DB::table('order_addresses')->where('order_id', $order)->value('notes'));
        $edit = app(OrderQueries::class)->editor($order);
        $this->put('/admin/orders/'.$order, [...$edit, 'billing' => [...$edit['billing'], 'floor' => str_repeat('x', 21)]])->assertSessionHasErrors('billing.floor');
        $edit['billing']['line1'] = 'A different street 34';
        unset($edit['billing']['floor'], $edit['billing']['bell_name'], $edit['billing']['notes']);
        app(OrderAdministration::class)->update((string) $this->actor->id, $order, $edit);
        self::assertNull(DB::table('order_addresses')->where('order_id', $order)->value('floor'));
        self::assertNull(DB::table('order_addresses')->where('order_id', $order)->value('bell_name'));
    }
}
