<?php

namespace Tests\Feature;

use App\Customer\Application\Command\SaveAddressData;
use App\Customer\Application\Command\SaveCustomerData;
use App\Customer\Application\Contract\CustomerAdministration;
use App\Customer\Application\Contract\CustomerBlacklist;
use App\Customer\Application\Contract\CustomerBlacklistAdministration;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Payment\Infrastructure\PaymentSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CustomerBlacklistTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    private function id(): string
    {
        return (string) $this->actor->id;
    }

    private function customer(string $email, ?string $phone = '+30 210 123 4567', ?string $addressPhone = null): string
    {
        $address = $addressPhone === null ? null : new SaveAddressData(null, 'A', 'B', null, 'Ermou 1', null, 'Athens', '10563', 'GR', null, $addressPhone);

        return app(CustomerAdministration::class)->create($this->id(), new SaveCustomerData('Test', 'Customer', $email, $phone, false, null, [], address: $address));
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

    public function test_entries_are_normalized_require_a_reason_and_are_unique_while_active(): void
    {
        $blacklist = app(CustomerBlacklistAdministration::class);
        $blacklist->add($this->id(), 'email', '  Fraud@Example.TEST ', 'Chargeback fraud');
        $blacklist->add($this->id(), 'phone', '0030 (210) 123-4567', 'Fake orders');
        self::assertSame(['fraud@example.test', '302101234567'], DB::table('customer_blacklist_entries')->orderBy('id')->pluck('value')->all());
        $this->invalid(fn () => $blacklist->add($this->id(), 'email', 'fraud@example.test', 'Again'), 'value');
        $this->invalid(fn () => $blacklist->add($this->id(), 'phone', '+30 210 1234567', 'Again'), 'value');
        $this->invalid(fn () => $blacklist->add($this->id(), 'email', 'someone@example.test', '  '), 'reason');
        $this->invalid(fn () => $blacklist->add($this->id(), 'email', 'not-an-email', 'Reason here'), 'value');
        $this->invalid(fn () => $blacklist->add($this->id(), 'phone', '12', 'Reason here'), 'value');
        $this->invalid(fn () => $blacklist->add($this->id(), 'fax', '123456', 'Reason here'), 'kind');
        self::assertSame(2, DB::table('customer_blacklist_entries')->count());
        // The database refuses unnormalized or reasonless rows too.
        foreach ([['kind' => 'email', 'value' => 'UPPER@example.test', 'reason' => 'x y z'], ['kind' => 'phone', 'value' => '+301234567', 'reason' => 'x y z'], ['kind' => 'email', 'value' => 'ok@example.test', 'reason' => ' ']] as $row) {
            DB::beginTransaction();
            try {
                DB::table('customer_blacklist_entries')->insert($row + ['created_at' => now()]);
                self::fail('Expected a database refusal');
            } catch (QueryException) {
                self::assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_the_checkout_port_matches_normalized_values_and_returns_the_reason(): void
    {
        $blacklist = app(CustomerBlacklistAdministration::class);
        $port = app(CustomerBlacklist::class);
        self::assertNull($port->match('clean@example.test', '+30 210 000 0000'));
        $email = $blacklist->add($this->id(), 'email', 'bad@example.test', 'Chargeback fraud');
        $blacklist->add($this->id(), 'phone', '+30 694 000 0000', 'Repeated fake orders');
        $byEmail = $port->match('BAD@example.test ', null);
        self::assertSame([$email, 'email', 'Chargeback fraud'], [$byEmail?->entryId, $byEmail?->kind, $byEmail?->reason]);
        self::assertSame('phone', $port->match('clean@example.test', '0030 6940000000')?->kind);
        self::assertNull($port->match('clean@example.test', '694 000 0000'));
        self::assertNull($port->match(null, null));
        // Lifting stops the block; the history stays.
        $blacklist->release($this->id(), $email);
        self::assertNull($port->match('bad@example.test', null));
        self::assertSame(1, DB::table('customer_blacklist_entries')->whereNotNull('released_at')->count());
        $this->invalid(fn () => $blacklist->release($this->id(), $email), 'entry');
        // A released value can be blacklisted again.
        $blacklist->add($this->id(), 'email', 'bad@example.test', 'Second offence');
        self::assertSame('Second offence', $port->match('bad@example.test', null)?->reason);
    }

    public function test_existing_customers_are_flagged_by_email_or_any_stored_phone(): void
    {
        $byEmail = $this->customer('flag@example.test');
        $byPhone = $this->customer('phone@example.test', '+30 694 111 1111');
        $byAddress = $this->customer('address@example.test', null, '+30 698 222 2222');
        $clean = $this->customer('clean@example.test', '+30 210 999 9999');
        $blacklist = app(CustomerBlacklistAdministration::class);
        $blacklist->add($this->id(), 'email', 'flag@example.test', 'Fraud');
        $blacklist->add($this->id(), 'phone', '306941111111', 'Fake orders');
        $blacklist->add($this->id(), 'phone', '0030 698 222 2222', 'Abuse');

        $this->get('/admin/customers?blacklisted=yes&sort=email&direction=asc')->assertInertia(fn (Assert $page) => $page->where('customers.total', 3)
            ->where('customers.data.0.email', 'address@example.test')->where('customers.data.0.blacklisted', true));
        $this->get('/admin/customers?blacklisted=no')->assertInertia(fn (Assert $page) => $page->where('customers.total', 1)->where('customers.data.0.email', 'clean@example.test'));
        $this->get('/admin/customers/'.$byEmail)->assertInertia(fn (Assert $page) => $page->where('customer.blacklist.0.reason', 'Fraud')->where('customer.blacklist.0.kind', 'email'));
        $this->get('/admin/customers/'.$byAddress)->assertInertia(fn (Assert $page) => $page->where('customer.blacklist.0.reason', 'Abuse'));
        $this->get('/admin/customers/'.$clean)->assertInertia(fn (Assert $page) => $page->where('customer.blacklist', []));
        $this->get('/admin/customers?blacklisted=maybe')->assertSessionHasErrors('blacklisted');
        // Lifting clears the flag everywhere.
        $entry = (string) DB::table('customer_blacklist_entries')->where('value', 'flag@example.test')->value('id');
        $this->delete('/admin/customer-blacklist/'.$entry)->assertSessionHasNoErrors();
        $this->get('/admin/customers?blacklisted=yes')->assertInertia(fn (Assert $page) => $page->where('customers.total', 2));
        self::assertNotNull($byPhone);
    }

    public function test_a_customer_is_blacklisted_from_its_own_record_with_a_reason(): void
    {
        $customer = $this->customer('own@example.test', '+30 210 555 0000');
        $this->post('/admin/customers/'.$customer.'/blacklist', ['kinds' => ['email', 'phone'], 'reason' => ' '])->assertSessionHasErrors('reason');
        $this->post('/admin/customers/'.$customer.'/blacklist', ['kinds' => [], 'reason' => 'Fraud'])->assertSessionHasErrors('kinds');
        $this->post('/admin/customers/'.$customer.'/blacklist', ['kinds' => ['email', 'phone'], 'reason' => 'Chargeback fraud', 'value' => 'forged@example.test'])->assertSessionHasNoErrors();
        $rows = DB::table('customer_blacklist_entries')->orderBy('id')->get();
        // Values come from the stored record; the request cannot supply them.
        self::assertSame(['own@example.test', '302105550000'], $rows->pluck('value')->all());
        self::assertSame([(int) $customer, (int) $customer], $rows->pluck('customer_id')->map(fn ($v): int => (int) $v)->all());
        $this->post('/admin/customers/'.$customer.'/blacklist', ['kinds' => ['email'], 'reason' => 'Again'])->assertSessionHasErrors('kinds');
        $noPhone = $this->customer('nophone@example.test', null);
        $this->post('/admin/customers/'.$noPhone.'/blacklist', ['kinds' => ['phone'], 'reason' => 'Why not'])->assertSessionHasErrors('kinds');
        // Blacklisting does not disable the customer or change staff accounts.
        self::assertSame('active', DB::table('customers')->where('id', $customer)->value('status'));
        $this->get('/admin/customer-blacklist?search=chargeback')->assertInertia(fn (Assert $page) => $page->component('Admin/CustomerBlacklist/Index')->where('entries.total', 2)->where('entries.data.0.customer.id', $customer));
        // Personal values and reasons stay out of the audit ledger.
        $events = DB::table('audit_logs')->where('entity_type', 'customer_blacklist_entry')->get();
        self::assertNotEmpty($events);
        foreach ($events as $event) {
            self::assertStringNotContainsString('own@example.test', (string) $event->changes);
            self::assertStringNotContainsString('Chargeback', (string) $event->changes);
        }
    }

    public function test_the_blacklist_requires_its_own_capabilities(): void
    {
        $customer = $this->customer('guard@example.test');
        $entry = app(CustomerBlacklistAdministration::class)->add($this->id(), 'email', 'x@example.test', 'Reason here');
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'customers.view', 'customers.update', 'customers.status']));
        $this->get('/admin/customer-blacklist')->assertForbidden();
        $this->post('/admin/customer-blacklist', ['kind' => 'email', 'value' => 'a@example.test', 'reason' => 'Reason here'])->assertForbidden();
        $this->post('/admin/customers/'.$customer.'/blacklist', ['kinds' => ['email'], 'reason' => 'Reason here'])->assertForbidden();
        $this->delete('/admin/customer-blacklist/'.$entry)->assertForbidden();
        // Viewers can read but not change.
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'customer-blacklist.view']));
        $this->get('/admin/customer-blacklist')->assertOk();
        $this->delete('/admin/customer-blacklist/'.$entry)->assertForbidden();
        self::assertNull(DB::table('customer_blacklist_entries')->value('released_at'));
    }

    public function test_an_entry_can_disable_only_some_payment_methods_and_is_not_a_full_block(): void
    {
        app(PaymentSettings::class)->ensure();
        $cod = (string) DB::table('payment_methods')->where('code', 'cod')->value('id');
        $bank = (string) DB::table('payment_methods')->where('code', 'bank_deposit')->value('id');
        $customer = $this->customer('buyer@example.test', '+30 690 000 0142');

        // A payment-method entry needs at least one existing method; the screen sends the choice.
        $this->post('/admin/customer-blacklist', ['kind' => 'email', 'value' => 'x@example.test', 'reason' => 'Refuses parcels', 'scope' => 'payment_methods'])->assertSessionHasErrors('payment_method_ids');
        $this->post('/admin/customer-blacklist', ['kind' => 'email', 'value' => 'x@example.test', 'reason' => 'Refuses parcels', 'scope' => 'payment_methods', 'payment_method_ids' => ['999999']])->assertSessionHasErrors('payment_method_ids');
        $this->post('/admin/customer-blacklist', ['kind' => 'email', 'value' => 'x@example.test', 'reason' => 'Refuses parcels', 'scope' => 'everything'])->assertSessionHasErrors('scope');
        $this->post('/admin/customers/'.$customer.'/blacklist', ['kinds' => ['email', 'phone'], 'reason' => 'Refuses cash on delivery parcels', 'scope' => 'payment_methods', 'payment_method_ids' => [$cod]])->assertSessionHasNoErrors();
        $entries = DB::table('customer_blacklist_entries')->orderBy('id')->get();
        self::assertSame(['payment_methods', 'payment_methods'], $entries->pluck('scope')->all());
        self::assertSame([$cod, $cod], DB::table('customer_blacklist_payment_methods')->orderBy('entry_id')->pluck('payment_method_id')->map(strval(...))->all());

        // Not a block: the purchasing port and the customer list do not treat the buyer as blacklisted.
        self::assertNull(app(CustomerBlacklist::class)->match('buyer@example.test', '+306900000142'));
        self::assertSame([$cod], app(CustomerBlacklist::class)->restrictedPaymentMethods('BUYER@example.test', [null, '00306900000142']));
        $this->get('/admin/customers?blacklisted=yes')->assertInertia(fn (Assert $p) => $p->where('customers.total', 0));
        $this->get('/admin/customers/'.$customer)->assertInertia(fn (Assert $p) => $p->where('customer.blacklist.0.scope', 'payment_methods')
            ->where('customer.blacklist.0.payment_methods', ['Cash on delivery'])->where('paymentMethods', fn ($methods) => collect($methods)->pluck('id')->contains($bank)));
        $this->get('/admin/customer-blacklist?scope=payment_methods')->assertInertia(fn (Assert $p) => $p->where('entries.total', 2)->where('entries.data.0.payment_methods', ['Cash on delivery']));
        $this->get('/admin/customer-blacklist?scope=full')->assertInertia(fn (Assert $p) => $p->where('entries.total', 0));
        // One active entry per value: a full block needs the restriction lifted first.
        $this->post('/admin/customer-blacklist', ['kind' => 'email', 'value' => 'buyer@example.test', 'reason' => 'Fraud'])->assertSessionHasErrors('value');
    }
}
