<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Application\Command\IndexableUrl;
use App\Storefront\Application\Contract\StorefrontUrls;
use App\Website\Infrastructure\RedirectMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SitemapProvidersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->parse('2026-11-03T12:00:00Z'));
        config(['app.url' => 'https://store.example']);
    }

    private function locale(string $code = 'en'): int
    {
        return (int) LocaleRecord::where('code', $code)->value('id');
    }

    /** @param array<string,mixed> $attributes */
    private function category(string $slug = 'laptops', ?CategoryRecord $parent = null, array $attributes = []): CategoryRecord
    {
        $row = CategoryRecord::create(['status' => 'active', 'position' => 1, 'parent_id' => $parent?->id]);
        $row->forceFill($attributes)->save();
        $row->translations()->create(['locale_id' => $this->locale(), 'name' => ucfirst($slug), 'slug' => $slug]);

        return $row;
    }

    /** @param array<string,mixed> $attributes */
    private function product(CategoryRecord $category, string $slug = 'notebook', array $attributes = []): ProductRecord
    {
        $row = ProductRecord::create(['category_id' => $category->id, 'sku' => 'MAP-'.$slug, 'status' => 'active', 'price' => '99', 'quantity' => 5, ...$attributes]);
        $row->translations()->create(['locale_id' => $this->locale(), 'name' => ucfirst($slug), 'slug' => $slug]);

        return $row;
    }

    /** @return array<string,IndexableUrl> */
    private function urls(string $section): array
    {
        $out = [];
        foreach (app(StorefrontUrls::class)->urls($section) as $url) {
            $out[$url->sourceId] = $url;
        }

        return $out;
    }

    public function test_product_and_category_urls_use_real_hierarchy_default_language_and_content_dates(): void
    {
        $root = $this->category('electronics');
        $category = $this->category('laptops', $root);
        $product = $this->product($category);
        $category->translations()->create(['locale_id' => $this->locale('el'), 'name' => 'Φορητοί', 'slug' => 'foritoi']);
        $product->translations()->create(['locale_id' => $this->locale('el'), 'name' => 'Υπολογιστής', 'slug' => 'ypologistis']);
        DB::table('products')->where('id', $product->id)->update(['updated_at' => '2026-01-02 10:00:00+00']);
        DB::table('product_translations')->where('product_id', $product->id)->update(['updated_at' => '2026-02-03 11:00:00+00']);
        app()->setLocale('el');

        $urls = $this->urls('products');

        self::assertSame('https://store.example/electronics/laptops/notebook', $urls[$product->id]->url);
        self::assertSame('2026-02-03T11:00:00+00:00', $urls[$product->id]->lastmod);
        self::assertSame('https://store.example/electronics/laptops', $this->urls('categories')[$category->id]->url);
        self::assertSame('https://store.example/', $this->urls('homepage')['1']->url);
        $this->get('/electronics/laptops/notebook')->assertOk()->assertSee('href="https://store.example/electronics/laptops/notebook"', false);
    }

    public function test_catalog_sitemap_exclusions_follow_ancestors_and_keep_direct_child_preferences(): void
    {
        $root = $this->category('technology');
        $first = $this->category('test1', $root);
        $second = $this->category('test2', $root);
        $deep = $this->category('deep', $first);
        $inside = $this->product($deep, 'inside');
        $secondProduct = $this->product($second, 'second');
        $deepProduct = $this->product($deep, 'deep-product');
        $outside = $this->category('outside');
        $kept = $this->product($outside, 'kept');
        $omitted = $this->product($outside, 'omitted', ['exclude_from_sitemap' => true]);
        self::assertArrayNotHasKey($omitted->id, $this->urls('products'));
        self::assertArrayHasKey($inside->id, $this->urls('products'));
        $root->update(['exclude_from_sitemap' => true]);
        $categories = $this->urls('categories');
        foreach ([$root, $first, $second, $deep] as $category) {
            self::assertArrayNotHasKey($category->id, $categories);
        }
        $products = $this->urls('products');
        foreach ([$inside, $secondProduct, $deepProduct] as $product) {
            self::assertArrayNotHasKey($product->id, $products);
        }
        self::assertArrayHasKey($kept->id, $products);
        self::assertFalse($first->fresh()->exclude_from_sitemap);
        self::assertFalse($inside->fresh()->exclude_from_sitemap);
        $this->get('/technology/test1')->assertOk();
        $this->get('/technology/test1/deep/inside')->assertOk();
        $first->update(['exclude_from_sitemap' => true]);
        $root->update(['exclude_from_sitemap' => false]);
        self::assertArrayHasKey($second->id, $this->urls('categories'));
        self::assertArrayHasKey($secondProduct->id, $this->urls('products'));
        self::assertArrayNotHasKey($inside->id, $this->urls('products'));
        self::assertArrayNotHasKey($deep->id, $this->urls('categories'));
        $first->update(['exclude_from_sitemap' => false]);
        self::assertArrayHasKey($deepProduct->id, $this->urls('products'));
        $root->update(['exclude_from_sitemap' => true]);
        $second->update(['parent_id' => $outside->id]);
        self::assertArrayHasKey($secondProduct->id, $this->urls('products'));
    }

    public function test_catalog_sitemap_migration_keeps_existing_records_and_defaults_to_inclusion(): void
    {
        $category = $this->category();
        $product = $this->product($category);
        $migration = require database_path('migrations/2026_11_28_000100_add_catalog_sitemap_exclusions.php');
        $migration->down();
        self::assertFalse(Schema::hasColumn('products', 'exclude_from_sitemap'));
        self::assertFalse(Schema::hasColumn('categories', 'exclude_from_sitemap'));
        $migration->up();
        self::assertFalse(DB::table('products')->where('id', $product->id)->value('exclude_from_sitemap'));
        self::assertFalse(DB::table('categories')->where('id', $category->id)->value('exclude_from_sitemap'));
        self::assertArrayHasKey($product->id, $this->urls('products'));
    }

    public function test_ineligible_product_and_category_ancestry_are_excluded(): void
    {
        $category = $this->category();
        $valid = $this->product($category);
        $this->product($category, 'disabled', ['status' => 'disabled']);
        $this->product($category, 'hidden', ['status' => 'hidden']);
        $trashed = $this->product($category, 'trashed');
        DB::table('products')->where('id', $trashed->id)->update(['deleted_at' => now()]);
        $this->product($category, 'future', ['available_from' => '2027-01-01']);
        $this->product($category, 'empty-stock', ['quantity' => 0]);
        $excluded = $this->category('excluded', attributes: ['exclude_from_discovery' => true]);
        $child = $this->category('child', $excluded);
        $this->product($child, 'not-discoverable');
        $age = $this->category('age-check', attributes: ['age_verification' => true, 'age_limit' => 18]);
        $this->product($age, 'adult');
        $off = $this->category('inactive', attributes: ['status' => 'disabled']);
        $this->product($this->category('under-inactive', $off), 'blocked');

        self::assertSame([(string) $valid->id], array_map(strval(...), array_keys($this->urls('products'))));
        self::assertSame([(string) $category->id], array_map(strval(...), array_keys($this->urls('categories'))));
    }

    public function test_stock_and_customer_group_visibility_follow_existing_rules(): void
    {
        $category = $this->category();
        $this->product($category, 'sold-out', ['quantity' => 0]);
        $backorder = $this->product($category, 'backorder', ['quantity' => 0, 'out_of_stock_actions' => 'B']);
        $private = $this->product($category, 'private');
        $group = DB::table('customer_groups')->insertGetId(['status' => 'active']);
        DB::table('product_customer_groups')->insert(['product_id' => $private->id, 'group_id' => $group]);

        self::assertSame([(string) $backorder->id], array_map(strval(...), array_keys($this->urls('products'))));
    }

    public function test_noindex_closed_store_reserved_urls_and_redirecting_sources_are_excluded(): void
    {
        $category = $this->category();
        $product = $this->product($category);
        DB::table('seo_redirects')->insert(['source_path' => '/laptops/notebook', 'destination' => '/elsewhere', 'status_code' => 301, 'enabled' => true]);
        app(RedirectMap::class)->forget();
        self::assertSame([], $this->urls('products'));
        DB::table('seo_redirects')->delete();
        app(RedirectMap::class)->forget();
        DB::table('storefront_layouts')->where('page_type', 'products')->update(['indexable' => false]);
        self::assertSame([], $this->urls('products'));
        $this->category('account');
        self::assertCount(1, $this->urls('categories'));
        foreach (['storefront_noindex' => true, 'storefront_open' => false] as $key => $value) {
            DB::table('general_settings')->update(['storefront_open' => true, 'storefront_noindex' => false, $key => $value]);
            app()->forgetInstance(GeneralSettings::class);
            self::assertSame([], $this->urls('homepage'));
            self::assertSame([], $this->urls('categories'));
        }
        self::assertNotNull($product->id);
    }

    private function page(string $slug, string $status = 'active', bool $indexable = true): int
    {
        $id = DB::table('pages')->insertGetId(['status' => $status, 'updated_at' => '2026-01-04 00:00:00+00']);
        DB::table('page_translations')->insert(['page_id' => $id, 'locale_id' => $this->locale(), 'name' => $slug, 'slug' => $slug]);
        DB::table('storefront_layouts')->insert(['page_type' => 'custom.sitemap-'.$id, 'page_id' => $id, 'indexable' => $indexable]);

        return $id;
    }

    public function test_pages_require_public_availability_a_linked_indexable_layout_and_the_canonical_slug(): void
    {
        $id = $this->page('about');
        $this->page('hidden-page', 'hidden');
        $this->page('disabled-page', 'disabled');
        $this->page('noindex-page', indexable: false);
        $expired = $this->page('expired-page');
        DB::table('pages')->where('id', $expired)->update(['available_until' => '2026-01-01']);
        DB::table('pages')->insert(['status' => 'active']);
        DB::table('page_translations')->insert(['page_id' => $id, 'locale_id' => $this->locale('el'), 'name' => 'Σχετικά', 'slug' => 'sxetika']);
        app()->setLocale('el');

        $urls = $this->urls('pages');

        self::assertCount(1, $urls);
        self::assertSame('https://store.example/about', $urls[$id]->url);
        self::assertSame('2026-01-04T00:00:00+00:00', $urls[$id]->lastmod);
    }

    public function test_brands_use_the_existing_root_url_rules_and_exclude_reserved_or_inactive_entries(): void
    {
        $ids = [];
        foreach (['apple' => 'active', 'login' => 'active', 'inactive' => 'disabled'] as $code => $status) {
            $id = DB::table('brands')->insertGetId(['code' => $code, 'status' => $status, 'updated_at' => '2026-01-05']);
            DB::table('brand_translations')->insert(['brand_id' => $id, 'locale_id' => $this->locale(), 'name' => ucfirst($code)]);
            $ids[$code] = $id;
        }
        $urls = $this->urls('brands');
        self::assertCount(1, $urls);
        self::assertSame('https://store.example/apple', $urls['brand:'.$ids['apple']]->url);
        self::assertSame('2026-01-05T00:00:00+00:00', $urls['brand:'.$ids['apple']]->lastmod);
        $this->category('apple');
        self::assertSame([], $this->urls('brands'));
    }

    /** @return array{int,int} */
    private function blog(): array
    {
        $id = DB::table('blogs')->insertGetId(['status' => 'active', 'updated_at' => '2026-01-06']);
        DB::table('blog_translations')->insert(['blog_id' => $id, 'locale_id' => $this->locale(), 'name' => 'News', 'slug' => 'news']);
        $post = DB::table('blog_posts')->insertGetId(['blog_id' => $id, 'status' => 'active', 'published_at' => '2026-01-01', 'updated_at' => '2026-02-07']);
        DB::table('blog_post_translations')->insert(['post_id' => $post, 'locale_id' => $this->locale(), 'name' => 'Article', 'slug' => 'article']);

        return [$id, $post];
    }

    public function test_blog_and_post_providers_use_real_urls_dates_and_publication_rules(): void
    {
        [$blog, $post] = $this->blog();
        DB::table('blog_translations')->insert(['blog_id' => $blog, 'locale_id' => $this->locale('el'), 'name' => 'Νέα', 'slug' => 'nea']);
        DB::table('blog_post_translations')->insert(['post_id' => $post, 'locale_id' => $this->locale('el'), 'name' => 'Άρθρο', 'slug' => 'arthro']);
        foreach (['draft' => null, 'scheduled' => '2027-01-01', 'published' => '2026-01-01'] as $slug => $date) {
            $id = DB::table('blog_posts')->insertGetId(['blog_id' => $blog, 'status' => $slug === 'published' ? 'hidden' : 'active', 'published_at' => $date]);
            DB::table('blog_post_translations')->insert(['post_id' => $id, 'locale_id' => $this->locale(), 'name' => $slug, 'slug' => $slug]);
        }
        app()->setLocale('el');
        self::assertSame('https://store.example/blog/news', $this->urls('blogs')[$blog]->url);
        $posts = $this->urls('posts');
        self::assertCount(1, $posts);
        self::assertSame('https://store.example/blog/news/article', $posts[$post]->url);
        self::assertSame('2026-02-07T00:00:00+00:00', $posts[$post]->lastmod);
        DB::table('blogs')->where('id', $blog)->update(['available_from' => '2027-01-01']);
        self::assertSame([], $this->urls('blogs'));
        self::assertSame([], $this->urls('posts'));
    }

    public function test_legacy_feature_landing_pages_reuse_the_existing_feature_option_model(): void
    {
        $group = DB::table('feature_groups')->insertGetId(['position' => 1]);
        $feature = DB::table('features')->insertGetId(['code' => 'sitemap-maker', 'type' => 'single', 'purpose' => 'landing_pages', 'status' => 'active', 'position' => 1, 'group_id' => $group]);
        DB::table('feature_translations')->insert(['feature_id' => $feature, 'locale_id' => $this->locale(), 'name' => 'Maker']);
        $option = DB::table('feature_options')->insertGetId(['feature_id' => $feature, 'status' => 'active', 'position' => 1]);
        DB::table('feature_option_translations')->insert(['option_id' => $option, 'locale_id' => $this->locale(), 'label' => 'Maker', 'slug' => 'maker']);
        DB::table('general_settings')->update(['brand_landing_pages' => false]);
        app()->forgetInstance(GeneralSettings::class);
        $urls = $this->urls('brands');
        self::assertSame('https://store.example/maker', $urls['option:'.$option]->url);
        self::assertNull($urls['option:'.$option]->lastmod);
        DB::table('feature_options')->where('id', $option)->update(['status' => 'archived']);
        self::assertSame([], $this->urls('brands'));
    }
}
