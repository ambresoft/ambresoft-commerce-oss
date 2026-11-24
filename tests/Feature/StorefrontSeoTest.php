<?php

namespace Tests\Feature;

use App\Storefront\Infrastructure\StorefrontSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class StorefrontSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => 'https://shop.example']);
        app()->setLocale('en');
    }

    private function metadata(array $main = [], string $path = '/phones/one', array $seo = []): array
    {
        return app(StorefrontSeo::class)->build(['regions' => ['content' => [['blocks' => [['template' => 'main_content', 'content' => $main]], 'children' => []]]]], ['title' => '', 'description' => '', 'keywords' => '', 'indexable' => true, 'custom_html' => '', ...$seo], Request::create($path), ['name' => 'A shop', 'logo' => null]);
    }

    private function product(array $extra = []): array
    {
        return ['name' => 'Phone "One" Ελληνικά', 'description' => '<p>Actual phone description.</p>', 'short_description' => '', 'url' => '/phones/one', 'sku' => 'SKU-1', 'images' => [['url' => '/storefront/media/product/1/2']], 'price' => '129.90', 'currency_format' => ['code' => 'EUR'], 'available' => true, 'availability' => ['in_stock' => true], 'rating' => ['count' => 0, 'average' => null], 'category' => ['name' => 'Phones', 'path' => [['name' => 'Technology', 'url' => '/tech'], ['name' => 'Phones', 'url' => '/phones']]], ...$extra];
    }

    public function test_product_graph_uses_actual_member_price_currency_stock_and_hierarchy(): void
    {
        $seo = $this->metadata(['product' => $this->product()]);
        $product = $seo['graph'][0];
        self::assertSame('Phone "One" Ελληνικά', $product['name']);
        self::assertSame('129.90', $product['offers']['price']);
        self::assertSame('EUR', $product['offers']['priceCurrency']);
        self::assertSame('https://schema.org/InStock', $product['offers']['availability']);
        self::assertSame('https://shop.example/phones/one', $product['offers']['url']);
        self::assertSame(['https://shop.example/storefront/media/product/1/2'], $product['image']);
        self::assertArrayNotHasKey('aggregateRating', $product);
        self::assertSame(['Home', 'Technology', 'Phones', 'Phone "One" Ελληνικά'], array_column($seo['graph'][1]['itemListElement'], 'name'));
    }

    public function test_unavailable_and_unpriced_products_do_not_publish_invented_offers_or_reviews(): void
    {
        $seo = $this->metadata(['product' => $this->product(['available' => false])]);
        self::assertSame('https://schema.org/OutOfStock', $seo['graph'][0]['offers']['availability']);
        $seo = $this->metadata(['product' => $this->product(['price' => null, 'sku' => '', 'images' => [], 'rating' => ['count' => 3, 'average' => '4.3']])]);
        foreach (['offers', 'sku', 'image', 'review'] as $key) {
            self::assertArrayNotHasKey($key, $seo['graph'][0]);
        }
        self::assertSame(3, $seo['graph'][0]['aggregateRating']['reviewCount']);
    }

    public function test_graph_serialization_and_head_escape_html_unicode_and_script_content(): void
    {
        $name = 'Phone </script><script>alert("x")</script> Ελληνικά';
        $seo = $this->metadata(['product' => $this->product(['name' => $name])]);
        $head = implode('', $seo['head']);
        self::assertStringNotContainsString('<script>alert', $head);
        self::assertSame(1, substr_count($head, '</script>'));
        preg_match('~<script[^>]*>(.*?)</script>~s', $head, $match);
        $decoded = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($name, $decoded['@graph'][0]['name']);
        self::assertStringContainsString('&lt;/script&gt;', $head);
    }

    public function test_collection_schema_contains_only_the_actual_page_and_preserves_page_canonical(): void
    {
        $seo = $this->metadata(['listing' => ['category' => ['name' => 'Phones', 'description' => '<p>Phones selected for you.</p>', 'url' => '/phones', 'path' => [['name' => 'Phones', 'url' => '/phones']]], 'items' => [['name' => 'Page two phone', 'url' => '/phones/two']], 'page' => 2]], '/phones?page=2', ['canonical' => 'https://shop.example/phones?page=2']);
        self::assertSame('CollectionPage', $seo['graph'][0]['@type']);
        self::assertSame('https://shop.example/phones?page=2', $seo['canonical']);
        self::assertSame('Phones selected for you.', $seo['description']);
        self::assertSame(['Page two phone'], array_column($seo['graph'][0]['mainEntity']['itemListElement'], 'name'));
    }

    public function test_private_gated_and_preview_pages_have_no_public_graph(): void
    {
        foreach (['/cart', '/checkout', '/account', '/search?q=phone', '/phones/one?preview=1'] as $path) {
            $seo = $this->metadata(['product' => $this->product()], $path);
            self::assertFalse($seo['indexable']);
            self::assertSame([], $seo['graph']);
        }
        $seo = $this->metadata(['category' => ['name' => 'Restricted', 'age_gate' => ['age' => 18]]]);
        self::assertFalse($seo['indexable']);
        self::assertSame([], $seo['graph']);
    }

    public function test_article_and_static_metadata_use_their_own_content(): void
    {
        $seo = $this->metadata(['page' => ['name' => 'Delivery', 'content' => '<p>We deliver on weekdays.</p>', 'url' => '/delivery']], '/delivery');
        self::assertSame('Delivery', $seo['title']);
        self::assertSame('We deliver on weekdays.', $seo['description']);
        self::assertSame('WebPage', $seo['graph'][0]['@type']);
        $seo = $this->metadata(['post' => ['name' => 'News', 'content' => '<p>Real news.</p>', 'url' => '/blog/news', 'published_at' => '2026-11-03T10:00:00Z', 'blog' => ['name' => 'Blog', 'url' => '/blog']]], '/blog/news');
        self::assertSame('BlogPosting', $seo['graph'][0]['@type']);
        self::assertSame('News', $seo['graph'][0]['headline']);
        self::assertArrayNotHasKey('author', $seo['graph'][0]);
    }

    public function test_production_does_not_publish_a_development_or_docker_canonical(): void
    {
        app()->instance('env', 'production');
        foreach (['http://localhost:8000', 'http://app', 'http://127.0.0.1', 'https://shop.test'] as $origin) {
            config(['app.url' => $origin]);
            $seo = $this->metadata(['product' => $this->product()]);
            self::assertNull($seo['canonical']);
            self::assertSame([], $seo['graph']);
        }
        app()->instance('env', 'testing');
    }

    public function test_admin_shell_does_not_receive_storefront_metadata_from_a_previous_request(): void
    {
        $this->get('/')->assertOk()->assertSee('application/ld+json', false);
        $this->withHeader('User-Agent', 'Mozilla/5.0')->get('/admin/login')->assertOk()->assertDontSee('application/ld+json', false)->assertDontSee('data-inertia="seo-', false);
    }

    public function test_initial_html_and_inertia_visits_share_the_same_managed_metadata(): void
    {
        $response = $this->get('/')->assertOk();
        $html = $response->getContent();
        self::assertSame(1, substr_count($html, '<title>'));
        self::assertSame(1, substr_count($html, '<meta name="description"'));
        self::assertSame(1, substr_count($html, '<link rel="canonical"'));
        self::assertSame(1, substr_count($html, '<script type="application/ld+json"'));
        $response->assertInertia(fn ($page) => $page->where('seo.canonical', 'https://shop.example/')->where('seo.graph.2.@type', 'WebSite')->has('seo.head'));
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $response->viewData('page')['version']])->get('/')->assertOk()->assertJsonPath('props.seo.canonical', 'https://shop.example/');
        $this->get('/missing-product')->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
