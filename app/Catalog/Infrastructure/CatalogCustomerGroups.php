<?php

namespace App\Catalog\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer-group visibility restrictions of categories and products. No rows means every visitor; otherwise members
 * of at least one listed group. Called inside the owner's transaction after its authorization. Duplication copies
 * the source restriction as is, including disabled groups it already holds.
 */
final class CatalogCustomerGroups
{
    private const TABLES = ['category' => ['category_customer_groups', 'category_id'], 'product' => ['product_customer_groups', 'product_id']];

    /** @return list<string> */
    public function assigned(string $owner, string $id): array
    {
        [$table, $key] = self::TABLES[$owner];

        return DB::table($table)->where($key, $id)->orderBy('group_id')->pluck('group_id')->map(fn ($v): string => (string) $v)->all();
    }

    /**
     * Replaces the restriction with exactly the given groups, writing only the difference.
     *
     * @param  list<int|string>  $groups
     * @return array{added: list<string>, removed: list<string>}
     */
    public function sync(string $owner, string $id, array $groups, string $errorField = 'customer_groups'): array
    {
        [$table, $key] = self::TABLES[$owner];
        $wanted = array_values(array_unique(array_map(strval(...), $groups)));
        sort($wanted);
        // The foreign key still rejects a group deleted concurrently after this friendly check.
        if ($wanted !== [] && DB::table('customer_groups')->whereIn('id', $wanted)->count() !== count($wanted)) {
            throw ValidationException::withMessages([$errorField => __($owner === 'product' ? 'products.customer_groups_missing' : 'categories.customer_groups_missing')]);
        }
        $current = $this->assigned($owner, $id);
        $added = array_values(array_diff($wanted, $current));
        // Like customer memberships, a disabled group may stay assigned but cannot be newly assigned.
        if ($added !== [] && DB::table('customer_groups')->whereIn('id', $added)->where('status', '<>', 'active')->exists()) {
            throw ValidationException::withMessages([$errorField => __($owner === 'product' ? 'products.customer_group_disabled' : 'categories.customer_group_disabled')]);
        }
        $removed = array_values(array_diff($current, $wanted));
        if ($removed !== []) {
            DB::table($table)->where($key, $id)->whereIn('group_id', $removed)->delete();
        }
        if ($added !== []) {
            DB::table($table)->insert(array_map(fn (string $group): array => [$key => $id, 'group_id' => $group], $added));
        }

        return ['added' => $added, 'removed' => $removed];
    }

    public function copy(string $owner, string $from, string $to): void
    {
        [$table, $key] = self::TABLES[$owner];
        $rows = array_map(fn (string $group): array => [$key => $to, 'group_id' => $group], $this->assigned($owner, $from));
        if ($rows !== []) {
            DB::table($table)->insert($rows);
        }
    }
}
