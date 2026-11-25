<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class ComparisonTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    /** Where the Comparison layout's main content block carries the comparison. */
    private const BLOCK = 'storefront.regions.content.0.blocks.0.content.comparison.';

    private CategoryRecord $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        Storage::fake('local');
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        app()->setLocale('en');
        $this->category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $this->category->translations()->create(['locale_id' => $this->locale(), 'name' => 'Laptops', 'slug' => 'laptops']);
    }

    private function locale(): int
    {
        return (int) DB::table('locales')->where('code', 'en')->value('id');
    }

    private function product(string $status = 'active'): ProductRecord
    {
        $p = ProductRecord::create(['sku' => 'CMP-'.Str::uuid(), 'price' => '100', 'quantity' => 5, 'category_id' => $this->category->id, 'status' => $status]);
        $p->translations()->create(['locale_id' => $this->locale(), 'name' => 'Laptop '.$p->id, 'slug' => 'laptop-'.$p->id]);

        return $p;
    }

    /** A single-choice feature with named options; returns [feature id, option ids]. */
    private function feature(string $name, array $labels): array
    {
        $group = DB::table('feature_groups')->orderBy('id')->value('id');
        $id = (int) DB::table('features')->insertGetId(['code' => Str::slug($name), 'type' => 'single', 'status' => 'active', 'group_id' => $group, 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('feature_translations')->insert(['feature_id' => $id, 'locale_id' => $this->locale(), 'name' => $name]);
        $options = [];
        foreach ($labels as $n => $label) {
            $option = (int) DB::table('feature_options')->insertGetId(['feature_id' => $id, 'position' => $n + 1]);
            DB::table('feature_option_translations')->insert(['option_id' => $option, 'locale_id' => $this->locale(), 'label' => $label]);
            $options[] = $option;
        }

        return [$id, $options];
    }

    private function choose(ProductRecord $p, int $feature, int $option): void
    {
        $assignment = DB::table('product_feature_assignments')->insertGetId(['product_id' => $p->id, 'feature_id' => $feature, 'type' => 'single']);
        DB::table('product_feature_choices')->insert(['assignment_id' => $assignment, 'feature_id' => $feature, 'option_id' => $option, 'type' => 'single']);
    }

    public function test_guests_compare_products_up_to_the_store_limit_and_see_similar_and_different_features(): void
    {
        [$a, $b, $c] = [$this->product(), $this->product(), $this->product()];
        [$color, [$black, $silver]] = $this->feature('Colour', ['Black', 'Silver']);
        [$screen, [$fourteen]] = $this->feature('Screen', ['14 in']);
        $this->choose($a, $color, $black);
        $this->choose($b, $color, $silver);
        $this->choose($a, $screen, $fourteen);
        $this->choose($b, $screen, $fourteen);

        // Nobody is signed in: the list lives in the visitor's session.
        $this->putJson('/comparison/products/'.$a->id)->assertOk()->assertJsonPath('ids', [(string) $a->id])->assertJsonPath('max', 4);
        $this->putJson('/comparison/products/'.$a->id)->assertOk()->assertJsonCount(1, 'ids');
        $this->putJson('/comparison/products/'.$b->id)->assertOk()->assertJsonCount(2, 'ids');

        $this->get('/comparison')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Storefront/Comparison')->has(self::BLOCK.'items', 2)
            ->where(self::BLOCK.'items.0.id', (string) $a->id)->where(self::BLOCK.'max', 4)
            ->where(self::BLOCK.'groups.0.rows.0.name', 'Colour')->where(self::BLOCK.'groups.0.rows.0.values', ['Black', 'Silver'])->where(self::BLOCK.'groups.0.rows.0.same', false)
            ->where(self::BLOCK.'groups.0.rows.1.name', 'Screen')->where(self::BLOCK.'groups.0.rows.1.values', ['14 in', '14 in'])->where(self::BLOCK.'groups.0.rows.1.same', true));

        // A feature only one product has is a difference; the missing value stays empty.
        [$ram, [$sixteen]] = $this->feature('Memory', ['16 GB']);
        $this->choose($a, $ram, $sixteen);
        $this->get('/comparison')->assertInertia(fn (Assert $page) => $page->where(self::BLOCK.'groups.0.rows.2.name', 'Memory')->where(self::BLOCK.'groups.0.rows.2.values', ['16 GB', null])->where(self::BLOCK.'groups.0.rows.2.same', false));

        // The limit comes from the store settings and is refused with a message, never silently.
        DB::table('general_settings')->update(['comparison_max_products' => 2]);
        $this->putJson('/comparison/products/'.$c->id)->assertUnprocessable()->assertJsonValidationErrors('product');
        DB::table('general_settings')->update(['comparison_max_products' => 3]);
        $this->putJson('/comparison/products/'.$c->id)->assertOk()->assertJsonCount(3, 'ids');

        // Removing one product and clearing the list.
        $this->deleteJson('/comparison/products/'.$a->id)->assertOk()->assertJsonCount(2, 'ids');
        $this->deleteJson('/comparison')->assertOk()->assertJsonPath('ids', []);
        $this->get('/comparison')->assertOk()->assertInertia(fn (Assert $page) => $page->has(self::BLOCK.'items', 0)->has(self::BLOCK.'groups', 0));
    }

    public function test_only_visible_products_can_be_compared_and_hidden_ones_leave_the_list(): void
    {
        $draft = $this->product('disabled');
        $this->putJson('/comparison/products/'.$draft->id)->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->putJson('/comparison/products/999999')->assertUnprocessable();
        $a = $this->product();
        $b = $this->product();
        $this->putJson('/comparison/products/'.$a->id)->assertOk();
        $this->putJson('/comparison/products/'.$b->id)->assertOk();
        DB::table('products')->where('id', $a->id)->update(['status' => 'disabled']);
        $this->get('/comparison')->assertInertia(fn (Assert $page) => $page->has(self::BLOCK.'items', 1)->where(self::BLOCK.'items.0.id', (string) $b->id));
        $this->putJson('/comparison/products/'.$b->id)->assertOk()->assertJsonPath('ids', [(string) $b->id]);
    }

    public function test_the_setting_switches_comparison_off_everywhere(): void
    {
        $p = $this->product();
        $this->putJson('/comparison/products/'.$p->id)->assertOk();
        DB::table('general_settings')->update(['allow_product_comparison' => false]);
        $this->get('/comparison')->assertNotFound();
        $this->putJson('/comparison/products/'.$p->id)->assertNotFound();
        $this->deleteJson('/comparison/products/'.$p->id)->assertNotFound();
        $this->deleteJson('/comparison')->assertNotFound();
        // The product page then has no comparison button; with the setting on it carries the visitor's list.
        $this->get('/laptops/laptop-'.$p->id)->assertInertia(fn (Assert $page) => $page->where('storefront.regions.content.0.blocks.0.content.product.comparison', null));
        DB::table('general_settings')->update(['allow_product_comparison' => true]);
        $this->get('/laptops/laptop-'.$p->id)->assertInertia(fn (Assert $page) => $page->where('storefront.regions.content.0.blocks.0.content.product.comparison.in_list', true)->where('storefront.regions.content.0.blocks.0.content.product.comparison.max', 4));
    }

    public function test_the_comparison_page_is_a_private_page_with_its_own_layout(): void
    {
        self::assertTrue(DB::table('storefront_layouts')->where('page_type', 'comparison')->exists());
        $response = $this->get('/comparison')->assertOk();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        // The limit is part of the General settings (2 to 10).
        $this->actingAs($this->staff(super: true));
        $this->get('/admin/settings/general?tab=catalog')->assertInertia(fn (Assert $page) => $page->where('settings.comparison_max_products', 4));
    }
}
