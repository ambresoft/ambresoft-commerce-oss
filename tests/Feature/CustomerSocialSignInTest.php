<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CustomerSocialSignInTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private const CLIENT_ID = '1234567890-abcdef.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
    }

    private function provider(bool $active = true): void
    {
        $this->actingAs($this->staff(super: true))->post('/admin/social-login', ['provider' => 'google', 'name' => 'Google', 'is_active' => $active, 'settings' => ['client_id' => self::CLIENT_ID, 'client_secret' => 'GOCSPX-secret']])->assertSessionHasNoErrors();
        auth()->logout();
        $this->flushSession();
    }

    /** Runs the redirect and returns the state the callback must echo. */
    private function start(): string
    {
        $location = $this->get('/auth/social/google/redirect')->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth?')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame(self::CLIENT_ID, $query['client_id']);
        self::assertSame(rtrim((string) config('app.url'), '/').'/auth/social/google/callback', $query['redirect_uri']);
        self::assertSame('S256', $query['code_challenge_method']);

        return $query['state'];
    }

    /** @param array<string, mixed> $profile */
    private function fakeGoogle(array $profile): void
    {
        // A new fake does not replace the previous one, so start from a fresh HTTP factory.
        app()->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token']),
            'openidconnect.googleapis.com/*' => Http::response($profile),
        ]);
    }

    public function test_only_an_active_complete_provider_shows_a_button_on_the_customer_pages(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('socialProviders', []));
        $this->provider(false);
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('socialProviders', []));
        $this->get('/auth/social/google/redirect')->assertRedirect('/login')->assertSessionHasErrors('email');
        DB::table('social_login_providers')->update(['is_active' => true]);
        foreach (['/login' => 'Auth/Login', '/register' => 'Customer/Register'] as $url => $component) {
            $this->get($url)->assertInertia(fn (Assert $p) => $p->component($component)->where('socialProviders.0.provider', 'google')->where('socialProviders.0.name', 'Google')->where('socialProviders.0.url', '/auth/social/google/redirect'));
        }
        $this->get('/admin/login')->assertInertia(fn (Assert $p) => $p->missing('socialProviders'));
    }

    public function test_a_new_google_customer_is_created_verified_and_signed_in(): void
    {
        $this->provider();
        $state = $this->start();
        $this->fakeGoogle(['email' => 'New.Customer@Example.test', 'email_verified' => true, 'given_name' => 'Nina', 'family_name' => 'Kosta']);

        $this->get('/auth/social/google/callback?code=abc&state='.$state)->assertRedirect('/account');
        $user = UserRecord::where('email', 'new.customer@example.test')->sole();
        $this->assertAuthenticatedAs($user);
        self::assertTrue($user->hasVerifiedEmail());
        self::assertSame(['Nina', 'Kosta'], [DB::table('customers')->where('user_id', $user->id)->value('first_name'), DB::table('customers')->where('user_id', $user->id)->value('last_name')]);
        $this->get('/admin')->assertForbidden();
    }

    public function test_a_wrong_state_unverified_google_email_or_replayed_callback_signs_nobody_in(): void
    {
        $this->provider();
        $this->fakeGoogle(['email' => 'a@example.test', 'email_verified' => true]);
        $state = $this->start();
        $this->get('/auth/social/google/callback?code=abc&state=forged')->assertRedirect('/login')->assertSessionHasErrors('email');
        // The state is single use.
        $this->get('/auth/social/google/callback?code=abc&state='.$state)->assertRedirect('/login');
        $this->assertGuest();
        $state = $this->start();
        $this->fakeGoogle(['email' => 'a@example.test', 'email_verified' => false]);
        $this->get('/auth/social/google/callback?code=abc&state='.$state)->assertRedirect('/login');
        $this->assertGuest();
        self::assertSame(0, UserRecord::where('email', 'a@example.test')->count());
    }

    public function test_an_existing_customer_signs_in_and_an_unverified_registration_loses_its_password(): void
    {
        $this->provider();
        $this->post('/register', ['first_name' => 'Maria', 'last_name' => 'P', 'email' => 'maria@example.test', 'password' => 'attacker pass', 'password_confirmation' => 'attacker pass'])->assertRedirect('/email/verify');
        $this->post('/logout');
        $this->flushSession();
        $this->fakeGoogle(['email' => 'maria@example.test', 'email_verified' => true]);
        $this->get('/auth/social/google/callback?code=abc&state='.$this->start())->assertRedirect('/account');
        $user = UserRecord::where('email', 'maria@example.test')->sole();
        $this->assertAuthenticatedAs($user);
        self::assertTrue($user->hasVerifiedEmail());
        self::assertFalse(Hash::check('attacker pass', $user->password));
        self::assertSame(1, UserRecord::where('email', 'maria@example.test')->count());
    }

    public function test_staff_and_deactivated_identities_cannot_use_a_provider_sign_in(): void
    {
        $this->provider();
        $staff = $this->staff();
        $this->fakeGoogle(['email' => $staff->email, 'email_verified' => true]);
        $this->get('/auth/social/google/callback?code=abc&state='.$this->start())->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/register', ['first_name' => 'Off', 'last_name' => 'Line', 'email' => 'off@example.test', 'password' => 'shop pass', 'password_confirmation' => 'shop pass']);
        $this->post('/logout');
        $this->flushSession();
        UserRecord::where('email', 'off@example.test')->update(['is_active' => false]);
        $this->fakeGoogle(['email' => 'off@example.test', 'email_verified' => true]);
        $this->get('/auth/social/google/callback?code=abc&state='.$this->start())->assertRedirect('/login');
        $this->assertGuest();
    }
}
