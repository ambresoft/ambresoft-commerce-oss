<?php

namespace App\Marketing\Infrastructure;

use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Pricing\Domain\Repository\CurrencyRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Coupons
{
    /** @param array<string, mixed> $input */
    public function save(string $actor, ?string $id, array $input): string
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('coupons.manage');

        return app(AuditLogger::class)->operation('coupon', $actor, fn (): string => DB::transaction(function () use ($id, $input): string {
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            if ($id !== null) {
                $current = DB::table('coupons')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
                if ($current === null || (int) $current->lock_version !== (int) ($input['version'] ?? 0)) {
                    throw ValidationException::withMessages(['version' => __('coupons.stale')]);
                }
            }
            foreach (['name', 'code', 'amount', 'minimum'] as $field) {
                if (is_string($input[$field] ?? null)) {
                    $input[$field] = trim($input[$field]);
                }
            }
            foreach (['starts_on', 'ends_on', 'total_limit', 'customer_limit'] as $field) {
                if (($input[$field] ?? '') === '' || (str_ends_with($field, '_limit') && (string) ($input[$field] ?? '') === '0')) {
                    $input[$field] = null;
                }
            }
            $input['minimum'] = ($input['minimum'] ?? '') === '' ? '0' : $input['minimum'];
            $scale = app(CurrencyRepository::class)->currentPrimary()->decimals;
            $decimal = 'regex:/^\d{1,14}(?:\.\d{1,'.max(1, $scale).'})?$/D';
            if ($scale === 0) {
                $decimal = 'regex:/^\d{1,14}$/D';
            }
            $data = Validator::make($input, [
                'name' => ['required', 'string', 'min:3', 'max:128'],
                'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{3,20}$/D', Rule::unique('coupons', 'code')->ignore($id)],
                'type' => ['required', Rule::in(['percentage', 'fixed'])],
                'amount' => ['required', 'numeric', ($input['type'] ?? '') === 'percentage' ? 'regex:/^\d{1,3}(?:\.\d{1,4})?$/D' : $decimal, 'min:0', ($input['type'] ?? '') === 'percentage' ? 'max:100' : 'max:99999999999999'],
                'minimum' => ['required', 'numeric', $decimal, 'min:0'],
                'free_shipping' => ['required', 'boolean'], 'login_required' => ['required', 'boolean'], 'all_products' => ['required', 'boolean'],
                'starts_on' => ['nullable', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d', ...(! empty($input['starts_on']) ? ['after_or_equal:starts_on'] : [])],
                'total_limit' => ['nullable', 'integer', 'between:1,1000000000'], 'customer_limit' => ['nullable', 'integer', 'between:1,1000000000'],
                'status' => ['required', Rule::in(['active', 'disabled'])],
                'product_ids' => ['present', 'array', 'max:500'], 'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
                'category_ids' => ['present', 'array', 'max:500'], 'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')],
            ], [], array_combine(array_keys($input), array_map(fn (string $f): string => __('coupons.'.$f), array_keys($input))))->validate();
            if (! $data['free_shipping'] && bccomp((string) $data['amount'], '0', 6) <= 0) {
                throw ValidationException::withMessages(['amount' => __('coupons.positive')]);
            }
            $products = $data['product_ids'];
            $categories = $data['category_ids'];
            unset($data['product_ids'], $data['category_ids']);
            if ($data['all_products']) {
                $products = $categories = [];
            }
            if ($id === null) {
                $id = (string) DB::table('coupons')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('coupons')->where('id', $id)->update([...$data, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            }
            foreach (['products' => ['product', $products], 'categories' => ['category', $categories]] as $table => [$singular, $ids]) {
                DB::table('coupon_'.$table)->where('coupon_id', $id)->delete();
                foreach ($ids as $target) {
                    DB::table('coupon_'.$table)->insert(['coupon_id' => $id, $singular.'_id' => $target]);
                }
            }

            return $id;
        }));
    }

    /** Archive definitions while retaining code uniqueness and financial history.
     * @param  list<array{id: string, version: int}>  $rows
     */
    public function delete(string $actor, array $rows): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('coupons.delete');
        app(AuditLogger::class)->operation('coupon', $actor, fn () => DB::transaction(function () use ($rows): void {
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            foreach ($rows as $row) {
                $current = DB::table('coupons')->where('id', $row['id'])->whereNull('deleted_at')->lockForUpdate()->first();
                if ($current === null || (int) $current->lock_version !== (int) $row['version']) {
                    throw ValidationException::withMessages(['version' => __('coupons.stale')]);
                }
                DB::table('coupons')->where('id', $row['id'])->update(['deleted_at' => now(), 'status' => 'disabled', 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            }
        }));
    }
}
