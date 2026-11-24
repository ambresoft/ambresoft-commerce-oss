<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Auth\StaffInvitations;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CustomerAccountTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private const PASSWORD = 'shop pass';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
    }

    /** @param array<string, mixed> $fields */
    private function register(array $fields = []): UserRecord
    {
        $this->post('/register', [...['first_name' => 'Maria', 'last_name' => 'Papadopoulou', 'email' => 'maria@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD], ...$fields])
            ->assertSessionHasNoErrors()->assertRedirect('/email/verify');

        return UserRecord::where('email', mb_strtolower(trim($fields['email'] ?? 'maria@example.test')))->sole();
    }

    private function verifyUrl(UserRecord $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)], absolute: false);
    }

    private function staffCustomer(string $email, string $status = 'active'): int
    {
        $id = (int) DB::table('customers')->insertGetId(['email' => $email, 'first_name' => 'Staff', 'last_name' => 'Entered', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_addresses')->insert(['customer_id' => $id, 'first_name' => 'Staff', 'last_name' => 'Entered', 'line1' => 'Ermou 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function defaultGroup(): int
    {
        $id = (int) DB::table('customer_groups')->insertGetId(['status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_group_translations')->insert(['group_id' => $id, 'locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'Retail']);

        return $id;
    }

    public function test_customer_auth_pages_share_the_store_brand_while_the_admin_area_does_not(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Auth/Login')->where('authArea', 'customer')
            ->has('storeBrand.name')->has('storeBrand.colors.primary')->where('storeBrand.colors.background', fn ($c) => preg_match('/^#[0-9a-f]{6}$/i', $c) === 1));
        $this->get('/admin/login')->assertOk()->assertInertia(fn (Assert $p) => $p->where('authArea', 'admin')->where('storeBrand', null));
    }

    public function test_registration_keeps_names_pending_and_sends_a_customer_verification_link(): void
    {
        $this->get('/register')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Customer/Register')->where('authArea', 'customer')->where('links', fn ($l) => $l['register.store'] === '/register')->where('links.login', '/login'));
        $user = $this->register(['first_name' => ' Maria ', 'email' => ' Maria@Example.test ']);
        self::assertSame(['Maria Papadopoulou', 'maria@example.test', false, null], [$user->name, $user->email, $user->staff_password, $user->email_verified_at]);
        self::assertSame(['Maria', 'Papadopoulou'], array_values((array) DB::table('customer_registrations')->where('user_id', $user->id)->first(['first_name', 'last_name'])));
        self::assertSame(0, DB::table('customers')->count());
        self::assertSame(0, DB::table('role_user')->where('user_id', $user->id)->count());
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;
            self::assertStringStartsWith(config('app.url').'/email/verify/'.$user->id.'/', $url);

            return true;
        });
        // Signed in but unverified: account pages wait for verification, administration stays closed.
        $this->get('/account')->assertRedirect('/email/verify');
        $this->get('/email/verify')->assertInertia(fn (Assert $p) => $p->component('Auth/VerifyEmail')->where('links', fn ($l) => $l['verification.send'] === '/email/verification-notification')->where('links.logout', '/logout'));
        $this->get('/admin')->assertRedirect('/admin/email/verify');
    }

    public function test_registration_is_validated_with_the_customer_password_rule(): void
    {
        $base = ['first_name' => 'Maria', 'last_name' => 'Papadopoulou', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
        $invalid = [['password' => 'seven77', 'password_confirmation' => 'seven77'], ['password_confirmation' => 'something else'], ['password' => str_repeat('p', 129), 'password_confirmation' => str_repeat('p', 129)],
            ['first_name' => '   '], ['last_name' => ''], ['first_name' => str_repeat('a', 61)], ['last_name' => "Tab\tbed"], ['email' => 'not-an-email']];
        foreach ($invalid as $n => $fields) {
            $this->post('/register', [...$base, 'email' => 'invalid'.$n.'@example.test', ...$fields])->assertSessionHasErrors();
        }
        self::assertSame(0, UserRecord::count());
        $this->register(['password' => '8chars!!', 'password_confirmation' => '8chars!!']);
        // An e-mail that already signs in (staff or customer) gets the generic answer.
        $this->post('/logout');
        $staff = $this->staff();
        $this->post('/register', [...$base, 'email' => strtoupper($staff->email)])->assertSessionHasErrors(['email' => __('auth.register_failed')]);
        self::assertSame(2, UserRecord::count());
    }

    public function test_verification_creates_a_profile_in_the_default_group(): void
    {
        $group = $this->defaultGroup();
        $user = $this->register();
        $this->get($this->verifyUrl($user))->assertRedirect('/account');
        $customer = DB::table('customers')->sole();
        self::assertSame([$user->id, 'maria@example.test', 'Maria', 'Papadopoulou', 'active', null], [(int) $customer->user_id, $customer->email, $customer->first_name, $customer->last_name, $customer->status, $customer->created_by]);
        self::assertNotNull($customer->account_linked_at);
        self::assertSame([$group], DB::table('customer_group_members')->where('customer_id', $customer->id)->pluck('group_id')->map(intval(...))->all());
        self::assertSame(0, DB::table('customer_registrations')->count());
        $this->get('/account')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Storefront/Account')->where('storefront.regions.content.0.blocks.0.content.account.profile.first_name', 'Maria')->where('storefront.regions.content.0.blocks.0.content.account.profile.email', 'maria@example.test')->where('authArea', 'customer'));
        // Linking is audited without copying the names.
        $log = DB::table('audit_logs')->where('entity_type', 'customer')->where('action', 'customer.created')->sole();
        self::assertStringContainsString('"user_id"', $log->changes);
        self::assertStringNotContainsString('Papadopoulou', $log->changes);
        self::assertSame((string) $user->id, (string) $log->actor_id);
    }

    public function test_verification_claims_a_staff_created_profile_with_the_same_email(): void
    {
        $existing = $this->staffCustomer('maria@example.test');
        DB::table('customer_group_members')->insert(['customer_id' => $existing, 'group_id' => $this->defaultGroup()]);
        $user = $this->register(['first_name' => 'Someone', 'last_name' => 'Else']);
        // Before verification nothing is linked: knowing an e-mail is not ownership.
        self::assertNull(DB::table('customers')->where('id', $existing)->value('user_id'));
        $this->get($this->verifyUrl($user))->assertRedirect('/account');
        $customer = DB::table('customers')->sole();
        self::assertSame([$existing, $user->id, 'Staff', 'Entered', 2], [(int) $customer->id, (int) $customer->user_id, $customer->first_name, $customer->last_name, (int) $customer->lock_version]);
        self::assertSame(1, DB::table('customer_addresses')->where('customer_id', $existing)->count());
        self::assertSame(1, DB::table('customer_group_members')->where('customer_id', $existing)->count());
        self::assertSame(0, DB::table('customer_registrations')->count());
    }

    public function test_sign_in_follows_verification_and_customer_status(): void
    {
        $user = $this->register();
        $this->post('/logout')->assertRedirect('/login');
        $this->get('/account')->assertRedirect('/login');
        $this->post('/login', ['email' => 'maria@example.test', 'password' => 'wrong password'])->assertSessionHasErrors(['email' => __('auth.failed')]);
        $this->post('/login', ['email' => 'maria@example.test', 'password' => self::PASSWORD])->assertRedirect('/email/verify');
        $user->markEmailAsVerified();
        $this->post('/logout');
        // Verified elsewhere: signing in attaches the profile from the pending registration.
        $this->post('/login', ['email' => 'maria@example.test', 'password' => self::PASSWORD])->assertRedirect('/account');
        self::assertSame($user->id, (int) DB::table('customers')->value('user_id'));
        $this->get('/login')->assertRedirect('/account');
        // Staff disable the customer: the open session ends and signing in is refused.
        DB::table('customers')->update(['status' => 'disabled']);
        $this->get('/account')->assertRedirect('/login')->assertSessionHas('status', __('auth.customer_disabled'));
        $this->assertGuest();
        $this->post('/login', ['email' => 'maria@example.test', 'password' => self::PASSWORD])->assertSessionHasErrors(['email' => __('auth.customer_disabled')]);
        $this->assertGuest();
        // An inactive identity cannot sign in either.
        DB::table('customers')->update(['status' => 'active']);
        $user->forceFill(['is_active' => false])->save();
        $this->post('/login', ['email' => 'maria@example.test', 'password' => self::PASSWORD])->assertSessionHasErrors(['email' => __('auth.failed')]);
    }

    public function test_a_staff_identity_completes_a_customer_profile_once(): void
    {
        $staff = $this->staff(['admin.access']);
        $this->post('/login', ['email' => $staff->email, 'password' => 'a long test passphrase'])->assertRedirect('/account/profile');
        $this->get('/account')->assertRedirect('/account/profile');
        $this->get('/account/profile')->assertInertia(fn (Assert $p) => $p->component('Customer/CompleteProfile'));
        foreach ([['first_name' => '', 'last_name' => 'Staff'], ['first_name' => 'Ana', 'last_name' => str_repeat('x', 61)]] as $invalid) {
            $this->post('/account/profile', $invalid)->assertSessionHasErrors();
        }
        $this->post('/account/profile', ['first_name' => ' Ana ', 'last_name' => 'Staff'])->assertRedirect('/account');
        self::assertSame(['Ana', 'Staff', $staff->id], array_values((array) DB::table('customers')->sole(['first_name', 'last_name', 'user_id'])));
        $this->get('/account/profile')->assertRedirect('/account');
        // Still a staff member: administration is unaffected by also being a customer.
        $this->get('/admin')->assertOk();
    }

    public function test_an_email_already_linked_to_another_identity_is_not_claimed(): void
    {
        $other = $this->staff();
        $id = $this->staffCustomer('shared@example.test');
        DB::table('customers')->where('id', $id)->update(['user_id' => $other->id, 'account_linked_at' => now()]);
        $user = $this->staff();
        DB::table('users')->where('id', $user->id)->update(['email' => 'shared@example.test']);
        $this->actingAs($user->fresh());
        $this->post('/account/profile', ['first_name' => 'Ana', 'last_name' => 'Staff'])->assertSessionHasErrors(['first_name' => __('auth.customer_conflict')]);
        self::assertSame(1, DB::table('customers')->count());
        self::assertSame($other->id, (int) DB::table('customers')->value('user_id'));
    }

    public function test_customers_never_gain_administration_and_short_passwords_cannot_open_it(): void
    {
        $user = $this->register();
        $this->get($this->verifyUrl($user));
        $this->get('/admin')->assertForbidden();
        $this->get('/admin/reviews')->assertForbidden();
        // The staff page does not serve customers: the right password fails like a wrong one and no session starts.
        $this->post('/logout');
        $this->post('/admin/login', ['email' => 'maria@example.test', 'password' => self::PASSWORD])->assertSessionHasErrors(['email' => __('auth.failed')]);
        $this->assertGuest();
        $this->get('/admin')->assertRedirect('/admin/login');
        // Granted staff access later: the 9-character customer password is not enough for administration.
        $role = DB::table('roles')->where('is_super_admin', true)->value('id');
        DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $role]);
        $this->post('/admin/login', ['email' => 'maria@example.test', 'password' => self::PASSWORD])->assertRedirect('/admin/access-pending');
        $this->get('/admin')->assertForbidden();
        $this->get('/admin/access-pending')->assertInertia(fn (Assert $p) => $p->where('passwordTooShort', true)->where('authArea', 'admin')->where('links.logout', '/admin/logout'));
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        // Staff identities get staff reset links; the staff reset requires the staff minimum.
        $this->post('/admin/forgot-password', ['email' => 'maria@example.test']);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user, &$token): bool {
            self::assertStringStartsWith(config('app.url').'/admin/reset-password/', $n->toMail($user)->actionUrl);
            $token = $n->token;

            return true;
        });
        $this->post('/admin/reset-password', ['token' => $token, 'email' => 'maria@example.test', 'password' => 'short pass', 'password_confirmation' => 'short pass'])->assertSessionHasErrors('password');
        $long = 'a much longer staff passphrase';
        $this->post('/admin/reset-password', ['token' => $token, 'email' => 'maria@example.test', 'password' => $long, 'password_confirmation' => $long])->assertRedirect('/admin/login');
        self::assertTrue($user->fresh()->staff_password);
        $this->post('/admin/login', ['email' => 'maria@example.test', 'password' => $long])->assertRedirect('/admin');
        $this->get('/admin')->assertOk();
    }

    public function test_customers_reset_passwords_on_customer_pages(): void
    {
        $user = $this->register();
        $user->markEmailAsVerified();
        $this->post('/logout');
        $this->get('/forgot-password')->assertInertia(fn (Assert $p) => $p->component('Auth/ForgotPassword')->where('links', fn ($l) => $l['password.email'] === '/forgot-password'));
        $this->post('/forgot-password', ['email' => 'maria@example.test'])->assertSessionHas('status', __('auth.reset_sent'));
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user, &$token): bool {
            self::assertStringStartsWith(config('app.url').'/reset-password/', $n->toMail($user)->actionUrl);
            $token = $n->token;

            return true;
        });
        $this->get('/reset-password/'.$token.'?email=maria@example.test')->assertInertia(fn (Assert $p) => $p->component('Auth/ResetPassword')->where('links', fn ($l) => $l['password.update'] === '/reset-password')->where('authArea', 'customer'));
        $this->post('/reset-password', ['token' => $token, 'email' => 'maria@example.test', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => $token, 'email' => 'maria@example.test', 'password' => 'new pass 9', 'password_confirmation' => 'new pass 9'])->assertRedirect('/login');
        $this->post('/login', ['email' => 'maria@example.test', 'password' => 'new pass 9'])->assertRedirect('/account');
        self::assertFalse($user->fresh()->staff_password);
    }

    public function test_each_area_redirects_to_its_own_pages(): void
    {
        $this->get('/admin/customers')->assertRedirect('/admin/login');
        $this->get('/account')->assertRedirect('/login');
        $this->get('/admin/login')->assertInertia(fn (Assert $p) => $p->component('Auth/Login')->where('authArea', 'admin')->where('links', fn ($l) => $l['login.store'] === '/admin/login')->where('links.register', '/admin/register')->where('links', fn ($l) => $l['password.request'] === '/admin/forgot-password'));
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->component('Auth/Login')->where('authArea', 'customer')->where('links', fn ($l) => $l['login.store'] === '/login')->where('links.register', '/register'));
        // Staff register only through a private invitation (A99): the page itself stays in the admin area.
        $this->get('/admin/register')->assertForbidden();
        $this->artisan('cms:admin-setup invited@example.test')->assertSuccessful();
        $invitation = app(StaffInvitations::class)->url(UserRecord::where('email', 'invited@example.test')->sole());
        $this->get($invitation)->assertInertia(fn (Assert $p) => $p->component('Auth/Register')->where('invitation.email', 'invited@example.test'));
        $staff = $this->staff(super: true);
        $this->actingAs($staff);
        $this->get('/admin/login')->assertRedirect('/admin/email/verify');
        $this->get('/admin/email/verify')->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertInertia(fn (Assert $p) => $p->where('links.logout', '/admin/logout'));
    }

    public function test_the_account_migration_round_trips(): void
    {
        $migration = require database_path('migrations/2026_10_28_000100_add_customer_accounts.php');
        $user = $this->register();
        $this->get($this->verifyUrl($user));
        $migration->down();
        self::assertFalse(Schema::hasColumn('customers', 'user_id'));
        self::assertFalse(Schema::hasTable('customer_registrations'));
        self::assertSame(1, DB::table('customers')->count());
        $migration->up();
        self::assertTrue((bool) DB::table('users')->where('id', $user->id)->value('staff_password'));
        self::assertNull(DB::table('customers')->value('user_id'));
    }
}
