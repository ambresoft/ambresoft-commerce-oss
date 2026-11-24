<?php

namespace Tests\Feature;

use App\Customer\Application\Command\SaveCustomerData;
use App\Customer\Application\Command\SaveCustomerGroupData;
use App\Customer\Application\Contract\CustomerAdministration;
use App\Customer\Application\Contract\CustomerGroupAdministration;
use App\Customer\Infrastructure\CustomerDemoSeeder;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CustomerGroupTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private CustomerGroupAdministration $groups;

    private CustomerAdministration $customers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        $this->groups = app(CustomerGroupAdministration::class);
        $this->customers = app(CustomerAdministration::class);
    }

    private function id(): string
    {
        return (string) $this->actor->id;
    }

    private function group(string $name, bool $default = false, string $status = 'active', string $description = ''): string
    {
        return $this->groups->create($this->id(), new SaveCustomerGroupData(['en' => $name], $description === '' ? [] : ['en' => $description], $status, $default));
    }

    /** @param list<string>|null $groups */
    private function customer(string $email, ?array $groups = null): string
    {
        return $this->customers->create($this->id(), new SaveCustomerData('Test', 'Customer', $email, null, false, null, $groups));
    }

    /** @return list<string> */
    private function memberships(string $customer): array
    {
        return DB::table('customer_group_members')->where('customer_id', $customer)->orderBy('group_id')->pluck('group_id')->map(strval(...))->all();
    }

    private function version(string $table, string $id): int
    {
        return (int) DB::table($table)->where('id', $id)->value('lock_version');
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

    public function test_groups_are_created_and_updated_with_localized_names(): void
    {
        $this->post('/admin/customer-groups', ['names' => ['en' => 'Wholesale', 'el' => 'Χονδρική'], 'descriptions' => ['en' => 'Trade buyers', 'el' => ''], 'status' => 'active', 'is_default' => false])->assertSessionHasNoErrors();
        $id = (string) DB::table('customer_groups')->value('id');
        $this->get('/admin/customer-groups/'.$id.'/edit')->assertInertia(fn (Assert $page) => $page->component('Admin/CustomerGroups/Form')->where('group.names.el', 'Χονδρική')->where('group.descriptions.en', 'Trade buyers'));
        $this->put('/admin/customer-groups/'.$id, ['version' => 1, 'names' => ['en' => 'Trade', 'el' => ''], 'descriptions' => ['en' => '', 'el' => ''], 'status' => 'disabled', 'is_default' => false])->assertSessionHasNoErrors();
        self::assertSame(['disabled', 2], [DB::table('customer_groups')->where('id', $id)->value('status'), $this->version('customer_groups', $id)]);
        self::assertSame(['Trade'], DB::table('customer_group_translations')->where('group_id', $id)->pluck('name')->all());
        $this->post('/admin/customer-groups', ['names' => ['en' => ''], 'descriptions' => [], 'status' => 'active', 'is_default' => false])->assertSessionHasErrors('names.en');
        $this->invalid(fn () => $this->group('TRADE'), 'names');
        $this->invalid(fn () => $this->groups->update($this->id(), $id, 1, new SaveCustomerGroupData(['en' => 'Late'], [], 'active', false)), 'version');
        $this->get('/admin/customer-groups')->assertInertia(fn (Assert $page) => $page->where('groups.0.name', 'Trade')->where('groups.0.members', 0));
    }

    public function test_the_default_group_is_explicit_unique_active_and_used_for_new_customers(): void
    {
        // Without a default group a new customer simply has no group.
        self::assertSame([], $this->memberships($this->customer('none@example.test')));
        $retail = $this->group('Retail', default: true);
        self::assertSame([$retail], $this->memberships($this->customer('retail@example.test')));
        // An explicit choice, including none, wins over the default.
        self::assertSame([], $this->memberships($this->customer('chosen@example.test', [])));
        // Making another group the default moves the flag in one step.
        $vip = $this->group('VIP', default: true);
        self::assertSame([$vip], DB::table('customer_groups')->where('is_default', true)->pluck('id')->map(strval(...))->all());
        self::assertSame([$vip], $this->memberships($this->customer('vip@example.test')));
        // The default must be active, in the application and in the database.
        $this->invalid(fn () => $this->groups->update($this->id(), $vip, 1, new SaveCustomerGroupData(['en' => 'VIP'], [], 'disabled', true)), 'status');
        $this->invalid(fn () => $this->group('Hidden default', default: true, status: 'disabled'), 'status');
        $this->expectException(QueryException::class);
        DB::table('customer_groups')->where('id', $vip)->update(['status' => 'disabled']);
        self::assertNotSame($retail, $vip);
    }

    public function test_memberships_are_assigned_changed_and_never_duplicated(): void
    {
        $a = $this->group('A');
        $b = $this->group('B');
        $customer = $this->customer('member@example.test', [$a]);
        $this->put('/admin/customers/'.$customer, ['version' => 1, 'first_name' => 'Test', 'last_name' => 'Customer', 'email' => 'member@example.test', 'tax_exempt' => false, 'group_ids' => [$b, $a]])->assertSessionHasNoErrors();
        self::assertSame([$a, $b], $this->memberships($customer));
        $this->put('/admin/customers/'.$customer, ['version' => 2, 'first_name' => 'Test', 'last_name' => 'Customer', 'email' => 'member@example.test', 'tax_exempt' => false, 'group_ids' => [$b, $b]])->assertSessionHasErrors('group_ids.0');
        $this->put('/admin/customers/'.$customer, ['version' => 2, 'first_name' => 'Test', 'last_name' => 'Customer', 'email' => 'member@example.test', 'tax_exempt' => false, 'group_ids' => ['99999']])->assertSessionHasErrors('group_ids');
        $this->put('/admin/customers/'.$customer, ['version' => 2, 'first_name' => 'Test', 'last_name' => 'Customer', 'email' => 'member@example.test', 'tax_exempt' => false, 'group_ids' => [$b]])->assertSessionHasNoErrors();
        self::assertSame([$b], $this->memberships($customer));
        // Omitting group_ids keeps memberships (older callers).
        $this->put('/admin/customers/'.$customer, ['version' => 3, 'first_name' => 'Renamed', 'last_name' => 'Customer', 'email' => 'member@example.test', 'tax_exempt' => false])->assertSessionHasNoErrors();
        self::assertSame([$b], $this->memberships($customer));
        $this->expectException(QueryException::class);
        DB::table('customer_group_members')->insert(['customer_id' => $customer, 'group_id' => $b]);
    }

    public function test_disabled_groups_stay_on_members_but_cannot_be_newly_assigned(): void
    {
        $old = $this->group('Legacy');
        $member = $this->customer('member@example.test', [$old]);
        $this->groups->update($this->id(), $old, 1, new SaveCustomerGroupData(['en' => 'Legacy'], [], 'disabled', false));
        $this->invalid(fn () => $this->customer('new@example.test', [$old]), 'group_ids');
        // The existing member keeps it through an unrelated edit.
        $this->customers->update($this->id(), $member, 1, new SaveCustomerData('Test', 'Customer', 'member@example.test', null, true, null, [$old]));
        self::assertSame([$old], $this->memberships($member));
        $this->get('/admin/customers/'.$member.'/edit')->assertInertia(fn (Assert $page) => $page->where('groups.0.active', false));
        $this->get('/admin/customers/create')->assertInertia(fn (Assert $page) => $page->where('groups', []));
    }

    public function test_deleting_a_group_never_orphans_or_silently_drops_memberships(): void
    {
        $default = $this->group('Retail', default: true);
        $old = $this->group('Old');
        $target = $this->group('Target');
        $off = $this->group('Off', status: 'disabled');
        $both = $this->customer('both@example.test', [$old, $target]);
        $only = $this->customer('only@example.test', [$old]);
        $before = $this->version('customers', $only);

        $this->invalid(fn () => $this->groups->delete($this->id(), $default, 1), 'group');
        $this->invalid(fn () => $this->groups->delete($this->id(), $old, 1), 'reassign_to');
        $this->invalid(fn () => $this->groups->delete($this->id(), $old, 1, $old), 'reassign_to');
        $this->invalid(fn () => $this->groups->delete($this->id(), $old, 1, $off), 'reassign_to');
        $this->invalid(fn () => $this->groups->delete($this->id(), $old, 1, '99999'), 'reassign_to');
        self::assertTrue(DB::table('customer_groups')->where('id', $old)->exists());

        $this->delete('/admin/customer-groups/'.$old, ['version' => 1, 'reassign_to' => $target])->assertSessionHasNoErrors();
        self::assertFalse(DB::table('customer_groups')->where('id', $old)->exists());
        self::assertSame([$target], $this->memberships($both));
        self::assertSame([$target], $this->memberships($only));
        self::assertSame($before + 1, $this->version('customers', $only));
        // An empty group is simply removed; the database refuses deleting one that still has members.
        $this->groups->delete($this->id(), $off, 1);
        $this->expectException(QueryException::class);
        DB::table('customer_groups')->where('id', $target)->delete();
    }

    public function test_group_management_requires_its_capability(): void
    {
        $group = $this->group('VIP');
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'customers.view', 'customer-groups.view']));
        $this->get('/admin/customer-groups')->assertOk();
        $this->get('/admin/customer-groups/'.$group.'/edit')->assertOk();
        $this->get('/admin/customer-groups/create')->assertForbidden();
        $this->post('/admin/customer-groups', ['names' => ['en' => 'X'], 'descriptions' => [], 'status' => 'active', 'is_default' => false])->assertForbidden();
        $this->put('/admin/customer-groups/'.$group, ['version' => 1, 'names' => ['en' => 'X'], 'descriptions' => [], 'status' => 'active', 'is_default' => false])->assertForbidden();
        $this->delete('/admin/customer-groups/'.$group, ['version' => 1])->assertForbidden();
        self::assertSame(1, DB::table('customer_groups')->count());
    }

    public function test_the_sample_customers_are_opt_in_idempotent_and_valid(): void
    {
        $seeder = app(CustomerDemoSeeder::class);
        self::assertSame(['groups' => 3, 'customers' => 8, 'blacklist' => 8], $seeder->run($this->id()));
        self::assertSame(['groups' => 0, 'customers' => 0, 'blacklist' => 0], $seeder->run($this->id()));
        self::assertSame(8, DB::table('customer_blacklist_entries')->whereNull('released_at')->count());
        // The linked entry flags the disabled sample customer.
        $this->get('/admin/customers?blacklisted=yes')->assertInertia(fn ($page) => $page->where('customers.total', 1));
        self::assertSame(8, DB::table('customers')->count());
        self::assertSame(1, DB::table('customers')->where('status', 'disabled')->count());
        self::assertSame(1, DB::table('customers')->where('tax_exempt', true)->count());
        // One customer deliberately has no address; every default points at an owned address.
        self::assertSame(7, DB::table('customer_addresses')->count());
        self::assertSame(1, DB::table('customer_groups')->where('is_default', true)->count());
        $this->app->instance('env', 'production');
        try {
            $this->expectException(\RuntimeException::class);
            $seeder->run($this->id());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
