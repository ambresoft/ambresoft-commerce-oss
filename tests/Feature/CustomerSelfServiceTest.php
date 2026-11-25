<?php

namespace Tests\Feature;

use App\Customer\Infrastructure\CustomerInvitation;
use App\Geography\Application\Contract\ShippingGeography;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Order\Infrastructure\OrderAdministration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CustomerSelfServiceTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    /** Where the Account layout's main content block carries the open section. */
    private const ACCOUNT = 'storefront.regions.content.0.blocks.0.content.account.';

    private const PASSWORD = 'shop pass';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->region('GR', 'ATT', 'Αττική', 'Attica');
        $this->region('GR', 'CRE', 'Κρήτη', 'Crete');
        $this->region('GR', 'OLD', 'Παλιά', 'Old', 'disabled');
    }

    private function region(string $country, string $code, string $el, string $en, string $status = 'active'): void
    {
        DB::table('shipping_countries')->insertOrIgnore(['country_code' => $country, 'created_at' => now()]);
        $id = DB::table('regions')->insertGetId(['country_code' => $country, 'code' => $code, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['el' => $el, 'en' => $en] as $locale => $name) {
            DB::table('region_translations')->insert(['region_id' => $id, 'locale_id' => DB::table('locales')->where('code', $locale)->value('id'), 'name' => $name]);
        }
    }

    /** A verified customer identity with a linked profile, signed in. */
    private function customer(string $email = 'maria@example.test'): UserRecord
    {
        $user = UserRecord::create(['name' => 'Maria Example', 'email' => $email, 'password' => self::PASSWORD]);
        $user->markEmailAsVerified();
        DB::table('customers')->insert(['email' => $email, 'first_name' => 'Maria', 'last_name' => 'Example', 'user_id' => $user->id, 'account_linked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    private function profile(UserRecord $user): object
    {
        return DB::table('customers')->where('user_id', $user->id)->sole();
    }

    /** @return array<string, mixed> */
    private function address(array $changes = []): array
    {
        return [...['first_name' => 'Maria', 'last_name' => 'Example', 'line1' => 'Ermou 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR', 'region_code' => 'ATT'], ...$changes];
    }

    public function test_region_options_are_active_regions_of_shipping_countries_named_in_the_locale(): void
    {
        $this->region('US', 'CA', 'Καλιφόρνια', 'California');
        DB::table('shipping_countries')->where('country_code', 'US')->delete();
        app()->setLocale('el');
        $options = app(ShippingGeography::class)->regionOptions();
        self::assertSame(['GR'], array_keys($options));
        self::assertSame([['code' => 'ATT', 'name' => 'Αττική'], ['code' => 'CRE', 'name' => 'Κρήτη']], $options['GR']);
        self::assertSame(['GR:OLD' => 'Old', 'US:CA' => 'California'], app(ShippingGeography::class)->regionNames([['GR', 'OLD'], ['US', 'CA'], ['GR', 'NOPE']], 'en'));
    }

    public function test_staff_addresses_take_regions_from_geography_and_keep_unchanged_saved_codes(): void
    {
        $this->actingAs($this->staff(super: true));
        $this->post('/admin/customers', ['first_name' => 'Ana', 'last_name' => 'Staff', 'email' => 'ana@example.test', 'tax_exempt' => false])->assertSessionHasNoErrors();
        $customer = (string) DB::table('customers')->value('id');
        $version = fn (): int => (int) DB::table('customers')->where('id', $customer)->value('lock_version');
        foreach ([['region_code' => 'OLD'], ['region_code' => 'NOPE'], ['country_code' => 'CY', 'region_code' => 'ATT'], ['country_code' => 'IE', 'region_code' => 'D']] as $invalid) {
            $this->post('/admin/customers/'.$customer.'/addresses', [...$this->address($invalid), 'version' => $version()])->assertSessionHasErrors(['region_code' => __('customers.region_invalid')]);
        }
        $this->post('/admin/customers/'.$customer.'/addresses', [...$this->address(['region_code' => 'att']), 'version' => $version()])->assertSessionHasNoErrors();
        $this->post('/admin/customers/'.$customer.'/addresses', [...$this->address(['country_code' => 'IE', 'region_code' => '']), 'version' => $version()])->assertSessionHasNoErrors();
        $address = (string) DB::table('customer_addresses')->where('region_code', 'ATT')->value('id');
        $this->get('/admin/customers/'.$customer)->assertInertia(fn (Assert $p) => $p->where('customer.addresses.0.region', 'Attica')->where('regions.GR.0.code', 'ATT')->has('regions.GR', 2));
        // A code saved before the catalog (or since disabled) stays valid while unchanged, and can be replaced.
        DB::table('customer_addresses')->where('id', $address)->update(['region_code' => 'M']);
        $this->put('/admin/customers/'.$customer.'/addresses/'.$address, [...$this->address(['region_code' => 'M', 'line1' => 'Ermou 2']), 'version' => $version()])->assertSessionHasNoErrors();
        $this->put('/admin/customers/'.$customer.'/addresses/'.$address, [...$this->address(['region_code' => 'K']), 'version' => $version()])->assertSessionHasErrors('region_code');
        $this->put('/admin/customers/'.$customer.'/addresses/'.$address, [...$this->address(['region_code' => 'CRE']), 'version' => $version()])->assertSessionHasNoErrors();
        self::assertSame(['Ermou 1', 'CRE'], array_values((array) DB::table('customer_addresses')->where('id', $address)->first(['line1', 'region_code'])));
    }

    public function test_orders_keep_their_saved_region_unchanged_but_new_regions_come_from_geography(): void
    {
        $staff = $this->staff(super: true);
        $product = $this->orderProduct('10');
        $orders = app(OrderAdministration::class);
        $id = $orders->create((string) $staff->id, $this->orderInput((string) $product->id, ['billing' => [...$this->address(), 'region_code' => 'ATT']]));
        DB::table('order_addresses')->where('order_id', $id)->update(['region_code' => 'M']);
        $item = (string) DB::table('order_items')->where('order_id', $id)->value('id');
        $update = fn (string $region): array => $this->orderInput((string) $product->id, ['version' => (int) DB::table('orders')->where('id', $id)->value('lock_version'), 'items' => [['item_id' => $item, 'quantity' => 1]], 'billing' => [...$this->address(), 'region_code' => $region]]);
        $orders->update((string) $staff->id, $id, $update('M'));
        try {
            $orders->update((string) $staff->id, $id, $update('K'));
            self::fail('An unknown region was accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('billing.region_code', $e->errors());
        }
        $orders->update((string) $staff->id, $id, $update('CRE'));
        self::assertSame('CRE', DB::table('order_addresses')->where('order_id', $id)->value('region_code'));
        $this->actingAs($staff)->get('/admin/orders/'.$id)->assertInertia(fn (Assert $p) => $p->where('order.billing.region', 'Crete'));
    }

    public function test_customers_edit_their_own_profile_only(): void
    {
        $user = $this->customer();
        $this->get('/account')->assertInertia(fn (Assert $p) => $p->component('Storefront/Account')->where(self::ACCOUNT.'profile.version', 1)->where(self::ACCOUNT.'profile.email', 'maria@example.test'));
        $this->patch('/account', ['version' => 1, 'first_name' => ' Marie ', 'last_name' => 'Exemple', 'phone' => '+30 210 1234567', 'email' => 'evil@example.test', 'status' => 'disabled', 'tax_exempt' => true, 'notes' => 'x'])->assertSessionHasNoErrors()->assertRedirect('/account');
        $row = $this->profile($user);
        self::assertSame(['Marie', 'Exemple', '+30 210 1234567', 'maria@example.test', 'active', false, null, 2, $user->id], [$row->first_name, $row->last_name, $row->phone, $row->email, $row->status, (bool) $row->tax_exempt, $row->notes, (int) $row->lock_version, (int) $row->updated_by]);
        $this->patch('/account', ['version' => 1, 'first_name' => 'Stale', 'last_name' => 'Tab'])->assertSessionHasErrors('version');
        foreach ([['first_name' => ''], ['last_name' => str_repeat('x', 101)], ['phone' => 'call me']] as $invalid) {
            $this->patch('/account', [...['version' => 2, 'first_name' => 'Marie', 'last_name' => 'Exemple'], ...$invalid])->assertSessionHasErrors();
        }
        self::assertSame(2, (int) $this->profile($user)->lock_version);
    }

    public function test_customers_manage_their_address_book(): void
    {
        $user = $this->customer();
        $other = (int) DB::table('customers')->insertGetId(['email' => 'other@example.test', 'first_name' => 'O', 'last_name' => 'T', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = (string) DB::table('customer_addresses')->insertGetId(['customer_id' => $other, 'first_name' => 'O', 'last_name' => 'T', 'line1' => 'X 1', 'city' => 'Y', 'postal_code' => '10000', 'country_code' => 'GR', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/account/addresses')->assertInertia(fn (Assert $p) => $p->component('Storefront/Account')->has(self::ACCOUNT.'addresses', 0)->where(self::ACCOUNT.'countries', [['code' => 'GR', 'name' => 'Greece']])->has(self::ACCOUNT.'regions.GR', 2));
        // Only countries the store ships to, and only that country's regions.
        $this->post('/account/addresses', [...$this->address(['country_code' => 'IE', 'region_code' => '']), 'version' => 1])->assertSessionHasErrors(['country_code' => __('account.country_not_shipped')]);
        $this->post('/account/addresses', [...$this->address(['region_code' => 'OLD']), 'version' => 1])->assertSessionHasErrors('region_code');
        $this->post('/account/addresses', [...$this->address(), 'version' => 1, 'customer_id' => $other])->assertSessionHasNoErrors();
        $this->post('/account/addresses', [...$this->address(['line1' => 'Kifisias 9', 'region_code' => 'CRE']), 'version' => 2])->assertSessionHasNoErrors();
        $customer = $this->profile($user);
        [$first, $second] = DB::table('customer_addresses')->where('customer_id', $customer->id)->orderBy('id')->pluck('id')->map(strval(...))->all();
        self::assertSame([$first, $first, 3], [(string) $customer->default_billing_address_id, (string) $customer->default_shipping_address_id, (int) $customer->lock_version]);
        self::assertSame(0, DB::table('customer_addresses')->where('customer_id', $other)->where('line1', 'Ermou 1')->count());
        $this->post('/account/addresses/'.$second.'/default', ['version' => 3, 'type' => 'shipping'])->assertSessionHasNoErrors();
        $this->post('/account/addresses/'.$second.'/default', ['version' => 4, 'type' => 'everything'])->assertSessionHasErrors('type');
        $this->put('/account/addresses/'.$second, [...$this->address(['line1' => 'Kifisias 10', 'region_code' => 'ATT']), 'version' => 4])->assertSessionHasNoErrors();
        $this->get('/account/addresses')->assertInertia(fn (Assert $p) => $p->where(self::ACCOUNT.'defaults.shipping', $second)->where(self::ACCOUNT.'addresses.1.line1', 'Kifisias 10')->where(self::ACCOUNT.'addresses.1.region', 'Attica'));
        // Another customer's address is not found, never a hint.
        $this->put('/account/addresses/'.$foreign, [...$this->address(), 'version' => 5])->assertNotFound();
        $this->delete('/account/addresses/'.$foreign, ['version' => 5])->assertNotFound();
        $this->post('/account/addresses/'.$foreign.'/default', ['version' => 5, 'type' => 'billing'])->assertNotFound();
        $this->delete('/account/addresses/'.$second, ['version' => 5])->assertSessionHasNoErrors();
        $customer = $this->profile($user);
        self::assertSame([$first, $first], [(string) $customer->default_billing_address_id, (string) $customer->default_shipping_address_id]);
        self::assertSame(1, DB::table('customer_addresses')->where('customer_id', $other)->count());
        // A disabled customer can no longer change anything.
        DB::table('customers')->where('id', $customer->id)->update(['status' => 'disabled']);
        $this->post('/account/addresses', [...$this->address(), 'version' => 6])->assertRedirect('/login');
    }

    public function test_customers_change_their_password_with_the_current_one(): void
    {
        $user = $this->customer();
        $this->get('/account/password')->assertInertia(fn (Assert $p) => $p->component('Storefront/Account')->where(self::ACCOUNT.'staff_rule', false));
        $this->put('/account/password', ['current_password' => 'wrong one', 'password' => 'new pass 9', 'password_confirmation' => 'new pass 9'])->assertSessionHasErrors('current_password');
        $this->put('/account/password', ['current_password' => self::PASSWORD, 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->put('/account/password', ['current_password' => self::PASSWORD, 'password' => 'new pass 9', 'password_confirmation' => 'new pass 9'])->assertSessionHasNoErrors()->assertRedirect('/account/password');
        // This session stays signed in; the old password no longer works.
        $this->get('/account')->assertOk();
        self::assertFalse($user->fresh()->staff_password);
        $this->post('/logout');
        $this->post('/login', ['email' => 'maria@example.test', 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'maria@example.test', 'password' => 'new pass 9'])->assertRedirect('/account');
        // Staff identities keep the staff minimum even from the customer page.
        $role = DB::table('roles')->where('is_super_admin', true)->value('id');
        DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $role]);
        $this->get('/account/password')->assertInertia(fn (Assert $p) => $p->where(self::ACCOUNT.'staff_rule', true));
        $this->put('/account/password', ['current_password' => 'new pass 9', 'password' => 'only ten c', 'password_confirmation' => 'only ten c'])->assertSessionHasErrors('password');
    }

    public function test_staff_invite_a_customer_who_sets_a_password_once(): void
    {
        $staff = $this->staff(['admin.access', 'customers.view', 'customers.update']);
        $this->actingAs($staff);
        $customer = (string) DB::table('customers')->insertGetId(['email' => 'nikos@example.test', 'first_name' => 'Nikos', 'last_name' => 'K', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/admin/customers/'.$customer)->assertInertia(fn (Assert $p) => $p->where('customer.account.state', 'none'));
        $this->post('/admin/customers/'.$customer.'/invitation')->assertSessionHasNoErrors()->assertSessionHas('status', __('customers.invite_sent'));
        $user = UserRecord::where('email', 'nikos@example.test')->sole();
        self::assertSame([null, 'Nikos K'], [$user->email_verified_at, $user->name]);
        self::assertSame(0, DB::table('role_user')->where('user_id', $user->id)->count());
        $this->get('/admin/customers/'.$customer)->assertInertia(fn (Assert $p) => $p->where('customer.account.state', 'invited'));
        $url = null;
        Notification::assertSentTo($user, CustomerInvitation::class, function (CustomerInvitation $n) use (&$url): bool {
            $url = $n->url;

            return true;
        });
        self::assertStringStartsWith(config('app.url').'/account/invitation/'.$user->id.'/', $url);
        $path = substr($url, strlen(config('app.url')));
        // A tampered link is refused by its signature.
        $this->post('/admin/logout');
        $this->get(str_replace('/invitation/'.$user->id.'/', '/invitation/'.$user->id.'/x', $path))->assertForbidden();
        $this->get($path)->assertInertia(fn (Assert $p) => $p->component('Customer/AcceptInvitation')->where('email', 'nikos@example.test'));
        $this->post($path, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post($path, ['password' => 'nikos pass', 'password_confirmation' => 'nikos pass'])->assertRedirect('/account');
        $this->assertAuthenticatedAs($user);
        self::assertNotNull($user->fresh()->email_verified_at);
        self::assertSame($user->id, (int) DB::table('customers')->where('id', $customer)->value('user_id'));
        // The link worked once.
        $this->post('/logout');
        $this->get($path)->assertRedirect('/login')->assertSessionHas('status', __('account.invitation_invalid'));
        $this->post($path, ['password' => 'other pass', 'password_confirmation' => 'other pass'])->assertForbidden();
        $this->actingAs($staff)->get('/admin/customers/'.$customer)->assertInertia(fn (Assert $p) => $p->where('customer.account.state', 'linked'));
        $this->get('/admin/customers?account=yes')->assertInertia(fn (Assert $p) => $p->where('customers.total', 1)->where('customers.data.0.has_account', true));
        $this->get('/admin/customers?account=no')->assertInertia(fn (Assert $p) => $p->where('customers.total', 0));
        $this->post('/admin/customers/'.$customer.'/invitation')->assertSessionHasErrors(['invitation' => __('customers.invite_linked')]);
    }

    public function test_invitations_are_refused_when_they_cannot_apply(): void
    {
        $reader = $this->staff(['admin.access', 'customers.view']);
        $customer = (string) DB::table('customers')->insertGetId(['email' => 'nikos@example.test', 'first_name' => 'Nikos', 'last_name' => 'K', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($reader)->post('/admin/customers/'.$customer.'/invitation')->assertForbidden();
        session()->forget('password_hash_web');
        $this->actingAs($this->staff(['admin.access', 'customers.view', 'customers.update']));
        DB::table('customers')->where('id', $customer)->update(['status' => 'disabled']);
        $this->post('/admin/customers/'.$customer.'/invitation')->assertSessionHasErrors(['invitation' => __('customers.invite_disabled')]);
        DB::table('customers')->where('id', $customer)->update(['status' => 'active']);
        $owner = UserRecord::create(['name' => 'Nikos', 'email' => 'nikos@example.test', 'password' => 'some other pass']);
        $owner->markEmailAsVerified();
        $this->post('/admin/customers/'.$customer.'/invitation')->assertSessionHasErrors(['invitation' => __('customers.invite_existing')]);
        Notification::assertNothingSent();
        self::assertSame(1, UserRecord::where('email', 'nikos@example.test')->count());
    }

    public function test_customers_review_what_they_bought_from_their_account(): void
    {
        $user = $this->customer();
        $customerId = (int) $this->profile($user)->id;
        $bought = $this->orderProduct('20');
        $other = $this->orderProduct('30');
        $staff = $this->staff(super: true);
        $order = app(OrderAdministration::class)->create((string) $staff->id, $this->orderInput((string) $bought->id, ['customer_id' => $customerId, 'billing' => $this->address()]));
        DB::table('order_payments')->insert(['reference' => (string) Str::uuid(), 'order_id' => $order, 'kind' => 'manual', 'state' => 'captured', 'amount' => DB::table('orders')->where('id', $order)->value('total'), 'currency_code' => 'EUR', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);
        $this->get('/account/reviews')->assertInertia(fn (Assert $p) => $p->component('Storefront/Account')->where(self::ACCOUNT.'reviewable', [['id' => (string) $bought->id, 'name' => 'Lamp']])->has(self::ACCOUNT.'reviews', 0));
        $this->post('/products/'.$bought->id.'/reviews', ['rating' => 5, 'title' => 'Bright', 'body' => 'Lights the whole room.', 'status' => 'approved', 'verified_purchase' => false, 'customer_id' => 999])->assertSessionHasNoErrors()->assertSessionHas('status', __('account.review_submitted'));
        $review = DB::table('product_reviews')->sole();
        self::assertSame([$customerId, (int) $bought->id, 'pending', true], [(int) $review->customer_id, (int) $review->product_id, $review->status, (bool) $review->verified_purchase]);
        $this->post('/products/'.$bought->id.'/reviews', ['rating' => 4, 'body' => 'Again'])->assertSessionHasErrors(['product' => __('reviews.duplicate')]);
        $this->post('/products/'.$other->id.'/reviews', ['rating' => 9, 'body' => 'x'])->assertSessionHasErrors('rating');
        // Products not bought can be reviewed too (not verified); the list only suggests purchases.
        $this->post('/products/'.$other->id.'/reviews', ['rating' => 3, 'body' => 'Seen in a friend\'s home.'])->assertSessionHasNoErrors();
        DB::table('product_reviews')->where('product_id', $bought->id)->update(['status' => 'spam', 'moderated_at' => now()]);
        $this->get('/account/reviews')->assertInertia(fn (Assert $p) => $p->where(self::ACCOUNT.'reviewable', [])->has(self::ACCOUNT.'reviews', 2)->where(self::ACCOUNT.'reviews.1.state', 'not_published')->where(self::ACCOUNT.'reviews.1.verified_purchase', true)->where(self::ACCOUNT.'reviews.0.state', 'pending'));
        $this->post('/logout');
        $this->post('/products/'.$bought->id.'/reviews', ['rating' => 5, 'body' => 'Guest'])->assertRedirect('/login');
        self::assertSame(2, DB::table('product_reviews')->count());
    }
}
