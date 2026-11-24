<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Application\Command\CatalogProduct;
use App\Storefront\Application\Command\CatalogQuery;
use App\Storefront\Application\Contract\CatalogProductSelection;
use App\Storefront\Application\Contract\CatalogUnavailable;
use App\Storefront\Application\Contract\StorefrontCatalog;
use App\Storefront\Infrastructure\GuestStorefrontCatalog;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use ReflectionClass;
use Tests\Concerns\GeneratesAddons;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class StorefrontCatalogTest extends TestCase
{
    use GeneratesAddons, RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        app()->setLocale('en');
        $this->settings(['storefront_open' => true]);
    }

    protected function tearDown(): void
    {
        $this->removeGeneratedAddons();
        parent::tearDown();
    }

    /** @param array<string, mixed> $values */
    private function settings(array $values): void
    {
        DB::table('general_settings')->update($values);
        app()->forgetInstance(GeneralSettings::class);
    }

    private function catalog(): StorefrontCatalog
    {
        return app(StorefrontCatalog::class);
    }

    private function locale(string $code = 'en'): int
    {
        return (int) DB::table('locales')->where('code', $code)->value('id');
    }

    /** @param array<string, mixed> $attributes */
    private function category(string $slug, ?CategoryRecord $parent = null, array $attributes = []): CategoryRecord
    {
        $c = CategoryRecord::create(['parent_id' => $parent?->id, 'position' => 1, 'status' => 'active']);
        $c->forceFill($attributes)->save();
        $c->translations()->create(['locale_id' => $this->locale(), 'name' => ucfirst($slug), 'slug' => $slug]);

        return $c;
    }

    /** @param array<string, mixed> $attributes */
    private function product(CategoryRecord $category, string $slug, array $attributes = []): ProductRecord
    {
        $p = ProductRecord::create(['sku' => 'CAT-'.Str::upper(Str::random(6)), 'price' => '100', 'quantity' => 5, 'category_id' => $category->id, 'status' => 'active']);
        $p->translations()->create(['locale_id' => $this->locale(), 'name' => Str::headline($slug), 'slug' => $slug]);
        if ($attributes !== []) {
            DB::table('products')->where('id', $p->id)->update($attributes);
        }

        return $p->refresh();
    }

    public function test_batch_selection_preserves_requested_order_public_visibility_and_current_prices(): void
    {
        $category = $this->category('feed-shoes');
        $one = $this->product($category, 'one');
        $two = $this->product($category, 'two', ['price' => '89.90']);
        $hidden = $this->product($category, 'hidden', ['status' => 'hidden']);
        $products = $this->catalog()->productsById([(string) $two->id, (string) $hidden->id, (string) $one->id, (string) $two->id, 'bad-id']);
        self::assertSame([(string) $two->id, (string) $one->id], array_column($products, 'id'));
        self::assertSame('89.90', $products[0]->pricing->current());
        self::assertNull($products[0]->rating);
        self::assertTrue($products[0]->availability->purchasable);
        self::assertSame([], $this->catalog()->productsById([]));
        $this->expectException(InvalidArgumentException::class);
        $this->catalog()->productsById(array_fill(0, 101, (string) $one->id));
    }

    /** @return list<string> Product names a browse finds, sorted */
    private function names(CatalogQuery $query = new CatalogQuery(perPage: 100)): array
    {
        $names = array_map(fn (CatalogProduct $p): string => $p->name, $this->catalog()->searchProducts($query)->products);
        sort($names);

        return $names;
    }

    /** @param array<string, mixed> $values */
    private function sale(ProductRecord $product, array $values = []): void
    {
        DB::table('product_sales')->insert(['product_id' => $product->id, 'kind' => 'fixed', 'amount' => '80', 'minimum_quantity' => 1, 'priority' => 1, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now(), ...$values]);
    }

    /** @return array<string, mixed> What the real product page shows a guest. */
    private function storefront(string $url): array
    {
        $this->get($url)->assertOk()->assertInertia(function (Assert $page) use (&$found): void {
            foreach ($page->toArray()['props']['storefront']['regions']['content'] ?? [] as $grid) {
                foreach ($grid['blocks'] as $block) {
                    if ($block['template'] === 'main_content') {
                        $found = $block['content']['product'];
                    }
                }
            }
        });

        return $found;
    }

    public function test_the_contract_is_published_through_the_container(): void
    {
        self::assertInstanceOf(GuestStorefrontCatalog::class, $this->catalog());
        $status = $this->catalog()->status();
        self::assertTrue($status->open);
        self::assertSame('EUR', $status->currency);
        self::assertSame(2, $status->decimals);
        self::assertTrue($status->pricesVisible);
    }

    public function test_a_normal_addon_consumes_the_catalog_through_the_published_contract_only(): void
    {
        $phones = $this->category('phones');
        $this->product($phones, 'public-phone');
        $this->product($phones, 'hidden-phone', ['status' => 'hidden']);
        $reader = <<<'PHP'
<?php

namespace {ns};

use App\Storefront\Application\Command\CatalogProduct;
use App\Storefront\Application\Command\CatalogQuery;
use App\Storefront\Application\Contract\StorefrontCatalog;

final class Reader
{
    public function __construct(private StorefrontCatalog $catalog) {}

    public function names(): array
    {
        return array_map(fn (CatalogProduct $p): string => $p->name, $this->catalog->searchProducts(new CatalogQuery)->products);
    }
}
PHP;
        $provider = "<?php\n\nnamespace {ns};\n\nfinal class Provider extends \\Illuminate\\Support\\ServiceProvider {}\n";
        $namespace = $this->installAddon('acme/catalog-reader', ['src/Reader.php' => $reader, 'src/Provider.php' => $provider]);

        self::assertSame(['Public Phone'], app($namespace.'\\Reader')->names());
    }

    public function test_products_are_paged(): void
    {
        $phones = $this->category('phones');
        foreach (['a', 'b', 'c', 'd', 'e'] as $slug) {
            $this->product($phones, 'item-'.$slug);
        }

        $first = $this->catalog()->searchProducts(new CatalogQuery(page: 1, perPage: 2));
        self::assertCount(2, $first->products);
        self::assertSame(5, $first->total);
        self::assertTrue($first->hasMore);
        $last = $this->catalog()->searchProducts(new CatalogQuery(page: 3, perPage: 2));
        self::assertCount(1, $last->products);
        self::assertFalse($last->hasMore);
        $exact = $this->catalog()->searchProducts(new CatalogQuery(page: 1, perPage: 5));
        self::assertFalse($exact->hasMore);
        $past = $this->catalog()->searchProducts(new CatalogQuery(page: 9, perPage: 2));
        self::assertSame([], $past->products);
        self::assertSame(5, $past->total);
        self::assertFalse($past->hasMore);
    }

    public function test_browsing_reports_an_exact_total(): void
    {
        $this->product($this->category('phones'), 'aster');

        self::assertTrue($this->catalog()->searchProducts(new CatalogQuery)->totalExact);
    }

    public function test_read_queries_do_not_grow_with_the_page(): void
    {
        $phones = $this->category('phones');
        foreach (range(1, 12) as $n) {
            $this->product($phones, 'item-'.$n);
        }
        $count = function (int $perPage): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $found = $this->catalog()->searchProducts(new CatalogQuery(perPage: $perPage))->products;
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            self::assertCount($perPage, $found);

            return $queries;
        };

        $count(1);
        self::assertSame($count(2), $count(12));
    }

    public function test_a_product_carries_its_public_details_in_plain_text_with_absolute_addresses(): void
    {
        $phones = $this->category('phones');
        $p = $this->product($phones, 'aster-one');
        DB::table('product_translations')->where('product_id', $p->id)->update(['description' => '<p>Fast &amp; light</p><p>Second</p>']);

        $found = $this->catalog()->product((string) $p->id);

        self::assertSame('Aster One', $found->name);
        self::assertSame('Fast & light Second', $found->description);
        self::assertSame(url('/phones/aster-one'), $found->url);
        self::assertSame($p->sku, $found->sku);
        self::assertSame((string) $phones->id, $found->category->id);
        self::assertSame('Phones', $found->category->name);
    }

    public function test_a_product_carries_its_ean_short_description_and_weight_in_grams(): void
    {
        $this->settings(['grams_per_weight_unit' => '453.59237']);
        $p = $this->product($this->category('phones'), 'aster-one', ['ean' => '4006381333931', 'weight' => '2.5']);
        DB::table('product_translations')->where('product_id', $p->id)->update(['short_description' => '<p>Small &amp; light</p>']);

        $found = $this->catalog()->product((string) $p->id);

        self::assertSame('4006381333931', $found->gtin);
        self::assertSame('Small & light', $found->shortDescription);
        self::assertSame('Small & light', $found->description, 'falls back to the short description');
        self::assertSame('1133.980925', $found->weightGrams);
        self::assertNull($this->catalog()->product((string) $this->product($this->category('tablets'), 'bare')->id)->weightGrams);
    }

    public function test_the_category_tree_and_category_queries(): void
    {
        $clothing = $this->category('clothing');
        $tops = $this->category('tops', $clothing);
        $shirts = $this->category('shirts', $tops);
        $socks = $this->category('socks', $clothing);
        $this->category('empty');
        $this->product($shirts, 'linen-shirt');
        $this->product($socks, 'wool-socks');

        $tree = collect($this->catalog()->categories())->keyBy('id');
        self::assertNull($tree[$clothing->id]->parentId);
        self::assertSame((string) $clothing->id, $tree[$tops->id]->parentId);
        self::assertSame((string) $tops->id, $tree[$shirts->id]->parentId);
        self::assertSame(url('/clothing/tops/shirts'), $tree[$shirts->id]->url);
        self::assertArrayNotHasKey(DB::table('categories')->whereNotNull('system_key')->value('id'), $tree->all());

        self::assertSame(['Linen Shirt'], $this->names(new CatalogQuery(categoryId: (string) $shirts->id)), 'exact leaf');
        self::assertSame([], $this->names(new CatalogQuery(categoryId: (string) $clothing->id)), 'exact parent: products sit on leaves');
        self::assertSame(['Linen Shirt'], $this->names(new CatalogQuery(categoryId: (string) $tops->id, includeDescendants: true)), 'parent');
        self::assertSame(['Linen Shirt', 'Wool Socks'], $this->names(new CatalogQuery(categoryId: (string) $clothing->id, includeDescendants: true)), 'two levels');
        self::assertSame(['Linen Shirt'], $this->names(new CatalogQuery(categoryId: (string) $shirts->id, includeDescendants: true)), 'a leaf has only itself');
        self::assertSame([], $this->names(new CatalogQuery(categoryId: '999999')), 'unknown');
    }

    public function test_only_publicly_discoverable_products_exist_for_search_or_by_id(): void
    {
        $phones = $this->category('phones');
        $visible = $this->product($phones, 'visible');
        $disabledParent = $this->category('off');
        $disabledChild = $this->category('off-child', $disabledParent);
        $excluded = $this->category('quiet', null, ['exclude_from_discovery' => true]);
        $excludedChild = $this->category('quiet-child', $excluded);
        $adult = $this->category('adult', null, ['age_verification' => true, 'age_limit' => 18]);
        $adultChild = $this->category('adult-child', $adult);
        $restrictedCategory = $this->category('members');
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('category_customer_groups')->insert(['category_id' => $restrictedCategory->id, 'group_id' => $group]);
        $blocked = [
            'disabled' => $this->product($phones, 'disabled', ['status' => 'disabled']),
            'hidden' => $this->product($phones, 'hidden', ['status' => 'hidden']),
            'trashed' => $this->product($phones, 'trashed', ['deleted_at' => now()]),
            'incomplete' => $this->product($phones, 'incomplete', ['sku' => null]),
            'restricted' => $this->product($phones, 'restricted'),
            'restricted category' => $this->product($restrictedCategory, 'in-members'),
            'disabled ancestor' => $this->product($disabledChild, 'under-off'),
            'excluded category' => $this->product($excluded, 'in-quiet'),
            'under excluded category' => $this->product($excludedChild, 'under-quiet'),
            'age checked' => $this->product($adult, 'in-adult'),
            'under age check' => $this->product($adultChild, 'under-adult'),
        ];
        DB::table('product_customer_groups')->insert(['product_id' => $blocked['restricted']->id, 'group_id' => $group]);
        $disabledParent->forceFill(['status' => 'disabled'])->save();

        self::assertSame(['Visible'], $this->names());
        self::assertNotNull($this->catalog()->product((string) $visible->id));
        foreach ($blocked as $case => $product) {
            self::assertNull($this->catalog()->product((string) $product->id), $case);
        }
        self::assertNull($this->catalog()->product('0'));
        self::assertNull($this->catalog()->product('not-a-number'));
        self::assertNull($this->catalog()->product((string) $visible->sku), 'an SKU is not an ID');
        $ids = array_map(fn ($c) => $c->id, $this->catalog()->categories());
        self::assertContains((string) $phones->id, $ids);
        foreach ([$excluded, $excludedChild, $adult, $adultChild, $restrictedCategory, $disabledParent, $disabledChild] as $hidden) {
            self::assertNotContains((string) $hidden->id, $ids);
        }
    }

    public function test_a_product_page_visitors_can_open_still_needs_to_be_listed_here(): void
    {
        $quiet = $this->category('quiet', null, ['exclude_from_discovery' => true]);
        $p = $this->product($quiet, 'quiet-item');

        $this->get('/quiet/quiet-item')->assertOk();
        self::assertNull($this->catalog()->product((string) $p->id));
    }

    public function test_out_of_stock_products_stay_visible_unless_asked_or_the_store_hides_them(): void
    {
        $phones = $this->category('phones');
        $this->product($phones, 'stocked');
        $empty = $this->product($phones, 'empty', ['quantity' => 0]);

        self::assertSame(['Empty', 'Stocked'], $this->names());
        $found = $this->catalog()->product((string) $empty->id);
        self::assertSame('out_of_stock', $found->availability->status);
        self::assertFalse($found->availability->purchasable);
        self::assertSame(['Stocked'], $this->names(new CatalogQuery(inStockOnly: true)));

        $this->settings(['track_inventory' => false]);
        self::assertSame(['Empty', 'Stocked'], $this->names(new CatalogQuery(inStockOnly: true)), 'without tracking everything is in stock');
        self::assertSame('in_stock', $this->catalog()->product((string) $empty->id)->availability->status);

        $this->settings(['track_inventory' => true, 'hide_out_of_stock_listings' => true]);
        self::assertSame(['Stocked'], $this->names());
        self::assertNull($this->catalog()->product((string) $empty->id));
    }

    public function test_availability_states(): void
    {
        $phones = $this->category('phones');
        $stocked = $this->product($phones, 'stocked');
        $backorder = $this->product($phones, 'backorder', ['quantity' => 0, 'out_of_stock_actions' => 'B']);
        $upcoming = $this->product($phones, 'upcoming', ['available_from' => now()->addDays(3)->toDateString()]);
        $released = $this->product($phones, 'released', ['available_from' => now()->subDay()->toDateString()]);

        $state = $this->catalog()->product((string) $stocked->id)->availability;
        self::assertSame('in_stock', $state->status);
        self::assertTrue($state->purchasable);
        self::assertNotSame('', $state->label);
        $state = $this->catalog()->product((string) $backorder->id)->availability;
        self::assertSame('backorder', $state->status);
        self::assertTrue($state->purchasable);
        $state = $this->catalog()->product((string) $upcoming->id)->availability;
        self::assertSame('upcoming', $state->status);
        self::assertSame(now()->addDays(3)->toDateString(), $state->availableFrom);
        self::assertFalse($state->purchasable);
        self::assertSame(['Backorder', 'Released', 'Stocked', 'Upcoming'], $this->names());

        // Time passing alone releases it; nothing is stored.
        $this->travelTo(now()->addDays(4));
        $state = $this->catalog()->product((string) $upcoming->id)->availability;
        self::assertSame('in_stock', $state->status);
        self::assertNull($state->availableFrom);
        self::assertTrue($state->purchasable);
        self::assertSame('in_stock', $this->catalog()->product((string) $released->id)->availability->status);
    }

    public function test_a_closed_store_refuses_reads_instead_of_answering_empty(): void
    {
        $p = $this->product($this->category('phones'), 'aster');
        $this->settings(['storefront_open' => false]);

        self::assertFalse($this->catalog()->status()->open);
        $reads = [fn () => $this->catalog()->searchProducts(new CatalogQuery), fn () => $this->catalog()->product((string) $p->id), fn () => $this->catalog()->categories(), fn () => $this->catalog()->pages()];
        foreach ($reads as $read) {
            try {
                $read();
                self::fail('Expected the closed store to refuse');
            } catch (CatalogUnavailable $e) {
                self::assertSame('closed', $e->reason);
            }
        }
    }

    public function test_hidden_prices_stay_hidden(): void
    {
        $phones = $this->category('phones');
        $p = $this->product($phones, 'aster', ['list_price' => '150']);
        $this->sale($p);
        $this->settings(['hide_product_prices' => true]);

        self::assertFalse($this->catalog()->status()->pricesVisible);
        self::assertNull($this->catalog()->product((string) $p->id)->pricing);
        self::assertNull($this->catalog()->searchProducts(new CatalogQuery)->products[0]->pricing);
        $this->expectException(InvalidArgumentException::class);
        $this->catalog()->searchProducts(new CatalogQuery(sort: 'price_asc'));
    }

    public function test_prices_match_the_storefront_with_tax_sale_and_list_price(): void
    {
        $actor = (string) $this->actor->id;
        $area = app(RateAreaAdministration::class)->create($actor, new SaveRateAreaData(['en' => 'Everywhere'], 'active', true));
        $tax = app(TaxAdministration::class)->create($actor, new SaveTaxData(['en' => 'VAT'], null, 0, 'active', false, 'shipping', [new SaveTaxRateData($area, 'percent', '24')]));
        $phones = $this->category('phones');
        $p = $this->product($phones, 'taxed', ['list_price' => '150']);
        DB::table('product_taxes')->insert(['product_id' => $p->id, 'tax_id' => $tax]);
        $this->sale($p);

        $page = $this->storefront('/phones/taxed');
        $pricing = $this->catalog()->product((string) $p->id)->pricing;

        self::assertSame('99.20', $page['price'], 'the sale, with tax');
        self::assertSame($page['price'], $pricing->current());
        self::assertSame($page['price'], $pricing->sale);
        self::assertSame($page['base_price'], $pricing->reference);
        self::assertSame('124.00', $pricing->regular);
        self::assertSame('186.00', $pricing->list);
        self::assertSame('EUR', $pricing->currency);
        foreach ([$pricing->regular, $pricing->sale, $pricing->list, $pricing->reference] as $amount) {
            self::assertIsString($amount);
        }
    }

    public function test_pricing_without_a_sale_or_list_price(): void
    {
        $p = $this->product($this->category('phones'), 'plain');

        $pricing = $this->catalog()->product((string) $p->id)->pricing;

        self::assertSame('100.00', $pricing->regular);
        self::assertSame('100.00', $pricing->current());
        self::assertNull($pricing->sale);
        self::assertNull($pricing->list);
        self::assertNull($pricing->reference);
        self::assertSame(1, $pricing->quantity);
    }

    public function test_a_sale_outside_its_window_is_never_reported(): void
    {
        $p = $this->product($this->category('phones'), 'timed');
        $this->sale($p, ['starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDays(2)->toDateString()]);

        self::assertNull($this->catalog()->product((string) $p->id)->pricing->sale, 'before');
        $this->travelTo(now()->addDay());
        self::assertSame('80.00', $this->catalog()->product((string) $p->id)->pricing->sale, 'during');
        $this->travelTo(now()->addDays(3));
        self::assertNull($this->catalog()->product((string) $p->id)->pricing->sale, 'after');
    }

    public function test_a_minimum_quantity_sale_applies_at_the_smallest_orderable_quantity_only(): void
    {
        $phones = $this->category('phones');
        $single = $this->product($phones, 'single');
        $this->sale($single, ['minimum_quantity' => 5]);
        $box = $this->product($phones, 'box', ['min_qty' => 5]);
        $this->sale($box, ['minimum_quantity' => 5]);

        self::assertNull($this->catalog()->product((string) $single->id)->pricing->sale);
        $pricing = $this->catalog()->product((string) $box->id)->pricing;
        self::assertSame('80.00', $pricing->sale);
        self::assertSame(5, $pricing->quantity);
    }

    public function test_a_signed_in_customer_never_changes_the_answer(): void
    {
        $phones = $this->category('phones');
        $open = $this->product($phones, 'open');
        $private = $this->product($phones, 'private');
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_customer_groups')->insert(['product_id' => $private->id, 'group_id' => $group]);
        $this->sale($open, ['customer_group_id' => $group, 'amount' => '50']);
        $user = UserRecord::create(['name' => 'Ria Stone', 'email' => 'ria@example.test', 'password' => 'a shopper passphrase']);
        $user->markEmailAsVerified();
        $customer = DB::table('customers')->insertGetId(['user_id' => $user->id, 'account_linked_at' => now(), 'email' => 'ria@example.test', 'first_name' => 'Ria', 'last_name' => 'Stone', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_group_members')->insert(['customer_id' => $customer, 'group_id' => $group]);

        $this->actingAs($user);
        self::assertSame('50.00', $this->storefront('/phones/open')['price'], 'the member sees their own price on the storefront');

        self::assertNull($this->catalog()->product((string) $open->id)->pricing->sale, 'the catalog stays a guest read');
        self::assertSame('100.00', $this->catalog()->product((string) $open->id)->pricing->current());
        self::assertNull($this->catalog()->product((string) $private->id));
        self::assertSame(['Open'], $this->names());
    }

    public function test_the_contract_has_no_customer_input_and_returns_plain_data_objects(): void
    {
        $contract = new ReflectionClass(StorefrontCatalog::class);
        foreach ($contract->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                self::assertDoesNotMatchRegularExpression('/customer|user|group|member/i', $parameter->getName(), $method->getName());
            }
        }
        foreach (glob(app_path('Storefront/Application/Command/Catalog*.php')) ?: [] as $file) {
            $class = new ReflectionClass('App\\Storefront\\Application\\Command\\'.basename($file, '.php'));
            self::assertTrue($class->isReadOnly(), $class->getName());
            self::assertFalse($class->getParentClass(), $class->getName());
            foreach ($class->getProperties() as $property) {
                self::assertDoesNotMatchRegularExpression('/customer|user|group|cost|quantityInStock/i', $property->getName(), $class->getName());
            }
        }
    }

    public function test_the_storefront_currency_is_the_only_one_even_with_an_active_secondary(): void
    {
        DB::table('currencies')->insert(['code' => 'USD', 'name' => 'US dollar', 'symbol' => '$', 'symbol_position' => 'before', 'thousands_separator' => ',', 'decimal_separator' => '.', 'decimals' => 2, 'rate' => '2', 'is_primary' => false, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $p = $this->product($this->category('phones'), 'aster');

        $pricing = $this->catalog()->product((string) $p->id)->pricing;

        self::assertSame('EUR', $this->catalog()->status()->currency);
        self::assertSame('EUR', $pricing->currency);
        self::assertSame('100.00', $pricing->regular, 'no conversion');
    }

    public function test_texts_follow_the_requested_language_and_fall_back_to_the_default(): void
    {
        DB::table('locales')->where('code', 'el')->update(['is_active' => true]);
        $phones = $this->category('phones');
        $p = $this->product($phones, 'aster');
        $p->translations()->create(['locale_id' => $this->locale('el'), 'name' => 'Άστερ', 'slug' => 'aster-el']);

        self::assertSame('Άστερ', $this->catalog()->product((string) $p->id, 'el')->name);
        self::assertSame('Aster', $this->catalog()->product((string) $p->id, 'en')->name);
        self::assertSame('Aster', $this->catalog()->product((string) $p->id, 'xx')->name, 'unknown languages use the default');
        self::assertSame('Phones', $this->catalog()->product((string) $p->id, 'el')->category->name, 'untranslated text falls back');
    }

    public function test_pages_are_the_published_ones_in_plain_text(): void
    {
        $page = function (string $name, string $status, array $values = []): int {
            $id = DB::table('pages')->insertGetId(['status' => $status, 'created_at' => now(), 'updated_at' => now(), ...$values]);
            DB::table('page_translations')->insert(['page_id' => $id, 'locale_id' => $this->locale(), 'name' => $name, 'slug' => Str::slug($name), 'content' => '<h2>'.$name.'</h2><p>Within 14 days &amp; free</p>']);

            return $id;
        };
        $returns = $page('Returns', 'active');
        $page('Hidden page', 'hidden');
        $page('Disabled page', 'disabled');
        $page('Expired page', 'active', ['available_until' => now()->subDay()]);
        $restricted = $page('Members page', 'active');
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('page_customer_groups')->insert(['page_id' => $restricted, 'group_id' => $group]);
        $faq = DB::table('faqs')->insertGetId(['type' => 'general', 'status' => 'active', 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('faq_translations')->insert(['faq_id' => $faq, 'locale_id' => $this->locale(), 'question' => 'Free?', 'answer' => '<p>Yes</p>']);
        DB::table('page_faqs')->insert(['page_id' => $returns, 'faq_id' => $faq, 'faq_type' => 'general', 'position' => 1]);

        $pages = $this->catalog()->pages();

        self::assertSame(['Returns'], array_map(fn ($p) => $p->title, $pages));
        self::assertSame('Returns Within 14 days & free', $pages[0]->content);
        self::assertSame(url('/returns'), $pages[0]->url);
        self::assertSame([['question' => 'Free?', 'answer' => 'Yes']], $pages[0]->faqs);
    }

    public function test_invalid_queries_are_refused(): void
    {
        $invalid = [fn () => new CatalogQuery(page: 0), fn () => new CatalogQuery(perPage: 0), fn () => new CatalogQuery(perPage: 101), fn () => new CatalogQuery(page: CatalogQuery::MAX_PAGE + 1),
            fn () => new CatalogQuery(sort: 'cheapest'), fn () => new CatalogQuery(categoryId: 'abc'), fn () => new CatalogQuery(includeDescendants: true)];
        foreach ($invalid as $build) {
            try {
                $build();
                self::fail('Expected a refused query');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_brands_skus_and_sales_narrow_a_listing_by_the_storefront_rules(): void
    {
        $laptops = $this->category('laptops');
        $acme = DB::table('brands')->insertGetId(['code' => 'acme', 'status' => 'active']);
        DB::table('brand_translations')->insert(['brand_id' => $acme, 'locale_id' => $this->locale(), 'name' => 'Acme Computers']);
        $other = DB::table('brands')->insertGetId(['code' => 'other', 'status' => 'active']);
        $retired = DB::table('brands')->insertGetId(['code' => 'retired', 'status' => 'disabled']);
        $a = $this->product($laptops, 'acme-one', ['brand_id' => $acme, 'sku' => 'ACME-1']);
        $b = $this->product($laptops, 'acme-two', ['brand_id' => $acme]);
        $c = $this->product($laptops, 'other-one', ['brand_id' => $other]);
        $this->product($laptops, 'retired-one', ['brand_id' => $retired]);
        $this->sale($b);
        $this->sale($c, ['is_enabled' => false]);
        $ids = fn (CatalogQuery $q): array => array_map(fn (CatalogProduct $p): string => $p->id, $this->catalog()->searchProducts($q)->products);

        self::assertEqualsCanonicalizing([(string) $a->id, (string) $b->id], $ids(new CatalogQuery(brands: ['ACME'])), 'a code, case ignored');
        self::assertEqualsCanonicalizing([(string) $a->id, (string) $b->id], $ids(new CatalogQuery(brands: ['acme computers'])), 'a name, case ignored');
        self::assertSame([], $ids(new CatalogQuery(brands: ['retired'])), 'a disabled brand matches nothing');
        self::assertSame([], $ids(new CatalogQuery(brands: ['nobody'])), 'an unknown brand matches nothing, not everything');
        self::assertSame([(string) $a->id], $ids(new CatalogQuery(skus: ['ACME-1'])));
        self::assertSame([(string) $b->id], $ids(new CatalogQuery(onSaleOnly: true)), 'only an enabled sale that lowers the price');
        self::assertSame([], $ids(new CatalogQuery(brands: ['other'], onSaleOnly: true)));
        self::assertSame(1, $this->catalog()->searchProducts(new CatalogQuery(onSaleOnly: true))->total, 'browsing stays exact');
        $this->settings(['hide_product_prices' => true]);
        $this->expectException(InvalidArgumentException::class); // a sale would show what hidden prices hide
        $this->catalog()->searchProducts(new CatalogQuery(onSaleOnly: true));
    }

    public function test_a_product_carries_its_typed_features_variation_group_and_public_companions(): void
    {
        $laptops = $this->category('laptops');
        $hiddenCategory = $this->category('hidden', attributes: ['exclude_from_discovery' => true]);
        $group = DB::table('feature_groups')->value('id');
        $ram = DB::table('features')->insertGetId(['group_id' => $group, 'code' => 'ram', 'type' => 'number', 'unit' => 'GB', 'position' => 1, 'status' => 'active']);
        DB::table('feature_translations')->insert(['feature_id' => $ram, 'locale_id' => $this->locale(), 'name' => 'Memory']);
        $one = $this->product($laptops, 'one');
        $two = $this->product($laptops, 'two');
        $bag = $this->product($laptops, 'bag');
        $secret = $this->product($hiddenCategory, 'secret');
        foreach ([[$one, '16'], [$two, '32.0']] as [$p, $gb]) {
            DB::table('product_feature_assignments')->insert(['product_id' => $p->id, 'feature_id' => $ram, 'type' => 'number', 'number_value' => $gb]);
        }
        $variation = DB::table('variation_groups')->insertGetId(['name' => 'Laptop', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('variation_group_features')->insert(['group_id' => $variation, 'feature_id' => $ram, 'position' => 1]);
        DB::table('variation_group_members')->insert([['group_id' => $variation, 'product_id' => $one->id, 'signature' => $ram.':16'], ['group_id' => $variation, 'product_id' => $two->id, 'signature' => $ram.':32']]);
        DB::table('product_companions')->insert([['product_id' => $one->id, 'companion_id' => $secret->id, 'position' => 1], ['product_id' => $one->id, 'companion_id' => $bag->id, 'position' => 2]]);

        $catalog = $this->catalog();
        self::assertInstanceOf(CatalogProductSelection::class, $catalog);
        [$first, $second, $alone] = $catalog->productsById([(string) $one->id, (string) $two->id, (string) $bag->id]);
        $feature = fn (CatalogProduct $p): array => array_values(array_filter($p->attributes, fn (array $a): bool => $a['id'] === (string) $ram))[0];

        self::assertSame(['code' => 'ram', 'type' => 'number', 'unit' => 'GB', 'number' => '16'], array_intersect_key($feature($first), array_flip(['code', 'type', 'unit', 'number'])));
        self::assertSame('32', $feature($second)['number'] ?? null);
        self::assertSame((string) $variation, $first->variation);
        self::assertSame($first->variation, $second->variation, 'variants share their group');
        self::assertSame([(string) $ram], $first->variationFeatures);
        self::assertSame([(string) $bag->id], $first->companions, 'a companion nobody may discover is left out');
        self::assertNull($alone->variation);
        self::assertSame([], $alone->companions);
    }

    public function test_sorting_follows_the_storefront(): void
    {
        $phones = $this->category('phones');
        $this->product($phones, 'dear', ['price' => '300']);
        $this->product($phones, 'cheap', ['price' => '10']);
        $this->product($phones, 'middle', ['price' => '50']);
        $order = fn (string $sort): array => array_map(fn ($p) => $p->name, $this->catalog()->searchProducts(new CatalogQuery(sort: $sort))->products);

        self::assertSame(['Cheap', 'Middle', 'Dear'], $order('price_asc'));
        self::assertSame(['Dear', 'Middle', 'Cheap'], $order('price_desc'));
    }
}
