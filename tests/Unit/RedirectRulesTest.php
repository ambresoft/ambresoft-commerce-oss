<?php

namespace Tests\Unit;

use App\Website\Domain\Service\RedirectRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedirectRulesTest extends TestCase
{
    /** @return array<string, array{string, string|null}> */
    public static function paths(): array
    {
        return [
            'plain' => ['/old-page', '/old-page'],
            'case and trailing slash' => ['/Old-Page/', '/old-page'],
            'repeated slashes' => ['/shop//phones///iphone', '/shop/phones/iphone'],
            'percent encoded letters' => ['/old%2Dpage', '/old-page'],
            'greek, encoded' => ['/%CE%9A%CE%B9%CE%BD%CE%B7%CF%84%CE%AC', '/κινητά'],
            'greek, raw' => ['/Κινητά/', '/κινητά'],
            'file extension' => ['/phones.HTML', '/phones.html'],
            'surrounding spaces' => ['  /old  ', '/old'],
            'no leading slash' => ['old-page', null],
            'protocol relative' => ['//evil.example/x', null],
            'query string' => ['/old?x=1', null],
            'fragment' => ['/old#top', null],
            'backslash' => ['/old\\page', null],
            'encoded slash' => ['/old%2Fpage', null],
            'encoded backslash' => ['/old%5cpage', null],
            'encoded null' => ['/old%00', null],
            'dot segment' => ['/a/../admin', null],
            'single dot' => ['/a/./b', null],
            'control character' => ["/old\tpage", null],
            'inner space' => ['/old page', null],
            'invalid utf-8' => ['/%FF%FE', null],
            'too long' => ['/'.str_repeat('a', 2048), null],
        ];
    }

    #[DataProvider('paths')]
    public function test_paths_are_normalized_or_refused(string $input, ?string $expected): void
    {
        self::assertSame($expected, RedirectRules::path($input));
    }

    public function test_the_home_page_and_store_endpoints_are_reserved(): void
    {
        foreach (['/', '/admin', '/admin/categories', '/login', '/cart', '/checkout', '/account/orders', '/storefront/media/x', '/build/app.js', '/robots.txt', '/.well-known/x', '/pay/1'] as $path) {
            self::assertTrue(RedirectRules::reserved($path), $path);
        }
        foreach (['/old-page', '/administration-tips', '/cart-accessories', '/phones.html', '/blog/old-post'] as $path) {
            self::assertFalse(RedirectRules::reserved($path), $path);
        }
    }

    /** @return array<string, array{string, string|null}> */
    public static function destinations(): array
    {
        return [
            'internal path keeps case, query and fragment' => ['/Phones//Smartphones/?color=red#top', '/Phones/Smartphones?color=red#top'],
            'internal root' => ['/', '/'],
            'external https' => ['https://partner.example.com/path?a=1', 'https://partner.example.com/path?a=1'],
            'external http' => ['http://example.org', 'http://example.org'],
            'idn host' => ['https://παράδειγμα.gr/σελίδα', 'https://παράδειγμα.gr/σελίδα'],
            'own host becomes a path' => ['https://shop.test/new-page?x=1', '/new-page?x=1'],
            'own host, any case' => ['HTTPS://SHOP.TEST/New', '/New'],
            'protocol relative' => ['//evil.example/x', null],
            'javascript' => ['javascript:alert(1)', null],
            'data' => ['data:text/html,x', null],
            'ftp' => ['ftp://example.com/x', null],
            'credentials' => ['https://user:pass@example.com/', null],
            'user only' => ['https://admin@example.com/', null],
            'ip literal' => ['http://127.0.0.1/', null],
            'ipv6 literal' => ['http://[::1]/', null],
            'bare host' => ['http://localhost/', null],
            'backslash trick' => ['https:\\\\evil.example', null],
            'no scheme' => ['example.com/page', null],
            'spaces' => ['https://exa mple.com/', null],
            'internal dot segment' => ['/a/../b', null],
            'internal encoded slash' => ['/a%2fb', null],
            'too long' => ['https://example.com/'.str_repeat('a', 2048), null],
        ];
    }

    #[DataProvider('destinations')]
    public function test_destinations_are_internal_paths_or_safe_external_urls(string $input, ?string $expected): void
    {
        self::assertSame($expected, RedirectRules::destination($input, 'shop.test'));
    }

    public function test_the_matching_path_of_a_destination(): void
    {
        self::assertSame('/phones/smartphones', RedirectRules::internalPath('/Phones/Smartphones?color=red#top'));
        self::assertNull(RedirectRules::internalPath('https://partner.example.com/x'));
    }

    public function test_one_letter_written_two_ways_is_one_address(): void
    {
        $composed = '/'."\u{03AC}"; // ά
        $decomposed = '/'."\u{03B1}\u{0301}"; // α + combining acute accent
        self::assertSame(RedirectRules::path($composed), RedirectRules::path($decomposed));
        self::assertSame(RedirectRules::path($composed), RedirectRules::path('/'.rawurlencode("\u{03B1}\u{0301}")));
    }

    public function test_locations_are_sent_ascii_only(): void
    {
        self::assertSame('/%CE%BA%CE%B9%CE%BD%CE%B7%CF%84%CE%AC?q=%CE%B1', RedirectRules::location('/κινητά?q=α'));
        self::assertSame('https://xn--hxajbheg2az3al.gr/%CF%83%CE%B5%CE%BB%CE%AF%CE%B4%CE%B1', RedirectRules::location('https://παράδειγμα.gr/σελίδα'));
        self::assertSame('https://partner.example.com/x?a=1', RedirectRules::location('https://partner.example.com/x?a=1'));
        self::assertSame('/already%20encoded', RedirectRules::location('/already%20encoded'));
    }
}
