<?php

namespace App\Customer\Infrastructure\Persistence;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Customer\Application\Command\SaveCustomerGroupData;
use App\Customer\Application\Contract\CustomerGroupAdministration;
use App\Customer\Infrastructure\CustomerGroupInput;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class EloquentCustomerGroupAdministration implements CustomerGroupAdministration
{
    public function __construct(private CustomerGroupInput $input) {}

    private function authorize(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('customer-groups.manage');
    }

    private function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(2142514)');
    }

    public function create(string $actor, SaveCustomerGroupData $data): string
    {
        return app(AuditLogger::class)->operation('customer_group', $actor, fn (): string => $this->guarded(fn (): string => DB::transaction(function () use ($actor, $data): string {
            $this->lock();
            $this->authorize($actor);
            $this->assertValid($data);
            if ($data->default) {
                DB::table('customer_groups')->where('is_default', true)->update(['is_default' => false, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            }
            $id = DB::table('customer_groups')->insertGetId(['status' => $data->status, 'is_default' => $data->default, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $this->writeTexts($id, $data);

            return (string) $id;
        })));
    }

    public function update(string $actor, string $id, int $version, SaveCustomerGroupData $data): void
    {
        app(AuditLogger::class)->operation('customer_group', $actor, fn () => $this->guarded(fn () => DB::transaction(function () use ($actor, $id, $version, $data): void {
            $this->lock();
            $this->authorize($actor);
            $group = $this->row($id, $version);
            $this->assertValid($data);
            if ($data->default && ! $group->is_default) {
                // Moving the default is one explicit step: the previous default stops being one in the same transaction.
                DB::table('customer_groups')->where('is_default', true)->update(['is_default' => false, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            }
            $changed = $group->status !== $data->status || (bool) $group->is_default !== $data->default;
            $changed = $this->writeTexts((int) $id, $data) || $changed;
            if ($changed) {
                DB::table('customer_groups')->where('id', $id)->update(['status' => $data->status, 'is_default' => $data->default, 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
            }
        })));
    }

    public function delete(string $actor, string $id, int $version, ?string $reassignTo = null): void
    {
        app(AuditLogger::class)->operation('customer_group', $actor, fn () => $this->guarded(fn () => DB::transaction(function () use ($actor, $id, $version, $reassignTo): void {
            $this->lock();
            $this->authorize($actor);
            $group = $this->row($id, $version);
            if ($group->is_default) {
                throw ValidationException::withMessages(['group' => __('customers.group_default_delete')]);
            }
            if (DB::table('product_sales')->where('customer_group_id', $id)->exists()) {
                throw ValidationException::withMessages(['group' => __('sales.group_in_use')]);
            }
            $members = DB::table('customer_group_members')->where('group_id', $id)->count();
            if ($members > 0) {
                if ($reassignTo === null) {
                    throw ValidationException::withMessages(['reassign_to' => __('customers.group_has_members', ['count' => $members])]);
                }
                $target = DB::table('customer_groups')->where('id', $reassignTo)->first();
                if ($target === null || $reassignTo === $id || $target->status !== 'active') {
                    throw ValidationException::withMessages(['reassign_to' => __('customers.group_reassign_invalid')]);
                }
                // Members move first (existing memberships are kept once); their profiles advance a version.
                DB::insert('INSERT INTO customer_group_members (customer_id, group_id) SELECT customer_id, ? FROM customer_group_members WHERE group_id = ? ON CONFLICT DO NOTHING', [$reassignTo, $id]);
                DB::update('UPDATE customers SET lock_version = lock_version + 1, updated_at = now() WHERE id IN (SELECT customer_id FROM customer_group_members WHERE group_id = ?)', [$id]);
                DB::table('customer_group_members')->where('group_id', $id)->delete();
            }
            DB::table('customer_groups')->where('id', $id)->delete();
        })));
    }

    private function assertValid(SaveCustomerGroupData $data): void
    {
        $this->input->assertValid($data);
        if ($data->default && $data->status !== 'active') {
            throw ValidationException::withMessages(['status' => __('customers.group_default_active')]);
        }
    }

    private function row(string $id, int $version): object
    {
        $group = DB::table('customer_groups')->where('id', $id)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['version' => __('customers.stale')]);
        if ((int) $group->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('customers.stale')]);
        }

        return $group;
    }

    private function writeTexts(int $id, SaveCustomerGroupData $data): bool
    {
        $existing = DB::table('customer_group_translations')->where('group_id', $id)->get()->keyBy(fn ($row): int => (int) $row->locale_id);
        $changed = false;
        foreach (app(LocaleCatalog::class)->active()->pluck('id', 'code') as $code => $localeId) {
            $name = $data->names[$code] ?? null;
            $description = $data->descriptions[$code] ?? '';
            $row = $existing->get($localeId);
            if ($name === null) {
                if ($row !== null) {
                    DB::table('customer_group_translations')->where('group_id', $id)->where('locale_id', $localeId)->delete();
                    $changed = true;
                }
            } elseif ($row === null || $row->name !== $name || $row->description !== $description) {
                DB::table('customer_group_translations')->updateOrInsert(['group_id' => $id, 'locale_id' => $localeId], ['name' => $name, 'description' => $description]);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function guarded(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['names' => __('customers.group_name_taken')]);
            }
            // Catalog visibility restrictions keep their groups: deleting one would silently widen visibility.
            if (in_array($e->getCode(), ['23001', '23503'], true) && preg_match('/\b(category|product)_customer_groups\b/', $e->getMessage()) === 1) {
                throw ValidationException::withMessages(['group' => __('customers.group_restricts_catalog')]);
            }
            throw $e;
        }
    }
}
