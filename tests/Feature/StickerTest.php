<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\SaveProductData;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Marketing\Infrastructure\StickerProducts;
use App\Marketing\Infrastructure\StickerQueries;
use App\Marketing\Infrastructure\Stickers;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Order\Infrastructure\OrderAdministration;
use App\Payment\Infrastructure\PaymentSettings;
use App\Pricing\Application\Command\SaveCurrencyData;
use App\Pricing\Application\Contract\CurrencyAdministration;
use App\Pricing\Infrastructure\ProductSales;
use App\Settings\Infrastructure\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class StickerTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private CategoryRecord $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00 UTC'));
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        $this->category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
    }

    private function product(string $price = '100', ?CategoryRecord $category = null): ProductRecord
    {
        $p = ProductRecord::create(['sku' => 'ST-'.Str::uuid(), 'price' => $price, 'quantity' => 10, 'category_id' => ($category ?? $this->category)->id, 'status' => 'active']);
        $p->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->value('id'), 'name' => 'Lamp '.$p->id, 'slug' => 'lamp-'.$p->id]);

        return $p;
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function data(array $changes = []): array
    {
        return array_replace([
            'name' => 'Internal reference', 'type' => 'manual', 'style' => 'text', 'status' => 'active', 'starts_at' => null, 'ends_at' => null, 'priority' => 100, 'match' => 'all', 'placement' => 'image_top', 'show_card' => true, 'show_detail' => true, 'show_mobile' => true, 'show_tablet' => true, 'show_desktop' => true,
            'background' => '#111827', 'color' => '#ffffff', 'border_color' => '#111827', 'border_width' => 0, 'radius' => 4, 'padding' => 6, 'font_size' => 12, 'alignment' => 'center', 'image_width' => 64, 'css_class' => '', 'image_media_id' => null,
            'translations' => ['en' => ['text' => 'New arrival', 'short_text' => 'New', 'tooltip' => 'Fresh selection'], 'el' => ['text' => 'Νέα άφιξη', 'short_text' => 'Νέο', 'tooltip' => '']], 'conditions' => [], 'products' => [],
        ], $changes);
    }

    /** @param array<string, mixed> $changes */
    private function sticker(array $changes = []): string
    {
        return app(Stickers::class)->save((string) $this->actor->id, null, $this->data($changes));
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function condition(string $type, array $changes = []): array
    {
        return array_replace(['type' => $type, 'minimum' => null, 'maximum' => null, 'category_id' => null, 'feature_id' => null, 'option_id' => null, 'boolean_value' => null, 'include_descendants' => true], $changes);
    }

    /** @return list<array<string, mixed>> */
    private function labels(ProductRecord $product, string $locale = 'en', string $context = 'card'): array
    {
        return app(StickerProducts::class)->forProducts([(string) $product->id], $locale, $context)[$product->id] ?? [];
    }

    public function test_manual_crud_multiple_assignments_removal_and_cascade(): void
    {
        $a = $this->product();
        $b = $this->product();
        $other = $this->product();
        $this->post('/admin/stickers', $this->data(['products' => [(string) $a->id, (string) $b->id]]))->assertSessionHasNoErrors()->assertRedirect();
        $id = (string) DB::table('stickers')->sole()->id;
        self::assertSame([$id], array_column($this->labels($a), 'id'));
        self::assertCount(1, $this->labels($b));
        self::assertSame([], $this->labels($other));
        self::assertFalse((bool) DB::table('stickers')->where('id', $id)->value('is_filter'));
        $this->post('/admin/stickers/'.$id, $this->data(['version' => 1, 'products' => [(string) $b->id], 'name' => 'Edited', 'is_filter' => true]))->assertSessionHasNoErrors();
        self::assertTrue((bool) DB::table('stickers')->where('id', $id)->value('is_filter'));
        // The filter address name follows the sticker name and stays unique.
        self::assertSame('edited', DB::table('stickers')->where('id', $id)->value('filter_slug'));
        $this->post('/admin/stickers', $this->data(['name' => 'Édited!']))->assertSessionHasNoErrors();
        self::assertSame('edited-2', DB::table('stickers')->where('name', 'Édited!')->value('filter_slug'));
        DB::table('stickers')->where('name', 'Édited!')->delete();
        $this->get('/admin/stickers/'.$id.'/edit')->assertInertia(fn ($page) => $page->where('sticker.filter_slug', 'edited'));
        self::assertSame([], $this->labels($a));
        self::assertCount(1, $this->labels($b));
        DB::table('products')->where('id', $b->id)->delete();
        $this->assertDatabaseCount('product_stickers', 0);
        $this->delete('/admin/stickers/'.$id, ['version' => 2])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sticker_translations', 0);
        $this->assertDatabaseCount('stickers', 0);
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'sticker')->exists());
    }

    public function test_permissions_navigation_listing_and_bounded_sku_selector(): void
    {
        $p = $this->product();
        $id = $this->sticker(['products' => [(string) $p->id]]);
        $this->get('/admin/stickers?search=Internal&sort=name')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Stickers/Index')->where('stickers.data.0.assigned_count', 1));
        $this->get('/admin/stickers/'.$id.'/edit?kind=products&search='.urlencode($p->sku))->assertOk()->assertInertia(fn (Assert $page) => $page->where('options.products.0.id', (string) $p->id));
        $this->get('/admin/products/'.$p->id.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->where('product.sticker_ids', [$id])->where('options.stickers.0.id', $id));
        $this->get('/admin/products')->assertOk()->assertInertia(fn (Assert $page) => $page->where('products.data.0.sticker_count', 1));
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'stickers.view']));
        $this->get('/admin/stickers')->assertOk()->assertInertia(fn (Assert $page) => $page->where('navigation', fn ($items) => collect($items)->contains('id', 'core.stickers')));
        $this->post('/admin/stickers', $this->data())->assertForbidden();
        $this->patch('/admin/stickers/'.$id.'/status', ['version' => 1, 'status' => 'disabled'])->assertForbidden();
        $this->delete('/admin/stickers/'.$id, ['version' => 1])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff());
        $this->get('/admin/stickers')->assertForbidden();
    }

    public function test_product_editor_saves_assignments_with_permission_and_stale_editor_protection(): void
    {
        $p = $this->product();
        $id = $this->sticker();
        $fields = ['sku' => $p->sku, 'price' => '100', 'type' => 'physical', 'status' => 'active', 'category_id' => (string) $this->category->id, 'translations' => ['en' => ['name' => 'Lamp']], 'stickers_submitted' => true, 'sticker_ids' => [$id]];
        app(ProductAdministration::class)->save((string) $this->actor->id, (string) $p->id, new SaveProductData(1, $fields));
        self::assertCount(1, $this->labels($p));
        $this->post('/admin/stickers/'.$id, $this->data(['version' => 1]))->assertSessionHasErrors('version');
        $this->post('/admin/stickers/'.$id, $this->data(['version' => 2]))->assertSessionHasNoErrors();
        $this->post('/admin/products/'.$p->id, [...$fields, 'version' => 2])->assertSessionHasErrors('version');
        self::assertSame([], $this->labels($p));
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'products.update']));
        $this->post('/admin/products/'.$p->id, [...$fields, 'version' => $p->fresh()->lock_version])->assertForbidden();
    }

    public function test_schedule_uses_store_timezone_start_inclusive_end_exclusive_without_jobs(): void
    {
        DB::table('general_settings')->where('id', 1)->update(['timezone' => 'Europe/Athens']);
        app()->forgetInstance(GeneralSettings::class);
        $p = $this->product();
        $id = $this->sticker(['products' => [(string) $p->id], 'starts_at' => '2026-10-18T16:00', 'ends_at' => '2026-10-18T17:00']);
        self::assertSame([], $this->labels($p));
        $this->travelTo(CarbonImmutable::parse('2026-10-18 13:00:00 UTC'));
        self::assertCount(1, $this->labels($p));
        $this->travelTo(CarbonImmutable::parse('2026-10-18 13:59:59 UTC'));
        self::assertCount(1, $this->labels($p));
        $this->travelTo(CarbonImmutable::parse('2026-10-18 14:00:00 UTC'));
        self::assertSame([], $this->labels($p));
        $forever = $this->sticker(['products' => [(string) $p->id]]);
        self::assertSame([$forever], array_column($this->labels($p), 'id'));
        $this->patch('/admin/stickers/'.$forever.'/status', ['version' => 1, 'status' => 'disabled'])->assertSessionHasNoErrors();
        self::assertSame([], $this->labels($p));
        self::assertSame('expired', app(StickerQueries::class)->editor($id)['state']);
    }

    public function test_price_and_applicable_sale_discount_refresh_after_price_dates_and_quota_change(): void
    {
        $p = $this->product();
        $price = $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('price', ['minimum' => '70', 'maximum' => '80'])]]);
        $discount = $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('discount_percent', ['minimum' => '20', 'maximum' => '30']), $this->condition('discount_amount', ['minimum' => '20'])]]);
        self::assertSame([], $this->labels($p));
        app(ProductSales::class)->sync((string) $p->id, [['minimum_quantity' => 1, 'priority' => 1, 'kind' => 'percentage', 'amount' => '25', 'stock_limit' => 1, 'starts_on' => '2026-10-18', 'ends_on' => '2026-10-18']]);
        self::assertSame([$price, $discount], array_column($this->labels($p), 'id'));
        $this->travel(1)->days();
        self::assertSame([], $this->labels($p));
        $this->travelBack();
        DB::table('product_sales')->update(['stock_limit' => 0]);
        self::assertSame([], $this->labels($p));
        $p->update(['price' => '75']);
        self::assertSame([$price], array_column($this->labels($p), 'id'));
        // Reference/list prices never manufacture an applicable discount.
        $p->update(['list_price' => '200']);
        self::assertSame([$price], array_column($this->labels($p), 'id'));
    }

    public function test_category_parent_descendants_and_deleted_targets_are_fail_closed_even_with_or(): void
    {
        $child = CategoryRecord::create(['parent_id' => $this->category->id, 'position' => 1, 'status' => 'active']);
        $p = $this->product(category: $child);
        $yes = $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('category', ['category_id' => (string) $this->category->id])]]);
        $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('category', ['category_id' => (string) $this->category->id, 'include_descendants' => false])]]);
        self::assertSame([$yes], array_column($this->labels($p), 'id'));
        $target = CategoryRecord::create(['position' => 2, 'status' => 'active']);
        $broken = $this->sticker(['type' => 'dynamic', 'match' => 'any', 'conditions' => [$this->condition('category', ['category_id' => (string) $target->id]), $this->condition('in_stock')]]);
        self::assertContains($broken, array_column($this->labels($p), 'id'));
        $target->delete();
        self::assertSame([$yes], array_column($this->labels($p), 'id'));
        $child->update(['parent_id' => null, 'position' => 3]);
        self::assertSame([], $this->labels($p));
        // An extra category inside the targeted category matches again, with descendants.
        DB::table('product_categories')->insert(['product_id' => $p->id, 'category_id' => $this->category->id, 'position' => 1]);
        self::assertContains($yes, array_column($this->labels($p), 'id'));
    }

    public function test_features_use_choice_id_numeric_range_boolean_and_current_applicability(): void
    {
        $p = $this->product();
        $group = DB::table('feature_groups')->value('id');
        $choice = (string) DB::table('features')->insertGetId(['group_id' => $group, 'code' => 'brand', 'type' => 'single', 'position' => 1]);
        $number = (string) DB::table('features')->insertGetId(['group_id' => $group, 'code' => 'size', 'type' => 'number', 'position' => 2]);
        $boolean = (string) DB::table('features')->insertGetId(['group_id' => $group, 'code' => 'waterproof', 'type' => 'boolean', 'position' => 3]);
        $option = (string) DB::table('feature_options')->insertGetId(['feature_id' => $choice, 'position' => 1]);
        $a = DB::table('product_feature_assignments')->insertGetId(['product_id' => $p->id, 'feature_id' => $choice, 'type' => 'single']);
        DB::table('product_feature_choices')->insert(['assignment_id' => $a, 'feature_id' => $choice, 'type' => 'single', 'option_id' => $option]);
        DB::table('product_feature_assignments')->insert(['product_id' => $p->id, 'feature_id' => $number, 'type' => 'number', 'number_value' => '12.5']);
        DB::table('product_feature_assignments')->insert(['product_id' => $p->id, 'feature_id' => $boolean, 'type' => 'boolean', 'boolean_value' => false]);
        $id = $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('feature', ['feature_id' => $choice, 'option_id' => $option]), $this->condition('feature', ['feature_id' => $number, 'minimum' => '12.5']), $this->condition('feature', ['feature_id' => $boolean, 'boolean_value' => false])]]);
        self::assertSame([$id], array_column($this->labels($p, 'el'), 'id'));
        DB::table('product_feature_assignments')->where('feature_id', $number)->update(['number_value' => '12']);
        self::assertSame([], $this->labels($p));
        DB::table('product_feature_assignments')->where('feature_id', $number)->update(['number_value' => '13']);
        DB::table('feature_options')->where('id', $option)->update(['status' => 'archived']);
        self::assertSame([], $this->labels($p));
        DB::table('feature_options')->where('id', $option)->update(['status' => 'active']);
        DB::table('product_feature_assignments')->where('id', $a)->update(['is_applicable' => false]);
        self::assertSame([], $this->labels($p));
    }

    public function test_stock_availability_new_product_free_shipping_and_or_refresh_live(): void
    {
        $p = $this->product();
        $in = $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('in_stock'), $this->condition('quantity', ['minimum' => '5']), $this->condition('purchasable')]]);
        $out = $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('out_of_stock')]]);
        $new = $this->sticker(['type' => 'dynamic', 'match' => 'any', 'conditions' => [$this->condition('new', ['maximum' => '14']), $this->condition('free_shipping')]]);
        self::assertSame([$in, $new], array_column($this->labels($p), 'id'));
        $p->update(['quantity' => 0]);
        self::assertSame([$out, $new], array_column($this->labels($p), 'id'));
        $this->travel(14)->days();
        self::assertSame([$out], array_column($this->labels($p), 'id'));
        $p->update(['free_shipping' => true]);
        self::assertSame([$out, $new], array_column($this->labels($p), 'id'));
        // Each variation member owns stock; a stocked sibling never makes this one in stock.
        $this->product();
        self::assertNotContains($in, array_column($this->labels($p), 'id'));
    }

    public function test_localization_default_fallback_compact_text_safety_placements_and_device_limits(): void
    {
        $p = $this->product();
        DB::table('locales')->where('is_default', true)->update(['is_default' => false]);
        DB::table('locales')->where('code', 'el')->update(['is_default' => true]);
        $p->translations()->create(['locale_id' => LocaleRecord::where('code', 'el')->value('id'), 'name' => 'Λάμπα', 'slug' => 'lamp-el']);
        $first = $this->sticker(['products' => [(string) $p->id], 'priority' => 1, 'show_mobile' => false]);
        $second = $this->sticker(['products' => [(string) $p->id], 'priority' => 2, 'placement' => 'below_price', 'translations' => ['en' => ['text' => '<script>alert(1)</script>', 'short_text' => '', 'tooltip' => '" onmouseover="alert(1)'], 'el' => ['text' => 'Ειδικό', 'short_text' => '', 'tooltip' => '']]]);
        DB::table('sticker_translations')->where('sticker_id', $first)->where('locale_id', LocaleRecord::where('code', 'en')->value('id'))->delete();
        app(Stickers::class)->limits((string) $this->actor->id, ['version' => 1, 'card_limit' => 1, 'detail_limit' => 5]);
        $labels = $this->labels($p);
        self::assertSame('Νέο', $labels[0]['text']);
        self::assertSame(['mobile' => false, 'tablet' => true, 'desktop' => true], $labels[0]['visibility']);
        self::assertSame(['mobile' => true, 'tablet' => false, 'desktop' => false], $labels[1]['visibility']);
        self::assertSame('Νέα άφιξη', $this->labels($p, context: 'detail')[0]['text']);
        self::assertSame('<script>alert(1)</script>', $labels[1]['text']);
        self::assertSame('below_price', $labels[1]['placement']);
        self::assertArrayNotHasKey('name', $labels[1]);
        self::assertSame($second, $labels[1]['id']);
        $this->post('/admin/stickers', $this->data(['css_class' => 'x" onclick="alert(1)']))->assertSessionHasErrors('css_class');
    }

    public function test_image_upload_reuse_missing_files_and_authorized_admin_media(): void
    {
        $p = $this->product();
        $id = $this->sticker(['style' => 'image', 'image' => UploadedFile::fake()->createWithContent('badge.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII=')), 'products' => [(string) $p->id]]);
        $media = DB::table('stickers')->where('id', $id)->value('image_media_id');
        $this->sticker(['style' => 'image', 'image_media_id' => (string) $media, 'products' => [(string) $p->id]]);
        $this->assertDatabaseCount('media_files', 1);
        self::assertSame(64, $this->labels($p)[0]['width']);
        self::assertSame(64, $this->labels($p)[0]['height']);
        self::assertSame('New arrival', $this->labels($p)[0]['alt']);
        self::assertSame((string) $media, $this->labels($p)[0]['image_media_id']);
        $this->get('/admin/stickers/'.$id.'/image')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->patch('/admin/stickers/'.$id.'/status', ['version' => 1, 'status' => 'disabled'])->assertSessionHasNoErrors();
        self::assertCount(1, $this->labels($p)); // Other active sticker still references the same asset.
        Storage::disk('local')->delete(MediaFileRecord::findOrFail($media)->storage_key);
        self::assertSame([], $this->labels($p));
        $this->post('/admin/stickers', $this->data(['style' => 'image', 'image' => UploadedFile::fake()->create('evil.svg', 1, 'image/svg+xml')]))->assertSessionHasErrors('image');
        $this->post('/admin/stickers', $this->data(['style' => 'image']))->assertSessionHasErrors('image');
    }

    public function test_eligible_product_scope_and_soft_delete_without_adding_storefront_routes(): void
    {
        $p = $this->product();
        $id = $this->sticker(['products' => [(string) $p->id]]);
        self::assertSame([$id], array_column($this->labels($p), 'id'));
        foreach (['disabled', 'hidden'] as $status) {
            $p->update(['status' => $status]);
            self::assertSame([], $this->labels($p));
        }
        $p->update(['status' => 'active']);
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active']);
        DB::table('category_customer_groups')->insert(['category_id' => $this->category->id, 'group_id' => $group]);
        self::assertSame([], $this->labels($p));
        DB::table('category_customer_groups')->delete();
        $p->delete();
        self::assertSame([], $this->labels($p));
        $this->assertDatabaseCount('product_stickers', 1);
        DB::table('products')->where('id', $p->id)->delete();
        $this->assertDatabaseCount('product_stickers', 0);
        foreach (['/shop', '/products', '/storefront'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_bad_configuration_and_inactive_languages_are_rejected_without_runtime_errors(): void
    {
        foreach ([['conditions' => 'bad'], ['conditions' => ['bad']], ['type' => 'dynamic', 'conditions' => []], ['type' => 'dynamic', 'conditions' => [$this->condition('subtotal')]], ['type' => 'dynamic', 'conditions' => [$this->condition('price', ['minimum' => '200', 'maximum' => '100'])]], ['starts_at' => '2026-10-18T16:00', 'ends_at' => '2026-10-18T15:00'], ['translations' => ['en' => ['text' => 'Sale']]], ['background' => 'url(javascript:evil)']] as $change) {
            $this->post('/admin/stickers', $this->data($change))->assertSessionHasErrors();
        }
        $this->post('/admin/stickers', $this->data(['translations' => [...$this->data()['translations'], 'fr' => ['text' => 'Offre']]]))->assertSessionHasErrors('translations');
        self::assertSame(0, DB::table('stickers')->count());
    }

    public function test_sales_units_use_paid_non_void_orders_and_refresh_after_cancellation(): void
    {
        app(PaymentSettings::class)->ensure();
        $p = $this->product();
        $id = $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('sales', ['minimum' => '1'])]]);
        $order = app(OrderAdministration::class)->create(null, $this->orderInput((string) $p->id));
        self::assertSame([], $this->labels($p));
        DB::table('order_payments')->insert(['reference' => (string) Str::uuid(), 'order_id' => $order, 'payment_method_id' => $this->methodId('cod'), 'kind' => 'manual', 'state' => 'captured', 'amount' => '100', 'currency_code' => 'EUR', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        self::assertSame([$id], array_column($this->labels($p), 'id'));
        DB::table('orders')->where('id', $order)->update(['status_id' => $this->orderStatus('cancelled')]);
        self::assertSame([], $this->labels($p));
    }

    public function test_currency_threshold_conversion_preserves_feature_percent_and_quantity_values(): void
    {
        $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('price', ['minimum' => '10', 'maximum' => '200']), $this->condition('discount_amount', ['minimum' => '1.25']), $this->condition('discount_percent', ['minimum' => '10']), $this->condition('quantity', ['minimum' => '5'])]]);
        $currencies = app(CurrencyAdministration::class);
        $usd = $currencies->create((string) $this->actor->id, new SaveCurrencyData('USD', 'Dollar', '$', 'before', ',', '.', 2, '2', 'active'));
        $currencies->makePrimary((string) $this->actor->id, $usd, 1, 'USD');
        $rules = DB::table('sticker_conditions')->get()->keyBy('type');
        self::assertSame('20.000000', $rules['price']->minimum);
        self::assertSame('400.000000', $rules['price']->maximum);
        self::assertSame('2.500000', $rules['discount_amount']->minimum);
        self::assertSame('10.000000', $rules['discount_percent']->minimum);
        self::assertSame('5.000000', $rules['quantity']->minimum);
    }

    public function test_inventory_tracking_release_dates_price_privacy_and_zero_limits(): void
    {
        $p = $this->product();
        $p->update(['quantity' => 0, 'available_from' => '2026-10-20']);
        $stock = $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('in_stock')]]);
        $buy = $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('purchasable')]]);
        $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('quantity', ['minimum' => '1'])]]);
        $price = $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('price', ['minimum' => '1'])]]);
        DB::table('general_settings')->where('id', 1)->update(['track_inventory' => false]);
        app()->forgetInstance(GeneralSettings::class);
        self::assertSame([$stock, $price], array_column($this->labels($p), 'id'));
        $this->travel(2)->days();
        self::assertSame([$stock, $buy, $price], array_column($this->labels($p), 'id'));
        DB::table('general_settings')->where('id', 1)->update(['hide_product_prices' => true]);
        app()->forgetInstance(GeneralSettings::class);
        self::assertSame([$stock, $buy], array_column($this->labels($p), 'id'));
        app(Stickers::class)->limits((string) $this->actor->id, ['version' => 1, 'card_limit' => 0, 'detail_limit' => 1]);
        self::assertSame([], $this->labels($p));
        self::assertSame([$stock], array_column($this->labels($p, context: 'detail'), 'id'));
        $projection = $this->labels($p, context: 'detail')[0];
        self::assertArrayNotHasKey('quantity', $projection);
        self::assertArrayNotHasKey('price', $projection);
    }

    public function test_batch_query_count_is_constant_as_products_and_stickers_grow(): void
    {
        $p = $this->product();
        $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('price', ['minimum' => '1']), $this->condition('category', ['category_id' => (string) $this->category->id])]]);
        $service = app(StickerProducts::class);
        $service->forProducts([(string) $p->id], 'en');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->forProducts(DB::table('products')->pluck('id')->map(strval(...))->all(), 'en');
        $one = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 20; $i++) {
            $this->product();
            $this->sticker(['type' => 'dynamic', 'conditions' => [$this->condition('in_stock')]]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->forProducts(DB::table('products')->pluck('id')->map(strval(...))->all(), 'en');
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        self::assertLessThanOrEqual($one + 1, $many, "Query growth: $one → $many");
        self::assertSame('100.000000', $p->fresh()->price);
        self::assertSame(10, $p->fresh()->quantity);
    }
}
