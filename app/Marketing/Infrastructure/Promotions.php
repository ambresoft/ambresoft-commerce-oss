<?php

namespace App\Marketing\Infrastructure;

use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Settings\Infrastructure\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Promotions
{
    public const TARGETS = ['products' => ['products', 'product_id'], 'categories' => ['categories', 'category_id'], 'groups' => ['customer_groups', 'group_id'], 'shipping' => ['shipping_methods', 'shipping_method_id']];

    /** @param array<string, mixed> $input */
    public function save(string $actor, ?string $id, array $input): string
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('promotions.manage');

        return app(AuditLogger::class)->operation('promotion', $actor, fn (): string => DB::transaction(function () use ($id, $input): string {
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            if ($id !== null) {
                $this->current($id, (int) ($input['version'] ?? 0));
            }
            $scale = app(CurrencyRepository::class)->currentPrimary()->decimals;
            $money = $scale === 0 ? 'regex:/^\d{1,14}$/D' : 'regex:/^\d{1,14}(?:\.\d{1,'.$scale.'})?$/D';
            foreach (['starts_at', 'ends_at', 'coupon_id', 'amount'] as $key) {
                if (($input[$key] ?? '') === '') {
                    $input[$key] = null;
                }
            }
            Validator::make($input, ['conditions' => ['present', 'array', 'max:6'], 'conditions.*' => ['array']])->validate();
            $locales = app(LocaleCatalog::class)->active()->pluck('id', 'code')->all();
            $rules = [
                'translations' => ['required', 'array:'.implode(',', array_keys($locales))],
                'status' => ['required', Rule::in(['active', 'disabled'])],
                'mode' => ['required', Rule::in(['automatic', 'coupon'])],
                'coupon_id' => ['nullable', 'required_if:mode,coupon', 'prohibited_if:mode,automatic', 'integer', Rule::exists('coupons', 'id')->whereNull('deleted_at'), Rule::unique('promotions', 'coupon_id')->ignore($id)],
                'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
                'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i', ...($input['starts_at'] !== null ? ['after:starts_at'] : [])],
                'priority' => ['required', 'integer', 'between:0,99999'], 'stop_other' => ['required', 'boolean'],
                'match' => ['required', Rule::in(['all', 'any'])],
                'benefit' => ['required', Rule::in(($input['mode'] ?? '') === 'coupon' ? ['coupon'] : ['percentage', 'fixed', 'free_shipping'])],
                'scope' => ['required', Rule::in(['order', 'products'])],
                'amount' => ['nullable', 'required_unless:benefit,free_shipping,coupon', 'prohibited_if:benefit,free_shipping,coupon', 'string', ($input['benefit'] ?? '') === 'percentage' ? 'regex:/^\d{1,3}(?:\.\d{1,4})?$/D' : $money, 'numeric', 'gt:0', ($input['benefit'] ?? '') === 'percentage' ? 'max:100' : 'max:99999999999999'],
                'all_products' => ['required', 'boolean'], 'exclude_sale' => ['required', 'boolean'], 'all_shipping' => ['required', 'boolean'],
                'conditions' => ['present', 'array', 'max:6'], 'conditions.*' => ['array:type,minimum,maximum,quantity,quantity_scope,match,products,categories,groups,shipping'],
                'conditions.*.type' => ['required', 'distinct', Rule::in(['subtotal', 'products', 'categories', 'quantity', 'groups', 'shipping'])],
                'conditions.*.minimum' => ['nullable', 'string', $money, 'numeric', 'min:0'],
                'conditions.*.maximum' => ['nullable', 'string', $money, 'numeric', 'min:0'],
                'conditions.*.quantity' => ['nullable', 'integer', 'between:1,1000000'],
                'conditions.*.quantity_scope' => ['required', Rule::in(['cart', 'products', 'categories'])],
                'conditions.*.match' => ['required', Rule::in(['all', 'any'])],
            ];
            foreach ($locales as $code => $localeId) {
                $rules['translations.'.$code] = ['required', 'array:name,description'];
                $rules['translations.'.$code.'.name'] = ['required', 'string', 'max:128'];
                $rules['translations.'.$code.'.description'] = ['present', 'nullable', 'string', 'max:2000'];
                if (isset($input['translations'][$code]['name']) && is_string($input['translations'][$code]['name'])) {
                    $input['translations'][$code]['name'] = trim($input['translations'][$code]['name']);
                }
            }
            foreach (self::TARGETS as $kind => [$table, $column]) {
                $exists = Rule::exists($table, 'id');
                if ($table === 'products') {
                    $exists->whereNull('deleted_at')->where('status', 'active');
                } elseif ($table === 'shipping_methods') {
                    $exists->where('is_active', true);
                } else {
                    $exists->where('status', 'active');
                }
                $rules['conditions.*.'.$kind] = ['present', 'array', 'max:500'];
                $rules['conditions.*.'.$kind.'.*'] = ['integer', 'distinct', $exists];
                if ($kind !== 'groups') {
                    foreach ($kind === 'shipping' ? [$kind] : [$kind, 'excluded_'.$kind] as $field) {
                        $rules[$field] = ['present', 'array', 'max:500'];
                        $rules[$field.'.*'] = ['integer', 'distinct', $exists];
                    }
                }
            }
            foreach ($input['conditions'] ?? [] as $n => $condition) {
                foreach (['minimum', 'maximum', 'quantity'] as $field) {
                    if (($condition[$field] ?? '') === '') {
                        $input['conditions'][$n][$field] = null;
                    }
                }
                // Distinct selections within each rule, rather than across unrelated rules.
                foreach (self::TARGETS as $kind => [$table, $column]) {
                    $rules['conditions.'.$n.'.'.$kind.'.*'] = $rules['conditions.*.'.$kind.'.*'];
                }
            }
            foreach (self::TARGETS as $kind => [$table, $column]) {
                unset($rules['conditions.*.'.$kind.'.*']);
            }
            $data = Validator::make($input, $rules)->validate();
            foreach ($data['conditions'] as $n => $condition) {
                $type = $condition['type'];
                if ($type === 'subtotal') {
                    $min = $condition['minimum'] ?? null;
                    $max = $condition['maximum'] ?? null;
                    if (($min === null && $max === null) || ($min !== null && $max !== null && bccomp($min, $max, 6) > 0)) {
                        $this->invalid('conditions.'.$n.'.minimum');
                    }
                } elseif ($type === 'quantity') {
                    if (empty($condition['quantity'])) {
                        $this->invalid('conditions.'.$n.'.quantity');
                    }
                    if ($condition['quantity_scope'] !== 'cart' && $condition[$condition['quantity_scope']] === []) {
                        $this->invalid('conditions.'.$n.'.'.$condition['quantity_scope']);
                    }
                } elseif ($condition[$type] === []) {
                    $this->invalid('conditions.'.$n.'.'.$type);
                }
            }
            if ($data['mode'] === 'automatic' && $data['benefit'] !== 'free_shipping' && $data['scope'] === 'products' && ! $data['all_products'] && $data['products'] === [] && $data['categories'] === []) {
                $this->invalid('products');
            }
            if ($data['benefit'] === 'free_shipping' && ! $data['all_shipping'] && $data['shipping'] === []) {
                $this->invalid('shipping');
            }
            $fields = array_intersect_key($data, array_flip(['status', 'mode', 'coupon_id', 'priority', 'stop_other', 'match', 'benefit', 'scope', 'amount', 'all_products', 'exclude_sale', 'all_shipping']));
            $timezone = app(GeneralSettings::class)->all()['timezone'];
            foreach (['starts_at', 'ends_at'] as $field) {
                $fields[$field] = $data[$field] === null ? null : CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data[$field], $timezone)->utc();
            }
            if ($id === null) {
                $id = (string) DB::table('promotions')->insertGetId([...$fields, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('promotions')->where('id', $id)->update([...$fields, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            }
            foreach ($locales as $code => $localeId) {
                DB::table('promotion_translations')->updateOrInsert(['promotion_id' => $id, 'locale_id' => $localeId], ['name' => $data['translations'][$code]['name'], 'description' => $data['translations'][$code]['description'] ?? '']);
            }
            DB::table('promotion_conditions')->where('promotion_id', $id)->delete();
            foreach ($data['conditions'] as $condition) {
                $conditionId = DB::table('promotion_conditions')->insertGetId(['promotion_id' => $id, 'type' => $condition['type'], 'minimum' => $condition['minimum'] ?? null, 'maximum' => $condition['maximum'] ?? null, 'quantity' => $condition['quantity'] ?? null, 'quantity_scope' => $condition['quantity_scope'], 'match' => $condition['match']]);
                foreach (self::TARGETS as $kind => [$table, $column]) {
                    foreach ($condition[$kind] as $target) {
                        DB::table('promotion_condition_'.$table)->insert(['condition_id' => $conditionId, $column => $target]);
                    }
                }
            }
            foreach (self::TARGETS as $kind => [$table, $column]) {
                if ($kind === 'groups') {
                    continue;
                }
                DB::table('promotion_'.$table)->where('promotion_id', $id)->delete();
                foreach ($kind === 'shipping' ? [$kind => false] : [$kind => false, 'excluded_'.$kind => true] as $field => $excluded) {
                    foreach ($data[$field] as $target) {
                        DB::table('promotion_'.$table)->insert(['promotion_id' => $id, $column => $target, 'excluded' => $excluded]);
                    }
                }
            }

            return $id;
        }));
    }

    public function status(string $actor, string $id, int $version, string $status): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('promotions.manage');
        Validator::make(['status' => $status], ['status' => ['required', Rule::in(['active', 'disabled'])]])->validate();
        app(AuditLogger::class)->operation('promotion', $actor, fn () => DB::transaction(function () use ($id, $version, $status): void {
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            $this->current($id, $version);
            DB::table('promotions')->where('id', $id)->update(['status' => $status, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
        }));
    }

    private function current(string $id, int $version): object
    {
        $row = DB::table('promotions')->where('id', $id)->lockForUpdate()->first();
        if ($row === null || (int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('promotions.stale')]);
        }

        return $row;
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => __('promotions.invalid_configuration')]);
    }
}
