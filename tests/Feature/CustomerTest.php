<?php

namespace Tests\Feature;

use App\Customer\Application\Command\SaveAddressData;
use App\Customer\Application\Command\SaveCustomerData;
use App\Customer\Application\Command\SaveCustomerGroupData;
use App\Customer\Application\Contract\CustomerAdministration;
use App\Customer\Application\Contract\CustomerGroupAdministration;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Tax\Application\Command\SaveRateAreaData;
use App\Tax\Application\Command\SaveTaxData;
use App\Tax\Application\Command\SaveTaxRateData;
use App\Tax\Application\Contract\CustomerTaxProfiles;
use App\Tax\Application\Contract\RateAreaAdministration;
use App\Tax\Application\Contract\TaxAdministration;
use App\Tax\Application\Service\TaxCalculationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CustomerTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private CustomerAdministration $customers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        $this->customers = app(CustomerAdministration::class);
    }

    private function id(): string
    {
        return (string) $this->actor->id;
    }

    /** Regions come from Geography: the country must ship and the region must be active (A100). */
    private function region(string $country, string $code, string $name): void
    {
        DB::table('shipping_countries')->insertOrIgnore(['country_code' => $country, 'created_at' => now()]);
        $id = DB::table('regions')->insertGetId(['country_code' => $country, 'code' => $code, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('region_translations')->insert(['region_id' => $id, 'locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => $name]);
    }

    private function address(string $country = 'GR', ?string $region = null, string $line = 'Ermou 1', ?string $label = 'Home'): SaveAddressData
    {
        if ($region !== null && ! DB::table('regions')->where('country_code', $country)->where('code', $region)->exists()) {
            $this->region($country, $region, $region.' region');
        }

        return new SaveAddressData($label, 'Maria', 'Papadopoulou', null, $line, null, 'Athens', '10563', $country, $region, null);
    }

    /** @param list<string>|null $groups */
    private function customer(string $email = 'maria@example.test', ?array $groups = null, ?SaveAddressData $address = null, bool $exempt = false): string
    {
        return $this->customers->create($this->id(), new SaveCustomerData('Maria', 'Papadopoulou', $email, '+30 210 1234567', $exempt, null, $groups, address: $address));
    }

    private function group(string $name, bool $default = false, string $status = 'active'): string
    {
        return app(CustomerGroupAdministration::class)->create($this->id(), new SaveCustomerGroupData(['en' => $name], [], $status, $default));
    }

    private function version(string $customer): int
    {
        return (int) DB::table('customers')->where('id', $customer)->value('lock_version');
    }

    /** @param callable(): mixed $operation */
    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            self::fail('Expected validation error '.$field);
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors());
        }
    }

    /** @return array<string, mixed> */
    private function form(array $changes = []): array
    {
        return array_replace(['first_name' => 'Nikos', 'last_name' => 'Georgiou', 'email' => 'nikos@example.test', 'phone' => '', 'tax_exempt' => false, 'notes' => '', 'group_ids' => []], $changes);
    }

    public function test_customers_are_created_and_edited_through_the_admin_screens(): void
    {
        $this->post('/admin/customers', $this->form(['email' => '  Nikos@Example.TEST ', 'phone' => '+30 694 000 0000', 'notes' => 'Prefers phone calls']))->assertSessionHasNoErrors()->assertRedirect();
        $customer = DB::table('customers')->sole();
        self::assertSame(['nikos@example.test', 'Nikos', 'Georgiou', '+30 694 000 0000', 'active', false, 'Prefers phone calls', 1], [$customer->email, $customer->first_name, $customer->last_name, $customer->phone, $customer->status, $customer->tax_exempt, $customer->notes, (int) $customer->lock_version]);
        $this->get('/admin/customers/'.$customer->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Customers/Show')->where('customer.email', 'nikos@example.test')->where('customer.addresses', []));

        $this->put('/admin/customers/'.$customer->id, $this->form(['version' => 1, 'first_name' => 'Nikolaos', 'tax_exempt' => true, 'phone' => '']))->assertSessionHasNoErrors();
        $fresh = DB::table('customers')->sole();
        self::assertSame(['Nikolaos', true, null, 2], [$fresh->first_name, $fresh->tax_exempt, $fresh->phone, (int) $fresh->lock_version]);
        // Stale edits are refused rather than silently overwriting.
        $this->put('/admin/customers/'.$customer->id, $this->form(['version' => 1, 'first_name' => 'Late']))->assertSessionHasErrors('version');
        // No staff identity is created or changed.
        self::assertSame(1, DB::table('users')->count());
    }

    public function test_required_fields_and_formats_are_validated_with_values_kept(): void
    {
        $this->from('/admin/customers/create')->post('/admin/customers', $this->form(['first_name' => '', 'last_name' => ' ', 'email' => 'not-an-email', 'phone' => 'call me']))
            ->assertRedirect('/admin/customers/create')->assertSessionHasErrors(['first_name', 'last_name', 'email', 'phone']);
        $this->assertSame('not-an-email', session()->getOldInput('email'));
        $this->post('/admin/customers', $this->form(['email' => '']))->assertSessionHasErrors('email');
        $this->post('/admin/customers', $this->form(['notes' => str_repeat('x', 5001)]))->assertSessionHasErrors('notes');
        $this->post('/admin/customers', $this->form(['default_billing_address_id' => 1]))->assertSessionHasErrors('default_billing_address_id');
        self::assertSame(0, DB::table('customers')->count());
    }

    public function test_emails_are_normalized_and_unique_without_merging_records(): void
    {
        $first = $this->customer('ELENI@example.test');
        self::assertSame('eleni@example.test', DB::table('customers')->where('id', $first)->value('email'));
        $this->invalid(fn () => $this->customer(' eleni@EXAMPLE.test '), 'email');
        $other = $this->customer('other@example.test');
        $this->invalid(fn () => $this->customers->update($this->id(), $other, 1, new SaveCustomerData('A', 'B', 'Eleni@example.test', null, false, null)), 'email');
        // Keeping one's own email is allowed.
        $this->customers->update($this->id(), $first, 1, new SaveCustomerData('Eleni', 'K', 'eleni@example.test', null, false, null));
        self::assertSame(2, DB::table('customers')->count());
        // The database enforces both normalization and uniqueness.
        foreach ([['email' => 'Upper@example.test'], ['email' => 'eleni@example.test']] as $values) {
            DB::beginTransaction();
            try {
                DB::table('customers')->insert([...$values, 'first_name' => 'X', 'last_name' => 'Y', 'created_at' => now(), 'updated_at' => now()]);
                self::fail('Expected a database refusal');
            } catch (QueryException) {
                self::assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
        // A customer may share an email with a staff identity: they are separate records and grant nothing.
        $this->customer($this->actor->email);
        self::assertSame(1, DB::table('users')->where('email', $this->actor->email)->count());
    }

    public function test_disabling_and_restoring_keeps_the_record_and_rejects_invalid_transitions(): void
    {
        $customer = $this->customer(address: $this->address());
        $this->patch('/admin/customers/'.$customer.'/status', ['version' => 1, 'status' => 'disabled'])->assertSessionHasNoErrors();
        self::assertSame(['disabled', 2], [DB::table('customers')->where('id', $customer)->value('status'), $this->version($customer)]);
        $this->patch('/admin/customers/'.$customer.'/status', ['version' => 2, 'status' => 'disabled'])->assertSessionHasErrors('status');
        $this->patch('/admin/customers/'.$customer.'/status', ['version' => 2, 'status' => 'deleted'])->assertSessionHasErrors('status');
        $this->patch('/admin/customers/'.$customer.'/status', ['version' => 1, 'status' => 'active'])->assertSessionHasErrors('version');
        self::assertSame(1, DB::table('customer_addresses')->where('customer_id', $customer)->count());
        $this->patch('/admin/customers/'.$customer.'/status', ['version' => 2, 'status' => 'active'])->assertSessionHasNoErrors();
        self::assertSame('active', DB::table('customers')->where('id', $customer)->value('status'));
        // There is no delete route: customers are retained for future order history.
        $this->delete('/admin/customers/'.$customer)->assertStatus(405);
        // Staff access is untouched.
        $this->get('/admin')->assertOk();
    }

    public function test_the_list_searches_filters_sorts_and_paginates_in_the_database(): void
    {
        $vip = $this->group('VIP');
        foreach (range(1, 27) as $n) {
            $this->customers->create($this->id(), new SaveCustomerData('Person', sprintf('Zeta %02d', $n), sprintf('p%02d@example.test', $n), null, false, null, []));
        }
        $alpha = $this->customers->create($this->id(), new SaveCustomerData('Anna', 'Alpha', 'anna@shop.test', '+30 (210) 555-0101', false, null, [$vip]));
        $this->customers->changeStatus($this->id(), $alpha, 1, 'disabled');

        $this->get('/admin/customers')->assertInertia(fn (Assert $page) => $page->component('Admin/Customers/Index')->where('customers.total', 28)->has('customers.data', 25)->where('customers.last_page', 2)
            ->where('customers.data.0.email', 'anna@shop.test')->where('customers.data.0.groups', ['VIP']));
        $this->get('/admin/customers?search=ANNA')->assertInertia(fn (Assert $page) => $page->where('customers.total', 1));
        $this->get('/admin/customers?search=anna%20alpha')->assertInertia(fn (Assert $page) => $page->where('customers.total', 1));
        // Phone search ignores formatting.
        $this->get('/admin/customers?search=2105550101')->assertInertia(fn (Assert $page) => $page->where('customers.total', 1));
        $this->get('/admin/customers?search=%25')->assertInertia(fn (Assert $page) => $page->where('customers.total', 0));
        $this->get('/admin/customers?status=disabled')->assertInertia(fn (Assert $page) => $page->where('customers.total', 1));
        $this->get('/admin/customers?group='.$vip)->assertInertia(fn (Assert $page) => $page->where('customers.total', 1)->where('customers.data.0.name', 'Anna Alpha'));
        $this->get('/admin/customers?sort=name')->assertInertia(fn (Assert $page) => $page->where('customers.data.0.name', 'Anna Alpha')->where('customers.data.1.name', 'Person Zeta 01'));
        $this->get('/admin/customers?sort=email&direction=desc')->assertInertia(fn (Assert $page) => $page->where('customers.data.0.email', 'p27@example.test'));
        $this->get('/admin/customers?sort=name&page=2')->assertInertia(fn (Assert $page) => $page->where('customers.data.2.name', 'Person Zeta 27'));
        // Unknown sort fields and groups are validation errors, never SQL.
        $this->get('/admin/customers?sort=password')->assertSessionHasErrors('sort');
        $this->get('/admin/customers?group=99999')->assertSessionHasErrors('group');
    }

    public function test_every_customer_route_and_operation_requires_its_capability(): void
    {
        $customer = $this->customer(address: $this->address());
        $address = (string) DB::table('customer_addresses')->value('id');
        $this->flushSession();
        $this->actingAs($this->staff());
        foreach (['/admin/customers', '/admin/customers/create', '/admin/customers/'.$customer, '/admin/customers/'.$customer.'/edit', '/admin/customer-groups', '/admin/customer-groups/create'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/customers', $this->form())->assertForbidden();
        $this->put('/admin/customers/'.$customer, $this->form(['version' => 1]))->assertForbidden();
        $this->patch('/admin/customers/'.$customer.'/status', ['version' => 1, 'status' => 'disabled'])->assertForbidden();
        $this->post('/admin/customers/'.$customer.'/addresses', ['version' => 1])->assertForbidden();
        $this->delete('/admin/customers/'.$customer.'/addresses/'.$address, ['version' => 1])->assertForbidden();

        // View-only staff can read but not change or disable.
        $this->flushSession();
        $viewer = $this->staff(['admin.access', 'customers.view']);
        $this->actingAs($viewer);
        $this->get('/admin/customers/'.$customer)->assertOk();
        $this->put('/admin/customers/'.$customer, $this->form(['version' => 1]))->assertForbidden();
        $this->patch('/admin/customers/'.$customer.'/status', ['version' => 1, 'status' => 'disabled'])->assertForbidden();
        try {
            $this->customers->changeStatus((string) $viewer->id, $customer, 1, 'disabled');
            self::fail('Expected an authorization failure');
        } catch (AuthorizationException) {
            self::assertSame('active', DB::table('customers')->where('id', $customer)->value('status'));
        }
    }

    public function test_responses_never_carry_authentication_secrets(): void
    {
        $customer = $this->customer();
        $show = json_encode($this->get('/admin/customers/'.$customer)->viewData('page')['props']['customer']);
        $list = json_encode($this->get('/admin/customers')->viewData('page')['props']['customers']['data']);
        $edit = json_encode($this->get('/admin/customers/'.$customer.'/edit')->viewData('page')['props']['customer']);
        foreach (['password', 'remember_token', 'token', 'hash'] as $secret) {
            foreach ([$show, $list, $edit] as $payload) {
                self::assertStringNotContainsString($secret, (string) $payload);
            }
        }
        // Personal data stays out of the audit ledger; status changes are recorded with the actor.
        $this->customers->changeStatus($this->id(), $customer, 1, 'disabled');
        $events = DB::table('audit_logs')->where('entity_type', 'customer')->get();
        self::assertNotEmpty($events);
        foreach ($events as $event) {
            self::assertStringNotContainsString('maria@example.test', (string) $event->changes);
            self::assertStringNotContainsString('1234567', (string) $event->changes);
        }
        self::assertTrue($events->contains(fn ($e): bool => str_contains((string) $e->changes, 'disabled') && $e->actor_email === $this->actor->email));
    }

    public function test_a_customer_keeps_several_addresses_with_owned_deterministic_defaults(): void
    {
        $customer = $this->customer();
        self::assertSame(0, DB::table('customer_addresses')->count());
        self::assertNull(DB::table('customers')->where('id', $customer)->value('default_billing_address_id'));
        $home = $this->customers->addAddress($this->id(), $customer, 1, $this->address());
        // The first address becomes both defaults.
        $row = DB::table('customers')->where('id', $customer)->first();
        self::assertSame([$home, $home], [(string) $row->default_billing_address_id, (string) $row->default_shipping_address_id]);
        $office = $this->customers->addAddress($this->id(), $customer, 2, $this->address('CY', null, 'Makariou 5', 'Office'));
        $this->customers->updateAddress($this->id(), $customer, $office, 3, $this->address('CY', 'NIC', 'Makariou 7', 'Office'));
        self::assertSame(['Makariou 7', 'NIC'], [DB::table('customer_addresses')->where('id', $office)->value('line1'), DB::table('customer_addresses')->where('id', $office)->value('region_code')]);
        // Defaults change only when chosen, and only to the customer's own addresses.
        $this->customers->update($this->id(), $customer, 4, new SaveCustomerData('Maria', 'Papadopoulou', 'maria@example.test', null, false, null, null, null, $office));
        $row = DB::table('customers')->where('id', $customer)->first();
        self::assertSame([$home, $office], [(string) $row->default_billing_address_id, (string) $row->default_shipping_address_id]);
        $stranger = $this->customer('stranger@example.test', address: $this->address());
        $foreign = (string) DB::table('customer_addresses')->where('customer_id', $stranger)->value('id');
        $this->invalid(fn () => $this->customers->update($this->id(), $customer, 5, new SaveCustomerData('Maria', 'Papadopoulou', 'maria@example.test', null, false, null, null, $foreign)), 'default_billing_address_id');
        // The database refuses a foreign default even when written directly.
        $this->expectException(QueryException::class);
        DB::table('customers')->where('id', $customer)->update(['default_billing_address_id' => $foreign]);
    }

    public function test_deleting_a_default_address_falls_back_to_the_oldest_remaining_one(): void
    {
        $customer = $this->customer(address: $this->address());
        $first = (string) DB::table('customer_addresses')->value('id');
        $second = $this->customers->addAddress($this->id(), $customer, 1, $this->address('GR', 'K', 'Mytilene 3', 'Island'));
        $third = $this->customers->addAddress($this->id(), $customer, 2, $this->address('GR', 'K', 'Patision 9', 'Work'));
        $this->customers->update($this->id(), $customer, 3, new SaveCustomerData('Maria', 'Papadopoulou', 'maria@example.test', null, false, null, null, $third, $third));
        $this->delete('/admin/customers/'.$customer.'/addresses/'.$third, ['version' => 4])->assertSessionHasNoErrors();
        $row = DB::table('customers')->where('id', $customer)->first();
        self::assertSame([$first, $first], [(string) $row->default_billing_address_id, (string) $row->default_shipping_address_id]);
        $this->customers->deleteAddress($this->id(), $customer, $first, 5);
        $row = DB::table('customers')->where('id', $customer)->first();
        self::assertSame([$second, $second], [(string) $row->default_billing_address_id, (string) $row->default_shipping_address_id]);
        $this->customers->deleteAddress($this->id(), $customer, $second, 6);
        $row = DB::table('customers')->where('id', $customer)->first();
        self::assertSame([null, null], [$row->default_billing_address_id, $row->default_shipping_address_id]);
    }

    public function test_invalid_addresses_and_forged_address_ids_are_rejected(): void
    {
        $customer = $this->customer(address: $this->address());
        $own = (string) DB::table('customer_addresses')->value('id');
        $base = ['version' => 1, 'first_name' => 'Maria', 'last_name' => 'P', 'line1' => 'Ermou 1', 'city' => 'Athens', 'postal_code' => '10563', 'country_code' => 'GR'];
        foreach ([['country_code' => 'ZZ'], ['country_code' => ''], ['region_code' => 'bad region'], ['postal_code' => '10563!'], ['postal_code' => ''], ['postal_code' => '   '], ['line1' => ''], ['city' => ''], ['phone' => 'abc'], ['floor' => str_repeat('1', 21)], ['bell_name' => str_repeat('x', 101)], ['notes' => str_repeat('x', 501)]] as $change) {
            $this->post('/admin/customers/'.$customer.'/addresses', array_replace($base, $change))->assertSessionHasErrors(array_key_first($change));
        }
        self::assertSame(1, DB::table('customer_addresses')->count());
        // Lower-case codes are normalized and the region stays optional; the postal code is required.
        $this->post('/admin/customers/'.$customer.'/addresses', array_replace($base, ['country_code' => 'ie', 'region_code' => '', 'postal_code' => 'd02 x285']))->assertSessionHasNoErrors();
        self::assertSame(['IE', null, 'D02 X285'], [DB::table('customer_addresses')->where('country_code', 'IE')->value('country_code'), DB::table('customer_addresses')->where('country_code', 'IE')->value('region_code'), DB::table('customer_addresses')->where('country_code', 'IE')->value('postal_code')]);

        $stranger = $this->customer('stranger@example.test', address: $this->address());
        $foreign = (string) DB::table('customer_addresses')->where('customer_id', $stranger)->value('id');
        $version = $this->version($customer);
        $this->put('/admin/customers/'.$customer.'/addresses/'.$foreign, array_replace($base, ['version' => $version, 'line1' => 'Hijacked']))->assertNotFound();
        $this->delete('/admin/customers/'.$customer.'/addresses/'.$foreign, ['version' => $version])->assertNotFound();
        self::assertSame('Ermou 1', DB::table('customer_addresses')->where('id', $foreign)->value('line1'));
        self::assertNotNull($own);
    }

    public function test_the_initial_address_is_created_in_the_same_transaction_as_the_customer(): void
    {
        $this->post('/admin/customers', $this->form(['address' => ['first_name' => 'Nikos', 'last_name' => 'Georgiou', 'line1' => 'Egnatia 1', 'city' => 'Thessaloniki', 'country_code' => 'GR', 'postal_code' => '54630']]))->assertSessionHasNoErrors();
        $customer = DB::table('customers')->sole();
        self::assertSame((string) DB::table('customer_addresses')->value('id'), (string) $customer->default_shipping_address_id);
        // An invalid address rolls back the customer too.
        $this->post('/admin/customers', $this->form(['email' => 'second@example.test', 'address' => ['first_name' => 'A', 'last_name' => 'B', 'line1' => 'X', 'city' => 'Y', 'country_code' => 'ZZ']]))->assertSessionHasErrors('address.country_code');
        self::assertSame(1, DB::table('customers')->count());
    }

    public function test_tax_calculation_reads_the_customer_exemption_and_default_addresses(): void
    {
        $areas = app(RateAreaAdministration::class);
        $greece = $areas->create($this->id(), new SaveRateAreaData(['en' => 'Greece'], 'active', false, ['GR']));
        $islands = $areas->create($this->id(), new SaveRateAreaData(['en' => 'Islands'], 'active', false, ['GR'], ['GR' => ['K']]));
        $vat = app(TaxAdministration::class)->create($this->id(), new SaveTaxData(['en' => 'VAT'], null, 0, 'active', false, 'shipping', [new SaveTaxRateData($greece, 'percent', '24'), new SaveTaxRateData($islands, 'percent', '17')]));
        $calculate = fn (string $customer) => app(TaxCalculationService::class)->forTaxes([$vat], '100.00', app(CustomerTaxProfiles::class)->context($customer));

        $customer = $this->customer(address: $this->address());
        self::assertSame('24.00', $calculate($customer)->totalTax);
        // The shipping default selects the rate.
        $island = $this->customers->addAddress($this->id(), $customer, 1, $this->address('GR', 'K', 'Mytilene 3'));
        $this->customers->update($this->id(), $customer, 2, new SaveCustomerData('Maria', 'Papadopoulou', 'maria@example.test', null, false, null, null, null, $island));
        self::assertSame('17.00', $calculate($customer)->totalTax);
        // Toggling the customer's single exemption flag reaches the calculation.
        $this->customers->update($this->id(), $customer, 3, new SaveCustomerData('Maria', 'Papadopoulou', 'maria@example.test', null, true, null));
        $exempt = $calculate($customer);
        self::assertSame(['0.00', '100.00', true], [$exempt->totalTax, $exempt->final, $exempt->exempt]);
        // No address yet: only a default rate area could apply.
        $bare = $this->customer('bare@example.test');
        self::assertFalse($calculate($bare)->complete());
        self::assertNull(app(CustomerTaxProfiles::class)->context('999999'));
    }

    public function test_the_customer_migration_round_trips_without_touching_staff_accounts(): void
    {
        DB::beginTransaction();
        try {
            // Build the migration's original predecessor schema, without later customer dependants.
            DB::statement('CREATE SCHEMA customer_round_trip');
            DB::statement('SET LOCAL search_path TO customer_round_trip');
            $file = database_path('migrations/2026_10_13_001900_create_customers.php');
            foreach (glob(database_path('migrations/*.php')) as $predecessor) {
                if (strcmp($predecessor, $file) < 0) {
                    (require $predecessor)->up();
                }
            }
            $id = DB::table('users')->insertGetId(['name' => 'Existing staff', 'email' => 'staff@example.test', 'password' => 'existing-password-hash']);
            $before = DB::table('users')->where('id', $id)->first();
            $migration = require $file;
            $migration->up();
            DB::table('customers')->insert(['email' => 'buyer@example.test', 'first_name' => 'Maria', 'last_name' => 'Example']);
            $migration->down();
            foreach (['customers', 'customer_addresses', 'customer_groups', 'customer_group_translations', 'customer_group_members'] as $table) {
                self::assertFalse(Schema::hasTable($table));
            }
            self::assertEquals($before, DB::table('users')->where('id', $id)->first());
            $migration->up();
            self::assertSame(0, DB::table('customers')->count());
            $customer = DB::table('customers')->insertGetId(['email' => 'buyer@example.test', 'first_name' => 'Maria', 'last_name' => 'Example']);
            DB::table('customer_addresses')->insert(['customer_id' => $customer, 'first_name' => 'Maria', 'last_name' => 'Example', 'line1' => 'Ermou 1', 'city' => 'Athens', 'country_code' => 'GR']);
            self::assertSame(1, DB::table('customer_addresses')->count());
            self::assertEquals($before, DB::table('users')->where('id', $id)->first());
        } finally {
            DB::rollBack();
        }
    }
}
