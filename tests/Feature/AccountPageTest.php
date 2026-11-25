<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Order\Infrastructure\OrderAdministration;
use App\Storefront\Infrastructure\LayoutStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class AccountPageTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    /** Where the Account layout's main content block carries the open section. */
    private const ACCOUNT = 'storefront.regions.content.0.blocks.0.content.account.';

    private const PASSWORD = 'shop pass';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        DB::table('shipping_countries')->insertOrIgnore(['country_code' => 'GR', 'created_at' => now()]);
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
        return [...['first_name' => 'Maria', 'last_name' => 'Example', 'line1' => 'Ermou 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR'], ...$changes];
    }

    private function order(int $customerId, string $price = '20'): int
    {
        $product = $this->orderProduct($price);

        return app(OrderAdministration::class)->create((string) $this->staff(super: true)->id, $this->orderInput((string) $product->id, ['customer_id' => $customerId]));
    }

    public function test_the_account_is_a_layout_whose_main_content_block_shows_the_open_section(): void
    {
        $this->customer();
        $layout = app(LayoutStore::class)->read('account');
        self::assertSame(['main_content'], array_column(array_column($layout['placements'], 'block'), 'type'));
        foreach (['/account' => 'profile', '/account/orders' => 'orders', '/account/addresses' => 'addresses', '/account/reviews' => 'reviews', '/account/password' => 'password'] as $url => $section) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Storefront/Account')
                ->where(self::ACCOUNT.'section', $section)->where(self::ACCOUNT.'name', 'Maria')
                ->where('storefront.theme', 'default')->has('storefront.regions.content.0.blocks.0')->has('seo.title'));
        }
        $this->get('/account')->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $p) => $p->where(self::ACCOUNT.'profile.email', 'maria@example.test'));
        // Reviews switched off for the store close the account's review list.
        DB::table('general_settings')->where('id', 1)->update(['reviews_enabled' => false]);
        $this->get('/account/reviews')->assertNotFound();
        $this->get('/account')->assertInertia(fn (Assert $p) => $p->where('storefront.reviews_enabled', false));
        DB::table('general_settings')->where('id', 1)->update(['reviews_enabled' => true]);
        // Guests are sent to sign in.
        $this->post('/logout');
        $this->get('/account/orders')->assertRedirect('/login');
    }

    public function test_the_order_history_lists_only_the_customers_own_orders_newest_first_in_pages(): void
    {
        $user = $this->customer();
        $mine = (int) $this->profile($user)->id;
        $other = (int) DB::table('customers')->insertGetId(['email' => 'other@example.test', 'first_name' => 'O', 'last_name' => 'T', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/account/orders')->assertInertia(fn (Assert $p) => $p->where(self::ACCOUNT.'orders.total', 0)->has(self::ACCOUNT.'orders.rows', 0));
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->order($mine, (string) (10 + $i));
        }
        $this->order($other, '99');
        $this->actingAs($user);
        DB::table('orders')->where('id', $ids[0])->update(['created_at' => now()->subDays(5)]);
        $first = $this->get('/account/orders');
        $first->assertInertia(fn (Assert $p) => $p->where(self::ACCOUNT.'orders.total', 12)->where(self::ACCOUNT.'orders.last_page', 2)->where(self::ACCOUNT.'orders.page', 1)->has(self::ACCOUNT.'orders.rows', 10)
            ->where(self::ACCOUNT.'orders.rows.0.id', (string) $ids[11])->where(self::ACCOUNT.'orders.rows.0.units', 1)->has(self::ACCOUNT.'orders.rows.0.number')->has(self::ACCOUNT.'orders.rows.0.status.name')->has(self::ACCOUNT.'orders.rows.0.total'));
        $this->get('/account/orders?page=2')->assertInertia(fn (Assert $p) => $p->where(self::ACCOUNT.'orders.page', 2)->has(self::ACCOUNT.'orders.rows', 2)->where(self::ACCOUNT.'orders.rows.1.id', (string) $ids[0]));
        // The total is shown in the order's own currency format, from the stored decimal.
        $total = DB::table('orders')->where('id', $ids[11])->value('total');
        $this->get('/account/orders')->assertInertia(fn (Assert $p) => $p->where(self::ACCOUNT.'orders.rows.0.total', fn ($shown) => str_contains($shown, '21') && ! str_contains($shown, '.000000')));
        self::assertNotNull($total);
    }

    public function test_an_order_page_shows_the_customers_own_order_in_full_and_hides_other_peoples(): void
    {
        $user = $this->customer();
        $mine = (int) $this->profile($user)->id;
        $other = (int) DB::table('customers')->insertGetId(['email' => 'other@example.test', 'first_name' => 'O', 'last_name' => 'T', 'created_at' => now(), 'updated_at' => now()]);
        $own = $this->order($mine, '25');
        $foreign = $this->order($other, '99');
        $this->actingAs($user);
        $this->get('/account/orders/'.$own)->assertOk()->assertInertia(fn (Assert $p) => $p->where(self::ACCOUNT.'section', 'order')->where(self::ACCOUNT.'order.id', (string) $own)
            ->has(self::ACCOUNT.'order.number')->has(self::ACCOUNT.'order.items', 1)->has(self::ACCOUNT.'order.items.0.total')->has(self::ACCOUNT.'order.totals.total')->has(self::ACCOUNT.'order.billing.lines')
            ->missing(self::ACCOUNT.'order.internal_note')->missing(self::ACCOUNT.'order.ip_address'));
        // The methods are always part of the payload (empty until the order has them), so the page can show them.
        $this->get('/account/orders/'.$own)->assertInertia(fn (Assert $p) => $p->where(self::ACCOUNT.'order.payment_method', null)->where(self::ACCOUNT.'order.shipping_method', null));
        $this->get('/account/orders/'.$foreign)->assertNotFound();
        $this->get('/account/orders/999999')->assertNotFound();
    }

    public function test_saved_invoice_details_are_visible_only_on_the_owners_requested_invoice_order(): void
    {
        $user = $this->customer();
        $id = $this->order((int) $this->profile($user)->id);
        $url = '/account/orders/'.$id;
        $path = self::ACCOUNT.'order.invoice_details';
        $this->get($url)->assertInertia(fn (Assert $p) => $p->where($path, null));
        DB::table('orders')->where('id', $id)->update(['invoice_requested' => true]);
        $this->get($url)->assertInertia(fn (Assert $p) => $p->where($path, null));
        DB::table('order_invoice_verifications')->insert(['order_id' => $id, 'country_code' => 'GR', 'vat_number' => '000000012', 'business_name' => 'Saved Invoice Company', 'provider' => 'vies', 'verified_at' => now()]);
        DB::table('order_addresses')->where('order_id', $id)->update(['company' => 'Changed billing company']);
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $p) => $p
            ->where($path.'.vat_number', '000000012')->where($path.'.business_name', 'Saved Invoice Company')->where($path.'.country', 'Greece')->where($path.'.country_code', 'GR')
            ->missing($path.'.provider')->missing($path.'.verified_at'));
        DB::table('order_invoice_verifications')->where('order_id', $id)->update(['business_name' => null]);
        $this->get($url)->assertInertia(fn (Assert $p) => $p->where($path.'.business_name', null)->where($path.'.vat_number', '000000012'));
        DB::table('orders')->where('id', $id)->update(['invoice_requested' => false]);
        $this->get($url)->assertInertia(fn (Assert $p) => $p->where($path, null));
        $this->flushSession();
        $this->customer('other-invoice@example.test');
        $this->get($url)->assertNotFound();
    }

    public function test_addresses_need_a_postal_code_and_keep_floor_bell_name_and_notes(): void
    {
        $user = $this->customer();
        $this->post('/account/addresses', [...$this->address(['postal_code' => '']), 'version' => 1])->assertSessionHasErrors('postal_code');
        $this->post('/account/addresses', [...$this->address(['postal_code' => '  ']), 'version' => 1])->assertSessionHasErrors('postal_code');
        $this->post('/account/addresses', [...$this->address(['floor' => str_repeat('1', 21)]), 'version' => 1])->assertSessionHasErrors('floor');
        $this->post('/account/addresses', [...$this->address(['street_number' => str_repeat('1', 21)]), 'version' => 1])->assertSessionHasErrors('street_number');
        $this->post('/account/addresses', [...$this->address(['bell_name' => str_repeat('x', 101)]), 'version' => 1])->assertSessionHasErrors('bell_name');
        $this->post('/account/addresses', [...$this->address(['notes' => str_repeat('x', 501)]), 'version' => 1])->assertSessionHasErrors('notes');
        self::assertSame(0, DB::table('customer_addresses')->count());
        $this->post('/account/addresses', [...$this->address(['line1' => 'Mesogeion', 'street_number' => ' 58 ', 'floor' => ' 3rd ', 'bell_name' => 'Example', 'notes' => "Leave with the neighbour.\nRing twice."]), 'version' => 1])->assertSessionHasNoErrors();
        $row = DB::table('customer_addresses')->sole();
        self::assertSame(['Mesogeion', '58', '3rd', 'Example', "Leave with the neighbour.\nRing twice."], [$row->line1, $row->street_number, $row->floor, $row->bell_name, $row->notes]);
        $this->get('/account/addresses')->assertInertia(fn (Assert $p) => $p->where(self::ACCOUNT.'addresses.0.street_number', '58')->where(self::ACCOUNT.'addresses.0.floor', '3rd')->where(self::ACCOUNT.'addresses.0.bell_name', 'Example')->where(self::ACCOUNT.'addresses.0.notes', "Leave with the neighbour.\nRing twice."));
        // Delivery details are optional and can be cleared.
        $this->put('/account/addresses/'.$row->id, [...$this->address(['street_number' => '', 'floor' => '', 'bell_name' => '', 'notes' => '']), 'version' => 2])->assertSessionHasNoErrors();
        $row = DB::table('customer_addresses')->sole();
        self::assertSame([null, null, null, null], [$row->street_number, $row->floor, $row->bell_name, $row->notes]);
        // Postal codes are required by the database for new rows too.
        try {
            DB::table('customer_addresses')->insert(['customer_id' => $this->profile($user)->id, 'first_name' => 'A', 'last_name' => 'B', 'line1' => 'X 1', 'city' => 'Y', 'country_code' => 'GR', 'created_at' => now(), 'updated_at' => now()]);
            self::fail('An address without a postal code was stored');
        } catch (QueryException $e) {
            self::assertStringContainsString('customer_address_postal_code', $e->getMessage());
        }
    }

    public function test_the_default_address_checkbox_sets_billing_and_shipping(): void
    {
        $user = $this->customer();
        $this->post('/account/addresses', [...$this->address(['line1' => 'First']), 'version' => 1])->assertSessionHasNoErrors();
        [$first] = DB::table('customer_addresses')->pluck('id')->all();
        $this->post('/account/addresses', [...$this->address(['line1' => 'Second']), 'version' => 2])->assertSessionHasNoErrors();
        $row = $this->profile($user);
        self::assertSame([$first, $first], [(int) $row->default_billing_address_id, (int) $row->default_shipping_address_id]);
        $this->post('/account/addresses', [...$this->address(['line1' => 'Third']), 'is_default' => true, 'version' => 3])->assertSessionHasNoErrors();
        $third = (int) DB::table('customer_addresses')->where('line1', 'Third')->value('id');
        $row = $this->profile($user);
        self::assertSame([$third, $third], [(int) $row->default_billing_address_id, (int) $row->default_shipping_address_id]);
        $this->put('/account/addresses/'.$first, [...$this->address(['line1' => 'First']), 'is_default' => '1', 'version' => 4])->assertSessionHasNoErrors();
        $row = $this->profile($user);
        self::assertSame([$first, $first], [(int) $row->default_billing_address_id, (int) $row->default_shipping_address_id]);
    }

    public function test_the_region_is_required_when_the_country_has_regions_and_comes_from_them(): void
    {
        $this->customer();
        // Without regions configured for the country none is asked for.
        $this->post('/account/addresses', [...$this->address(), 'version' => 1])->assertSessionHasNoErrors();
        $id = DB::table('regions')->insertGetId(['country_code' => 'GR', 'code' => 'ATT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('region_translations')->insert(['region_id' => $id, 'locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'Attica']);
        $this->post('/account/addresses', [...$this->address(['region_code' => '']), 'version' => 2])->assertSessionHasErrors(['region_code' => __('customers.region_required')]);
        $this->post('/account/addresses', [...$this->address(['region_code' => 'XXX']), 'version' => 2])->assertSessionHasErrors('region_code');
        $this->post('/account/addresses', [...$this->address(['region_code' => 'ATT']), 'version' => 2])->assertSessionHasNoErrors();
        self::assertSame(2, DB::table('customer_addresses')->count());
    }

    public function test_deleting_the_account_is_a_soft_delete_that_keeps_everything(): void
    {
        $user = $this->customer();
        $id = (int) $this->profile($user)->id;
        $this->post('/account/addresses', [...$this->address(), 'version' => 1])->assertSessionHasNoErrors();
        $order = $this->order($id);
        $this->actingAs($user);
        $version = (int) $this->profile($user)->lock_version;
        // The e-mail address must be typed, and the page must be current.
        $this->delete('/account', ['version' => $version, 'confirmation' => 'someone@else.test'])->assertSessionHasErrors(['confirmation' => __('account.delete_confirmation_wrong')]);
        $this->delete('/account', ['version' => $version, 'confirmation' => ''])->assertSessionHasErrors('confirmation');
        $this->delete('/account', ['version' => $version + 5, 'confirmation' => 'maria@example.test'])->assertSessionHasErrors('version');
        self::assertNull($this->profile($user)->deleted_at);
        $this->delete('/account', ['version' => $version, 'confirmation' => ' Maria@Example.test '])->assertSessionHasNoErrors()->assertRedirect('/login')->assertSessionHas('status', __('account.deleted'));
        $this->assertGuest();
        // Nothing was removed: the profile is disabled and stamped, and its orders and addresses stay linked.
        $row = $this->profile($user);
        self::assertSame(['disabled', 'maria@example.test', $user->id], [$row->status, $row->email, (int) $row->user_id]);
        self::assertNotNull($row->deleted_at);
        self::assertSame(1, DB::table('customer_addresses')->where('customer_id', $id)->count());
        self::assertSame($id, (int) DB::table('orders')->where('id', $order)->value('customer_id'));
        self::assertSame(1, DB::table('users')->where('id', $user->id)->count());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'customer')->where('changes', 'like', '%deleted_at%')->exists());
        // It cannot be used again, and the sign-in page says why.
        $this->post('/login', ['email' => 'maria@example.test', 'password' => self::PASSWORD])->assertSessionHasErrors(['email' => __('auth.customer_deleted')]);
        $this->assertGuest();
        $this->get('/account')->assertRedirect('/login');
        // The database never lets a deleted profile stay active.
        try {
            DB::table('customers')->where('id', $id)->update(['status' => 'active']);
            self::fail('A deleted profile was activated without clearing the deletion');
        } catch (QueryException $e) {
            self::assertStringContainsString('customer_deleted_is_disabled', $e->getMessage());
        }
    }

    public function test_staff_see_a_deleted_account_and_bring_it_back_by_activating_the_profile(): void
    {
        $user = $this->customer();
        $id = (int) $this->profile($user)->id;
        $this->delete('/account', ['version' => (int) $this->profile($user)->lock_version, 'confirmation' => 'maria@example.test'])->assertSessionHasNoErrors();
        $staff = $this->staff(super: true);
        $this->actingAs($staff);
        $this->get('/admin/customers')->assertInertia(fn (Assert $p) => $p->where('customers.data.0.status', 'disabled')->has('customers.data.0.deleted_at'));
        $this->get('/admin/customers/'.$id)->assertInertia(fn (Assert $p) => $p->where('customer.status', 'disabled')->has('customer.deleted_at'));
        $this->patch('/admin/customers/'.$id.'/status', ['version' => (int) $this->profile($user)->lock_version, 'status' => 'active'])->assertSessionHasNoErrors();
        $row = $this->profile($user);
        self::assertSame(['active', null], [$row->status, $row->deleted_at]);
        $this->get('/admin/customers/'.$id)->assertInertia(fn (Assert $p) => $p->where('customer.deleted_at', null));
        $this->post('/logout');
        $this->post('/login', ['email' => 'maria@example.test', 'password' => self::PASSWORD])->assertSessionHasNoErrors()->assertRedirect('/account');
    }

    public function test_a_customer_only_sees_their_own_addresses(): void
    {
        $this->customer();
        $this->post('/account/addresses', [...$this->address(['line1' => 'Secret 9']), 'version' => 1])->assertSessionHasNoErrors();
        $this->get('/account/addresses')->assertInertia(fn (Assert $p) => $p->has(self::ACCOUNT.'addresses', 1));
        $this->post('/logout');
        $this->customer('other@example.test');
        $this->get('/account/addresses')->assertInertia(fn (Assert $p) => $p->has(self::ACCOUNT.'addresses', 0));
    }

    public function test_order_tracking_shows_guest_orders_by_number_and_customer_orders_only_to_their_signed_in_owner(): void
    {
        $product = $this->orderProduct('20');
        $staff = (string) $this->staff(super: true)->id;
        $guestOrder = app(OrderAdministration::class)->create($staff, $this->orderInput((string) $product->id));
        $guestNumber = (string) DB::table('orders')->where('id', $guestOrder)->value('order_number');
        $page = 'storefront.regions.content.0.blocks.0.content.tracking.';

        // The empty page, then a guest order by its number alone: status and history, nothing personal.
        $this->get('/order-status')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Storefront/OrderTracking')->where($page.'state', null));
        $this->get('/order-status?number='.$guestNumber)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertInertia(fn (Assert $p) => $p->where($page.'state', 'found')
            ->where($page.'order.number', $guestNumber)->where($page.'order.account_url', null)->has($page.'order.timeline', 1)
            ->where($page.'order', fn ($order) => ! collect($order)->keys()->intersect(['items', 'billing', 'shipping', 'totals', 'email'])->count()));
        $this->get('/order-status?number=999999')->assertInertia(fn (Assert $p) => $p->where($page.'state', 'not_found'));
        $this->get('/order-status?number=<script>')->assertInertia(fn (Assert $p) => $p->where($page.'state', 'not_found'));

        // A registered customer's order: a visitor must sign in, and signing in returns to it.
        $owner = $this->customer();
        $ownerOrder = $this->order((int) $this->profile($owner)->id);
        $ownerNumber = (string) DB::table('orders')->where('id', $ownerOrder)->value('order_number');
        auth()->logout();
        $this->get('/order-status?number='.$ownerNumber)->assertInertia(fn (Assert $p) => $p->where($page.'state', 'login')->where($page.'order', null));
        $this->post('/login', ['email' => 'maria@example.test', 'password' => self::PASSWORD])->assertRedirect('/order-status?number='.$ownerNumber);
        $this->get('/order-status?number='.$ownerNumber)->assertInertia(fn (Assert $p) => $p->where($page.'state', 'found')->where($page.'order.account_url', '/account/orders/'.$ownerOrder));

        // Another customer, signed in, learns nothing: the same answer as a number that does not exist.
        $this->customer('other@example.test');
        $this->get('/order-status?number='.$ownerNumber)->assertInertia(fn (Assert $p) => $p->where($page.'state', 'not_found'));
    }

    public function test_customers_see_their_newsletters_and_unsubscribe(): void
    {
        $this->customer();
        $en = DB::table('locales')->where('code', 'en')->value('id');
        $list = function (string $name) use ($en): int {
            $id = DB::table('newsletter_lists')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('newsletter_list_translations')->insert(['list_id' => $id, 'locale_id' => $en, 'name' => $name]);

            return $id;
        };
        [$offers, $news, $theirs] = [$list('Offers'), $list('News'), $list('Other')];
        // Signed up as a guest with the account's e-mail (still unconfirmed) and on another list confirmed; someone else's list is not shown.
        $mine = DB::table('newsletter_subscribers')->insertGetId(['email' => 'Maria@example.test', 'status' => 'active', 'source' => 'storefront', 'created_at' => now(), 'updated_at' => now()]);
        $other = DB::table('newsletter_subscribers')->insertGetId(['email' => 'other@example.test', 'status' => 'active', 'source' => 'storefront', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('newsletter_list_subscribers')->insert([['list_id' => $offers, 'subscriber_id' => $mine, 'confirmed_at' => null, 'created_at' => now()], ['list_id' => $news, 'subscriber_id' => $mine, 'confirmed_at' => now(), 'created_at' => now()], ['list_id' => $theirs, 'subscriber_id' => $other, 'confirmed_at' => now(), 'created_at' => now()]]);

        $this->get('/account/newsletters')->assertOk()->assertInertia(fn (Assert $p) => $p->where(self::ACCOUNT.'newsletters.0.name', 'Offers')->where(self::ACCOUNT.'newsletters.0.confirmed', false)
            ->where(self::ACCOUNT.'newsletters.1.name', 'News')->has(self::ACCOUNT.'newsletters', 2));
        $this->delete("/account/newsletters/$theirs")->assertNotFound();
        $this->delete("/account/newsletters/$offers")->assertRedirect('/account/newsletters');
        self::assertSame([(string) $news], DB::table('newsletter_list_subscribers')->where('subscriber_id', $mine)->pluck('list_id')->map(strval(...))->all());
        self::assertSame('active', DB::table('newsletter_subscribers')->where('id', $mine)->value('status'));
        // Leaving the last list ends the subscription.
        $this->delete("/account/newsletters/$news")->assertRedirect('/account/newsletters');
        self::assertSame('unsubscribed', DB::table('newsletter_subscribers')->where('id', $mine)->value('status'));
        $this->get('/account/newsletters')->assertInertia(fn (Assert $p) => $p->has(self::ACCOUNT.'newsletters', 0));
        self::assertTrue(DB::table('newsletter_list_subscribers')->where('subscriber_id', $other)->exists());
    }
}
