<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class RobotsTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_private_pages_are_marked_noindex_in_headers_and_markup(): void
    {
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actingAs($this->staff(super: true));
        foreach (['/admin', '/admin/customers', '/admin/taxes'] as $url) {
            $response = $this->withHeader('User-Agent', self::BROWSER)->get($url)->assertOk();
            self::assertSame('noindex, nofollow, noarchive, nosnippet, noimageindex', $response->headers->get('X-Robots-Tag'), $url);
            self::assertStringContainsString('<meta name="robots" content="noindex, nofollow', $response->getContent());
        }
        foreach (['/login', '/register', '/forgot-password', '/admin/login', '/admin/register', '/admin/forgot-password'] as $url) {
            $this->flushSession();
            $response = $this->withHeader('User-Agent', self::BROWSER)->get($url);
            self::assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'), $url);
        }
    }

    public function test_redirects_and_denials_under_admin_are_also_noindex(): void
    {
        // A guest is redirected to sign in; the redirect itself must not be indexable either.
        $guest = $this->withHeader('User-Agent', self::BROWSER)->get('/admin/customers');
        $guest->assertRedirect();
        self::assertStringContainsString('noindex', (string) $guest->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('nofollow', (string) $guest->headers->get('X-Robots-Tag'));
    }

    public function test_known_crawlers_never_reach_the_admin_area(): void
    {
        foreach (['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Mozilla/5.0 (compatible; bingbot/2.0)', 'AhrefsBot/7.0', 'GPTBot/1.0', 'Mozilla/5.0 (compatible; YandexBot/3.0)', 'curl/8.4.0'] as $agent) {
            $response = $this->withHeader('User-Agent', $agent)->get('/admin');
            $response->assertForbidden();
            self::assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        }
        // Not even a redirect to the sign-in page is offered to a crawler.
        $this->withHeader('User-Agent', 'Googlebot/2.1')->get('/admin/customers')->assertForbidden();
        // Browsers are unaffected.
        $this->withHeader('User-Agent', self::BROWSER)->get('/admin')->assertRedirect();
    }

    public function test_the_health_check_and_robots_file_stay_reachable(): void
    {
        $this->withHeader('User-Agent', 'Googlebot/2.1')->get('/up')->assertOk();
        $robots = (string) file_get_contents(public_path('robots.txt'));
        foreach (['Disallow: /admin', 'Disallow: /login', 'User-agent: *', 'GPTBot'] as $line) {
            self::assertStringContainsString($line, $robots);
        }
        // Allow-everything would defeat the purpose.
        self::assertDoesNotMatchRegularExpression('/^Disallow:\s*$/m', $robots);
    }
}
