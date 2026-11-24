<?php

namespace Tests\Unit;

use Ambresoft\Sitemap\Domain\Service\SitemapUrl;
use Ambresoft\Sitemap\Domain\Service\SitemapXml;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../modules/ambresoft/sitemap/src/Domain/Service/SitemapXml.php';
require_once __DIR__.'/../../modules/ambresoft/sitemap/src/Domain/Service/SitemapUrl.php';

final class SitemapXmlTest extends TestCase
{
    public function test_xml_escapes_utf8_and_uses_the_standard_namespace_without_unused_hints(): void
    {
        $parts = iterator_to_array((new SitemapXml)->parts([['url' => 'https://store.example/νέα&οδηγός', 'lastmod' => '2026-01-01T00:00:00Z']]));
        $document = new DOMDocument;
        self::assertTrue($document->loadXML($parts[0]['xml']));
        self::assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $document->documentElement->namespaceURI);
        self::assertSame('https://store.example/νέα&οδηγός', $document->getElementsByTagName('loc')->item(0)->textContent);
        self::assertStringNotContainsString('<changefreq>', $parts[0]['xml']);
        self::assertStringNotContainsString('<priority>', $parts[0]['xml']);
    }

    public function test_real_fifty_thousand_url_limit_splits_without_missing_or_repeating_an_entry(): void
    {
        $urls = (function (): iterable {
            for ($i = 0; $i < 50001; $i++) {
                yield ['url' => 'https://store.example/p-'.$i, 'lastmod' => null];
            }
        })();
        $parts = iterator_to_array((new SitemapXml)->parts($urls));
        self::assertCount(2, $parts);
        self::assertSame(50000, $parts[0]['count']);
        self::assertSame(1, $parts[1]['count']);
        self::assertSame(50000, substr_count($parts[0]['xml'], '<loc>'));
        self::assertStringContainsString('/p-49999</loc>', $parts[0]['xml']);
        self::assertStringContainsString('/p-50000</loc>', $parts[1]['xml']);
        self::assertStringNotContainsString('/p-49999</loc>', $parts[1]['xml']);
    }

    public function test_actual_fifty_megabyte_limit_splits_before_url_count_limit(): void
    {
        $urls = (function (): iterable {
            for ($i = 0; $i < 27000; $i++) {
                yield ['url' => 'https://store.example/'.str_repeat('x', 1950).'-'.$i, 'lastmod' => null];
            }
        })();
        $count = 0;
        $files = 0;
        foreach ((new SitemapXml)->parts($urls) as $part) {
            self::assertLessThanOrEqual(52428800, strlen($part['xml']));
            self::assertLessThanOrEqual(50000, $part['count']);
            self::assertSame($part['count'], substr_count($part['xml'], '<loc>'));
            $count += $part['count'];
            $files++;
        }
        self::assertSame(27000, $count);
        self::assertSame(2, $files);
    }

    public function test_byte_limit_includes_escaping_and_envelope_bytes(): void
    {
        $parts = iterator_to_array((new SitemapXml(50000, 270))->parts([
            ['url' => 'https://store.example/'.str_repeat('&', 10), 'lastmod' => null],
            ['url' => 'https://store.example/'.str_repeat('&', 10), 'lastmod' => null],
        ]));
        self::assertCount(2, $parts);
        foreach ($parts as $part) {
            self::assertLessThanOrEqual(270, strlen($part['xml']));
        }
    }

    /** @return iterable<string,array{string}> */
    public static function unsuitableUrls(): iterable
    {
        foreach (['https://foreign.example/a', 'https://store.example.evil/a', 'https://store.example/admin', 'https://store.example/cart/1', 'https://store.example/search?q=x', 'https://store.example/page?sort=a', 'https://store.example/page#anchor', 'https://store.example/../private', 'https://store.example/%2e%2e/private', 'https://store.example//about', 'https://store.example/%2fadmin', 'https://store.example/%zz', 'javascript:alert(1)', 'https://store.example/a b', 'https://store.example/%0a', 'https://store.example/%ff', 'https://store.example/forgot-password', 'https://store.example/order-status', 'https://store.example/wishlists/public'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('unsuitableUrls')]
    public function test_unsuitable_urls_are_rejected(string $url): void
    {
        self::assertFalse(SitemapUrl::valid($url, 'https://store.example'));
    }
}
