<?php

namespace App\Customer\Infrastructure\Persistence;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Customer\Application\Command\SaveAddressData;
use App\Customer\Application\Command\SaveCustomerData;
use App\Customer\Application\Contract\CustomerAdministration;
use App\Customer\Infrastructure\CustomerAddressBook;
use App\Customer\Infrastructure\CustomerInput;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class EloquentCustomerAdministration implements CustomerAdministration
{
    public function __construct(private CustomerInput $input, private CustomerAddressBook $book) {}

    private function authorize(string $actor, string $ability): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize($ability);
    }

    /** Customer writes and group status/deletion serialize, so a membership can never target a group being retired. */
    private function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(2142514)');
    }

    public function create(string $actor, SaveCustomerData $data): string
    {
        return app(AuditLogger::class)->operation('customer', $actor, fn (): string => $this->unique(fn (): string => DB::transaction(function () use ($actor, $data): string {
            $this->lock();
            $this->authorize($actor, 'customers.create');
            $this->input->assertValid($data, true);
            $this->assertEmailFree($this->email($data), null);
            $id = DB::table('customers')->insertGetId([
                ...$this->profile($data), 'status' => 'active', 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            // No explicit choice: the configured default group, if one exists. Never an incidental "first" group.
            $default = DB::table('customer_groups')->where('is_default', true)->value('id');
            $groups = $data->groupIds ?? ($default === null ? [] : [(string) $default]);
            $this->syncGroups($id, $groups, []);
            if ($data->address !== null) {
                $this->book->insert($id, $data->address);
                $this->book->ensureDefaults($id);
            }

            return (string) $id;
        })));
    }

    public function update(string $actor, string $id, int $version, SaveCustomerData $data): void
    {
        app(AuditLogger::class)->operation('customer', $actor, fn () => $this->unique(fn () => DB::transaction(function () use ($actor, $id, $version, $data): void {
            $this->lock();
            $this->authorize($actor, 'customers.update');
            $customer = $this->row($id, $version);
            $this->input->assertValid($data, false);
            $this->assertEmailFree($this->email($data), (int) $id);
            $changed = false;
            foreach ($this->profile($data) as $field => $value) {
                $changed = $changed || $customer->{$field} !== $value;
            }
            if ($data->groupIds !== null) {
                $current = DB::table('customer_group_members')->where('customer_id', $id)->pluck('group_id')->map(strval(...))->all();
                $changed = $this->syncGroups((int) $id, $data->groupIds, $current) || $changed;
            }
            $defaults = [];
            foreach (['default_billing_address_id' => $data->defaultBillingAddressId, 'default_shipping_address_id' => $data->defaultShippingAddressId] as $field => $address) {
                // Omitted means "keep": while addresses exist both defaults are always set.
                if ($address === null) {
                    continue;
                }
                if (! DB::table('customer_addresses')->where('id', $address)->where('customer_id', $id)->exists()) {
                    throw ValidationException::withMessages([$field => __('customers.address_not_owned')]);
                }
                if ((string) $customer->{$field} !== (string) $address) {
                    $defaults[$field] = $address;
                }
            }
            if ($changed || $defaults !== []) {
                DB::table('customers')->where('id', $id)->update([...$this->profile($data), ...$defaults, 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
            }
        })));
    }

    public function changeStatus(string $actor, string $id, int $version, string $status): void
    {
        app(AuditLogger::class)->operation('customer', $actor, fn () => DB::transaction(function () use ($actor, $id, $version, $status): void {
            $this->lock();
            $this->authorize($actor, 'customers.status');
            if (! in_array($status, ['active', 'disabled'], true)) {
                throw ValidationException::withMessages(['status' => __('customers.status_invalid')]);
            }
            $customer = $this->row($id, $version);
            if ($customer->status === $status) {
                throw ValidationException::withMessages(['status' => __($status === 'disabled' ? 'customers.already_disabled' : 'customers.already_active')]);
            }
            // Activating a profile the customer deleted brings the account back.
            DB::table('customers')->where('id', $id)->update([...($status === 'active' ? ['deleted_at' => null] : []), 'status' => $status, 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
        }));
    }

    public function addAddress(string $actor, string $customerId, int $version, SaveAddressData $data): string
    {
        return app(AuditLogger::class)->operation('customer', $actor, fn (): string => DB::transaction(function () use ($actor, $customerId, $version, $data): string {
            $this->lock();
            $this->authorize($actor, 'customers.update');
            $this->row($customerId, $version);
            $this->input->assertAddress($data);
            $id = $this->book->insert((int) $customerId, $data);
            $this->book->ensureDefaults((int) $customerId);
            $this->touch($customerId, $actor);

            return (string) $id;
        }));
    }

    public function updateAddress(string $actor, string $customerId, string $addressId, int $version, SaveAddressData $data): void
    {
        app(AuditLogger::class)->operation('customer', $actor, fn () => DB::transaction(function () use ($actor, $customerId, $addressId, $version, $data): void {
            $this->lock();
            $this->authorize($actor, 'customers.update');
            $this->row($customerId, $version);
            $this->input->assertAddress($data, $this->input->savedRegion($this->book->owned($customerId, $addressId)));
            $this->book->update($customerId, $addressId, $data);
            $this->touch($customerId, $actor);
        }));
    }

    public function deleteAddress(string $actor, string $customerId, string $addressId, int $version): void
    {
        app(AuditLogger::class)->operation('customer', $actor, fn () => DB::transaction(function () use ($actor, $customerId, $addressId, $version): void {
            $this->lock();
            $this->authorize($actor, 'customers.update');
            $customer = $this->row($customerId, $version);
            $this->book->owned($customerId, $addressId);
            $this->book->delete($customer, $addressId);
            $this->touch($customerId, $actor);
        }));
    }

    private function row(string $id, int $version): object
    {
        $customer = DB::table('customers')->where('id', $id)->lockForUpdate()->first() ?? throw new ModelNotFoundException('Customer not found.');
        if ((int) $customer->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('customers.stale')]);
        }

        return $customer;
    }

    private function touch(string $customerId, string $actor): void
    {
        DB::table('customers')->where('id', $customerId)->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
    }

    /**
     * Makes memberships equal to the requested groups; returns whether anything changed.
     * A disabled group may be kept but never newly assigned; unknown IDs are rejected.
     *
     * @param  list<string>  $requested
     * @param  list<string>  $current
     */
    private function syncGroups(int $customerId, array $requested, array $current): bool
    {
        $requested = array_values(array_unique($requested));
        $groups = DB::table('customer_groups')->whereIn('id', $requested)->pluck('status', 'id')->mapWithKeys(fn ($status, $id): array => [(string) $id => $status])->all();
        foreach ($requested as $group) {
            if (! isset($groups[$group]) || ($groups[$group] !== 'active' && ! in_array($group, $current, true))) {
                throw ValidationException::withMessages(['group_ids' => __('customers.group_invalid')]);
            }
        }
        $add = array_diff($requested, $current);
        $remove = array_diff($current, $requested);
        if ($remove !== []) {
            DB::table('customer_group_members')->where('customer_id', $customerId)->whereIn('group_id', $remove)->delete();
        }
        if ($add !== []) {
            DB::table('customer_group_members')->insertOrIgnore(array_map(fn (string $group): array => ['customer_id' => $customerId, 'group_id' => $group], array_values($add)));
        }

        return $add !== [] || $remove !== [];
    }

    private function assertEmailFree(string $email, ?int $self): void
    {
        if (DB::table('customers')->where('email', $email)->when($self !== null, fn ($q) => $q->where('id', '<>', $self))->exists()) {
            throw ValidationException::withMessages(['email' => __('customers.email_taken')]);
        }
    }

    /** @return array<string, mixed> */
    private function profile(SaveCustomerData $data): array
    {
        return ['first_name' => $data->firstName, 'last_name' => $data->lastName, 'email' => $this->email($data), 'phone' => $data->phone, 'tax_exempt' => $data->taxExempt, 'notes' => $data->notes];
    }

    /** Every caller gets the staff-identity normalization: trimmed and lower case. */
    private function email(SaveCustomerData $data): string
    {
        return mb_strtolower(trim($data->email));
    }

    /**
     * Two requests racing for one email: the unique index decides and the loser gets a field error.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function unique(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['email' => __('customers.email_taken')]);
            }
            throw $e;
        }
    }
}
