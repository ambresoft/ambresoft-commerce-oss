<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Infrastructure\Coupons;
use App\Marketing\Infrastructure\Promotions;
use App\Order\Infrastructure\OrderAdministration;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Infrastructure\BlockContent;
use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class DealBlocksTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(12, 0));
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    private function coupon(string $code, array $changes = []): string
    {
        return app(Coupons::class)->save((string) $this->actor->id, null, array_replace(['name' => 'Private staff campaign name', 'code' => $code, 'type' => 'percentage', 'amount' => '15', 'minimum' => '25', 'free_shipping' => false, 'login_required' => false, 'all_products' => true, 'starts_on' => '', 'ends_on' => '', 'total_limit' => '', 'customer_limit' => '', 'status' => 'active', 'product_ids' => [], 'category_ids' => []], $changes));
    }

    private function offer(array $changes = []): string
    {
        return app(Promotions::class)->save((string) $this->actor->id, null, array_replace(['translations' => app(LocaleCatalog::class)->active()->mapWithKeys(fn ($l): array => [$l->code => ['name' => $l->code === 'el' ? 'Προσφορά εβδομάδας' : 'Weekly offer', 'description' => 'Save on your next order']])->all(), 'status' => 'active', 'mode' => 'automatic', 'coupon_id' => null, 'starts_at' => null, 'ends_at' => null, 'priority' => '100', 'stop_other' => false, 'match' => 'all', 'benefit' => 'fixed', 'scope' => 'order', 'amount' => '20', 'all_products' => true, 'exclude_sale' => false, 'all_shipping' => true, 'conditions' => [], 'products' => [], 'categories' => [], 'shipping' => [], 'excluded_products' => [], 'excluded_categories' => [], 'excluded_shipping' => []], $changes));
    }

    private function block(string $type, array $ids): array
    {
        $definition = app(StorefrontRegistry::class)->type($type)->definition();

        return ['id' => (string) Str::uuid(), 'type' => $type, 'name' => 'Private block name', 'template' => $definition['templates'][0], 'css_class' => '', 'settings' => $definition['defaults'], 'selections' => ['items' => $ids], 'translations' => ['en' => ['title' => 'Rewards', 'html' => ''], 'el' => ['title' => 'Κερδίστε', 'html' => '']]];
    }

    private function content(string $type, array $ids, ?string $customer = null): array
    {
        return app(BlockContent::class)->resolve($type, ['block' => $this->block($type, $ids)], ['locale' => 'en', 'customer' => $customer]);
    }

    public function test_two_manual_blocks_save_render_in_order_and_hide_when_empty(): void
    {
        $first = $this->coupon('FIRST');
        $second = $this->coupon('SECOND');
        $offer = $this->offer();
        $layout = app(LayoutStore::class)->read('homepage');
        $grid = (string) Str::uuid();
        $layout['grids'] = [['id' => $grid, 'parent_id' => null, 'region' => 'content', 'width' => 16, 'row' => 1, 'position' => 1]];
        $layout['placements'] = [];
        foreach ([$this->block('coupons', [$second, $first]), $this->block('offers', [$offer])] as $n => $block) {
            $layout['placements'][] = ['id' => (string) Str::uuid(), 'grid_id' => $grid, 'position' => $n + 1, 'enabled' => true, 'mobile' => true, 'tablet' => true, 'desktop' => true, 'block' => $block];
        }
        $this->put('/admin/layouts/homepage', $layout)->assertSessionHasNoErrors()->assertRedirect();
        $saved = app(LayoutStore::class)->read('homepage');
        self::assertSame([$second, $first], $saved['placements'][0]['block']['selections']['items']);
        $rendered = json_decode(json_encode(app(LayoutRenderer::class)->page('homepage', 'el', null)), true);
        $blocks = $rendered['regions']['content'][0]['blocks'];
        self::assertSame(['coupon_carousel', 'offer_carousel'], array_column($blocks, 'template'));
        self::assertSame([$second, $first], array_column($blocks[0]['content']['items'], 'id'));
        self::assertSame('Προσφορά εβδομάδας', $blocks[1]['content']['items'][0]['name']);
        self::assertSame('ticket', $blocks[0]['content']['display']['title_icon']);
        self::assertStringNotContainsString('Private staff', json_encode($rendered));
        self::assertSame([], $this->content('coupons', []));
        self::assertSame([], $this->content('offers', []));
        DB::table('coupons')->whereIn('id', [$first, $second])->update(['status' => 'disabled']);
        self::assertSame([], $this->content('coupons', [$first, $second]));
    }

    public function test_coupon_dates_visibility_limits_and_public_payload(): void
    {
        $live = $this->coupon('TODAY', ['ends_on' => now()->toDateString()]);
        $expired = $this->coupon('EXPIRED', ['ends_on' => now()->subDay()->toDateString()]);
        $future = $this->coupon('FUTURE', ['starts_on' => now()->addDay()->toDateString()]);
        $disabled = $this->coupon('DISABLED', ['status' => 'disabled']);
        $deleted = $this->coupon('DELETED');
        DB::table('coupons')->where('id', $deleted)->update(['deleted_at' => now()]);
        $login = $this->coupon('LOGIN', ['login_required' => true]);
        $customer = (string) DB::table('customers')->insertGetId(['email' => 'maria@example.test', 'first_name' => 'Maria', 'last_name' => 'Example']);
        $ids = [$live, $expired, $future, $disabled, $deleted, $login];
        $guest = $this->content('coupons', $ids)['block']['items'];
        self::assertSame([$live], array_column($guest, 'id'));
        self::assertSame('15%', $guest[0]['deal']['benefit']);
        self::assertSame('TODAY', $guest[0]['deal']['code']);
        self::assertSame([$live, $login], array_column($this->content('coupons', $ids, $customer)['block']['items'], 'id'));
        $limited = $this->coupon('LIMITED', ['customer_limit' => 1]);
        $product = $this->orderProduct('100');
        app(OrderAdministration::class)->create(null, $this->orderInput((string) $product->id, ['coupon_code' => 'LIMITED']));
        self::assertSame([], $this->content('coupons', [$limited], $customer));
        self::assertCount(1, $this->content('coupons', [$limited])['block']['items']);
        DB::table('coupons')->where('id', $limited)->update(['total_limit' => 1]);
        self::assertSame([], $this->content('coupons', [$limited]));
        DB::table('coupon_redemptions')->where('coupon_id', $limited)->update(['released_at' => now()]);
        self::assertCount(1, $this->content('coupons', [$limited], $customer)['block']['items']);
    }

    public function test_offers_and_linked_coupons_obey_dates_and_customer_groups(): void
    {
        $group = (string) DB::table('customer_groups')->insertGetId(['status' => 'active']);
        $customer = (string) DB::table('customers')->insertGetId(['email' => 'group@example.test', 'first_name' => 'Group', 'last_name' => 'Member']);
        DB::table('customer_group_members')->insert(['customer_id' => $customer, 'group_id' => $group]);
        $condition = ['type' => 'groups', 'minimum' => null, 'maximum' => null, 'quantity' => null, 'quantity_scope' => 'cart', 'match' => 'any', 'products' => [], 'categories' => [], 'groups' => [$group], 'shipping' => []];
        $restricted = $this->offer(['conditions' => [$condition]]);
        $live = $this->offer();
        $expired = $this->offer(['ends_at' => '2026-10-28T10:00']);
        $future = $this->offer(['starts_at' => '2026-10-30T10:00']);
        $disabled = $this->offer(['status' => 'disabled']);
        $ids = [$restricted, $live, $expired, $future, $disabled];
        self::assertSame([$live], array_column($this->content('offers', $ids)['block']['items'], 'id'));
        self::assertSame([$restricted, $live], array_column($this->content('offers', $ids, $customer)['block']['items'], 'id'));
        $coupon = $this->coupon('LINKED');
        $linked = $this->offer(['mode' => 'coupon', 'coupon_id' => $coupon, 'benefit' => 'coupon', 'amount' => null, 'conditions' => [$condition]]);
        self::assertSame([], $this->content('coupons', [$coupon]));
        self::assertCount(1, $this->content('coupons', [$coupon], $customer)['block']['items']);
        self::assertSame([], $this->content('offers', [$linked]));
        DB::table('promotions')->where('id', $linked)->update(['status' => 'disabled']);
        self::assertSame([], $this->content('coupons', [$coupon], $customer));
    }

    public function test_unrestricted_deals_have_no_synthetic_terms_but_actual_restrictions_are_retained(): void
    {
        $unrestricted = $this->coupon('NO_TERMS', ['minimum' => '0']);
        $minimum = $this->coupon('MINIMUM', ['minimum' => '50']);
        $shipping = $this->coupon('SHIPPING', ['amount' => '0', 'minimum' => '0', 'free_shipping' => true]);
        $coupons = $this->content('coupons', [$unrestricted, $minimum, $shipping])['block']['items'];
        self::assertSame([], $coupons[0]['deal']['terms']);
        self::assertSame(['Minimum merchandise subtotal: €50.00'], $coupons[1]['deal']['terms']);
        self::assertSame([], $coupons[2]['deal']['terms']);
        $offer = $this->offer(['translations' => app(LocaleCatalog::class)->active()->mapWithKeys(fn ($l): array => [$l->code => ['name' => 'Unrestricted offer', 'description' => '']])->all()]);
        $restricted = $this->offer(['conditions' => [['type' => 'subtotal', 'minimum' => '30', 'maximum' => null, 'quantity' => null, 'quantity_scope' => 'cart', 'match' => 'any', 'products' => [], 'categories' => [], 'groups' => [], 'shipping' => []]]]);
        $offers = $this->content('offers', [$offer, $restricted])['block']['items'];
        self::assertSame([], $offers[0]['deal']['terms']);
        self::assertContains('Merchandise subtotal: €30.00 to no upper limit', $offers[1]['deal']['terms']);
    }

    public function test_picker_bounds_selected_retention_limits_and_shipping_only_coupons(): void
    {
        $ids = [];
        for ($n = 0; $n < 32; $n++) {
            $ids[] = $this->coupon('PICK'.$n);
        }
        $this->get('/admin/layouts?kind=coupons&search=PICK')->assertOk()->assertInertia(fn (Assert $p) => $p->has('options.coupons', 30));
        $this->get('/admin/layouts?kind=coupons&search=NO_MATCH&selected[]='.$ids[31])->assertOk()->assertInertia(fn (Assert $p) => $p->has('options.coupons', 1)->where('options.coupons.0.id', $ids[31]));
        $block = $this->block('coupons', array_reverse($ids));
        $block['settings']['limit'] = 2;
        $out = app(BlockContent::class)->resolve('coupons', ['block' => $block], ['locale' => 'en', 'customer' => null]);
        self::assertSame([$ids[31], $ids[30]], array_column($out['block']['items'], 'id'));
        $shipping = $this->coupon('FREESHIP', ['amount' => '0', 'free_shipping' => true]);
        self::assertSame('Free shipping', $this->content('coupons', [$shipping])['block']['items'][0]['deal']['benefit']);
    }

    public function test_picker_is_searchable_bounded_and_rejects_wrong_records_and_settings(): void
    {
        $coupon = $this->coupon('PUBLICCODE');
        $offer = $this->offer();
        $linked = $this->offer(['mode' => 'coupon', 'coupon_id' => $coupon, 'benefit' => 'coupon', 'amount' => null]);
        $this->get('/admin/layouts?kind=coupons&search=public')->assertOk()->assertInertia(fn (Assert $p) => $p->has('options.coupons', 1)->where('options.coupons.0.name', 'PUBLICCODE')->has('definitions.coupons')->has('definitions.offers'));
        $this->get('/admin/layouts?kind=promotions&search=Weekly')->assertOk()->assertInertia(fn (Assert $p) => $p->has('options.promotions', 1)->where('options.promotions.0.id', $offer));
        $layout = app(LayoutStore::class)->read('homepage');
        $grid = (string) Str::uuid();
        $layout['grids'] = [['id' => $grid, 'parent_id' => null, 'region' => 'content', 'width' => 16, 'row' => 1, 'position' => 1]];
        $layout['placements'] = [['id' => (string) Str::uuid(), 'grid_id' => $grid, 'position' => 1, 'enabled' => true, 'mobile' => true, 'tablet' => true, 'desktop' => true, 'block' => $this->block('offers', [$linked])]];
        $this->put('/admin/layouts/homepage', $layout)->assertSessionHasErrors();
        $layout['placements'][0]['block']['selections']['items'] = [$offer];
        $layout['placements'][0]['block']['settings']['title_icon'] = '<script>';
        $this->put('/admin/layouts/homepage', $layout)->assertSessionHasErrors();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'layouts.view']));
        $this->put('/admin/layouts/homepage', $layout)->assertForbidden();
    }
}
