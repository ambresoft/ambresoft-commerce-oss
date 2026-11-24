<?php

namespace Tests\Feature;

use App\Authorization\Infrastructure\AccessChecker;
use App\Identity\Infrastructure\Security\AccountSecurity;
use App\Identity\Infrastructure\Security\IpLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class AccountSecurityTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private const AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->app->instance(IpLocation::class, new class extends IpLocation
        {
            public function resolve(?string $ip): array
            {
                return ['country' => 'GR', 'city' => 'Athens', 'timezone' => 'Europe/Athens'];
            }
        });
    }

    public function test_registration_and_login_capture_request_origin_without_passwords(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->withHeader('User-Agent', self::AGENT)
            ->post('/register', ['first_name' => 'Nikos', 'last_name' => 'Test', 'email' => 'new@example.test', 'password' => 'a long test passphrase', 'password_confirmation' => 'a long test passphrase'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $event = DB::table('identity_security_events')->where('kind', 'registration')->sole();
        self::assertSame('8.8.8.8', $event->ip);
        self::assertSame('Chrome', $event->browser);
        self::assertSame('Europe/Athens', $event->timezone);
        self::assertStringNotContainsString('a long test passphrase', json_encode($event));
        self::assertSame(1, DB::table('identity_security_events')->where('kind', 'login')->count());
        self::assertSame(1, DB::table('identity_security_events')->distinct()->count('device_key'));
    }

    public function test_same_browser_is_recognized_but_new_ip_and_new_device_are_flagged_separately(): void
    {
        $user = $this->staff();
        $device = (string) Str::uuid();
        foreach ([['8.8.8.8', $device], ['8.8.4.4', $device], ['8.8.4.4', (string) Str::uuid()]] as [$ip, $cookie]) {
            auth()->guard('web')->logout();
            auth()->forgetGuards();
            $this->withCookie(AccountSecurity::COOKIE, $cookie)->withServerVariables(['REMOTE_ADDR' => $ip])->withHeader('User-Agent', self::AGENT)
                ->post('/admin/login', ['email' => $user->email, 'password' => 'a long test passphrase'])->assertSessionHasNoErrors();
        }
        $events = DB::table('identity_security_events')->orderBy('id')->get();
        self::assertCount(3, $events);
        self::assertFalse($events[0]->new_device);
        self::assertTrue($events[1]->new_ip);
        self::assertFalse($events[1]->new_device);
        self::assertFalse($events[2]->new_ip);
        self::assertTrue($events[2]->new_device);
    }

    public function test_failed_password_counts_include_request_ip_and_cannot_grant_admin_access(): void
    {
        $user = $this->staff();
        $response = $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->withHeader('User-Agent', self::AGENT)
            ->post('/admin/login', ['email' => $user->email, 'password' => 'wrong password']);
        $response->assertSessionHasErrors('email');
        $response->assertCookie(AccountSecurity::COOKIE);
        $this->assertGuest();
        $summary = app(AccountSecurity::class)->summary((string) $user->id);
        self::assertSame(1, $summary['failures']);
        self::assertSame(1, $summary['recent_failures']);
        self::assertNull($summary['latest']);
        self::assertSame('8.8.8.8', DB::table('identity_security_events')->sole()->ip);
        $this->post('/admin/login', ['email' => 'unknown@example.test', 'password' => 'wrong password'])->assertCookie(AccountSecurity::COOKIE)->assertSessionHasErrors('email');
        self::assertSame(1, DB::table('identity_security_events')->count());
    }

    public function test_security_history_and_sensitive_props_are_reserved_for_super_admins_even_with_a_forged_grant(): void
    {
        $ordinary = $this->staff(['admin.access', 'users.update', 'account-security.view']);
        $target = $this->staff();
        self::assertFalse(app(AccessChecker::class)->allows($ordinary, 'account-security.view'));
        $this->actingAs($ordinary)->get('/admin/account-security/'.$target->id)->assertForbidden();
        $this->get('/admin/users/'.$target->id.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->where('security', null));
        $request = request();
        $request->setLaravelSession(app('session.store'));
        app(AccountSecurity::class)->record($request, $target, 'login');
        $super = $this->staff(super: true);
        $this->flushSession();
        $this->actingAs($super)->get('/admin/account-security/'.$target->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Security/Show')->where('security.identity_id', (string) $target->id)->where('events.total', 1)->where('ips.total', 1)->where('devices.total', 1)->where('events.data.0.user_id', (string) $target->id)->missing('events.data.0.device_key')->missing('events.data.0.user_agent'));
        $super->forceFill(['is_active' => false])->save();
        self::assertFalse(app(AccessChecker::class)->allows($super, 'account-security.view'));
    }

    public function test_remembered_sign_in_records_a_new_browser_after_the_session_expires(): void
    {
        $user = $this->staff();
        $rememberName = auth()->guard('web')->getRecallerName();
        $response = $this->withCookie(AccountSecurity::COOKIE, (string) Str::uuid())->post('/admin/login', ['email' => $user->email, 'password' => 'a long test passphrase', 'remember' => true]);
        $remember = $response->getCookie($rememberName)->getValue();
        $this->flushSession();
        auth()->forgetGuards();
        $this->withCookie($rememberName, $remember)->withCookie(AccountSecurity::COOKIE, (string) Str::uuid())->get('/admin')->assertOk();
        self::assertSame(2, DB::table('identity_security_events')->where('kind', 'login')->count());
        self::assertTrue(DB::table('identity_security_events')->orderByDesc('id')->first()->new_device);
    }

    public function test_private_ip_and_unconfigured_geolocation_do_not_fabricate_location(): void
    {
        $lookup = new IpLocation;
        self::assertSame(['country' => null, 'city' => null, 'timezone' => null], $lookup->resolve('127.0.0.1'));
        config(['account_security.geoip_database' => '/does-not-exist.mmdb']);
        self::assertSame(['country' => null, 'city' => null, 'timezone' => null], $lookup->resolve('8.8.8.8'));
    }
}
