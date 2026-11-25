<?php

namespace Tests\Feature;

use App\Authorization\Infrastructure\Persistence\PermissionRecord;
use App\Authorization\Infrastructure\Persistence\RoleRecord;
use App\Identity\Infrastructure\Persistence\UserRecord as User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSPHRASE = 'a long test passphrase';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
    }

    private function user(bool $verified = true, string $email = 'staff@example.test'): User
    {
        $user = User::create(['name' => 'Test Staff', 'email' => $email, 'password' => self::PASSPHRASE]);
        if ($verified) {
            $user->markEmailAsVerified();
        }
        if ($email === 'staff@example.test') {
            $permission = PermissionRecord::firstOrCreate(['name' => 'admin.access']);
            $role = RoleRecord::firstOrCreate(['slug' => 'test-staff'], ['name' => 'Test staff']);
            $role->permissions()->sync([$permission->id]);
            $user->roles()->attach($role->id);
        }

        return $user;
    }

    private function verificationUrl(User $user, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('admin.verification.verify', now()->addMinutes($minutes), [
            'id' => $user->getKey(), 'hash' => sha1($user->email),
        ], absolute: false);
    }

    public function test_guest_authentication_pages_and_admin_redirect(): void
    {
        foreach (['/admin/login' => 'Auth/Login', '/admin/forgot-password' => 'Auth/ForgotPassword', '/admin/reset-password/token?email=test@example.test' => 'Auth/ResetPassword'] as $url => $component) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component)->where('auth.user', null));
        }
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/email/verify')->assertRedirect('/admin/login');
    }

    public function test_registration_requires_a_private_invitation_even_on_a_fresh_install(): void
    {
        $this->get('/admin/register')->assertForbidden();
        $this->post('/admin/register', [
            'name' => 'First User', 'email' => 'stranger@example.test',
            'password' => self::PASSPHRASE, 'password_confirmation' => self::PASSPHRASE,
        ])->assertForbidden();
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_valid_login_rotates_session_remembers_user_and_ignores_external_intended_redirect(): void
    {
        $user = $this->user();
        $this->get('/admin/login');
        $oldId = session()->getId();
        $response = $this->withSession(['url.intended' => 'https://attacker.test'])->post('/admin/login', [
            'email' => ' STAFF@example.test ', 'password' => self::PASSPHRASE, 'remember' => true,
        ]);
        $response->assertRedirect('/admin')->assertSessionMissing('url.intended');
        $response->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($user);
        self::assertNotSame($oldId, session()->getId());
        self::assertNotEmpty($user->fresh()->remember_token);
        self::assertNotEmpty(session('password_hash_web'));
    }

    public function test_invalid_login_is_generic_and_does_not_flash_password(): void
    {
        $this->user();
        foreach (['staff@example.test', 'missing@example.test'] as $email) {
            $this->from('/admin/login')->post('/admin/login', ['email' => $email, 'password' => 'incorrect'])
                ->assertSessionHasErrors(['email' => 'These credentials do not match our records.'])
                ->assertSessionMissing('_old_input.password');
            $this->assertGuest();
        }
    }

    public function test_login_attempts_are_limited_and_recover_after_window(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/admin/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])->assertSessionHasErrors('email');
        }
        $this->post('/admin/login', ['email' => ' MISSING@example.test ', 'password' => 'incorrect'])->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->post('/admin/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])->assertSessionHasErrors('email');
    }

    public function test_rotating_login_identities_does_not_bypass_the_ip_limit(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post('/admin/login', ['email' => 'invalid-'.$i, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        }
        $this->post('/admin/login', ['email' => 'another@example.test', 'password' => 'incorrect'])->assertStatus(429);
    }

    public function test_public_registration_and_recovery_endpoints_are_rate_limited(): void
    {
        foreach (['/admin/register' => 3, '/admin/forgot-password' => 3, '/admin/reset-password' => 5] as $path => $attempts) {
            for ($i = 0; $i < $attempts; $i++) {
                $response = $this->post($path, ['email' => 'missing@example.test']);
                $path === '/admin/register' ? $response->assertForbidden() : $response->assertRedirect();
            }
            $this->post($path, ['email' => 'missing@example.test'])->assertStatus(429)->assertHeader('Retry-After');
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_revoking_a_persisted_staff_role_denies_the_next_admin_request(): void
    {
        $this->actingAs($this->user())->get('/admin')->assertOk();
        DB::table('role_user')->delete();
        $this->get('/admin')->assertForbidden();
    }

    public function test_logout_clears_encrypted_inertia_history(): void
    {
        $this->actingAs($this->user())->get('/admin');
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $page = $this->get('/admin/login')->assertOk()->viewData('page');
        self::assertTrue($page['clearHistory']);
        self::assertTrue($page['encryptHistory']);
    }

    public function test_verified_staff_admin_has_minimal_typed_props_and_real_navigation(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get('/admin')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')
                ->where('auth.user.id', (string) $user->id)->where('auth.user.name', $user->name)
                ->where('auth.user.emailVerified', true)->where('auth.canAccessAdmin', true)
                ->has('auth.user', 4)->missing('auth.user.password')->missing('auth.user.remember_token')
                ->has('navigation', 1)->where('navigation.0.id', 'core.dashboard')
                ->where('navigation.0.href', '/admin')->where('navigation.0.active', true)
                ->missing('environment')->missing('revenue')->missing('orders')
                ->where('overview.orders', null)->where('overview.catalog', null)->where('overview.customers', null)->where('overview.activity', []));
    }

    public function test_unverified_users_cannot_enter_admin_and_verified_registration_is_not_a_staff_grant(): void
    {
        $user = $this->user(false, 'ordinary@example.test');
        $this->actingAs($user)->get('/admin')->assertRedirect('/admin/email/verify');
        $user->markEmailAsVerified();
        $this->get('/admin')->assertForbidden();
        $this->get('/admin/access-pending')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/AccessPending')->where('auth.canAccessAdmin', false));
        $this->get('/admin/email/verify')->assertRedirect('/admin/access-pending');
    }

    public function test_admin_login_accepts_assigned_staff_and_refuses_nonstaff_credentials(): void
    {
        $user = $this->user(false);
        $this->post('/admin/login', ['email' => $user->email, 'password' => self::PASSPHRASE])->assertRedirect('/admin/email/verify');
        $this->post('/admin/logout');
        $user = $this->user(false, 'ordinary@example.test');
        $this->post('/admin/login', ['email' => $user->email, 'password' => self::PASSPHRASE])->assertSessionHasErrors('email');
        $this->assertGuest();
        $user->markEmailAsVerified();
        $this->post('/admin/login', ['email' => $user->email, 'password' => self::PASSPHRASE])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_authenticated_users_cannot_access_guest_pages(): void
    {
        $this->actingAs($this->user());
        foreach (['/admin/login', '/admin/register', '/admin/forgot-password', '/admin/reset-password/token'] as $path) {
            $this->get($path)->assertRedirect('/admin/email/verify');
        }
        $this->get('/admin/email/verify')->assertRedirect('/admin');
    }

    public function test_logout_invalidates_session_rotates_csrf_and_clears_authentication(): void
    {
        $user = $this->user();
        $this->actingAs($user)->withSession(['sentinel' => 'private'])->get('/admin');
        $oldId = session()->getId();
        $oldToken = session()->token();
        $this->post('/admin/logout')->assertRedirect('/admin/login')->assertSessionMissing('sentinel')->assertSessionMissing('password_hash_web');
        $this->assertGuest();
        self::assertNotSame($oldId, session()->getId());
        self::assertNotSame($oldToken, session()->token());
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/logout')->assertStatus(405);
    }

    public function test_reset_requests_are_generic_tokens_are_hashed_and_links_use_canonical_host(): void
    {
        $user = $this->user();
        $status = 'If an account exists for that email, a password reset link will be sent.';
        $this->withHeader('Host', 'attacker.test')->from('/admin/forgot-password')->post('/admin/forgot-password', ['email' => $user->email])->assertSessionHas('status', $status);
        $token = '';
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$token): bool {
            $token = $notification->token;
            self::assertStringStartsWith(config('app.url').'/admin/reset-password/', $notification->toMail($user)->actionUrl);

            return true;
        });
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset_requested', 'status' => 'success']);
        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        self::assertNotSame($token, $stored);
        self::assertTrue(Hash::check($token, $stored));
        $this->from('/admin/forgot-password')->post('/admin/forgot-password', ['email' => 'missing@example.test'])->assertSessionHas('status', $status);
        $this->post('/admin/forgot-password', ['email' => $user->email])->assertSessionHas('status', $status);
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_password_reset_changes_hash_rotates_remember_token_and_is_single_use(): void
    {
        $user = $this->user();
        $oldHash = $user->password;
        $token = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'a new secure passphrase', 'password_confirmation' => 'a new secure passphrase'];
        $this->post('/admin/reset-password', $data)->assertRedirect('/admin/login')->assertSessionHas('status');
        $fresh = $user->fresh();
        self::assertNotSame($oldHash, $fresh->password);
        self::assertTrue(Hash::check($data['password'], $fresh->password));
        self::assertNotEmpty($fresh->remember_token);
        $this->assertGuest();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset_completed', 'actor_id' => (string) $user->id, 'status' => 'success']);
        self::assertStringNotContainsString($token, json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR));
        $this->post('/admin/reset-password', $data)->assertSessionHasErrors('email');
        $this->post('/admin/login', ['email' => $user->email, 'password' => self::PASSPHRASE])->assertSessionHasErrors('email');
        $this->post('/admin/login', ['email' => $user->email, 'password' => $data['password']])->assertRedirect('/admin');
    }

    public function test_expired_wrong_email_and_invalid_reset_tokens_are_rejected(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'a new secure passphrase', 'password_confirmation' => 'a new secure passphrase'];
        $this->post('/admin/reset-password', [...$data, 'token' => 'bad'])->assertSessionHasErrors('email');
        self::assertNull(session('_old_input.token'));
        $this->post('/admin/reset-password', [...$data, 'email' => 'other@example.test'])->assertSessionHasErrors('email');
        $this->travel(61)->minutes();
        $this->post('/admin/reset-password', $data)->assertSessionHasErrors('email');
        self::assertTrue(Hash::check(self::PASSPHRASE, $user->fresh()->password));
    }

    public function test_password_changes_revoke_existing_authenticated_sessions(): void
    {
        $user = $this->user();
        $this->post('/admin/login', ['email' => $user->email, 'password' => self::PASSPHRASE]);
        $sessionHash = session('password_hash_web');
        $user->password = 'a changed secure passphrase';
        $user->save();
        Auth::forgetGuards();
        $this->withSession(['password_hash_web' => $sessionHash])->get('/admin')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_signed_email_verification_marks_only_its_authenticated_owner_once(): void
    {
        Event::fake([Verified::class]);
        $user = $this->user(false);
        $url = $this->verificationUrl($user);
        $this->actingAs($user)->get($url)->assertRedirect('/admin')->assertSessionHas('status');
        self::assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get($url)->assertRedirect('/admin');
        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_invalid_expired_and_other_users_verification_links_are_rejected(): void
    {
        $user = $this->user(false);
        $other = $this->user(false, 'other@example.test');
        $this->actingAs($user)->get($this->verificationUrl($user).'tampered')->assertForbidden();
        $this->get($this->verificationUrl($user, -1))->assertForbidden();
        $this->get($this->verificationUrl($other))->assertForbidden();
        $wrongHash = URL::temporarySignedRoute('admin.verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1('wrong@example.test')], absolute: false);
        $this->get($wrongHash)->assertForbidden();
        self::assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_resend_is_limited_and_already_verified_accounts_are_not_mailed(): void
    {
        $user = $this->user(false);
        $this->actingAs($user);
        for ($i = 0; $i < 6; $i++) {
            $this->post('/admin/email/verification-notification')->assertRedirect()->assertSessionHas('status');
        }
        $this->post('/admin/email/verification-notification')->assertStatus(429);
        Notification::assertSentToTimes($user, VerifyEmail::class, 6);
        $this->travel(61)->seconds();
        $user->markEmailAsVerified();
        $this->post('/admin/email/verification-notification')->assertRedirect();
        Notification::assertSentToTimes($user, VerifyEmail::class, 6);
    }

    public function test_security_email_verification_url_uses_canonical_host(): void
    {
        $user = $this->user(false);
        $this->withHeader('Host', 'attacker.test')->actingAs($user)->post('/admin/email/verification-notification');
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;
            self::assertStringStartsWith(config('app.url').'/admin/email/verify/', $url);
            $this->get($url)->assertRedirect('/admin');

            return true;
        });
    }

    public function test_csrf_is_required_for_browser_mutations(): void
    {
        // Laravel normally bypasses CSRF in tests. Exercise the real middleware.
        $this->app->instance('env', 'production');
        foreach (['/admin/login', '/admin/register', '/admin/forgot-password', '/admin/reset-password'] as $path) {
            $this->post($path, [])->assertStatus(419);
        }
        $this->actingAs($this->user())->post('/admin/logout')->assertStatus(419);
        $this->post('/admin/email/verification-notification')->assertStatus(419);
        $this->get('/admin/email/verify');
        $this->post('/admin/logout', ['_token' => session()->token()])->assertRedirect('/admin/login');
    }
}
