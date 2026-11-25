<?php

namespace Tests\Feature;

use Ambresoft\ProductFeeds\Application\Service\FeedXml;
use App\Authorization\Infrastructure\AccessChecker;
use App\Authorization\Infrastructure\AuthorizationServiceProvider;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Shared\Infrastructure\Modules\AddonManager;
use App\Storefront\Application\Command\CatalogAvailability;
use App\Storefront\Application\Command\CatalogCategory;
use App\Storefront\Application\Command\CatalogPricing;
use App\Storefront\Application\Command\CatalogProduct;
use App\Storefront\Application\Command\CatalogProductPage;
use App\Storefront\Application\Command\CatalogQuery;
use App\Storefront\Application\Command\CatalogStatus;
use App\Storefront\Application\Contract\StorefrontCatalog;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class ProductFeedsTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private const BASE = '/admin/addons/ambresoft/product-feeds';

    private UserRecord $actor;

    private FakeFeedCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        app(AddonManager::class)->installBundled((string) $this->actor->id, 'ambresoft/product-feeds');
        app(AddonManager::class)->enable((string) $this->actor->id, 'ambresoft/product-feeds');
        app('router')->getRoutes()->refreshNameLookups();
        (new AuthorizationServiceProvider(app()))->boot(app(AccessChecker::class));
        $this->catalog = new FakeFeedCatalog;
        $this->catalog->categories = [new CatalogCategory('1', 'Clothing', null, null), new CatalogCategory('2', 'Shirts & Tops', null, '1')];
        app()->instance(StorefrontCatalog::class, $this->catalog);
    }

    /**
     * @param  list<array<string, mixed>>  $attributes
     * @param  array<string, mixed>  $overrides
     */
    private function product(string $id, array $overrides = [], array $attributes = []): CatalogProduct
    {
        $values = [
            'id' => $id, 'name' => 'Linen shirt '.$id, 'description' => 'Breathable linen.', 'url' => 'https://store.example/p/'.$id,
            'images' => ['https://store.example/i/'.$id.'.jpg'], 'sku' => 'SKU-'.$id, 'category' => $this->catalog->categories[1], 'attributes' => $attributes,
            'pricing' => new CatalogPricing('EUR', '100', null, null, null, 1), 'availability' => new CatalogAvailability('in_stock', 'In stock', null, true),
        ];

        return new CatalogProduct(...[...$values, ...$overrides]);
    }

    /** @param array<string, mixed> $overrides */
    private function feed(array $overrides = []): string
    {
        $this->post(self::BASE, [...$this->blank(), ...$overrides])->assertSessionHasNoErrors();

        return (string) DB::table('ambresoft_product_feeds')->latest('created_at')->value('id');
    }

    /** @return array<string, mixed> */
    private function blank(): array
    {
        return ['name' => 'Google feed', 'channel' => 'google', 'cron_password' => 'secret-cron-1', 'status' => 'active', 'scope' => 'all', 'categories' => [],
            'locale_id' => null, 'id_source' => 'id', 'description_source' => 'full', 'description_limit' => null, 'title_for_empty_description' => true,
            'empty_description' => '', 'apply_promotions' => true, 'use_list_price' => false, 'discount_label' => false, 'show_categories' => true,
            'additional_images' => false, 'in_stock_only' => false, 'min_price' => null, 'show_weight' => false,
            'attributes' => [['attribute' => 'condition', 'feature' => 'Condition', 'fallback' => 'new']]];
    }

    private function generate(string $id): string
    {
        $this->get('/storefront/product-feeds/'.$id.'/generate?password=secret-cron-1')->assertStatus(202);
        $response = $this->get('/storefront/product-feeds/'.$id.'.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        return (string) $response->getContent();
    }

    /**
     * Parses the file and checks every item against the channel's published requirements.
     *
     * @return array<array-key, array<string, list<string>>> Item values by g:id
     */
    private function validated(string $xml, string $channel): array
    {
        $dom = new DOMDocument;
        self::assertTrue($dom->loadXML($xml, LIBXML_NONET), 'well-formed XML');
        self::assertSame('UTF-8', $dom->xmlEncoding);
        self::assertSame('rss', $dom->documentElement->tagName);
        self::assertSame('2.0', $dom->documentElement->getAttribute('version'));
        self::assertSame(FeedXml::NAMESPACE, $dom->documentElement->lookupNamespaceURI('g'));
        $path = new DOMXPath($dom);
        $path->registerNamespace('g', FeedXml::NAMESPACE);
        foreach (['title', 'link', 'description'] as $element) {
            self::assertNotSame('', trim((string) $path->evaluate('string(/rss/channel/'.$element.')')), 'channel '.$element);
        }
        foreach (explode("\n", $xml) as $line) {
            self::assertLessThan(5242880, strlen($line), 'Meta line limit');
        }
        $limits = $channel === 'google' ? ['id' => 50, 'title' => 150, 'description' => 5000] : ['id' => 100, 'title' => 200, 'description' => 9999];
        $availability = $channel === 'google' ? ['in_stock', 'out_of_stock', 'preorder', 'backorder'] : ['in stock', 'out of stock'];
        $items = [];
        foreach ($path->query('/rss/channel/item') as $item) {
            $values = [];
            foreach ($item->childNodes as $node) {
                if ($node instanceof DOMElement) {
                    self::assertSame(FeedXml::NAMESPACE, $node->namespaceURI, 'every attribute carries the g: prefix');
                    self::assertNotSame('', $node->textContent, 'no empty attributes');
                    $values[$node->localName][] = $node->textContent;
                }
            }
            $required = ['id', 'title', 'description', 'link', 'image_link', 'availability', 'price', ...($channel === 'meta' ? ['condition'] : [])];
            foreach ($required as $name) {
                self::assertCount(1, $values[$name] ?? [], $name.' is required once');
            }
            foreach ($limits as $name => $limit) {
                self::assertLessThanOrEqual($limit, mb_strlen($values[$name][0]), $name.' length');
            }
            self::assertContains($values['availability'][0], $availability);
            self::assertMatchesRegularExpression('~^https?://~', $values['link'][0]);
            foreach (['price', 'sale_price'] as $name) {
                foreach ($values[$name] ?? [] as $amount) {
                    self::assertMatchesRegularExpression('/^\d+\.\d{2} [A-Z]{3}$/D', $amount, $name.' format');
                }
            }
            if (in_array($values['availability'][0], ['preorder', 'backorder'], true)) {
                self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}Z$/D', $values['availability_date'][0] ?? '', 'availability_date');
            }
            self::assertLessThanOrEqual(10, count($values['additional_image_link'] ?? []));
            self::assertContains($values['condition'][0] ?? 'new', ['new', 'refurbished', 'used']);
            if ($channel === 'meta') {
                self::assertTrue(isset($values['brand']) || isset($values['gtin']) || isset($values['mpn']), 'Meta needs brand, gtin or mpn');
            }
            $items[$values['id'][0]] = $values;
        }

        return $items;
    }

    public function test_google_feed_is_valid_and_maps_catalog_data(): void
    {
        $id = $this->feed(['additional_images' => true, 'show_weight' => true, 'description_limit' => 20, 'discount_label' => true,
            'attributes' => [['attribute' => 'color', 'feature' => 'colour', 'fallback' => ''], ['attribute' => 'condition', 'feature' => 'Condition', 'fallback' => 'new']]]);
        $images = array_map(fn (int $n): string => 'https://store.example/i/'.$n.'.jpg', range(1, 14));
        $this->catalog->products = [
            $this->product('1', ['name' => "Shirt & <Tie>\u{0001}", 'images' => $images, 'pricing' => new CatalogPricing('EUR', '100', '79.5', null, null, 1),
                'brand' => ['name' => 'Acme', 'url' => null], 'gtin' => '4006381333931', 'weightGrams' => '1250.5', 'variation' => '7', 'discountPercent' => 20],
                [['id' => '9', 'name' => 'Color', 'value' => 'Blue', 'code' => 'COLOUR']]),
            $this->product('2', ['images' => []]),
            $this->product('3', ['availability' => new CatalogAvailability('upcoming', 'Soon', now()->addMonth()->toDateString(), true)]),
            $this->product('4', ['availability' => new CatalogAvailability('backorder', 'On order', null, true), 'url' => null]),
            $this->product('5', ['availability' => new CatalogAvailability('backorder', 'On order', null, true)]),
        ];

        $items = $this->validated($this->generate($id), 'google');

        self::assertSame(['1', '3', '5'], array_map(strval(...), array_keys($items)), 'items without image or link are skipped');
        $shirt = $items['1'];
        self::assertSame(['Shirt & <Tie>'], $shirt['title']);
        self::assertSame(['Breathable linen.'], $shirt['description'], 'within the 20 character limit');
        self::assertSame(['100.00 EUR'], $shirt['price']);
        self::assertSame(['79.50 EUR'], $shirt['sale_price']);
        self::assertSame(['in_stock'], $shirt['availability']);
        self::assertSame(['Acme'], $shirt['brand']);
        self::assertSame(['4006381333931'], $shirt['gtin']);
        self::assertArrayNotHasKey('identifier_exists', $shirt);
        self::assertSame(['new'], $shirt['condition'], 'fixed value when the feature is missing');
        self::assertSame(['Blue'], $shirt['color'], 'feature matched by code, case ignored');
        self::assertSame(['7'], $shirt['item_group_id']);
        self::assertSame(['Clothing > Shirts & Tops'], $shirt['product_type']);
        self::assertSame(['1250.5 g'], $shirt['shipping_weight']);
        self::assertSame(['20%'], $shirt['custom_label_3']);
        self::assertSame(array_slice($images, 1, 10), $shirt['additional_image_link']);
        self::assertSame(['no'], $items['3']['identifier_exists']);
        self::assertSame(['preorder'], $items['3']['availability']);
        self::assertSame([now()->addMonth()->toDateString().'T00:00Z'], $items['3']['availability_date']);
        self::assertSame(['out_of_stock'], $items['5']['availability'], 'no restock date is known, so no backorder');
        $this->assertDatabaseHas('ambresoft_product_feeds', ['id' => $id, 'product_count' => 3, 'last_error' => null]);
    }

    public function test_meta_feed_uses_meta_values_and_price_options(): void
    {
        $id = $this->feed(['channel' => 'meta', 'id_source' => 'sku', 'use_list_price' => true, 'apply_promotions' => false, 'min_price' => '50',
            'description_source' => 'short', 'empty_description' => 'See the store for details.', 'attributes' => []]);
        $this->catalog->products = [
            $this->product('1', ['name' => str_repeat('x', 250), 'pricing' => new CatalogPricing('EUR', '100', '80', '120', null, 1), 'brand' => ['name' => 'Acme', 'url' => null]]),
            $this->product('2', ['pricing' => new CatalogPricing('EUR', '50', null, null, null, 1), 'gtin' => '12345670']),
            $this->product('3', ['availability' => new CatalogAvailability('backorder', 'On order', null, true), 'shortDescription' => 'Short text', 'gtin' => '12345670']),
            $this->product('4', ['gtin' => '123']),
        ];

        $items = $this->validated($this->generate($id), 'meta');

        self::assertSame(['SKU-1', 'SKU-3'], array_keys($items), 'SKU as ID; a price not above the limit and an item without brand, GTIN or MPN are left out');
        self::assertSame(200, mb_strlen($items['SKU-1']['title'][0]));
        self::assertSame(['120.00 EUR'], $items['SKU-1']['price'], 'list price');
        self::assertSame(['100.00 EUR'], $items['SKU-1']['sale_price'], 'regular price while promotions are off');
        self::assertSame(['in stock'], $items['SKU-1']['availability']);
        self::assertSame(['new'], $items['SKU-1']['condition']);
        self::assertSame(['See the store for details.'], $items['SKU-1']['description']);
        self::assertSame(['Short text'], $items['SKU-3']['description']);
        self::assertSame(['out of stock'], $items['SKU-3']['availability']);
    }

    public function test_selected_categories_are_read_with_their_descendants_once(): void
    {
        $clothing = (string) CategoryRecord::create(['position' => 1, 'status' => 'active'])->id;
        $shirts = (string) CategoryRecord::create(['parent_id' => $clothing, 'position' => 1, 'status' => 'active'])->id;
        $this->catalog->categories = [new CatalogCategory($clothing, 'Clothing', null, null), new CatalogCategory($shirts, 'Shirts', null, $clothing)];
        $id = $this->feed(['scope' => 'categories', 'categories' => [$clothing, $shirts], 'in_stock_only' => true]);
        $this->catalog->products = [$this->product('1')];

        self::assertCount(1, $this->validated($this->generate($id), 'google'));
        DB::table('categories')->where('id', $shirts)->delete();
        self::assertSame([$clothing], DB::table('ambresoft_product_feed_categories')->pluck('category_id')->map(strval(...))->all(), 'a deleted category leaves the feed');
        self::assertSame([[$clothing, true, true], [$shirts, true, true]], array_map(fn (CatalogQuery $q): array => [$q->categoryId, $q->includeDescendants, $q->inStockOnly], $this->catalog->queries));
        $this->post(self::BASE, [...$this->blank(), 'scope' => 'categories', 'categories' => []])->assertSessionHasErrors('categories');
        $this->post(self::BASE, [...$this->blank(), 'scope' => 'categories', 'categories' => ['999']])->assertSessionHasErrors('categories.0');
    }

    public function test_cron_and_public_file_require_the_password_and_an_active_feed(): void
    {
        $id = $this->feed();
        $this->catalog->products = [$this->product('1')];
        $this->get('/storefront/product-feeds/'.$id.'.xml')->assertNotFound();
        $this->get('/storefront/product-feeds/'.$id.'/generate?password=wrong-password')->assertNotFound();
        $this->get('/storefront/product-feeds/'.$id.'/generate')->assertNotFound();
        self::assertNull(DB::table('ambresoft_product_feeds')->where('id', $id)->value('generated_at'));
        $this->generate($id);
        self::assertNotSame('secret-cron-1', DB::table('ambresoft_product_feeds')->where('id', $id)->value('cron_password'), 'stored encrypted');

        $this->put(self::BASE.'/'.$id, [...$this->blank(), 'status' => 'disabled'])->assertSessionHasNoErrors();
        $this->get('/storefront/product-feeds/'.$id.'.xml')->assertNotFound();
        $this->get('/storefront/product-feeds/'.$id.'/generate?password=secret-cron-1')->assertNotFound();
        app(AddonManager::class)->disable((string) $this->actor->id, 'ambresoft/product-feeds');
        $this->get(self::BASE)->assertNotFound();
    }

    public function test_a_failed_generation_keeps_the_previous_file(): void
    {
        $id = $this->feed();
        $this->catalog->products = [$this->product('1')];
        $first = $this->generate($id);
        $this->catalog->status = new CatalogStatus(true, 'EUR', 2, false, false);

        self::assertSame($first, $this->generate($id));
        $this->assertDatabaseHas('ambresoft_product_feeds', ['id' => $id, 'product_count' => 1, 'last_error' => __('cms-product-feeds::ui.error_prices_hidden')]);
    }

    public function test_only_known_attributes_can_become_xml_elements(): void
    {
        $this->post(self::BASE, [...$this->blank(), 'attributes' => [['attribute' => 'script', 'feature' => 'x', 'fallback' => '']]])->assertSessionHasErrors('attributes.0.attribute');
        $this->post(self::BASE, [...$this->blank(), 'attributes' => [['attribute' => 'color', 'feature' => '', 'fallback' => '']]])->assertSessionHasErrors('attributes.0.fallback');
        $this->post(self::BASE, [...$this->blank(), 'cron_password' => 'short'])->assertSessionHasErrors('cron_password');
        self::assertSame(0, DB::table('ambresoft_product_feeds')->count());
    }

    public function test_viewers_cannot_change_feeds_or_see_the_cron_password(): void
    {
        $id = $this->feed();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'ambresoft.product-feeds.view']));
        $this->get(self::BASE)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Addons/ProductFeeds')->where('feeds.0.id', $id)->where('canManage', false));
        $this->get(self::BASE.'/'.$id.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->where('feed.cron_password', ''));
        $this->post(self::BASE, $this->blank())->assertForbidden();
        $this->put(self::BASE.'/'.$id, $this->blank())->assertForbidden();
        $this->post(self::BASE.'/'.$id.'/generate')->assertForbidden();
        $this->delete(self::BASE.'/'.$id)->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access']))->get(self::BASE)->assertForbidden();
    }

    public function test_bundled_package_uses_the_ambresoft_icon_and_links_from_addons(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('modules/ambresoft/product-feeds/composer.json')), true);
        self::assertSame('icon.svg', $manifest['extra']['cms-module']['icon']);
        self::assertFileEquals(base_path('modules/ambresoft/stripe-checkout/icon.svg'), base_path('modules/ambresoft/product-feeds/icon.svg'));
        $this->get('/admin/addons')->assertOk()->assertInertia(fn (Assert $page) => $page->where('addons', fn (iterable $addons): bool => collect($addons)
            ->firstWhere('name', 'ambresoft/product-feeds')['settings'] === [['name' => 'Facebook/Google XML', 'href' => self::BASE]]));
        $id = $this->feed();
        $this->delete(self::BASE.'/'.$id)->assertRedirect(self::BASE);
        $this->assertDatabaseMissing('ambresoft_product_feeds', ['id' => $id]);
    }
}

final class FakeFeedCatalog implements StorefrontCatalog
{
    /** @var list<CatalogProduct> */
    public array $products = [];

    /** @var list<CatalogCategory> */
    public array $categories = [];

    /** @var list<CatalogQuery> */
    public array $queries = [];

    public CatalogStatus $status;

    public function __construct()
    {
        $this->status = new CatalogStatus(true, 'EUR', 2, true, false);
    }

    public function status(): CatalogStatus
    {
        return $this->status;
    }

    public function searchProducts(CatalogQuery $query): CatalogProductPage
    {
        $this->queries[] = $query;
        $page = array_slice($this->products, ($query->page - 1) * $query->perPage, $query->perPage);

        return new CatalogProductPage($page, $query->page, $query->perPage, count($this->products), true, count($this->products) > $query->page * $query->perPage);
    }

    public function product(string $id, ?string $locale = null): ?CatalogProduct
    {
        return null;
    }

    public function categories(?string $locale = null): array
    {
        return $this->categories;
    }

    public function pages(?string $locale = null): array
    {
        return [];
    }
}
