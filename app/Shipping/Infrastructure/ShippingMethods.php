<?php

namespace App\Shipping\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\ImageAttachment;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Shared\Domain\Service\Decimal;
use App\Shipping\Application\Contract\QuotesRates;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Administration of shipping methods (`shipping-methods.manage`). Names, descriptions and delivery times are per
 * language. A method is enabled only when usable: manual methods need rates, carrier methods a provider that quotes
 * rates and a complete account. Disabling affects new orders only; methods referenced by orders or shipments are
 * kept (disable them instead of deleting). Writes serialize on one advisory lock with optimistic versions.
 */
final class ShippingMethods
{
    private const LOCK = 2143201;

    public function __construct(private CarrierSettings $carriers) {}

    /** @param array<string, mixed> $input */
    public function save(string $actor, ?string $id, ?int $version, array $input): string
    {
        $images = app(ImageAttachment::class);
        try {
            return $this->write($actor, $id, $version, $input, $images);
        } catch (\Throwable $e) {
            $images->discardUncommitted();
            throw $e;
        }
    }

    /** @param array<string, mixed> $input */
    private function write(string $actor, ?string $id, ?int $version, array $input, ImageAttachment $images): string
    {
        return $this->audited($actor, fn (): string => DB::transaction(function () use ($actor, $id, $version, $input, $images): string {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $current = $id === null ? null : $this->locked($id, (int) $version);
            $data = $this->validated($input, $current);
            // An optional image (e.g. the carrier's logo); a new upload replaces the current one.
            $image = $current->image_media_id ?? null;
            if (filter_var($input['remove_image'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $image = null;
            }
            if (($input['image'] ?? null) instanceof UploadedFile) {
                $image = $images->store($input['image']);
            }
            $row = [
                'calculation' => $data['calculation'], 'provider' => $data['provider'], 'account_id' => $data['account_id'], 'is_active' => false,
                'min_weight' => $data['min_weight'], 'max_weight' => $data['max_weight'], 'free_shipping_applies' => $data['free_shipping_applies'],
                'carrier_amount' => $data['carrier_amount'], 'carrier_surcharge' => $data['carrier_surcharge'], 'value_limit_enabled' => $data['value_limit_enabled'], 'max_shipment_value' => $data['max_shipment_value'],
                'image_media_id' => $image, 'updated_by' => $actor, 'updated_at' => now(),
            ];
            if ($id === null) {
                $id = (string) DB::table('shipping_methods')->insertGetId([...$row, 'position' => min(99999, (int) DB::table('shipping_methods')->max('position') + 10), 'created_by' => $actor, 'created_at' => now()]);
            } else {
                DB::table('shipping_methods')->where('id', $id)->update([...$row, 'lock_version' => (int) $version + 1]);
            }
            foreach ($data['translations'] as $localeId => $text) {
                if ($text === null) {
                    DB::table('shipping_method_translations')->where('method_id', $id)->where('locale_id', $localeId)->delete();
                } else {
                    DB::table('shipping_method_translations')->updateOrInsert(['method_id' => $id, 'locale_id' => $localeId], $text);
                }
            }
            DB::table('shipping_method_rates')->where('method_id', $id)->delete();
            foreach ($data['rates'] as $rate) {
                DB::table('shipping_method_rates')->insert([...$rate, 'method_id' => $id]);
            }
            foreach (['shipping_method_areas' => ['rate_area_id', $data['area_ids']], 'shipping_method_taxes' => ['tax_id', $data['tax_ids']],
                'shipping_method_payment_methods' => ['payment_method_id', $data['payment_method_ids']], 'shipping_method_customer_groups' => ['group_id', $data['group_ids']]] as $table => [$column, $ids]) {
                DB::table($table)->where('method_id', $id)->whereNotIn($column, $ids)->delete();
                foreach ($ids as $value) {
                    DB::table($table)->insertOrIgnore(['method_id' => $id, $column => $value]);
                }
            }
            if ($data['is_active']) {
                $this->assertUsable($id);
                DB::table('shipping_methods')->where('id', $id)->update(['is_active' => true]);
            }

            return $id;
        }));
    }

    public function setActive(string $actor, string $id, int $version, bool $active): void
    {
        $this->audited($actor, fn () => DB::transaction(function () use ($actor, $id, $version, $active): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $this->locked($id, $version);
            if ($active) {
                $this->assertUsable($id);
            }
            DB::table('shipping_methods')->where('id', $id)->update(['is_active' => $active, 'lock_version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now()]);
        }));
    }

    /** @param list<string> $ids Every method, in the new order */
    public function reorder(string $actor, array $ids): void
    {
        $this->audited($actor, fn () => DB::transaction(function () use ($actor, $ids): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $all = DB::table('shipping_methods')->pluck('id')->map(strval(...))->all();
            if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $all) !== [] || array_diff($all, $ids) !== []) {
                throw ValidationException::withMessages(['ids' => __('shipping.stale')]);
            }
            foreach ($ids as $n => $id) {
                DB::table('shipping_methods')->where('id', $id)->where('position', '<>', ($n + 1) * 10)
                    ->update(['position' => ($n + 1) * 10, 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
            }
        }));
    }

    /** Deletes an unused method; methods used by orders or shipments are kept so history stays readable. */
    public function delete(string $actor, string $id, int $version): void
    {
        $this->audited($actor, fn () => DB::transaction(function () use ($actor, $id, $version): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $this->locked($id, $version);
            $used = DB::table('orders')->where('shipping_method_id', $id)->count() + DB::table('order_shipments')->where('shipping_method_id', $id)->count();
            if ($used > 0) {
                throw ValidationException::withMessages(['method' => __('shipping.method_in_use', ['count' => $used])]);
            }
            DB::table('shipping_methods')->where('id', $id)->delete();
        }));
    }

    private function assertUsable(string $id): void
    {
        $method = DB::table('shipping_methods')->where('id', $id)->first();
        if ($method->calculation === 'manual') {
            if (! DB::table('shipping_method_rates')->where('method_id', $id)->exists()) {
                throw ValidationException::withMessages(['is_active' => __('shipping.enable_needs_rates')]);
            }

            return;
        }
        $provider = $this->carriers->provider($method->provider);
        if (! $provider instanceof QuotesRates || $this->carriers->usableAccount($provider, $method->account_id === null ? null : (string) $method->account_id) === null) {
            throw ValidationException::withMessages(['is_active' => __('shipping.enable_needs_account')]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $input, ?object $current): array
    {
        $errors = [];
        $scale = app(CurrencyRepository::class)->currentPrimary()->decimals;
        $text = fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '';
        $bool = fn (string $field, bool $default): bool => array_key_exists($field, $input) ? filter_var($input[$field], FILTER_VALIDATE_BOOLEAN) : $default;
        $amount = function (mixed $value, string $key, bool $required = false) use (&$errors, $text, $scale): ?string {
            $value = $text($value);
            if ($value === '') {
                if ($required) {
                    $errors[$key] = __('shipping.amount_invalid', ['decimals' => $scale]);
                }

                return null;
            }
            $fraction = $scale === 0 ? '' : '(?:\.\d{1,'.$scale.'})?';
            if (preg_match('/^\d{1,14}'.$fraction.'$/D', $value) !== 1) {
                $errors[$key] = __('shipping.amount_invalid', ['decimals' => $scale]);

                return null;
            }

            return $value;
        };
        $weight = function (mixed $value, string $key) use (&$errors, $text): ?string {
            $value = $text($value);
            if ($value === '') {
                return null;
            }
            if (preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D', $value) !== 1) {
                $errors[$key] = __('shipping.weight_invalid');

                return null;
            }

            return Decimal::trim($value);
        };

        $calculation = $text($input['calculation'] ?? ($current->calculation ?? 'manual'));
        if (! in_array($calculation, ['manual', 'carrier'], true)) {
            $errors['calculation'] = __('shipping.choice_invalid');
        }
        $provider = $text($input['provider'] ?? '') ?: null;
        $accountId = $text($input['account_id'] ?? '') ?: null;
        if ($provider !== null) {
            $instance = $this->carriers->provider($provider);
            $unchanged = $current !== null && $current->provider === $provider;
            if ($instance === null && ! $unchanged) {
                $errors['provider'] = __('shipping.provider_unavailable');
            } elseif ($calculation === 'carrier' && $instance !== null && ! $instance instanceof QuotesRates) {
                $errors['provider'] = __('shipping.provider_no_rates');
            }
            if ($accountId !== null && ! DB::table('shipping_carrier_accounts')->where('id', ctype_digit($accountId) ? $accountId : 0)->where('provider', $provider)->exists()) {
                $errors['account_id'] = __('shipping.account_missing');
            }
        } else {
            $accountId = null;
            if ($calculation === 'carrier') {
                $errors['provider'] = __('shipping.provider_required');
            }
        }
        if ($calculation === 'carrier' && $provider !== null && $accountId === null) {
            $errors['account_id'] = __('shipping.account_required');
        }
        $min = $weight($input['min_weight'] ?? null, 'min_weight');
        $max = $weight($input['max_weight'] ?? null, 'max_weight');
        if ($max !== null && Decimal::compare($max, '0') <= 0) {
            $errors['max_weight'] = __('shipping.weight_invalid');
        }
        if ($min !== null && $max !== null && Decimal::compare($max, $min) < 0) {
            $errors['max_weight'] = __('shipping.weight_limits_invalid');
        }
        $carrierAmount = $text($input['carrier_amount'] ?? 'gross');
        if (! in_array($carrierAmount, ['gross', 'net'], true)) {
            $errors['carrier_amount'] = __('shipping.choice_invalid');
        }
        $surcharge = $amount($input['carrier_surcharge'] ?? '0', 'carrier_surcharge') ?? '0';

        $locales = app(LocaleCatalog::class);
        $translations = [];
        foreach ($locales->active() as $locale) {
            $fields = (array) (($input['translations'] ?? [])[$locale->code] ?? []);
            $name = $text($fields['name'] ?? '');
            $key = 'translations.'.$locale->code.'.';
            foreach (['name' => 100, 'delivery_time' => 100] as $field => $limit) {
                $value = $text($fields[$field] ?? '');
                if (mb_strlen($value) > $limit || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                    $errors[$key.$field] = __('shipping.text_invalid', ['max' => $limit]);
                }
            }
            $description = $text($fields['description'] ?? '');
            if (mb_strlen($description) > 500) {
                $errors[$key.'description'] = __('shipping.text_invalid', ['max' => 500]);
            }
            if ($name === '' && $locale->code === $locales->defaultCode()) {
                $errors[$key.'name'] = __('shipping.name_required');
            }
            $translations[(int) $locale->id] = $name === '' ? null : ['name' => $name, 'description' => $description, 'delivery_time' => $text($fields['delivery_time'] ?? '')];
        }

        $rates = [];
        if ($calculation === 'manual') {
            $submitted = is_array($input['rates'] ?? null) ? array_values($input['rates']) : [];
            if (count($submitted) > 500) {
                $errors['rates'] = __('shipping.rates_too_many');
                $submitted = [];
            }
            $validAreas = DB::table('rate_areas')->pluck('id')->map(strval(...))->all();
            foreach ($submitted as $n => $rate) {
                $rate = is_array($rate) ? $rate : [];
                $key = 'rates.'.$n.'.';
                $area = $text($rate['rate_area_id'] ?? '');
                if (! in_array($area, $validAreas, true)) {
                    $errors[$key.'rate_area_id'] = __('shipping.area_required');
                }
                $from = $weight($rate['weight_from'] ?? '0', $key.'weight_from') ?? '0';
                $to = $weight($rate['weight_to'] ?? null, $key.'weight_to');
                if ($to !== null && Decimal::compare($to, $from) <= 0) {
                    $errors[$key.'weight_to'] = __('shipping.bracket_invalid');
                }
                $rates[] = ['rate_area_id' => $area, 'weight_from' => $from, 'weight_to' => $to, 'base' => $amount($rate['base'] ?? '0', $key.'base') ?? '0', 'per_unit' => $amount($rate['per_unit'] ?? '0', $key.'per_unit') ?? '0'];
            }
            // Brackets of one area may not overlap; an open-ended bracket must be the last one.
            $byArea = [];
            foreach ($rates as $n => $rate) {
                $byArea[$rate['rate_area_id']][] = [$n, $rate];
            }
            foreach ($byArea as $list) {
                usort($list, fn (array $a, array $b): int => Decimal::compare($a[1]['weight_from'], $b[1]['weight_from']));
                for ($i = 1; $i < count($list); $i++) {
                    $previous = $list[$i - 1][1];
                    if ($previous['weight_to'] === null || Decimal::compare($previous['weight_to'], $list[$i][1]['weight_from']) > 0) {
                        $errors['rates.'.$list[$i][0].'.weight_from'] = __('shipping.bracket_overlap');
                    }
                }
            }
        }

        $ids = function (string $field, string $table, string $column = 'id', ?callable $filter = null) use ($input, &$errors): array {
            $values = is_array($input[$field] ?? null) ? array_values(array_unique(array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $input[$field]))) : [];
            $values = array_values(array_filter($values, fn (string $v): bool => $v !== ''));
            if ($values === []) {
                return [];
            }
            $query = DB::table($table)->whereIn($column, array_filter($values, ctype_digit(...)));
            if ($filter !== null) {
                $filter($query);
            }
            $found = $query->pluck($column)->map(strval(...))->all();
            if (count($found) !== count($values)) {
                $errors[$field] = __('shipping.choice_invalid');
            }

            return $found;
        };
        $data = [
            'calculation' => $calculation, 'provider' => $provider, 'account_id' => $accountId, 'is_active' => $bool('is_active', (bool) ($current->is_active ?? false)),
            'min_weight' => $min, 'max_weight' => $max, 'free_shipping_applies' => $bool('free_shipping_applies', (bool) ($current->free_shipping_applies ?? true)),
            'carrier_amount' => $carrierAmount, 'carrier_surcharge' => $surcharge, 'translations' => $translations, 'rates' => $rates,
            // Maximum shipment value: switched off or empty means no limit; zero is a real limit (only zero-value shipments).
            'value_limit_enabled' => $bool('value_limit_enabled', (bool) ($current->value_limit_enabled ?? false)),
            'max_shipment_value' => array_key_exists('max_shipment_value', $input) ? $amount($input['max_shipment_value'], 'max_shipment_value') : ($current?->max_shipment_value === null ? null : Decimal::trim($current->max_shipment_value)),
            'area_ids' => $calculation === 'carrier' ? $ids('area_ids', 'rate_areas') : [], 'tax_ids' => $ids('tax_ids', 'taxes'),
            'payment_method_ids' => $ids('payment_method_ids', 'payment_methods'), 'group_ids' => $ids('customer_group_ids', 'customer_groups'),
        ];
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    private function locked(string $id, int $version): object
    {
        $row = DB::table('shipping_methods')->where('id', $id)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['version' => __('shipping.stale')]);
        if ((int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('shipping.stale')]);
        }

        return $row;
    }

    private function authorize(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('shipping-methods.manage');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function audited(string $actor, callable $operation): mixed
    {
        return app(AuditLogger::class)->operation('shipping_method', $actor, $operation);
    }
}
