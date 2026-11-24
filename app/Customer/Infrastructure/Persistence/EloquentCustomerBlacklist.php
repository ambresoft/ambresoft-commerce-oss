<?php

namespace App\Customer\Infrastructure\Persistence;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Customer\Application\Contract\CustomerBlacklist;
use App\Customer\Application\Contract\CustomerBlacklistAdministration;
use App\Customer\Application\Service\BlacklistMatch;
use App\Customer\Infrastructure\ContactNormalizer;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class EloquentCustomerBlacklist implements CustomerBlacklist, CustomerBlacklistAdministration
{
    public function match(?string $email, ?string $phone): ?BlacklistMatch
    {
        foreach (['email' => ContactNormalizer::email($email), 'phone' => ContactNormalizer::phone($phone)] as $kind => $value) {
            if ($value === null) {
                continue;
            }
            $row = DB::table('customer_blacklist_entries')->where('kind', $kind)->where('value', $value)->where('scope', 'full')->whereNull('released_at')->first(['id', 'reason']);
            if ($row !== null) {
                return new BlacklistMatch((string) $row->id, $kind, $row->reason);
            }
        }

        return null;
    }

    public function restrictedPaymentMethods(?string $email, array $phones): array
    {
        $email = ContactNormalizer::email($email);
        $phones = array_values(array_unique(array_filter(array_map(ContactNormalizer::phone(...), $phones))));
        if ($email === null && $phones === []) {
            return [];
        }

        return DB::table('customer_blacklist_entries as e')->join('customer_blacklist_payment_methods as m', 'm.entry_id', '=', 'e.id')
            ->where('e.scope', 'payment_methods')->whereNull('e.released_at')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('e.kind', 'email')->where('e.value', $email ?? ''))->orWhere(fn ($q) => $q->where('e.kind', 'phone')->whereIn('e.value', $phones)))
            ->distinct()->pluck('m.payment_method_id')->map(strval(...))->all();
    }

    public function add(string $actor, string $kind, string $value, string $reason, string $scope = 'full', array $paymentMethodIds = []): string
    {
        return app(AuditLogger::class)->operation('customer_blacklist_entry', $actor, fn (): string => DB::transaction(function () use ($actor, $kind, $value, $reason, $scope, $paymentMethodIds): string {
            $this->authorize($actor, 'customer-blacklist.manage');

            return $this->insert($actor, $kind, $value, $reason, null, $scope, $paymentMethodIds);
        }));
    }

    public function addForCustomer(string $actor, string $customerId, array $kinds, string $reason, string $scope = 'full', array $paymentMethodIds = []): array
    {
        return app(AuditLogger::class)->operation('customer_blacklist_entry', $actor, fn (): array => DB::transaction(function () use ($actor, $customerId, $kinds, $reason, $scope, $paymentMethodIds): array {
            $this->authorize($actor, 'customer-blacklist.manage');
            $this->authorize($actor, 'customers.view');
            $customer = DB::table('customers')->where('id', $customerId)->first() ?? throw new ModelNotFoundException('Customer not found.');
            $kinds = array_values(array_unique($kinds));
            if ($kinds === [] || array_diff($kinds, ['email', 'phone']) !== []) {
                throw ValidationException::withMessages(['kinds' => __('customers.blacklist_kinds_required')]);
            }
            $ids = [];
            foreach ($kinds as $kind) {
                $value = $kind === 'email' ? ContactNormalizer::email($customer->email) : ContactNormalizer::phone($customer->phone);
                if ($value === null) {
                    throw ValidationException::withMessages(['kinds' => __('customers.blacklist_no_phone')]);
                }
                $ids[] = $this->insert($actor, $kind, $value, $reason, (int) $customerId, $scope, $paymentMethodIds);
            }

            return $ids;
        }));
    }

    public function release(string $actor, string $entryId): void
    {
        app(AuditLogger::class)->operation('customer_blacklist_entry', $actor, fn () => DB::transaction(function () use ($actor, $entryId): void {
            $this->authorize($actor, 'customer-blacklist.manage');
            $entry = DB::table('customer_blacklist_entries')->where('id', $entryId)->lockForUpdate()->first() ?? throw new ModelNotFoundException('Entry not found.');
            if ($entry->released_at !== null) {
                throw ValidationException::withMessages(['entry' => __('customers.blacklist_already_released')]);
            }
            DB::table('customer_blacklist_entries')->where('id', $entryId)->update(['released_at' => now(), 'released_by' => $actor]);
        }));
    }

    /** @param list<string> $paymentMethodIds */
    private function insert(string $actor, string $kind, string $value, string $reason, ?int $customerId, string $scope, array $paymentMethodIds): string
    {
        $normalized = $kind === 'email' ? ContactNormalizer::email($value) : ($kind === 'phone' ? ContactNormalizer::phone($value) : null);
        $reason = trim($reason);
        $methods = $scope === 'payment_methods' ? $paymentMethodIds : [];
        Validator::make(['kind' => $kind, 'value' => $normalized, 'reason' => $reason, 'scope' => $scope, 'payment_method_ids' => $methods], [
            'kind' => ['required', 'in:email,phone'],
            'value' => $kind === 'email' ? ['required', 'string', 'max:254', 'email:rfc'] : ['required', 'regex:/^[0-9]{5,15}$/D'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'scope' => ['required', 'in:full,payment_methods'],
            // A payment-method entry must take at least one existing method away.
            'payment_method_ids' => [...($scope === 'payment_methods' ? ['required', 'array', 'min:1', 'max:50'] : ['array', 'max:0']),
                fn (string $attribute, mixed $ids, \Closure $fail) => ! is_array($ids) || (array_filter($ids, fn (mixed $id): bool => ! is_scalar($id) || preg_match('/^[1-9][0-9]{0,18}$/D', (string) $id) !== 1) === []
                    && DB::table('payment_methods')->whereIn('id', array_unique($ids))->count() === count(array_unique($ids))) ?: $fail(__('customers.blacklist_methods_required'))],
        ], ['value.*' => __($kind === 'email' ? 'customers.email_invalid' : 'customers.phone_invalid'), 'reason.*' => __('customers.blacklist_reason_required'),
            'scope.*' => __('customers.blacklist_scope_invalid'), 'payment_method_ids.*' => __('customers.blacklist_methods_required')])->validate();
        try {
            $id = (string) DB::table('customer_blacklist_entries')->insertGetId(['kind' => $kind, 'value' => $normalized, 'reason' => $reason, 'scope' => $scope, 'customer_id' => $customerId, 'created_by' => $actor, 'created_at' => now()]);
            DB::table('customer_blacklist_payment_methods')->insert(array_map(fn (mixed $method): array => ['entry_id' => $id, 'payment_method_id' => (int) $method], array_values(array_unique($methods))));

            return $id;
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages([$customerId === null ? 'value' : 'kinds' => __('customers.blacklist_exists')]);
            }
            throw $e;
        }
    }

    private function authorize(string $actor, string $ability): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize($ability);
    }
}
