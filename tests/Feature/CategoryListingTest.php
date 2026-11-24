<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Order\Infrastructure\OrderAdministration;
use App\Payment\Infrastructure\PaymentSettings;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Infrastructure\LayoutStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

final class CategoryListingTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    private CategoryRecord $phones;

    private CategoryRecord $smartphones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        Storage::fake('local');
        app()->setLocale('en');
        $tech = $this->category('Tech', landing: true);
        $this->phones = $this->category('Phones', $tech);
        $this->smartphones = $this->category('Smartphones', $this->phones);
    }

    private function locale(): int
    {
        return (int) DB::table('locales')->where('code', 'en')->value('id');
    }

    private function category(string $name, ?CategoryRecord $parent = null, bool $landing = false): CategoryRecord
    {
        $c = CategoryRecord::create(['parent_id' => $parent?->id, 'position' => 1, 'status' => 'active']);
        $c->forceFill(['is_landing_page' => $landing])->save();
        $c->translations()->create(['locale_id' => $this->locale(), 'name' => $name, 'slug' => Str::slug($name), 'seo_title' => $name.' title', 'seo_description' => 'About '.$name]);

        return $c;
    }

    /** @param array<string,mixed> $values */
    private function product(string $name, string $price, ?CategoryRecord $category = null, array $values = []): string
    {
        $p = ProductRecord::create(['sku' => 'CL-'.Str::upper(Str::random(8)), 'price' => $price, 'quantity' => 5, 'category_id' => ($category ?? $this->smartphones)->id, 'status' => 'active', ...$values]);
        $p->translations()->create(['locale_id' => $this->locale(), 'name' => $name, 'slug' => Str::slug($name)]);

        return (string) $p->id;
    }

    private function brand(string $code, string $name): string
    {
        $id = (string) DB::table('brands')->insertGetId(['code' => $code, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('brand_translations')->insert(['brand_id' => $id, 'locale_id' => $this->locale(), 'name' => $name]);

        return $id;
    }

    private function feature(string $name, string $type, bool $filterable = true): string
    {
        $id = (string) DB::table('features')->insertGetId(['position' => (int) DB::table('features')->max('position') + 1, 'code' => Str::upper(Str::slug($name)), 'type' => $type, 'filterable' => $filterable, 'group_id' => DB::table('feature_groups')->where('system_key', 'general')->value('id')]);
        DB::table('feature_translations')->insert(['feature_id' => $id, 'locale_id' => $this->locale(), 'name' => $name]);

        return $id;
    }

    private function option(string $feature, string $label): string
    {
        $id = (string) DB::table('feature_options')->insertGetId(['feature_id' => $feature, 'position' => (int) DB::table('feature_options')->where('feature_id', $feature)->max('position') + 1]);
        DB::table('feature_option_translations')->insert(['option_id' => $id, 'locale_id' => $this->locale(), 'label' => $label]);

        return $id;
    }

    /** @param list<string>|string|bool $value Option ids, a number or a yes/no. */
    private function assign(string $product, string $feature, array|string|bool $value): void
    {
        $type = DB::table('features')->where('id', $feature)->value('type');
        $assignment = DB::table('product_feature_assignments')->insertGetId(['product_id' => $product, 'feature_id' => $feature, 'type' => $type,
            'number_value' => $type === 'number' ? $value : null, 'boolean_value' => $type === 'boolean' ? $value : null]);
        foreach (is_array($value) ? $value : [] as $option) {
            DB::table('product_feature_choices')->insert(['assignment_id' => $assignment, 'feature_id' => $feature, 'option_id' => $option, 'type' => $type]);
        }
    }

    private function listing(string $query = '', string $path = '/tech/phones/smartphones'): TestResponse
    {
        return $this->get($path.($query === '' ? '' : '?'.$query))->assertOk();
    }

    /** @return array<string,array<string,mixed>> The content of each content-region block by template. */
    private function blocks(TestResponse $response): array
    {
        $out = [];
        foreach (json_decode((string) json_encode($response->viewData('page')), true)['props']['storefront']['regions']['content'] as $grid) {
            foreach ($grid['blocks'] as $block) {
                $out[$block['template']] = [...$block['content'], 'grid_width' => $grid['width']];
            }
        }

        return $out;
    }

    /** Saves a layout's filters block settings (the structure cache follows the layout version).
     * @param array<string,mixed> $settings */
    private function filterSettings(string $pageType, array $settings): void
    {
        $layout = DB::table('storefront_layouts')->where('page_type', $pageType)->value('id');
        DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'product_filters')->update(['settings' => json_encode($settings)]);
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
    }

    /** @return list<string> */
    private function names(TestResponse $response): array
    {
        return array_column($this->blocks($response)['main_content']['listing']['items'], 'name');
    }

    /** @return array<string,int> Option label => count of one filter group. */
    private function counts(TestResponse $response, string $key): array
    {
        foreach ($this->blocks($response)['product_filters']['filters']['groups'] as $group) {
            if ($group['key'] === $key) {
                return array_column($group['options'], 'count', 'label');
            }
        }

        return [];
    }

    public function test_extra_categories_list_a_product_without_changing_its_address(): void
    {
        $gifts = $this->category('Gifts');
        $forHim = $this->category('For him', $gifts);
        $phone = $this->product('Gift phone', '300');
        $this->product('Plain phone', '200');
        DB::table('product_categories')->insert(['product_id' => $phone, 'category_id' => $forHim->id, 'position' => 1]);

        // Listed where it is an extra (and in that category's parent), with counts, and still in its main category.
        self::assertSame(['Gift phone'], $this->names($this->listing('', '/gifts/for-him')));
        self::assertSame(['Gift phone'], $this->names($this->listing('', '/gifts')));
        self::assertSame(1, $this->blocks($this->listing('', '/gifts'))['product_filters']['filters']['total']);
        self::assertEqualsCanonicalizing(['Gift phone', 'Plain phone'], $this->names($this->listing()));
        // Its address and breadcrumb keep following the main category.
        $item = collect($this->blocks($this->listing('', '/gifts'))['main_content']['listing']['items'])->firstWhere('name', 'Gift phone');
        self::assertStringStartsWith('/tech/phones/smartphones/', $item['url']);
        $page = $this->get($item['url'])->assertOk();
        self::assertStringContainsString('/tech/phones/smartphones', json_encode($page->viewData('page')['props']['storefront'] ?? [], JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString('"url":"\/gifts', json_encode($page->viewData('page')['props']['storefront'] ?? []));
    }

    public function test_a_product_category_lists_its_subtree_with_filters_beside_the_products(): void
    {
        $this->product('Pixel', '500');
        $basic = $this->category('Basic phones', $this->phones);
        $this->product('Nokia', '50', $basic);
        $this->product('Laptop', '900', $this->category('Laptops'));
        $this->product('Hidden phone', '10', null, ['status' => 'hidden']);
        $this->product('Disabled phone', '10', null, ['status' => 'disabled']);

        $response = $this->listing('', '/tech/phones');
        $response->assertInertia(fn (Assert $page) => $page->component('Storefront/Category')->where('seo.title', 'Phones title')->where('seo.graph.0.@type', 'CollectionPage')->has('seo.graph.0.mainEntity.itemListElement'));
        $blocks = $this->blocks($response);
        self::assertSame(4, $blocks['product_filters']['grid_width']);
        self::assertSame(12, $blocks['main_content']['grid_width']);
        self::assertSame(3, $blocks['main_content']['listing']['per_row']);
        // The category and its subcategories, never unrelated, hidden or disabled products.
        self::assertSame(['Nokia', 'Pixel'], $this->names($response));
        self::assertSame(2, $blocks['main_content']['listing']['total']);
        self::assertSame(['Smartphones', 'Basic phones'], array_column($blocks['main_content']['listing']['category']['items'], 'name'));
        self::assertStringContainsString('<link rel="canonical" href="'.rtrim((string) config('app.url'), '/').'/tech/phones" data-inertia="seo-canonical">', $response->getContent());
        self::assertNull($response->headers->get('X-Robots-Tag'));

        // Cards get a compare button holding the visitor's comparison; none while comparison is switched off.
        $this->put('/comparison/products/'.DB::table('products')->where('sku', 'like', 'CL-%')->orderBy('id')->value('id'))->assertOk();
        $comparison = $this->blocks($this->listing('', '/tech/phones'))['main_content']['listing']['comparison'];
        self::assertSame([(string) DB::table('products')->where('sku', 'like', 'CL-%')->orderBy('id')->value('id')], $comparison['ids']);
        DB::table('general_settings')->where('id', 1)->update(['allow_product_comparison' => false]);
        app()->forgetInstance(GeneralSettings::class);
        self::assertNull($this->blocks($this->listing('', '/tech/phones'))['main_content']['listing']['comparison']);

        // A landing page keeps its own layout; a disabled category is gone.
        $this->get('/tech')->assertOk()->assertInertia(fn (Assert $page) => $page->has('storefront.regions.content.0.blocks.0.content.category.items'));
        $this->phones->update(['status' => 'disabled']);
        $this->get('/tech/phones')->assertNotFound();
        $this->get('/tech/phones/smartphones')->assertNotFound();
    }

    public function test_filters_combine_with_and_across_groups_and_or_within_one_with_disjunctive_counts(): void
    {
        $apple = $this->brand('apple', 'Apple');
        $samsung = $this->brand('samsung', 'Samsung');
        $this->brand('nokia', 'Nokia');
        $color = $this->feature('Color', 'single');
        [$black, $white] = [$this->option($color, 'Black'), $this->option($color, 'White')];
        $bands = $this->feature('Bands', 'multiple');
        [$lte, $nr] = [$this->option($bands, 'LTE'), $this->option($bands, '5G')];
        $a1 = $this->product('Apple black', '100', null, ['brand_id' => $apple]);
        $a2 = $this->product('Apple white', '200', null, ['brand_id' => $apple]);
        $s1 = $this->product('Samsung black', '300', null, ['brand_id' => $samsung]);
        $this->assign($a1, $color, [$black]);
        $this->assign($a2, $color, [$white]);
        $this->assign($s1, $color, [$black]);
        // Several values of one product never duplicate it.
        foreach ([$a1, $a2, $s1] as $p) {
            $this->assign($p, $bands, [$lte, $nr]);
        }

        $all = $this->listing();
        self::assertSame(['Apple' => 2, 'Samsung' => 1], $this->counts($all, 'brand'));
        self::assertSame(['LTE' => 3, '5G' => 3], $this->counts($all, 'f'.$bands));
        self::assertSame(3, $this->blocks($all)['main_content']['listing']['total']);

        $both = $this->listing('brand=apple,samsung');
        self::assertCount(3, $this->names($both));
        $black = $this->listing('brand=apple&f'.$color.'='.$black);
        self::assertSame(['Apple black'], $this->names($black));
        // Brand counts ignore the brand choice but apply the color; color counts ignore the color but apply the brand.
        self::assertSame(['Apple' => 1, 'Samsung' => 1], $this->counts($black, 'brand'));
        self::assertSame(['Black' => 1, 'White' => 1], $this->counts($black, 'f'.$color));
        self::assertSame(['LTE' => 1, '5G' => 1], $this->counts($black, 'f'.$bands));
        $chips = $this->blocks($black)['main_content']['listing']['active'];
        self::assertSame(['Apple', 'Black'], array_column($chips, 'label'));
        self::assertSame('noindex, nofollow', $black->headers->get('X-Robots-Tag'));

        // A choice without results stays listed (with zero) so it can be removed; the listing says nothing matches.
        $none = $this->listing('brand=nokia');
        self::assertSame([], $this->names($none));
        self::assertSame(['Apple' => 2, 'Nokia' => 0, 'Samsung' => 1], $this->counts($none, 'brand'));
    }

    public function test_price_numbers_yes_no_stock_and_ratings_filter_on_their_own_values(): void
    {
        $screen = $this->feature('Screen', 'number');
        $wireless = $this->feature('Wireless charging', 'boolean');
        $small = $this->product('Small', '99.99', null, ['quantity' => 0]);
        $mid = $this->product('Mid', '100');
        $big = $this->product('Big', '250.50');
        $this->assign($small, $screen, '9');
        $this->assign($mid, $screen, '10');
        $this->assign($big, $screen, '100');
        $this->assign($mid, $wireless, true);
        $this->assign($big, $wireless, false);

        // Bounds are inclusive and compared as decimals, not text.
        self::assertSame(['Mid', 'Big'], $this->names($this->listing('price_min=100&price_max=250.50&sort=price_asc')));
        self::assertSame(['Mid'], $this->names($this->listing('price_min=99.995&price_max=100')));
        self::assertSame(['Mid', 'Big'], $this->names($this->listing('f'.$screen.'_min=10&sort=price_asc')));
        self::assertSame(['Small', 'Mid'], $this->names($this->listing('f'.$screen.'_max=10&sort=price_asc')));
        // Swapped bounds are read the right way round.
        self::assertSame(['Small', 'Mid'], $this->names($this->listing('f'.$screen.'_min=10&f'.$screen.'_max=9&sort=price_asc')));
        self::assertSame(['Mid'], $this->names($this->listing('f'.$wireless.'=1')));
        self::assertSame(['Mid', 'Big'], $this->names($this->listing('in_stock=1&sort=price_asc')));
        $group = collect($this->blocks($this->listing())['product_filters']['filters']['groups'])->firstWhere('key', 'f'.$screen);
        self::assertSame(['9', '100'], [$group['min'], $group['max']]);

        // Ratings: the approved average as shown on cards (4.5 rounds to 4.5, 3.95 shows 4.0 and counts as 4 stars).
        $customers = array_map(fn (int $n): string => (string) DB::table('customers')->insertGetId(['email' => "c$n@example.test", 'first_name' => 'C', 'last_name' => (string) $n, 'created_at' => now(), 'updated_at' => now()]), range(1, 20));
        $review = fn (string $product, string $customer, int $rating, string $status = 'approved') => DB::table('product_reviews')->insert(['product_id' => $product, 'customer_id' => $customer, 'rating' => $rating, 'body' => 'Text', 'status' => $status, 'moderated_at' => $status === 'pending' ? null : now(), 'created_at' => now(), 'updated_at' => now()]);
        $review($mid, $customers[0], 5);
        $review($mid, $customers[1], 4);
        foreach (range(0, 18) as $n) {
            $review($big, $customers[$n], 4);
        }
        $review($big, $customers[19], 3);
        $review($small, $customers[0], 5, 'pending');
        // Whole stars as cards show them: 4.5 and 4.0 are both four stars; several choices combine with OR.
        self::assertSame(['Mid', 'Big'], $this->names($this->listing('rating=4&sort=price_asc')));
        self::assertSame([], $this->names($this->listing('rating=5')));
        // The rating and in-stock filters are off by default; the block setting shows them.
        self::assertSame([], $this->counts($this->listing(), 'rating'));
        self::assertSame([], $this->counts($this->listing(), 'in_stock'));
        $this->filterSettings('category', ['show_rating' => true, 'show_stock' => true]);
        self::assertSame(['4 stars' => 2], $this->counts($this->listing(), 'rating'));
        self::assertSame(['In stock' => 2], $this->counts($this->listing(), 'in_stock'));
        $review($small, $customers[1], 1, 'approved');
        self::assertSame(['4 stars' => 2, '1 stars' => 1], $this->counts($this->listing(), 'rating'));
        self::assertSame(['Small'], $this->names($this->listing('rating=1')));
        self::assertEqualsCanonicalizing(['Small', 'Mid', 'Big'], $this->names($this->listing('rating=1,4')));
        // A product whose reviews are switched off no longer matches, nor is counted.
        DB::table('products')->where('id', $small)->update(['reviews_enabled' => false]);
        self::assertSame([], $this->names($this->listing('rating=1')));
        self::assertSame(['4 stars' => 2], $this->counts($this->listing(), 'rating'));
        // Off for the store: no rating filter and no rating sort.
        DB::table('general_settings')->where('id', 1)->update(['reviews_enabled' => false]);
        self::assertSame([], $this->counts($this->listing(), 'rating'));
        self::assertNotContains('rating', $this->blocks($this->listing())['main_content']['listing']['sorts']);
    }

    public function test_forged_and_unknown_filters_are_ignored_and_never_reveal_other_products(): void
    {
        $apple = $this->brand('apple', 'Apple');
        DB::table('brands')->where('id', $this->brand('gone', 'Gone'))->update(['status' => 'disabled']);
        $hiddenFeature = $this->feature('Internal', 'single', filterable: false);
        $secret = $this->option($hiddenFeature, 'Secret');
        $phone = $this->product('Phone', '100', null, ['brand_id' => $apple]);
        $this->assign($phone, $hiddenFeature, [$secret]);
        $this->product('Laptop', '100', $this->category('Laptops'), ['brand_id' => $apple]);

        foreach (['brand=gone', 'brand=APPLE;drop', 'f'.$hiddenFeature.'='.$secret, 'f999999=1', 'f1=abc', 'price_min=-5', 'price_max=1e9', 'rating=9', 'sort=price;drop', 'page=abc', 'brand[]=apple'] as $query) {
            $response = $this->listing($query);
            self::assertSame(['Phone'], $this->names($response), $query);
            self::assertSame([], $this->blocks($response)['main_content']['listing']['active'], $query);
        }
        // A non-filterable feature never becomes a filter.
        self::assertSame([], $this->counts($this->listing(), 'f'.$hiddenFeature));
    }

    public function test_sorting_is_stable_and_pages_come_from_the_database(): void
    {
        $ids = [];
        foreach (range(1, 8) as $n) {
            $ids[] = $this->product('Phone '.$n, $n <= 4 ? '100' : '200');
        }
        $layout = DB::table('storefront_layouts')->where('page_type', 'category')->value('id');
        $block = DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'main_content')->value('id');
        DB::table('storefront_blocks')->where('id', $block)->update(['settings' => json_encode(['products_per_page' => 6, 'products_per_row' => 4, 'mobile_products_per_row' => '1', 'tablet_products_per_row' => '3', 'default_sort' => 'price_asc'])]);
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');

        $first = $this->blocks($this->listing())['main_content']['listing'];
        self::assertSame(['price_asc', 4, 2, 8], [$first['sort'], $first['per_row'], $first['last_page'], $first['total']]);
        self::assertSame(1, $first['mobile_per_row']);
        self::assertSame(3, $first['tablet_per_row']);
        // Equal prices keep the product order, so pages never overlap or skip.
        self::assertSame(['Phone 1', 'Phone 2', 'Phone 3', 'Phone 4', 'Phone 5', 'Phone 6'], array_column($first['items'], 'name'));
        self::assertSame(['Phone 7', 'Phone 8'], $this->names($this->listing('page=2')));
        // A page past the end shows the last page.
        self::assertSame(['Phone 7', 'Phone 8'], $this->names($this->listing('page=99')));
        self::assertSame(['Phone 8', 'Phone 7', 'Phone 6', 'Phone 5', 'Phone 4', 'Phone 3'], $this->names($this->listing('sort=price_desc')));
        self::assertSame(['Phone 8', 'Phone 7'], array_slice($this->names($this->listing('sort=newest')), 0, 2));
        $this->listing('page=2')->assertSee('<link rel="canonical" href="'.rtrim((string) config('app.url'), '/').'/tech/phones/smartphones?page=2" data-inertia="seo-canonical">', false);

        // Hidden prices: no price filter or price sorting.
        DB::table('general_settings')->where('id', 1)->update(['hide_product_prices' => true]);
        app()->forgetInstance(GeneralSettings::class);
        $hidden = $this->listing('price_min=150&sort=price_desc');
        self::assertCount(6, $this->names($hidden));
        self::assertSame(['recommended', 'newest', 'rating'], $this->blocks($hidden)['main_content']['listing']['sorts']);
        self::assertNull(collect($this->blocks($hidden)['product_filters']['filters']['groups'])->firstWhere('key', 'price'));
    }

    public function test_the_listing_block_settings_are_validated_and_default_to_three_per_row(): void
    {
        $type = app(StorefrontRegistry::class)->type('main_content');
        $defaults = $type->validate([]);
        self::assertSame([3, 24, 'recommended', true, true, false, false, false], [$defaults['products_per_row'], $defaults['products_per_page'], $defaults['default_sort'], $defaults['show_sorting'], $defaults['show_count'], $defaults['show_brand'], $defaults['show_category'], $defaults['show_variations']]);
        self::assertSame(5, $type->validate(['products_per_row' => 5])['products_per_row']);
        self::assertSame('2', $defaults['mobile_products_per_row']);
        self::assertSame('2', $defaults['tablet_products_per_row']);
        self::assertSame('3', $type->validate(['tablet_products_per_row' => '3'])['tablet_products_per_row']);
        self::assertSame('1', $type->validate(['mobile_products_per_row' => '1'])['mobile_products_per_row']);
        $mobile = collect($type->definition()['fields'])->firstWhere('key', 'mobile_products_per_row');
        self::assertSame(['select', ['1', '2']], [$mobile['kind'], $mobile['options']]);
        $tablet = collect($type->definition()['fields'])->firstWhere('key', 'tablet_products_per_row');
        self::assertSame(['select', ['2', '3']], [$tablet['kind'], $tablet['options']]);
        foreach ([['tablet_products_per_row' => '1'], ['tablet_products_per_row' => '4'], ['mobile_products_per_row' => '3'], ['mobile_products_per_row' => '0'], ['products_per_row' => 6], ['products_per_row' => 1], ['products_per_page' => 500], ['default_sort' => 'popular'], ['default_sort' => 'name']] as $forged) {
            try {
                $type->validate($forged);
                self::fail('Accepted '.json_encode($forged));
            } catch (ValidationException) {
            }
        }
        $filters = app(StorefrontRegistry::class)->type('product_filters')->definition();
        self::assertSame([false, ['category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'search'], false], [$filters['creatable'], $filters['pages'], $filters['policy']['delete']]);
        self::assertSame(['visible_options' => 8, 'show_shopping' => true, 'show_delivery' => true, 'show_rating' => false, 'show_stock' => false], app(StorefrontRegistry::class)->type('product_filters')->validate([]));
        self::assertNotEmpty(app(LayoutStore::class)->read('category', true)['placements']);
    }

    public function test_recommended_and_rating_orders_and_the_optional_card_category_and_variations(): void
    {
        $customer = fn (int $n): string => (string) DB::table('customers')->insertGetId(['email' => "r$n@example.test", 'first_name' => 'R', 'last_name' => (string) $n, 'created_at' => now(), 'updated_at' => now()]);
        $review = fn (string $product, string $who, int $rating) => DB::table('product_reviews')->insert(['product_id' => $product, 'customer_id' => $who, 'rating' => $rating, 'body' => 'Text', 'status' => 'approved', 'moderated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $old = $this->product('Old five stars', '100');
        $sold = $this->product('Sold out five stars', '100', null, ['quantity' => 0]);
        $three = $this->product('Three stars', '100');
        $plain = $this->product('Not reviewed', '100');
        $review($old, $customer(1), 5);
        $review($sold, $customer(2), 5);
        $review($three, $customer(3), 3);
        DB::table('products')->where('id', $old)->update(['created_at' => now()->subYear()]);

        // Recommended (the default): in stock first, then the best rating, then the newest.
        $first = $this->blocks($this->listing())['main_content']['listing'];
        self::assertSame('recommended', $first['sort']);
        self::assertSame(['Old five stars', 'Three stars', 'Not reviewed', 'Sold out five stars'], array_column($first['items'], 'name'));
        self::assertSame(['Sold out five stars', 'Old five stars', 'Three stars', 'Not reviewed'], $this->names($this->listing('sort=rating')));

        // The card carries its category; the block shows it, and the variations, only when asked (both off by default).
        self::assertSame(['name' => 'Smartphones', 'url' => '/tech/phones/smartphones'], $first['items'][0]['category']);
        self::assertSame([false, false], [$first['display']['show_category'], $first['display']['show_variations']]);
        self::assertArrayNotHasKey('variants', $first['items'][0]);
        self::assertArrayNotHasKey('brand_link', $first['items'][0]);
        DB::table('products')->where('id', $old)->update(['brand_id' => $this->brand('apple', 'Apple')]);
        $group = DB::table('variation_groups')->insertGetId(['name' => 'Colours', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('variation_group_members')->insert([['group_id' => $group, 'product_id' => $old, 'signature' => '1:1'], ['group_id' => $group, 'product_id' => $three, 'signature' => '1:2']]);
        $layout = DB::table('storefront_layouts')->where('page_type', 'category')->value('id');
        DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'main_content')->update(['settings' => json_encode(['show_brand' => true, 'show_category' => true, 'show_variations' => true])]);
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
        $listing = $this->blocks($this->listing())['main_content']['listing'];
        self::assertSame([true, true], [$listing['display']['show_category'], $listing['display']['show_variations']]);
        $cards = array_column($listing['items'], null, 'name');
        self::assertSame([$old, $three], array_column($cards['Old five stars']['variants'], 'id'));
        self::assertSame('/tech/phones/smartphones/three-stars', $cards['Old five stars']['variants'][1]['url']);
        self::assertSame([], $cards['Not reviewed']['variants']);
        // The brand links to its brand page; products without a brand show none.
        self::assertSame(['name' => 'Apple', 'url' => '/apple'], $cards['Old five stars']['brand_link']);
        self::assertNull($cards['Not reviewed']['brand_link']);
        self::assertSame($plain, $cards['Not reviewed']['id']);
    }

    public function test_a_brand_page_lists_that_brands_products_with_filters_but_no_brand_filter(): void
    {
        $apple = $this->brand('apple', 'Apple');
        $samsung = $this->brand('samsung', 'Samsung');
        $color = $this->feature('Color', 'single');
        $black = $this->option($color, 'Black');
        $mac = $this->product('MacBook', '1000', $this->category('Laptops'), ['brand_id' => $apple]);
        $this->assign($mac, $color, [$black]);
        $this->product('iPhone', '900', null, ['brand_id' => $apple]);
        $this->product('Galaxy', '800', null, ['brand_id' => $samsung]);
        $this->product('Hidden Mac', '10', null, ['brand_id' => $apple, 'status' => 'hidden']);

        $response = $this->listing('sort=price_asc', '/apple');
        $response->assertInertia(fn (Assert $page) => $page->component('Storefront/Page')->where('seo.title', 'Apple'));
        $blocks = $this->blocks($response);
        // Every category, never another brand or a hidden product.
        self::assertSame(['iPhone', 'MacBook'], $this->names($response));
        self::assertSame(['Apple', 'brand'], [$blocks['main_content']['listing']['brand']['name'], $blocks['main_content']['listing']['brand']['source']]);
        self::assertArrayNotHasKey('category', $blocks['main_content']['listing']);
        self::assertSame([], $this->counts($response, 'brand'));
        self::assertSame(['Black' => 1], $this->counts($response, 'f'.$color));
        self::assertSame(['MacBook'], $this->names($this->listing('f'.$color.'='.$black, '/apple')));
        self::assertStringContainsString('<link rel="canonical" href="'.rtrim((string) config('app.url'), '/').'/apple" data-inertia="seo-canonical">', $response->getContent());
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertNull($this->listing('', '/apple')->headers->get('X-Robots-Tag'));
    }

    public function test_shopping_options_find_new_on_sale_and_best_selling_products(): void
    {
        $old = $this->product('Old', '100');
        $recent = $this->product('Recent', '100');
        $sale = $this->product('On sale', '100');
        $ended = $this->product('Ended sale', '100');
        $sold = $this->product('Sold', '100');
        DB::table('products')->whereIn('id', [$old, $sale, $ended, $sold])->update(['created_at' => now()->subDays(200)]);
        DB::table('product_sales')->insert([
            ['product_id' => $sale, 'kind' => 'percentage', 'amount' => '10', 'ends_on' => null, 'created_at' => now(), 'updated_at' => now()],
            // Configured but no longer running: never "on sale".
            ['product_id' => $ended, 'kind' => 'percentage', 'amount' => '10', 'ends_on' => now()->subDays(3)->toDateString(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        app(PaymentSettings::class)->ensure();
        $order = app(OrderAdministration::class)->create(null, $this->orderInput($sold));
        DB::table('order_payments')->insert(['reference' => (string) Str::uuid(), 'order_id' => $order, 'payment_method_id' => $this->methodId('cod'), 'kind' => 'manual', 'state' => 'captured', 'amount' => DB::table('orders')->where('id', $order)->value('total'), 'currency_code' => 'EUR', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        // On by default, after the price; each option counted with the other groups applied.
        $all = $this->listing();
        self::assertSame(['New' => 1, 'On sale' => 1, 'Best seller' => 1], $this->counts($all, 'shop'));
        self::assertSame('shop', $this->blocks($all)['product_filters']['filters']['groups'][1]['key']);
        self::assertSame(['Recent'], $this->names($this->listing('shop=new')));
        self::assertSame(['On sale'], $this->names($this->listing('shop=sale')));
        self::assertSame(['Sold'], $this->names($this->listing('shop=bestseller')));
        // Options of one group combine with OR.
        self::assertEqualsCanonicalizing(['On sale', 'Recent'], $this->names($this->listing('shop=new,sale')));

        // The filters block setting turns the group off (an address that asks for an option still works).
        $layout = DB::table('storefront_layouts')->where('page_type', 'category')->value('id');
        DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'product_filters')->update(['settings' => json_encode(['show_shopping' => false])]);
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
        self::assertSame([], $this->counts($this->listing(), 'shop'));
        self::assertSame(['Recent'], $this->names($this->listing('shop=new')));

        // While prices are hidden there is no "on sale".
        DB::table('general_settings')->where('id', 1)->update(['hide_product_prices' => true]);
        app()->forgetInstance(GeneralSettings::class);
        self::assertCount(5, $this->names($this->listing('shop=sale')));
    }

    public function test_stickers_offered_as_filters_are_shopping_options(): void
    {
        $tagged = $this->product('Tagged', '100');
        $cheap = $this->product('Cheap', '40');
        $this->product('Plain', '100');
        $sticker = function (string $text, string $type, bool $filter, string $status = 'active'): string {
            $id = (string) DB::table('stickers')->insertGetId(['name' => $text, 'type' => $type, 'style' => 'text', 'status' => $status, 'is_filter' => $filter, 'filter_slug' => Str::slug($text), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('sticker_translations')->insert(['sticker_id' => $id, 'locale_id' => $this->locale(), 'text' => $text]);

            return $id;
        };
        $friday = $sticker('Black Friday', 'manual', true);
        $under = $sticker('Under 50', 'dynamic', true);
        $hidden = $sticker('Not a filter', 'manual', false);
        $off = $sticker('Disabled', 'manual', true, 'disabled');
        DB::table('sticker_conditions')->insert(['sticker_id' => $under, 'type' => 'price', 'maximum' => '50']);
        foreach ([$friday, $hidden, $off] as $id) {
            DB::table('product_stickers')->insert(['product_id' => $tagged, 'sticker_id' => $id]);
        }

        self::assertSame(['Black Friday' => 1, 'Under 50' => 1], array_intersect_key($this->counts($this->listing(), 'shop'), ['Black Friday' => 0, 'Under 50' => 0, 'Not a filter' => 0, 'Disabled' => 0]));
        // Shared links use the sticker's readable name, never its id.
        $shop = collect($this->blocks($this->listing())['product_filters']['filters']['groups'])->firstWhere('key', 'shop');
        self::assertSame(['value' => 'black-friday', 'param' => 'product-label'], array_intersect_key(collect($shop['options'])->firstWhere('label', 'Black Friday'), ['value' => 0, 'param' => 0]));
        $chosen = $this->listing('product-label=black-friday');
        self::assertSame(['Tagged'], $this->names($chosen));
        self::assertSame([['product-label', 'black-friday']], array_map(fn (array $a): array => [$a['param'], $a['value']], $this->blocks($chosen)['product_filters']['filters']['active']));
        self::assertSame(['Cheap'], $this->names($this->listing('product-label=under-50')));
        // Labels combine with OR, and with the other shopping options of the group.
        self::assertEqualsCanonicalizing(['Cheap', 'Tagged'], $this->names($this->listing('product-label=black-friday,under-50')));
        // A sticker that is not offered as a filter, or an id, is dropped from the address like any unknown value.
        self::assertCount(3, $this->names($this->listing('product-label=not-a-filter')));
        self::assertCount(3, $this->names($this->listing('shop=sticker'.$friday)));
    }

    public function test_delivery_time_counts_each_product_under_the_availability_label_it_shows(): void
    {
        $status = fn (string $name, int $position) => (string) tap(DB::table('availability_statuses')->insertGetId(['position' => $position]), fn ($id) => DB::table('availability_status_translations')->insert(['status_id' => $id, 'locale_id' => $this->locale(), 'name' => $name]));
        $days = $status('In 2-3 days', 50);
        $preorder = $status('Pre-order', 60);
        $this->product('Default in stock', '100');
        $this->product('Own label', '100', null, ['in_stock_status_id' => $days]);
        $this->product('Sold out', '100', null, ['quantity' => 0, 'in_stock_status_id' => $days, 'out_of_stock_status_id' => $preorder]);
        $defaultIn = DB::table('availability_status_translations')->where('status_id', DB::table('availability_statuses')->where('default_in_stock', true)->value('id'))->where('locale_id', $this->locale())->value('name');

        $counts = $this->counts($this->listing(), 'availability');
        self::assertSame([$defaultIn => 1, 'In 2-3 days' => 1, 'Pre-order' => 1], $counts);
        self::assertSame(['Own label'], $this->names($this->listing('availability='.$days)));
        // The out-of-stock product shows its out-of-stock label, not its in-stock one.
        self::assertSame(['Sold out'], $this->names($this->listing('availability='.$preorder)));
        self::assertEqualsCanonicalizing(['Own label', 'Sold out'], $this->names($this->listing('availability='.$days.','.$preorder)));
        self::assertCount(3, $this->names($this->listing('availability=999999')));

        $layout = DB::table('storefront_layouts')->where('page_type', 'category')->value('id');
        DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'product_filters')->update(['settings' => json_encode(['show_delivery' => false])]);
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
        self::assertSame([], $this->counts($this->listing(), 'availability'));
    }

    public function test_new_bestsellers_and_offers_list_the_whole_store_with_a_category_filter(): void
    {
        $laptops = $this->category('Laptops');
        $fresh = $this->product('Fresh phone', '100');
        $freshLaptop = $this->product('Fresh laptop', '900', $laptops);
        $old = $this->product('Old laptop', '800', $laptops);
        $sold = $this->product('Sold phone', '300');
        $soldMore = $this->product('Sold laptop', '1000', $laptops);
        $hidden = $this->product('Hidden', '10', null, ['status' => 'hidden']);
        DB::table('products')->whereIn('id', [$old, $sold, $soldMore])->update(['created_at' => now()->subDays(200)]);
        DB::table('product_sales')->insert(['product_id' => $old, 'kind' => 'percentage', 'amount' => '20', 'created_at' => now(), 'updated_at' => now()]);
        app(PaymentSettings::class)->ensure();
        foreach ([[$sold, 1], [$soldMore, 3], [$hidden, 5]] as [$product, $quantity]) {
            $order = app(OrderAdministration::class)->create(null, $this->orderInput($product, ['items' => [['product_id' => $product, 'quantity' => $quantity]]]));
            DB::table('order_payments')->insert(['reference' => (string) Str::uuid(), 'order_id' => $order, 'payment_method_id' => $this->methodId('cod'), 'kind' => 'manual', 'state' => 'captured', 'amount' => DB::table('orders')->where('id', $order)->value('total'), 'currency_code' => 'EUR', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        // Each page has its own layout (filters beside the listing), title and canonical address.
        $new = $this->listing('', '/new');
        $new->assertInertia(fn (Assert $page) => $page->component('Storefront/Category')->where('seo.title', 'New products'));
        self::assertStringContainsString('<link rel="canonical" href="'.rtrim((string) config('app.url'), '/').'/new" data-inertia="seo-canonical">', $new->getContent());
        self::assertSame('New products', $this->blocks($new)['main_content']['listing']['category']['name']);
        self::assertEqualsCanonicalizing(['Fresh phone', 'Fresh laptop'], $this->names($new));
        // Every product's own category is a filter here, most products first; the page's own shopping option is not.
        self::assertSame(['Laptops' => 1, 'Smartphones' => 1], $this->counts($new, 'category'));
        self::assertSame(['Fresh laptop'], $this->names($this->listing('category='.$laptops->id, '/new')));
        self::assertArrayNotHasKey('New', $this->counts($new, 'shop'));

        // Best sellers: everything visible that sold, most sold first (hidden products never).
        self::assertSame(['Sold laptop', 'Sold phone'], $this->names($this->listing('', '/bestsellers')));
        DB::table('general_settings')->where('id', 1)->update(['bestsellers_min_units' => 2]);
        app()->forgetInstance(GeneralSettings::class);
        self::assertSame(['Sold laptop'], $this->names($this->listing('', '/bestsellers')));
        self::assertSame([], $this->names($this->listing('shop=bestseller')));
        DB::table('general_settings')->where('id', 1)->update(['bestsellers_min_units' => 1]);
        DB::table('bestseller_excluded_categories')->insert(['category_id' => $this->phones->id]);
        app()->forgetInstance(GeneralSettings::class);
        self::assertSame(['Sold laptop'], $this->names($this->listing('', '/bestsellers')));
        self::assertSame([], $this->names($this->listing('shop=bestseller')));
        DB::table('bestseller_excluded_categories')->delete();
        app()->forgetInstance(GeneralSettings::class);

        self::assertSame(['Old laptop'], $this->names($this->listing('', '/offers')));
        self::assertSame(['Laptops' => 1], $this->counts($this->listing('', '/offers'), 'category'));
        // Categories do not get the category filter; an unknown or hidden category asked for is ignored.
        self::assertSame([], $this->counts($this->listing(), 'category'));
        $secret = $this->category('Secret');
        $secret->update(['status' => 'disabled']);
        self::assertCount(2, $this->names($this->listing('category='.$secret->id, '/new')));
    }

    public function test_cards_and_counts_take_a_fixed_number_of_queries(): void
    {
        $brand = $this->brand('apple', 'Apple');
        $color = $this->feature('Color', 'single');
        $options = array_map(fn (int $n): string => $this->option($color, 'Color '.$n), range(1, 6));
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->listing();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $p = $this->product('Phone 0', '100', null, ['brand_id' => $brand]);
        $this->assign($p, $color, [$options[0]]);
        $this->listing();
        $few = $count();
        foreach (range(1, 12) as $n) {
            $p = $this->product('Phone '.$n, '100', null, ['brand_id' => $brand]);
            $this->assign($p, $color, [$options[$n % 6]]);
        }
        self::assertSame($few, $count());
    }
}
