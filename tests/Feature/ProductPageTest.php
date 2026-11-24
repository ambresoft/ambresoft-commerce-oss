<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\ProductReviews;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Search\Infrastructure\SearchDocuments;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Infrastructure\BlockContent;
use App\Storefront\Infrastructure\CategoryListing;
use App\Storefront\Infrastructure\FirstPartyBlock;
use App\Storefront\Infrastructure\LayoutStore;
use App\Storefront\Infrastructure\ProductPage;
use App\Storefront\Infrastructure\RecentlyViewedProducts;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class ProductPageTest extends TestCase
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
        app()->setLocale('en');
    }

    private function locale(string $code = 'en'): int
    {
        return (int) DB::table('locales')->where('code', $code)->value('id');
    }

    private function category(string $slug, ?CategoryRecord $parent = null): CategoryRecord
    {
        $c = CategoryRecord::create(['parent_id' => $parent?->id, 'position' => 1, 'status' => 'active']);
        $c->translations()->create(['locale_id' => $this->locale(), 'name' => ucfirst($slug), 'slug' => $slug]);

        return $c;
    }

    private function product(CategoryRecord $category, string $slug, string $price = '100', array $texts = []): ProductRecord
    {
        $p = ProductRecord::create(['sku' => 'PDP-'.Str::upper(Str::random(6)), 'price' => $price, 'quantity' => 5, 'category_id' => $category->id, 'status' => 'active']);
        $p->translations()->create(['locale_id' => $this->locale(), 'name' => Str::headline($slug), 'slug' => $slug, ...$texts]);

        return $p;
    }

    /** @return array<string,mixed> The main content block's product payload. */
    private function main(Assert $page): array
    {
        $regions = $page->toArray()['props']['storefront']['regions'];
        foreach ($regions['content'] ?? [] as $grid) {
            foreach ($grid['blocks'] as $block) {
                if ($block['template'] === 'main_content') {
                    $product = $block['content']['product'];
                    $graph = $page->toArray()['props']['seo']['graph'] ?? [];
                    if ($graph !== [] && $product['price'] !== null) {
                        self::assertSame($product['price'], $graph[0]['offers']['price']);
                        self::assertSame($product['currency_format']['code'], $graph[0]['offers']['priceCurrency']);
                    }

                    return $product;
                }
            }
        }
        self::fail('No main content block');
    }

    public function test_a_product_page_shows_the_product_in_the_protected_main_content_block_of_the_products_layout(): void
    {
        $parent = $this->category('phones');
        $leaf = $this->category('smartphones', $parent);
        $p = $this->product($leaf, 'aster-one', '499.90', ['short_description' => '<p>Short <script>x()</script>text</p>', 'description' => '<h2>All about it</h2>', 'seo_title' => 'Aster One phone']);
        $group = DB::table('feature_groups')->orderBy('id')->value('id');
        $feature = DB::table('features')->insertGetId(['code' => 'battery', 'type' => 'number', 'status' => 'active', 'group_id' => $group, 'position' => 1, 'unit' => 'mAh', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('feature_translations')->insert(['feature_id' => $feature, 'locale_id' => $this->locale(), 'name' => 'Battery']);
        DB::table('product_feature_assignments')->insert(['product_id' => $p->id, 'feature_id' => $feature, 'type' => 'number', 'number_value' => '4832']);

        $captured = null;
        $this->get('/phones/smartphones/aster-one')->assertOk()
            ->assertSee('<title>Aster One phone</title>', false)
            ->assertSee('<link rel="canonical" href="'.rtrim((string) config('app.url'), '/').'/phones/smartphones/aster-one" data-inertia="seo-canonical">', false)
            ->assertSee('<meta name="description" content="Short text" data-inertia="seo-description">', false)
            ->assertInertia(function (Assert $page) use (&$captured): void {
                $page->component('Storefront/Product')->where('seo.title', 'Aster One phone')->where('reviews.total', 0)->has('storefront.regions.header');
                $captured = $this->main($page);
            });
        self::assertSame((string) $p->id, $captured['id']);
        self::assertSame('Aster One', $captured['name']);
        self::assertSame('<p>Short text</p>', $captured['short_description']);
        self::assertSame('<h2>All about it</h2>', $captured['description']);
        self::assertSame('499.90', $captured['price']);
        self::assertNull($captured['saving']);
        self::assertSame(['name' => 'Smartphones', 'url' => '/phones/smartphones'], array_intersect_key($captured['category'], ['name' => 1, 'url' => 1]));
        self::assertSame([['id' => (string) $feature, 'name' => 'Battery', 'value' => '4832 mAh']], $captured['features'][0]['features']);
        self::assertSame('Battery', $captured['highlights'][0]['name']);
        self::assertSame(['count' => 0, 'average' => null], array_intersect_key($captured['rating'], ['count' => 1, 'average' => 1]));
        self::assertNull($captured['bought_together']);
        self::assertSame('/products/'.$p->id.'/reviews', $captured['review']['url']);
    }

    public function test_the_brand_logo_leads_to_the_brand_page(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('brands/apple.png', 'x');
        $p = $this->product($this->category('phones'), 'aster-one');
        $media = DB::table('media_files')->insertGetId(['disk' => 'local', 'storage_key' => 'brands/apple.png', 'mime_type' => 'image/png', 'byte_size' => 10, 'width' => 120, 'height' => 60]);
        $brand = DB::table('brands')->insertGetId(['code' => 'apple', 'status' => 'active', 'image_media_id' => $media]);
        DB::table('brand_translations')->insert(['brand_id' => $brand, 'locale_id' => $this->locale(), 'name' => 'Apple', 'description' => '']);
        $p->update(['brand_id' => $brand]);
        $brandOf = function () {
            $captured = null;
            $this->get('/phones/aster-one')->assertOk()->assertInertia(function (Assert $page) use (&$captured): void {
                $captured = $this->main($page)['brand'];
            });

            return $captured;
        };
        self::assertSame('/apple', $brandOf()['url']);
        // Brand pages switched off for another source: the logo stays, without a link.
        DB::table('general_settings')->where('id', 1)->update(['brand_landing_pages' => false]);
        app()->forgetInstance(GeneralSettings::class);
        self::assertSame(['Apple', null], [$brandOf()['name'], $brandOf()['url']]);
        DB::table('brands')->where('id', $brand)->update(['image_media_id' => null]);
        self::assertSame(['Apple', null, null], [$brandOf()['name'], $brandOf()['image'], $brandOf()['url']]);
        DB::table('general_settings')->where('id', 1)->update(['brand_landing_pages' => true]);
        app()->forgetInstance(GeneralSettings::class);
        self::assertSame('/apple', $brandOf()['url']);
        $this->get('/phones/aster-one')->assertInertia(fn (Assert $page) => $page->where('seo.graph.0.brand.name', 'Apple'));
        DB::table('brands')->where('id', $brand)->update(['status' => 'disabled']);
        self::assertNull($brandOf());
    }

    public function test_a_product_on_sale_carries_the_sale_end_and_the_quantity_rules(): void
    {
        $p = $this->product($this->category('deals'), 'flash-deal', '100');
        $p->forceFill(['min_qty' => 2, 'max_qty' => 10, 'qty_step' => 2])->save();
        DB::table('product_sales')->insert(['product_id' => $p->id, 'customer_group_id' => null, 'minimum_quantity' => 1, 'priority' => 1, 'kind' => 'percentage', 'amount' => '20', 'ends_on' => now()->addDays(3)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/deals/flash-deal')->assertOk()->assertInertia(function (Assert $page) use (&$captured): void {
            $captured = $this->main($page);
        });
        self::assertSame('80.00', $captured['price']);
        self::assertNotNull($captured['sale']['ends_at']);
        self::assertNull($captured['sale']['title']);
        self::assertSame(['min' => 2, 'max' => 10, 'step' => 2], $captured['quantity']);
    }

    public function test_a_sale_title_and_end_time_reach_the_page_and_a_finished_time_ends_the_sale(): void
    {
        $p = $this->product($this->category('timed'), 'timed-deal', '100');
        $zone = app(GeneralSettings::class)->all()['timezone'];
        $end = now()->timezone($zone)->addDay();
        $sale = DB::table('product_sales')->insertGetId(['product_id' => $p->id, 'customer_group_id' => null, 'minimum_quantity' => 1, 'priority' => 1, 'kind' => 'percentage', 'amount' => '20', 'ends_on' => $end->toDateString(), 'ends_time' => '12:30:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_sale_translations')->insert(['sale_id' => $sale, 'locale_id' => $this->locale(), 'title' => 'Black Friday Deals']);

        $this->get('/timed/timed-deal')->assertInertia(function (Assert $page) use (&$captured): void {
            $captured = $this->main($page);
        });
        self::assertSame('Black Friday Deals', $captured['sale']['title']);
        self::assertSame($end->setTime(12, 30)->toIso8601String(), CarbonImmutable::parse($captured['sale']['ends_at'])->timezone($zone)->toIso8601String());

        DB::table('product_sales')->where('id', $sale)->update(['ends_on' => $end->subDays(3)->toDateString()]);
        $this->get('/timed/timed-deal')->assertInertia(function (Assert $page) use (&$captured): void {
            $captured = $this->main($page);
        });
        self::assertNull($captured['sale']);
        self::assertSame('100.00', $captured['price']);
    }

    public function test_product_prices_include_tax_and_the_price_without_tax_shows_only_when_the_setting_is_on(): void
    {
        $actor = (string) $this->actor->id;
        $area = app(RateAreaAdministration::class)->create($actor, new SaveRateAreaData(['en' => 'Everywhere'], 'active', true));
        $tax = app(TaxAdministration::class)->create($actor, new SaveTaxData(['en' => 'VAT'], null, 0, 'active', false, 'shipping', [new SaveTaxRateData($area, 'percent', '24')]));
        $p = $this->product($this->category('taxed'), 'taxed-item', '100');
        DB::table('product_taxes')->insert(['product_id' => $p->id, 'tax_id' => $tax]);
        DB::table('general_settings')->update(['display_catalog_prices_with_tax' => false, 'show_prices_without_tax' => false]);
        app()->forgetInstance(GeneralSettings::class);

        $this->get('/taxed/taxed-item')->assertInertia(function (Assert $page) use (&$captured): void {
            $captured = $this->main($page);
        });
        self::assertSame('124.00', $captured['price']);
        self::assertNull($captured['price_without_tax']);

        DB::table('general_settings')->update(['show_prices_without_tax' => true]);
        app()->forgetInstance(GeneralSettings::class);
        $this->get('/taxed/taxed-item')->assertInertia(function (Assert $page) use (&$captured): void {
            $captured = $this->main($page);
        });
        self::assertSame('124.00', $captured['price']);
        self::assertSame('100.00', $captured['price_without_tax']);
    }

    public function test_the_storefront_address_of_a_product_exists_only_while_visitors_can_see_it(): void
    {
        $p = $this->product($this->category('shop'), 'visible-item', '10');
        $page = app(ProductPage::class);
        self::assertSame('/shop/visible-item', $page->address((string) $p->id, 'en'));
        $p->forceFill(['status' => 'disabled'])->save();
        self::assertNull($page->address((string) $p->id, 'en'));
    }

    public function test_addresses_redirect_to_the_current_one_and_unknown_or_hidden_products_are_not_found(): void
    {
        $leaf = $this->category('laptops');
        $p = $this->product($leaf, 'nimbus-air');
        DB::table('product_translations')->insert(['product_id' => $p->id, 'locale_id' => $this->locale('el'), 'name' => 'Nimbus', 'slug' => 'nimbus-air-el']);
        // Another language's slug (or a stale category path) leads to the current address.
        $this->get('/laptops/nimbus-air-el')->assertRedirect('/laptops/nimbus-air')->assertStatus(301);
        $this->get('/old-category/nimbus-air')->assertRedirect('/laptops/nimbus-air');
        $this->get('/laptops/missing-product')->assertNotFound();
        $this->get('/nimbus-air')->assertNotFound();
        foreach (['disabled', 'hidden'] as $status) {
            DB::table('products')->where('id', $p->id)->update(['status' => $status]);
            $this->get('/laptops/nimbus-air')->assertNotFound();
        }
        DB::table('products')->where('id', $p->id)->update(['status' => 'active', 'deleted_at' => now()]);
        $this->get('/laptops/nimbus-air')->assertNotFound();
        // Existing routes keep working; the product route only catches what nothing else matches.
        $this->get('/')->assertOk();
        DB::table('general_settings')->update(['storefront_open' => false]);
        Cache::flush();
        DB::table('products')->where('id', $p->id)->update(['deleted_at' => null]);
        $this->get('/laptops/nimbus-air')->assertStatus(503);
    }

    public function test_the_page_carries_reviews_bought_together_variations_and_similar_products(): void
    {
        $leaf = $this->category('phones');
        $p = $this->product($leaf, 'aster-two', '300');
        $case = $this->product($leaf, 'case', '20');
        DB::table('product_companions')->insert(['product_id' => $p->id, 'companion_id' => $case->id, 'position' => 1]);
        $customer = DB::table('customers')->insertGetId(['email' => 'r@example.test', 'first_name' => 'Ria', 'last_name' => 'Stone', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_reviews')->insert(['product_id' => $p->id, 'customer_id' => $customer, 'rating' => 4, 'body' => 'Solid phone.', 'status' => 'approved', 'verified_purchase' => true, 'moderated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_reviews')->insert(['product_id' => $case->id, 'customer_id' => $customer, 'rating' => 1, 'body' => 'Pending one.', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        // Variation group: two colours, each linking to its member.
        $group = DB::table('feature_groups')->orderBy('id')->value('id');
        $colour = DB::table('features')->insertGetId(['code' => 'colour', 'type' => 'single', 'status' => 'active', 'group_id' => $group, 'position' => 1, 'purpose' => 'variations', 'variation_style' => 'text', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('feature_translations')->insert(['feature_id' => $colour, 'locale_id' => $this->locale(), 'name' => 'Colour']);
        $blue = $this->product($leaf, 'aster-two-blue', '310');
        $variation = DB::table('variation_groups')->insertGetId(['name' => 'Aster two', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('variation_group_features')->insert(['group_id' => $variation, 'feature_id' => $colour, 'position' => 1]);
        foreach ([[$p, 'Black'], [$blue, 'Blue']] as $n => [$member, $label]) {
            $option = DB::table('feature_options')->insertGetId(['feature_id' => $colour, 'position' => $n + 1]);
            DB::table('feature_option_translations')->insert(['option_id' => $option, 'locale_id' => $this->locale(), 'label' => $label]);
            $assignment = DB::table('product_feature_assignments')->insertGetId(['product_id' => $member->id, 'feature_id' => $colour, 'type' => 'single']);
            DB::table('product_feature_choices')->insert(['assignment_id' => $assignment, 'feature_id' => $colour, 'option_id' => $option, 'type' => 'single']);
            DB::table('variation_group_members')->insert(['group_id' => $variation, 'product_id' => $member->id, 'signature' => $colour.':'.$option]);
        }
        $faq = DB::table('faqs')->insertGetId(['type' => 'product', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('faq_translations')->insert(['faq_id' => $faq, 'locale_id' => $this->locale(), 'question' => 'Is it waterproof?', 'answer' => '<p>Yes</p><script>x()</script>']);
        $draft = DB::table('faqs')->insertGetId(['type' => 'product', 'status' => 'disabled', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('faq_translations')->insert(['faq_id' => $draft, 'locale_id' => $this->locale(), 'question' => 'Hidden?', 'answer' => '<p>No</p>']);
        DB::table('product_faqs')->insert([['product_id' => $p->id, 'faq_id' => $faq, 'faq_type' => 'product', 'position' => 1], ['product_id' => $p->id, 'faq_id' => $draft, 'faq_type' => 'product', 'position' => 2]]);
        DB::table('product_price_history')->insert([['product_id' => $p->id, 'price' => '300', 'recorded_at' => now()->subDays(10)], ['product_id' => $p->id, 'price' => '300', 'recorded_at' => now()]]);
        $captured = null;
        $this->get('/phones/aster-two')->assertOk()->assertInertia(function (Assert $page) use (&$captured): void {
            $page->where('reviews.total', 1)->where('reviews.data.0.body', 'Solid phone.')->where('reviews.data.0.author', 'Ria S.');
            $captured = $this->main($page);
        });
        self::assertSame(['count' => 1, 'average' => '4.0'], array_intersect_key($captured['rating'], ['count' => 1, 'average' => 1]));
        self::assertSame([(string) $p->id, (string) $case->id], array_column($captured['bought_together']['items'], 'id'));
        self::assertSame('320.00', $captured['bought_together']['total']);
        self::assertSame('Colour', $captured['variations'][0]['name']);
        self::assertSame([['id' => (string) $faq, 'question' => 'Is it waterproof?', 'answer' => '<p>Yes</p>']], $captured['faqs']);
        self::assertSame(['300.00', '300.00'], array_column($captured['price_history'], 'price'));
        self::assertIsBool($captured['returnable']);
        self::assertSame([['Black', true, '/phones/aster-two'], ['Blue', false, '/phones/aster-two-blue']], array_map(fn ($o) => [$o['label'], $o['selected'], $o['url']], $captured['variations'][0]['options']));
        self::assertContains((string) $blue->id, array_column($captured['similar']['items'], 'id'));
        // Same presentation as a product carousel block with its defaults.
        self::assertSame(app(StorefrontRegistry::class)->type('products')->definition()['defaults']['image_height'], $captured['similar']['display']['image_height']);
        DB::table('products')->where('id', $p->id)->update(['list_price' => '350']);
        $this->get('/phones/aster-two')->assertInertia(function (Assert $page): void {
            $product = $this->main($page);
            self::assertSame(['350.00', '300.00', '50.00'], [$product['base_price'], $product['price'], $product['saving']]);
        });
    }

    public function test_variation_choices_keep_colour_fixed_but_allow_storage_to_change_with_memory(): void
    {
        $leaf = $this->category('laptops');
        $black = $this->product($leaf, 'black-16');
        $silver = $this->product($leaf, 'silver-32');
        $group = DB::table('variation_groups')->insertGetId(['name' => 'Laptop', 'created_at' => now(), 'updated_at' => now()]);
        $featureGroup = DB::table('feature_groups')->orderBy('id')->value('id');
        $assign = function ($product, $feature, $option): void {
            $assignment = DB::table('product_feature_assignments')->insertGetId(['product_id' => $product, 'feature_id' => $feature, 'type' => 'single']);
            DB::table('product_feature_choices')->insert(['assignment_id' => $assignment, 'feature_id' => $feature, 'option_id' => $option, 'type' => 'single']);
        };
        $options = [];
        foreach (['colour' => ['Black', 'Silver'], 'memory' => ['16 GB', '32 GB'], 'storage' => ['1 TB', '512 GB']] as $code => $labels) {
            $feature = DB::table('features')->insertGetId(['code' => $code, 'type' => 'single', 'status' => 'active', 'group_id' => $featureGroup, 'position' => 1, 'purpose' => 'variations', 'variation_style' => $code === 'colour' ? 'color' : 'dropdown', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('feature_translations')->insert(['feature_id' => $feature, 'locale_id' => $this->locale(), 'name' => $code]);
            DB::table('variation_group_features')->insert(['group_id' => $group, 'feature_id' => $feature, 'position' => count($options) + 1]);
            foreach ($labels as $n => $label) {
                $option = DB::table('feature_options')->insertGetId(['feature_id' => $feature, 'position' => $n + 1]);
                DB::table('feature_option_translations')->insert(['option_id' => $option, 'locale_id' => $this->locale(), 'label' => $label]);
                $options[$code][] = [$feature, $option];
                $assign($n === 0 ? $black->id : $silver->id, $feature, $option);
            }
        }
        foreach ([$black, $silver] as $n => $product) {
            DB::table('variation_group_members')->insert(['group_id' => $group, 'product_id' => $product->id, 'signature' => implode(',', array_map(fn ($axis) => implode(':', $axis[$n]), $options))]);
        }
        $page = app(ProductPage::class)->data((string) $black->id, 'en', null);
        self::assertTrue($page['variations'][0]['options'][1]['compatible']);
        self::assertSame('/laptops/silver-32', $page['variations'][0]['options'][1]['url']);
        foreach (array_slice($page['variations'], 1) as $feature) {
            self::assertTrue($feature['options'][0]['compatible']);
            self::assertFalse($feature['options'][1]['compatible']);
            self::assertNull($feature['options'][1]['url']);
        }
        // Switching to Silver enables its 32 GB value, which Black does not offer yet.
        $silverPage = app(ProductPage::class)->data((string) $silver->id, 'en', null);
        self::assertTrue($silverPage['variations'][1]['options'][1]['compatible']);
        self::assertTrue($silverPage['variations'][1]['options'][1]['selected']);
        self::assertFalse($silverPage['variations'][1]['options'][0]['compatible']);
        self::assertSame([(string) $silver->id], array_column($page['variation_alternatives'], 'id'));
        // When a closer member exists, preserve the current colour while changing memory.
        $bridge = $this->product($leaf, 'black-32');
        foreach ([$options['colour'][0], $options['memory'][1], $options['storage'][1]] as [$feature, $option]) {
            $assign($bridge->id, $feature, $option);
        }
        DB::table('variation_group_members')->insert(['group_id' => $group, 'product_id' => $bridge->id, 'signature' => implode(':', $options['colour'][0]).','.implode(':', $options['memory'][1]).','.implode(':', $options['storage'][1])]);
        // Matching colour beats matching storage when both preserve one other choice.
        $silverTb = $this->product($leaf, 'silver-32-tb');
        $silverChoices = [$options['colour'][1], $options['memory'][1], $options['storage'][0]];
        foreach ($silverChoices as [$feature, $option]) {
            $assign($silverTb->id, $feature, $option);
        }
        DB::table('variation_group_members')->insert(['group_id' => $group, 'product_id' => $silverTb->id, 'signature' => implode(',', array_map(fn ($choice) => implode(':', $choice), $silverChoices))]);
        $page = app(ProductPage::class)->data((string) $black->id, 'en', null);
        self::assertTrue($page['variations'][0]['options'][1]['compatible']);
        self::assertTrue($page['variations'][1]['options'][1]['compatible']);
        self::assertSame('/laptops/black-32', $page['variations'][1]['options'][1]['url']);
        self::assertContains((string) $silver->id, array_column($page['variation_alternatives'], 'id'));
        $page = app(ProductPage::class)->data((string) $bridge->id, 'en', null);
        self::assertTrue($page['variations'][0]['options'][1]['compatible']);
        self::assertSame('/laptops/silver-32', $page['variations'][0]['options'][1]['url']);
        self::assertTrue($page['variations'][1]['options'][1]['selected']);
        self::assertTrue($page['variations'][2]['options'][1]['selected']);
    }

    public function test_product_documents_are_listed_and_served_only_for_visible_products(): void
    {
        $leaf = $this->category('manuals');
        $p = $this->product($leaf, 'nimbus-dock');
        $file = function (string $purpose, string $name, string $original, string $mime) use ($p): int {
            Storage::disk('local')->put('product-files/'.$name, $mime === 'application/pdf' ? '%PDF-1.4 sheet' : 'PK zip');
            $private = DB::table('private_product_files')->insertGetId(['disk' => 'local', 'storage_key' => 'product-files/'.$name, 'original_name' => $original, 'mime_type' => $mime, 'byte_size' => 10, 'ready' => true, 'created_at' => now(), 'updated_at' => now()]);

            return (int) DB::table('product_files')->insertGetId(['product_id' => $p->id, 'private_file_id' => $private, 'display_name' => $name === 'a.pdf' ? 'Information sheet' : 'Buyer download', 'position' => $private, 'purpose' => $purpose]);
        };
        $sheet = $file('attachment', 'a.pdf', 'sheet.pdf', 'application/pdf');
        $download = $file('download', 'b.zip', 'software.zip', 'application/zip');
        $captured = null;
        $this->get('/manuals/nimbus-dock')->assertOk()->assertInertia(function (Assert $page) use (&$captured): void {
            $captured = $this->main($page);
        });
        self::assertSame([['id' => (string) $sheet, 'name' => 'Information sheet', 'type' => 'pdf', 'url' => '/storefront/products/'.$p->id.'/documents/'.$sheet]], $captured['documents']);
        $this->get('/storefront/products/'.$p->id.'/documents/'.$sheet)->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'")->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Disposition', 'inline; filename="Information sheet.pdf"');
        // Buyer downloads are never public; a document is reachable only through its own product.
        $this->get('/storefront/products/'.$p->id.'/documents/'.$download)->assertNotFound();
        $other = $this->product($leaf, 'other-dock');
        $this->get('/storefront/products/'.$other->id.'/documents/'.$sheet)->assertNotFound();
        DB::table('products')->where('id', $p->id)->update(['status' => 'hidden']);
        $this->get('/storefront/products/'.$p->id.'/documents/'.$sheet)->assertNotFound();
    }

    public function test_a_review_from_the_product_page_returns_to_the_product_page_only(): void
    {
        $leaf = $this->category('speakers');
        $p = $this->product($leaf, 'pocket-boom');
        $user = UserRecord::create(['name' => 'Ria Stone', 'email' => 'ria@example.test', 'password' => 'a shopper passphrase']);
        $user->markEmailAsVerified();
        DB::table('customers')->insert(['user_id' => $user->id, 'account_linked_at' => now(), 'email' => 'ria@example.test', 'first_name' => 'Ria', 'last_name' => 'Stone', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);
        // The session's "previous" address can be anything (here a missing page): it is not used.
        $this->get('/.well-known/appspecific/com.chrome.devtools.json')->assertNotFound();
        $this->post('/products/'.$p->id.'/reviews', ['rating' => 5, 'title' => 'Great', 'body' => 'Loud and clear sound.', 'return_to' => '/speakers/pocket-boom'])
            ->assertSessionHasNoErrors()->assertRedirect('/speakers/pocket-boom');
        $this->assertDatabaseHas('product_reviews', ['product_id' => $p->id, 'status' => 'pending', 'rating' => 5]);
        // The page now shows the customer's own review state instead of the form; a second review is refused.
        $this->get('/speakers/pocket-boom')->assertInertia(fn (Assert $page) => self::assertSame('pending', $this->main($page)['review']['mine']));
        $this->post('/products/'.$p->id.'/reviews', ['rating' => 4, 'body' => 'Trying again here.', 'return_to' => '/speakers/pocket-boom'])->assertSessionHasErrors('product');
        // Another site is never a return address.
        $q = $this->product($leaf, 'pocket-boom-two');
        foreach (['//evil.example/x', 'https://evil.example', '/\\evil.example'] as $n => $target) {
            $other = $n === 0 ? $q : $this->product($leaf, 'boom-'.$n);
            $response = $this->post('/products/'.$other->id.'/reviews', ['rating' => 4, 'body' => 'Good sound overall.', 'return_to' => $target]);
            self::assertStringNotContainsString('evil.example', (string) $response->headers->get('Location'));
        }
    }

    public function test_the_products_layout_keeps_its_main_content_block_while_merchants_add_blocks_around_it(): void
    {
        $this->actingAs($this->actor);
        $this->get('/admin/layouts/products')->assertOk()->assertInertia(fn (Assert $p) => $p->where('layout.page_type', 'products')->where('pageKind', 'products')
            ->where('definitions.main_content.creatable', false)->where('definitions.main_content.policy', ['move' => true, 'disable' => false, 'delete' => false, 'configure' => true])
            ->where('tabs.2.key', 'products'));
        $layout = app(LayoutStore::class)->read('products');
        $main = $layout['placements'][0];
        self::assertSame('main_content', $main['block']['type']);
        // Deleting or disabling it is refused; its settings choose the sections the page shows (all by default).
        $this->put('/admin/layouts/products', [...$layout, 'placements' => []])->assertSessionHasErrors('placements');
        $this->put('/admin/layouts/products', [...$layout, 'placements' => [[...$main, 'enabled' => false]]])->assertSessionHasErrors('placements');
        self::assertSame([...array_fill_keys(FirstPartyBlock::MAIN_SECTIONS, true), 'newest_same_category' => false, 'section_order' => FirstPartyBlock::PRODUCT_SECTION_ORDER, 'similar_per_row' => 5, 'newest_per_row' => 5, 'recently_viewed_per_row' => 5, 'variation_alternatives_per_row' => 5, 'similar_limit' => 12, 'newest_limit' => 12, 'recently_viewed_limit' => 12, 'variation_alternatives_limit' => 12, 'per_row' => 5, 'card_shadow' => true, 'mobile_display' => 'grid', 'mobile_per_row' => 2, 'products_per_row' => 3, 'mobile_products_per_row' => '2', 'tablet_products_per_row' => '2', 'products_per_page' => 24, 'default_sort' => 'recommended', 'show_sorting' => true, 'show_count' => true, 'show_brand' => false, 'show_category' => false, 'show_variations' => false, 'show_invoice' => true, 'hide_arrows_mobile' => false], app(StorefrontRegistry::class)->type('main_content')->validate([]));
        $this->put('/admin/layouts/products', [...$layout, 'placements' => [[...$main, 'block' => [...$main['block'], 'settings' => ['show_reviews' => 'maybe']]]]])->assertSessionHasErrors();
        $main['block']['settings'] = ['show_similar' => false, 'show_product_bar' => false];
        $this->put('/admin/layouts/products', [...$layout, 'placements' => [$main]])->assertSessionHasNoErrors();
        $layout = app(LayoutStore::class)->read('products');
        $main = $layout['placements'][0];
        // A banner-like HTML block can be placed above it; the main block can move below.
        $grid = ['id' => (string) Str::uuid(), 'parent_id' => null, 'region' => 'content', 'width' => 16, 'row' => 1, 'position' => 1];
        $html = app(StorefrontRegistry::class)->type('html')->definition();
        $mainGrid = array_values(array_filter($layout['grids'], fn ($g) => $g['id'] === $main['grid_id']))[0];
        $this->put('/admin/layouts/products', [...$layout, 'grids' => [$grid, [...$mainGrid, 'row' => 2]], 'placements' => [$main, ['id' => (string) Str::uuid(), 'grid_id' => $grid['id'], 'position' => 1, 'enabled' => true, 'mobile' => true, 'tablet' => true, 'desktop' => true,
            'block' => ['id' => (string) Str::uuid(), 'type' => 'html', 'name' => 'Promo', 'template' => 'html', 'css_class' => '', 'settings' => $html['defaults'], 'selections' => [], 'translations' => ['en' => ['title' => '', 'html' => '<p>Free returns</p>']]]]]])->assertSessionHasNoErrors();
        // The main content block cannot be added to other pages.
        $home = app(LayoutStore::class)->read('homepage');
        $copy = [...$main, 'id' => (string) Str::uuid(), 'grid_id' => $home['grids'][0]['id'], 'position' => 99, 'block' => [...$main['block'], 'id' => (string) Str::uuid()]];
        $this->put('/admin/layouts/homepage', [...$home, 'placements' => [...$home['placements'], $copy]])->assertSessionHasErrors();
        $leaf = $this->category('audio');
        $this->product($leaf, 'pocket-sound');
        $this->flushSession();
        auth()->logout();
        $this->get('/audio/pocket-sound')->assertOk()->assertInertia(function (Assert $page): void {
            $content = $page->toArray()['props']['storefront']['regions']['content'];
            self::assertSame(['html', 'main_content'], array_map(fn ($g) => $g['blocks'][0]['template'], $content));
            self::assertFalse($content[1]['blocks'][0]['content']['sections']['show_similar']);
            self::assertFalse($content[1]['blocks'][0]['content']['sections']['show_product_bar']);
            self::assertTrue($content[1]['blocks'][0]['content']['sections']['show_reviews']);
        });
    }

    public function test_product_section_order_is_validated_saved_and_projected_without_changing_visibility(): void
    {
        $type = app(StorefrontRegistry::class)->type('main_content');
        self::assertSame(FirstPartyBlock::PRODUCT_SECTION_ORDER, $type->validate(['show_reviews' => false])['section_order']);
        $this->actingAs($this->actor);
        $layout = app(LayoutStore::class)->read('products');
        $order = array_reverse(FirstPartyBlock::PRODUCT_SECTION_ORDER);
        $layout['placements'][0]['block']['settings'] = ['section_order' => $order, 'show_reviews' => false, 'show_faqs' => false];
        $this->put('/admin/layouts/products', $layout)->assertSessionHasNoErrors();
        $saved = app(LayoutStore::class)->read('products');
        self::assertSame($order, $saved['placements'][0]['block']['settings']['section_order']);
        $category = $this->category('section-order');
        $this->product($category, 'ordered');
        $this->get('/section-order/ordered')->assertOk()->assertInertia(function (Assert $page) use ($order): void {
            $main = $this->mainBlock($page);
            self::assertSame($order, $main['section_order']);
            self::assertFalse($main['sections']['show_reviews']);
            self::assertFalse($main['sections']['show_faqs']);
        });
        foreach ([array_fill(0, 6, 'show_reviews'), ['show_faqs', ...array_slice($order, 1)], array_slice($order, 1)] as $invalid) {
            $saved['placements'][0]['block']['settings']['section_order'] = $invalid;
            $this->put('/admin/layouts/products', $saved)->assertSessionHasErrors();
        }
        self::assertSame($order, app(LayoutStore::class)->read('products')['placements'][0]['block']['settings']['section_order']);
    }

    public function test_newest_settings_use_the_existing_window_category_and_eligibility_rules(): void
    {
        $category = $this->category('recommendations');
        $other = $this->category('other-recommendations');
        $current = $this->product($category, 'current');
        $same = $this->product($category, 'same-category');
        $elsewhere = $this->product($other, 'elsewhere');
        $old = $this->product($category, 'old');
        $old->forceFill(['created_at' => now()->subDays(91)])->save();
        $hidden = $this->product($category, 'hidden');
        $hidden->forceFill(['status' => 'disabled'])->save();
        $restricted = $this->product($category, 'restricted');
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_customer_groups')->insert(['product_id' => $restricted->id, 'group_id' => $group]);
        $ids = fn () => array_column(app(ProductPage::class)->data((string) $current->id, 'en', null)['newest']['items'] ?? [], 'id');
        self::assertSame([(string) $elsewhere->id, (string) $same->id], $ids());
        $recent = app(ProductPage::class)->data((string) $current->id, 'en', null, [(string) $hidden->id, (string) $restricted->id, (string) $old->id]);
        self::assertSame([(string) $old->id], array_column($recent['recently_viewed']['items'], 'id'));
        $this->actingAs($this->actor);
        $layout = app(LayoutStore::class)->read('products');
        $layout['placements'][0]['block']['settings']['newest_same_category'] = true;
        $this->put('/admin/layouts/products', $layout)->assertSessionHasNoErrors();
        self::assertSame([(string) $same->id], $ids());
        $layout = app(LayoutStore::class)->read('products');
        $layout['placements'][0]['block']['settings']['show_newest'] = false;
        $layout['placements'][0]['block']['settings']['show_recently_viewed'] = false;
        $this->put('/admin/layouts/products', $layout)->assertSessionHasNoErrors();
        $data = app(ProductPage::class)->data((string) $current->id, 'en', null, [(string) $same->id]);
        self::assertNull($data['newest']);
        self::assertNull($data['recently_viewed']);
        $this->get('/recommendations/current')->assertOk();
        $this->assertDatabaseHas('recently_viewed_products', ['user_id' => $this->actor->id, 'product_id' => $current->id]);
    }

    public function test_main_content_caps_how_many_products_each_carousel_offers(): void
    {
        $category = $this->category('capped');
        $current = $this->product($category, 'capped-current');
        $others = array_map(fn (int $n): string => (string) $this->product($category, 'capped-'.$n)->id, range(1, 3));
        $count = fn (?array $carousel): int => count($carousel['items'] ?? []);
        $data = app(ProductPage::class)->data((string) $current->id, 'en', null, $others);
        self::assertSame([3, 3, 3], [$count($data['similar']), $count($data['newest']), $count($data['recently_viewed'])]);
        $layout = app(LayoutStore::class)->read('products');
        $placement = array_search('main_content', array_column(array_column($layout['placements'], 'block'), 'type'), true);
        $layout['placements'][$placement]['block']['settings'] = ['similar_limit' => 1, 'newest_limit' => 2, 'recently_viewed_limit' => 1];
        app(LayoutStore::class)->save((string) $this->actor->id, 'products', $layout);
        $data = app(ProductPage::class)->data((string) $current->id, 'en', null, $others);
        self::assertSame([1, 2, 1], [$count($data['similar']), $count($data['newest']), $count($data['recently_viewed'])]);
        $this->expectException(ValidationException::class);
        app(StorefrontRegistry::class)->type('main_content')->validate(['similar_limit' => 31]);
    }

    public function test_guest_history_is_unique_ordered_bounded_and_filters_hidden_products(): void
    {
        $category = $this->category('history');
        $products = [];
        for ($i = 0; $i < 13; $i++) {
            $products[] = $p = $this->product($category, 'view-'.$i);
            $this->travel(1)->seconds();
            $this->get('/history/view-'.$i)->assertOk();
        }
        $history = session(RecentlyViewedProducts::SESSION);
        self::assertCount(12, $history);
        self::assertArrayNotHasKey($products[0]->id, $history);
        $this->travel(1)->seconds();
        $this->get('/history/view-1')->assertOk()->assertInertia(function (Assert $page) use ($products): void {
            self::assertSame((string) $products[12]->id, $this->main($page)['recently_viewed']['items'][0]['id']);
        });
        self::assertSame($products[1]->id, array_key_first(session(RecentlyViewedProducts::SESSION)));
        $products[12]->forceFill(['status' => 'disabled'])->save();
        $this->get('/history/view-2')->assertOk()->assertInertia(function (Assert $page) use ($products): void {
            self::assertNotContains((string) $products[12]->id, array_column($this->main($page)['recently_viewed']['items'], 'id'));
        });
        $before = session(RecentlyViewedProducts::SESSION);
        $this->withHeader('Purpose', 'prefetch')->get('/history/view-3')->assertOk();
        self::assertSame($before, session(RecentlyViewedProducts::SESSION));
        $this->flushSession();
        $this->get('/history/view-0')->assertOk()->assertInertia(fn (Assert $page) => self::assertNull($this->main($page)['recently_viewed']));
    }

    public function test_registration_persists_guest_history_before_verification_and_isolates_accounts(): void
    {
        Notification::fake();
        $category = $this->category('registration-history');
        $product = $this->product($category, 'viewed');
        $this->get('/registration-history/viewed')->assertOk();
        $this->post('/register', ['first_name' => 'New', 'last_name' => 'Shopper', 'email' => 'history@example.test',
            'password' => 'a long registration password', 'password_confirmation' => 'a long registration password'])->assertSessionHasNoErrors()->assertRedirect('/email/verify');
        $user = UserRecord::where('email', 'history@example.test')->sole();
        $this->assertDatabaseHas('recently_viewed_products', ['user_id' => $user->id, 'product_id' => $product->id]);
        self::assertNull(session(RecentlyViewedProducts::SESSION));
        $this->assertDatabaseMissing('recently_viewed_products', ['user_id' => $this->actor->id]);
        $this->actingAs($this->actor)->get('/registration-history/viewed')->assertOk()->assertInertia(fn (Assert $page) => self::assertNull($this->main($page)['recently_viewed']));
    }

    public function test_login_merges_guest_and_persisted_views_by_timestamp_and_keeps_twelve(): void
    {
        $category = $this->category('merged-history');
        $products = [];
        for ($i = 0; $i < 14; $i++) {
            $products[] = $this->product($category, 'merged-'.$i);
        }
        foreach (array_slice($products, 0, 12) as $i => $p) {
            DB::table('recently_viewed_products')->insert(['user_id' => $this->actor->id, 'product_id' => $p->id, 'viewed_at' => now()->subMinutes(20 - $i)]);
        }
        $this->withSession([RecentlyViewedProducts::SESSION => [
            $products[0]->id => now()->subMinutes(30)->format('Y-m-d H:i:s.uP'),
            $products[5]->id => now()->subMinute()->format('Y-m-d H:i:s.uP'),
            $products[12]->id => now()->subMinutes(2)->format('Y-m-d H:i:s.uP'),
            $products[13]->id => now()->subMinutes(3)->format('Y-m-d H:i:s.uP'),
        ]]);
        $this->post('/login', ['email' => $this->actor->email, 'password' => 'a long test passphrase'])->assertSessionHasNoErrors();
        self::assertNull(session(RecentlyViewedProducts::SESSION));
        $rows = DB::table('recently_viewed_products')->where('user_id', $this->actor->id)->orderByDesc('viewed_at')->pluck('product_id')->all();
        self::assertCount(12, $rows);
        self::assertSame([$products[5]->id, $products[12]->id, $products[13]->id], array_slice($rows, 0, 3));
        self::assertNotContains($products[0]->id, $rows);
        $this->get('/merged-history/merged-10')->assertOk();
        self::assertSame($products[10]->id, DB::table('recently_viewed_products')->where('user_id', $this->actor->id)->orderByDesc('viewed_at')->value('product_id'));
        $this->flushSession();
        auth()->logout();
        $this->get('/merged-history/merged-0')->assertOk()->assertInertia(fn (Assert $page) => self::assertNull($this->main($page)['recently_viewed']));
    }

    /** The main content block's content as sent, whatever it holds. */
    private function mainBlock(Assert $page): array
    {
        foreach ($page->toArray()['props']['storefront']['regions']['content'] ?? [] as $grid) {
            foreach ($grid['blocks'] as $block) {
                if ($block['template'] === 'main_content') {
                    return $block['content'];
                }
            }
        }
        self::fail('No main content block.');
    }

    public function test_a_product_in_an_age_restricted_category_shows_only_the_gate_until_the_age_is_confirmed(): void
    {
        $adult = $this->category('adult');
        $wines = $this->category('wines', $adult);
        $adult->forceFill(['age_verification' => true, 'age_limit' => 18])->save();
        $this->product($wines, 'red-wine');

        $response = $this->get('/adult/wines/red-wine')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertInertia(function (Assert $page): void {
            $main = $this->mainBlock($page);
            // The inherited limit, the theme's own words, and nothing of the product.
            self::assertSame(['age' => 18, 'message' => ''], $main['age_gate']);
            self::assertArrayNotHasKey('product', $main);
        });
        $this->withCookie('age_verified', '18')->get('/adult/wines/red-wine')->assertOk()->assertInertia(function (Assert $page): void {
            self::assertSame('Red Wine', $this->main($page)['name']);
        });
    }

    public function test_customer_groups_on_the_product_or_its_category_decide_who_can_open_it(): void
    {
        $phones = $this->category('phones');
        $p = $this->product($phones, 'aster-one');
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_customer_groups')->insert(['product_id' => $p->id, 'group_id' => $group]);
        $this->get('/phones/aster-one')->assertNotFound();

        $user = UserRecord::create(['name' => 'Ria Stone', 'email' => 'ria@example.test', 'password' => 'a shopper passphrase']);
        $user->markEmailAsVerified();
        $customer = DB::table('customers')->insertGetId(['user_id' => $user->id, 'account_linked_at' => now(), 'email' => 'ria@example.test', 'first_name' => 'Ria', 'last_name' => 'Stone', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_group_members')->insert(['customer_id' => $customer, 'group_id' => $group]);
        $this->actingAs($user)->get('/phones/aster-one')->assertOk();

        // Open to everyone again, but its category is limited to another group: hidden from this member too.
        DB::table('product_customer_groups')->where('product_id', $p->id)->delete();
        $other = DB::table('customer_groups')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('category_customer_groups')->insert(['category_id' => $phones->id, 'group_id' => $other]);
        $this->actingAs($user)->get('/phones/aster-one')->assertNotFound();
    }

    public function test_main_content_controls_mobile_arrows_for_product_recommendations(): void
    {
        $this->product($this->category('devices'), 'mobile-arrows');
        $layout = app(LayoutStore::class)->read('products');
        $placement = array_search('main_content', array_column(array_column($layout['placements'], 'block'), 'type'), true);
        $layout['placements'][$placement]['block']['settings']['hide_arrows_mobile'] = true;
        app(LayoutStore::class)->save((string) $this->actor->id, 'products', $layout);
        $this->get('/devices/mobile-arrows')->assertOk()->assertInertia(fn (Assert $page) => $page->where('storefront.regions.content.0.blocks.0.content.display.hide_arrows_mobile', true));
    }

    public function test_main_content_sets_items_per_row_for_each_carousel_and_can_hide_other_variations(): void
    {
        $this->product($this->category('rows'), 'row-item');
        $layout = app(LayoutStore::class)->read('products');
        $placement = array_search('main_content', array_column(array_column($layout['placements'], 'block'), 'type'), true);
        $layout['placements'][$placement]['block']['settings'] = ['similar_per_row' => 3, 'variation_alternatives_per_row' => 2, 'show_variation_alternatives' => false];
        app(LayoutStore::class)->save((string) $this->actor->id, 'products', $layout);
        $this->get('/rows/row-item')->assertOk()->assertInertia(function (Assert $page): void {
            $main = $this->mainBlock($page);
            self::assertSame(['similar' => 3, 'newest' => 5, 'recently_viewed' => 5, 'variation_alternatives' => 2], $main['carousel_per_row']);
            self::assertFalse($main['sections']['show_variation_alternatives']);
        });
        $this->expectException(ValidationException::class);
        app(StorefrontRegistry::class)->type('main_content')->validate(['newest_per_row' => 9]);
    }

    public function test_reviews_and_ratings_can_be_turned_off_for_the_store_or_one_product(): void
    {
        $p = $this->product($this->category('rated'), 'rated-item');
        $customer = DB::table('customers')->insertGetId(['email' => 'rate@example.test', 'first_name' => 'Ria', 'last_name' => 'Stone', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_reviews')->insert(['product_id' => $p->id, 'customer_id' => $customer, 'rating' => 5, 'body' => 'Great item.', 'status' => 'approved', 'verified_purchase' => false, 'moderated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $other = DB::table('customers')->insertGetId(['email' => 'new@example.test', 'first_name' => 'Noa', 'last_name' => 'Lee', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $state = function () use ($p): array {
            app()->forgetInstance(GeneralSettings::class);
            $card = app(BlockContent::class)->productCards([(string) $p->id], ['locale' => 'en', 'customer' => null], app(PublicCatalog::class)->products(null))['cards'][(string) $p->id];

            return [app(ProductPage::class)->data((string) $p->id, 'en', null)['reviews_enabled'], $card['rating'] !== null];
        };
        $refused = function () use ($p, $other): bool {
            try {
                app(ProductReviews::class)->submit((string) $other, (string) $p->id, ['rating' => 4, 'body' => 'Another opinion.']);
            } catch (ValidationException) {
                return true;
            }
            DB::table('product_reviews')->where('customer_id', $other)->delete();

            return false;
        };
        self::assertSame([true, true], $state());
        self::assertFalse($refused());
        $indexed = fn (): ?int => app(SearchDocuments::class)->build([(string) $p->id])['documents'][0]->ratingTenths;
        self::assertSame(50, $indexed());
        $queued = fn (): int => (int) DB::table('search_outbox')->where('product_id', $p->id)->value('version');
        $before = $queued();
        DB::table('products')->where('id', $p->id)->update(['reviews_enabled' => false]);
        self::assertSame([false, false], $state());
        self::assertNull($indexed());
        // The switch queues the product's search document for rebuilding.
        self::assertGreaterThan($before, $queued());
        self::assertTrue($refused());
        DB::table('products')->where('id', $p->id)->update(['reviews_enabled' => true]);
        DB::table('general_settings')->where('id', 1)->update(['reviews_enabled' => false]);
        self::assertSame([false, false], $state());
        self::assertTrue($refused());
        // The rating filter ignores the address while reviews are off.
        self::assertSame([], app(CategoryListing::class)->state(['rating' => '5'])['ratings']);
    }

    public function test_card_variation_pictures_show_each_colour_once(): void
    {
        $leaf = $this->category('iphones');
        $featureGroup = DB::table('feature_groups')->orderBy('id')->value('id');
        $group = DB::table('variation_groups')->insertGetId(['name' => 'iPhone', 'created_at' => now(), 'updated_at' => now()]);
        $options = [];
        foreach (['colour' => ['Black', 'Blue'], 'memory' => ['128 GB', '256 GB']] as $code => $labels) {
            $feature = DB::table('features')->insertGetId(['code' => $code, 'type' => 'single', 'status' => 'active', 'group_id' => $featureGroup, 'position' => 1, 'purpose' => 'variations', 'variation_style' => $code === 'colour' ? 'color' : 'dropdown', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('variation_group_features')->insert(['group_id' => $group, 'feature_id' => $feature, 'position' => count($options) + 1]);
            foreach ($labels as $label) {
                $options[$label] = [$feature, DB::table('feature_options')->insertGetId(['feature_id' => $feature, 'position' => 1])];
            }
        }
        $members = [];
        foreach (['black-128' => ['Black', '128 GB'], 'black-256' => ['Black', '256 GB'], 'blue-128' => ['Blue', '128 GB']] as $slug => $choices) {
            $members[$slug] = $product = $this->product($leaf, $slug);
            foreach ($choices as $choice) {
                [$feature, $option] = $options[$choice];
                $assignment = DB::table('product_feature_assignments')->insertGetId(['product_id' => $product->id, 'feature_id' => $feature, 'type' => 'single']);
                DB::table('product_feature_choices')->insert(['assignment_id' => $assignment, 'feature_id' => $feature, 'option_id' => $option, 'type' => 'single']);
            }
            DB::table('variation_group_members')->insert(['group_id' => $group, 'product_id' => $product->id, 'signature' => implode(',', array_map(fn ($c) => implode(':', $options[$c]), $choices))]);
        }
        $content = app(BlockContent::class);
        $ids = array_map(fn ($m) => (string) $m->id, $members);
        $cards = $content->withVariations($content->productCards(array_values($ids), ['locale' => 'en', 'customer' => null], app(PublicCatalog::class)->products(null))['cards'], ['locale' => 'en', 'customer' => null]);
        // The card's own product stands for its colour; the other black member is not repeated.
        self::assertSame([$ids['black-256'], $ids['blue-128']], array_column($cards[$ids['black-256']]['variants'], 'id'));
        self::assertSame([$ids['black-128'], $ids['blue-128']], array_column($cards[$ids['black-128']]['variants'], 'id'));
        self::assertSame([$ids['black-128'], $ids['blue-128']], array_column($cards[$ids['blue-128']]['variants'], 'id'));
    }
}
