<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\SaveProductData;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\SimilarProducts;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Storefront\Infrastructure\ProductRecommendations;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class ProductRecommendationsTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        Storage::fake('local');
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    private function locale(string $code = 'en'): int
    {
        return (int) DB::table('locales')->where('code', $code)->value('id');
    }

    private function category(string $name, ?CategoryRecord $parent = null): CategoryRecord
    {
        $c = CategoryRecord::create(['parent_id' => $parent?->id, 'position' => 1, 'status' => 'active']);
        $c->translations()->create(['locale_id' => $this->locale(), 'name' => $name, 'slug' => Str::slug($name).'-'.$c->id]);

        return $c;
    }

    private function product(CategoryRecord $category, string $price = '100', string $status = 'active', string $type = 'physical'): ProductRecord
    {
        $p = ProductRecord::create(['sku' => 'REC-'.Str::uuid(), 'price' => $price, 'quantity' => 10, 'category_id' => $category->id, 'status' => $status, 'type' => $type]);
        $p->translations()->create(['locale_id' => $this->locale(), 'name' => 'Product '.$p->id, 'slug' => 'product-'.$p->id]);

        return $p;
    }

    /** A single-choice feature with options; returns [feature id, option ids]. */
    private function feature(string $code, int $options = 2): array
    {
        $group = DB::table('feature_groups')->orderBy('id')->value('id');
        $id = (int) DB::table('features')->insertGetId(['code' => $code, 'type' => 'single', 'status' => 'active', 'group_id' => $group, 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $ids = [];
        for ($n = 1; $n <= $options; $n++) {
            $ids[] = (int) DB::table('feature_options')->insertGetId(['feature_id' => $id, 'position' => $n]);
        }

        return [$id, $ids];
    }

    private function choose(ProductRecord $p, int $feature, int $option): void
    {
        $assignment = DB::table('product_feature_assignments')->insertGetId(['product_id' => $p->id, 'feature_id' => $feature, 'type' => 'single']);
        DB::table('product_feature_choices')->insert(['assignment_id' => $assignment, 'feature_id' => $feature, 'option_id' => $option, 'type' => 'single']);
    }

    /** @param array<string, mixed> $changes */
    private function save(ProductRecord $p, array $changes = []): void
    {
        $fields = ['sku' => $p->sku ?? 'SKU-'.$p->id, 'type' => $p->type, 'status' => $p->status, 'price' => '10', 'category_id' => (string) $p->category_id, 'translations' => ['en' => ['name' => 'Product '.$p->id]]];
        app(ProductAdministration::class)->save((string) $this->actor->id, (string) $p->id, new SaveProductData($p->fresh()->lock_version, array_replace($fields, $changes)));
    }

    private function context(string $locale = 'en'): array
    {
        return ['locale' => $locale, 'customer' => null];
    }

    public function test_short_descriptions_are_translated_sanitized_optional_copied_and_fall_back_to_the_default_language(): void
    {
        $leaf = $this->category('Phones');
        $p = $this->product($leaf);
        $this->save($p, ['translations' => ['en' => ['name' => 'Phone', 'short_description' => '<p>Fast <script>alert(1)</script>phone</p>'], 'el' => ['name' => 'Κινητό', 'short_description' => '<p>Γρήγορο</p>']]]);
        $texts = DB::table('product_translations')->where('product_id', $p->id)->pluck('short_description', 'locale_id');
        self::assertSame('<p>Fast phone</p>', $texts[$this->locale()]);
        self::assertSame('<p>Γρήγορο</p>', $texts[$this->locale('el')]);
        $this->get('/admin/products/'.$p->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.translations.en.short_description', '<p>Fast phone</p>')->where('product.similar_products', true)->where('product.companion_ids', []));
        // Optional: clearing it is allowed; an over-long text is refused.
        $this->save($p, ['translations' => ['en' => ['name' => 'Phone', 'short_description' => ''], 'el' => ['name' => 'Κινητό', 'short_description' => '']]]);
        self::assertSame('', DB::table('product_translations')->where('product_id', $p->id)->where('locale_id', $this->locale())->value('short_description'));
        try {
            $this->save($p, ['translations' => ['en' => ['name' => 'Phone', 'short_description' => str_repeat('a', 5001)]]]);
            self::fail('Expected a length error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('translations.en.short_description', $e->errors());
        }
        // Duplicates keep it; the storefront card falls back to the default language when a language has none.
        $this->save($p, ['translations' => ['en' => ['name' => 'Phone', 'short_description' => '<p>Fast phone</p>']]]);
        $copy = app(ProductAdministration::class)->duplicate((string) $this->actor->id, (string) $p->id, $p->fresh()->lock_version);
        self::assertSame('<p>Fast phone</p>', DB::table('product_translations')->where('product_id', $copy)->where('locale_id', $this->locale())->value('short_description'));
        $other = $this->product($leaf);
        $this->save($other, ['companion_ids' => [$p->id]]);
        $section = app(ProductRecommendations::class)->boughtTogether((string) $other->id, $this->context('el'));
        self::assertSame('<p>Fast phone</p>', $section['items'][1]['short_description']);
    }

    public function test_similar_products_come_only_from_the_same_category_ranked_by_shared_features(): void
    {
        $phones = $this->category('Phones & tablets');
        $smart = $this->category('Smartphones', $phones);
        $tablets = $this->category('Tablets', $phones);
        $tv = $this->category('Televisions');
        [$brand, [$apple, $samsung]] = $this->feature('brand');
        $main = $this->product($smart, '900');
        $this->choose($main, $brand, $apple);
        $plain = $this->product($smart, '800');
        $sameBrand = $this->product($smart, '1500');
        $this->choose($sameBrand, $brand, $apple);
        $otherBrand = $this->product($smart, '901');
        $this->choose($otherBrand, $brand, $samsung);
        // Neighbouring categories and other areas never appear, however many features they share.
        $sibling = $this->product($tablets, '700');
        $this->choose($sibling, $brand, $apple);
        $otherAreaSameBrand = $this->product($tv, '900');
        $this->choose($otherAreaSameBrand, $brand, $apple);

        $ids = app(SimilarProducts::class)->ids((string) $main->id);
        // The shared brand ranks first even though it is the furthest in price; then the closest price.
        self::assertSame([(string) $sameBrand->id, (string) $otherBrand->id, (string) $plain->id], $ids);
        self::assertNotContains((string) $sibling->id, $ids);
        self::assertNotContains((string) $otherAreaSameBrand->id, $ids);
        // A product alone in its category gets none rather than filler from elsewhere.
        $lonely = $this->product($this->category('Garden'));
        self::assertSame([], app(SimilarProducts::class)->ids((string) $lonely->id));
        self::assertNull(app(ProductRecommendations::class)->similar((string) $lonely->id, $this->context()));
        // The merchant can switch them off for a product.
        $this->save($main, ['similar_products' => false]);
        self::assertSame([], app(SimilarProducts::class)->ids((string) $main->id));
        $this->get('/admin/products/'.$main->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.similar_products', false));
    }

    public function test_similar_products_exclude_unavailable_products_follow_changes_and_stop_at_twelve(): void
    {
        $leaf = $this->category('Laptops');
        $main = $this->product($leaf);
        $products = [];
        for ($n = 0; $n < 15; $n++) {
            $products[] = $this->product($leaf, (string) (100 + $n));
        }
        $ids = app(SimilarProducts::class)->ids((string) $main->id);
        self::assertCount(12, $ids);
        DB::table('products')->where('id', $products[0]->id)->update(['status' => 'disabled']);
        DB::table('products')->where('id', $products[1]->id)->update(['status' => 'hidden']);
        DB::table('products')->where('id', $products[2]->id)->update(['deleted_at' => now()]);
        $customers = DB::table('customer_groups')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_customer_groups')->insert(['product_id' => $products[3]->id, 'group_id' => $customers]);
        $ids = app(SimilarProducts::class)->ids((string) $main->id, null, 50);
        self::assertCount(11, $ids);
        foreach (array_slice($products, 0, 4) as $gone) {
            self::assertNotContains((string) $gone->id, $ids);
        }
        // Closest price first among equals (the main product costs 100).
        self::assertSame((string) $products[4]->id, $ids[0]);
        $cards = app(ProductRecommendations::class)->similar((string) $main->id, $this->context());
        self::assertCount(11, $cards['items']);
        foreach (['id', 'name', 'sku', 'short_description', 'url', 'rating'] as $key) {
            self::assertArrayHasKey($key, $cards['items'][0]);
        }
    }

    public function test_similar_products_use_a_constant_number_of_queries_whatever_the_catalog_size(): void
    {
        $leaf = $this->category('Cameras');
        [$feature, [$option]] = $this->feature('sensor', 1);
        $main = $this->product($leaf);
        $this->choose($main, $feature, $option);
        $count = function () use ($main): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(SimilarProducts::class)->ids((string) $main->id);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        for ($n = 0; $n < 3; $n++) {
            $this->choose($this->product($leaf), $feature, $option);
        }
        $small = $count();
        for ($n = 0; $n < 30; $n++) {
            $this->choose($this->product($leaf), $feature, $option);
        }
        // One product read, the visibility scope's settings and the single scoring query: no per-candidate queries.
        self::assertSame($small, $count());
        // One additional batched read checks opt-in variation listing groups.
        self::assertLessThanOrEqual(9, $small);
    }

    public function test_frequently_bought_together_saves_up_to_three_valid_companions_in_order(): void
    {
        $leaf = $this->category('Phones');
        $main = $this->product($leaf, '900');
        [$case, $glass, $charger, $cable] = [$this->product($leaf, '20'), $this->product($leaf, '15'), $this->product($leaf, '30'), $this->product($leaf, '5')];
        $this->save($main, ['companions_submitted' => true, 'companion_ids' => [2 => $glass->id, 7 => $case->id]]);
        self::assertSame([1, 2], DB::table('product_companions')->where('product_id', $main->id)->orderBy('position')->pluck('position')->all());
        self::assertSame([(string) $glass->id, (string) $case->id], DB::table('product_companions')->where('product_id', $main->id)->orderBy('position')->pluck('companion_id')->map(strval(...))->all());
        $this->get('/admin/products/'.$main->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.companion_ids', [(string) $glass->id, (string) $case->id])
            ->where('companions.0.id', (string) $glass->id)->where('companions.0.sku', $glass->sku)->has('companions.0.price')->where('companions.1.status', 'active'));
        $this->get('/admin/products/'.$main->id.'/edit?kind=companions&search='.urlencode($cable->sku))->assertInertia(fn (Assert $page) => $page->where('companionOptions.0.id', (string) $cable->id));
        foreach ([
            [[$case->id, $glass->id, $charger->id, $cable->id], 'companions_max'],
            [[$case->id, $case->id], 'companions_duplicate'],
            [[$main->id], 'companions_self'],
        ] as [$ids, $message]) {
            try {
                $this->save($main, ['companion_ids' => $ids]);
                self::fail('Expected '.$message);
            } catch (ValidationException $e) {
                self::assertSame(__('products.'.$message, ['max' => 3]), $e->errors()['companion_ids'][0]);
            }
        }
        // New choices must be live products; a saved companion that was disabled later is kept.
        DB::table('products')->where('id', $charger->id)->update(['status' => 'disabled']);
        $this->expectValidation(fn () => $this->save($main, ['companion_ids' => [$glass->id, $charger->id]]), 'companion_ids');
        DB::table('products')->where('id', $case->id)->update(['status' => 'disabled']);
        $this->save($main, ['companion_ids' => [$glass->id, $case->id, $cable->id]]);
        self::assertSame(3, DB::table('product_companions')->where('product_id', $main->id)->count());
        // The duplicate keeps the companions; an empty submission clears them.
        $copy = app(ProductAdministration::class)->duplicate((string) $this->actor->id, (string) $main->id, $main->fresh()->lock_version);
        self::assertSame(3, DB::table('product_companions')->where('product_id', $copy)->count());
        $this->save($main, ['companions_submitted' => true, 'companion_ids' => []]);
        $this->assertDatabaseMissing('product_companions', ['product_id' => $main->id]);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'product_companion']);
    }

    public function test_the_bought_together_section_shows_live_prices_and_leaves_unavailable_products_out_of_the_total(): void
    {
        $leaf = $this->category('Phones');
        $main = $this->product($leaf, '900');
        self::assertNull(app(ProductRecommendations::class)->boughtTogether((string) $main->id, $this->context()));
        $case = $this->product($leaf, '20');
        $this->save($main, ['companion_ids' => [$case->id]]);
        DB::table('products')->where('id', $main->id)->update(['price' => '900']);
        $one = app(ProductRecommendations::class)->boughtTogether((string) $main->id, $this->context());
        self::assertSame([true, false], array_column($one['items'], 'main'));
        self::assertSame('920.00', $one['total']);
        self::assertSame(2, $one['included']);
        $glass = $this->product($leaf, '15');
        $cable = $this->product($leaf, '5');
        $this->save($main, ['companion_ids' => [$case->id, $glass->id, $cable->id]]);
        DB::table('products')->where('id', $main->id)->update(['price' => '900']);
        // Prices are read live: a price change shows up without saving the main product.
        DB::table('products')->where('id', $glass->id)->update(['price' => '25']);
        self::assertSame('950.00', app(ProductRecommendations::class)->boughtTogether((string) $main->id, $this->context())['total']);
        // Out of stock: still shown, not included. Disabled or deleted: not shown at all. The bundle keeps working.
        DB::table('products')->where('id', $case->id)->update(['quantity' => 0]);
        DB::table('general_settings')->update(['track_inventory' => true]);
        DB::table('products')->where('id', $cable->id)->update(['deleted_at' => now()]);
        Cache::flush();
        $section = app(ProductRecommendations::class)->boughtTogether((string) $main->id, $this->context());
        self::assertSame([(string) $main->id, (string) $case->id, (string) $glass->id], array_column($section['items'], 'id'));
        self::assertSame([true, false, true], array_column($section['items'], 'included'));
        self::assertSame('925.00', $section['total']);
        self::assertSame(1, $section['items'][0]['quantity']);
        // With every companion gone there is no section.
        DB::table('products')->whereIn('id', [$case->id, $glass->id])->update(['status' => 'disabled']);
        self::assertNull(app(ProductRecommendations::class)->boughtTogether((string) $main->id, $this->context()));
        // Permanently deleting a companion removes the link through its key.
        DB::table('products')->where('id', $cable->id)->delete();
        self::assertSame(2, DB::table('product_companions')->where('product_id', $main->id)->count());
    }

    public function test_the_database_refuses_self_references_duplicates_and_a_fourth_position(): void
    {
        $leaf = $this->category('Phones');
        [$a, $b] = [$this->product($leaf), $this->product($leaf)];
        foreach ([
            ['product_id' => $a->id, 'companion_id' => $a->id, 'position' => 1],
            ['product_id' => $a->id, 'companion_id' => $b->id, 'position' => 4],
        ] as $row) {
            try {
                DB::transaction(fn () => DB::table('product_companions')->insert($row));
                self::fail('Expected a constraint error');
            } catch (QueryException $e) {
                self::assertSame('23514', $e->getCode());
            }
        }
        DB::table('product_companions')->insert(['product_id' => $a->id, 'companion_id' => $b->id, 'position' => 1]);
        $this->expectException(QueryException::class);
        DB::table('product_companions')->insert(['product_id' => $a->id, 'companion_id' => $b->id, 'position' => 2]);
    }

    private function expectValidation(callable $operation, string $field): void
    {
        try {
            $operation();
            self::fail('Expected validation error '.$field);
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors());
        }
    }
}
