<?php

namespace App\Pricing\Infrastructure\Persistence;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Marketing\Infrastructure\PromotionCurrency;
use App\Marketing\Infrastructure\StickerCurrency;
use App\Pricing\Application\Command\SaveCurrencyData;
use App\Pricing\Application\Contract\CurrencyAdministration;
use App\Pricing\Application\Service\CurrencyDeletionGuards;
use App\Pricing\Domain\Exception\InvalidCurrencyChange;
use App\Pricing\Domain\Service\PrimaryCurrencyChange;
use App\Pricing\Infrastructure\CurrencyAudit;
use App\Pricing\Infrastructure\CurrencyInput;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class EloquentCurrencyAdministration implements CurrencyAdministration
{
    public function __construct(private CurrencyInput $input, private CurrencyAudit $audit, private EloquentCurrencyRepository $repository, private CurrencyMapper $mapper, private PrimaryCurrencyChange $change, private CurrencyDeletionGuards $guards) {}

    private function authorize(string $actor, string $ability): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize($ability);
    }

    private function lock(): void
    {
        // The shared Catalog lock: product price writes/validation and currency precision or primary changes serialize.
        DB::select('SELECT pg_advisory_xact_lock(2142513)');
    }

    private function record(string $id, int $version): CurrencyRecord
    {
        $currency = CurrencyRecord::lockForUpdate()->findOrFail($id);
        if ($currency->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('currencies.stale')]);
        }

        return $currency;
    }

    private function invalidateAfterCommit(): void
    {
        DB::afterCommit(fn () => $this->repository->forget());
    }

    /** @return array<string, string|int> */
    private function format(SaveCurrencyData $data): array
    {
        return ['name' => $data->name, 'symbol' => $data->symbol, 'symbol_position' => $data->symbolPosition, 'thousands_separator' => $data->thousandsSeparator, 'decimal_separator' => $data->decimalSeparator, 'decimals' => $data->decimals];
    }

    /** @return array<string, mixed> */
    private function snapshot(CurrencyRecord $currency): array
    {
        return array_diff_key($this->mapper->row($currency), array_flip(['id', 'lock_version']));
    }

    public function create(string $actor, SaveCurrencyData $data): string
    {
        return app(AuditLogger::class)->operation('currency', $actor, function () use ($actor, $data): string {
            try {
                return DB::transaction(function () use ($actor, $data): string {
                    $this->lock();
                    $this->authorize($actor, 'currencies.create');
                    $this->input->assertValid($data, true);
                    if (CurrencyRecord::where('code', $data->code)->exists()) {
                        throw ValidationException::withMessages(['code' => __('currencies.code_taken')]);
                    }
                    // New currencies are always secondary; primary changes use the converting operation.
                    $currency = CurrencyRecord::create([...$this->format($data), 'code' => $data->code, 'rate' => $data->rate, 'status' => $data->status, 'is_primary' => false]);
                    $this->audit->record((string) $currency->id, $currency->code, $actor, 'created', ['values' => $this->snapshot($currency->refresh())]);
                    $this->invalidateAfterCommit();

                    return (string) $currency->id;
                });
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
                throw ValidationException::withMessages(['code' => __('currencies.code_taken')]);
            }

        });
    }

    public function update(string $actor, string $id, int $version, SaveCurrencyData $data): void
    {
        app(AuditLogger::class)->operation('currency', $actor, function () use ($actor, $id, $version, $data): void {
            DB::transaction(function () use ($actor, $id, $version, $data): void {
                $this->lock();
                $this->authorize($actor, 'currencies.view');
                $currency = $this->record($id, $version);
                if ($data->code !== $currency->code) {
                    throw ValidationException::withMessages(['code' => __('currencies.code_immutable')]);
                }
                $this->input->assertValid($data, false);
                $changes = [];
                foreach ($this->format($data) as $field => $value) {
                    if ((string) $currency->{$field} !== (string) $value) {
                        $changes[$field] = ['from' => $currency->{$field}, 'to' => $value];
                    }
                }
                $oldRate = Decimal::trim((string) $currency->rate);
                $rateChanged = Decimal::compare($data->rate, $oldRate) !== 0;
                $statusChanged = $data->status !== $currency->status;
                if ($changes === [] && ! $rateChanged && ! $statusChanged) {
                    return;
                }
                if ($changes !== []) {
                    $this->authorize($actor, 'currencies.update');
                }
                if ($rateChanged) {
                    $this->authorize($actor, 'currencies.rates');
                    if ($currency->is_primary) {
                        throw ValidationException::withMessages(['rate' => __('currencies.primary_rate_fixed')]);
                    }
                }
                if ($statusChanged) {
                    $this->authorize($actor, 'currencies.status');
                    if ($currency->is_primary) {
                        throw ValidationException::withMessages(['status' => __('currencies.primary_cannot_disable')]);
                    }
                }
                if ($currency->is_primary && $data->decimals < $currency->decimals) {
                    // Stored prices are primary-currency amounts; never silently round them by lowering precision.
                    $count = DB::table('products')->whereRaw('(price <> round(price, ?) OR list_price <> round(list_price, ?) OR shipping_cost <> round(shipping_cost, ?))', [$data->decimals, $data->decimals, $data->decimals])->count();
                    if ($count > 0) {
                        throw ValidationException::withMessages(['decimals' => __('currencies.precision_in_use', ['count' => $count, 'decimals' => $data->decimals])]);
                    }
                    app(PromotionCurrency::class)->assertPrecision($data->decimals);
                    app(StickerCurrency::class)->assertPrecision($data->decimals);
                    // Fixed tax amounts are primary-currency amounts too.
                    if (DB::table('product_sales')->where('kind', 'fixed')->whereRaw('amount <> round(amount, ?)', [$data->decimals])->exists()) {
                        throw ValidationException::withMessages(['decimals' => __('sales.precision_in_use')]);
                    }
                    if (DB::table('coupons')->whereRaw('(minimum <> round(minimum, ?) OR (type = ? AND amount <> round(amount, ?)))', [$data->decimals, 'fixed', $data->decimals])->exists()) {
                        throw ValidationException::withMessages(['decimals' => __('coupons.precision_in_use')]);
                    }
                    $fixed = DB::table('tax_rates')->whereNotNull('fixed_amount')->whereRaw('fixed_amount <> round(fixed_amount, ?)', [$data->decimals])->count();
                    if ($fixed > 0) {
                        throw ValidationException::withMessages(['decimals' => __('currencies.precision_in_use_taxes', ['count' => $fixed, 'decimals' => $data->decimals])]);
                    }
                    // Shipping rates and carrier surcharges are primary-currency amounts as well.
                    $shipping = DB::table('shipping_method_rates')->whereRaw('(base <> round(base, ?) OR per_unit <> round(per_unit, ?))', [$data->decimals, $data->decimals])->distinct()->count('method_id')
                        + DB::table('shipping_methods')->whereRaw('(carrier_surcharge <> round(carrier_surcharge, ?) OR max_shipment_value <> round(max_shipment_value, ?))', [$data->decimals, $data->decimals])->count();
                    if ($shipping > 0) {
                        throw ValidationException::withMessages(['decimals' => __('shipping.precision_in_use', ['decimals' => $data->decimals])]);
                    }
                }
                $oldStatus = $currency->status;
                $currency->fill([...$this->format($data), 'rate' => $data->rate, 'status' => $data->status]);
                $currency->lock_version++;
                $currency->save();
                if ($changes !== []) {
                    $this->audit->record($id, $currency->code, $actor, 'updated', ['changes' => $changes]);
                }
                if ($rateChanged) {
                    $this->audit->record($id, $currency->code, $actor, 'rate_changed', ['from' => $oldRate, 'to' => $data->rate, 'relative_to' => $this->repository->currentPrimary()->code]);
                }
                if ($statusChanged) {
                    $this->audit->record($id, $currency->code, $actor, 'status_changed', ['from' => $oldStatus, 'to' => $data->status]);
                }
                $this->invalidateAfterCommit();
            });

        });
    }

    public function makePrimary(string $actor, string $id, int $version, string $confirmation): array
    {
        return app(AuditLogger::class)->operation('currency', $actor, function () use ($actor, $id, $version, $confirmation): array {
            $this->authorize($actor, 'currencies.primary');
            $code = CurrencyRecord::findOrFail($id)->code;
            $converting = false;
            try {
                return DB::transaction(function () use ($actor, $id, $version, $confirmation, &$converting): array {
                    $this->lock();
                    $this->authorize($actor, 'currencies.primary');
                    $records = CurrencyRecord::orderBy('id')->lockForUpdate()->get();
                    $target = $records->firstWhere('id', (int) $id) ?? throw ValidationException::withMessages(['version' => __('currencies.stale')]);
                    if ($target->lock_version !== $version) {
                        throw ValidationException::withMessages(['version' => __('currencies.stale')]);
                    }
                    if (! hash_equals($target->code, $confirmation)) {
                        throw ValidationException::withMessages(['confirmation' => __('currencies.confirm_code', ['code' => $target->code])]);
                    }
                    if ($target->is_primary || $target->status !== 'active') {
                        throw ValidationException::withMessages(['currency' => __($target->is_primary ? 'currencies.already_primary' : 'currencies.primary_requires_active', ['code' => $target->code])]);
                    }
                    // Preconditions passed: from here, failures are audited conversion failures.
                    $converting = true;
                    $old = $records->firstWhere('is_primary', true);
                    $currencies = $records->map(fn (CurrencyRecord $r) => $this->mapper->toDomain($this->mapper->row($r)))->all();
                    $domainTarget = $this->mapper->toDomain($this->mapper->row($target));
                    try {
                        if (DB::table('coupons')->where('type', 'fixed')->where('free_shipping', false)->whereRaw('round(amount * ?::numeric, ?) = 0', [$domainTarget->rate, $domainTarget->decimals])->exists()) {
                            throw ValidationException::withMessages(['currency' => __('coupons.conversion_zero')]);
                        }
                        foreach (['minimum', 'amount'] as $field) {
                            $max = DB::table('coupons')->when($field === 'amount', fn ($q) => $q->where('type', 'fixed'))->max($field);
                            if ($max !== null) {
                                $this->change->convertPrice(Decimal::trim((string) $max), $domainTarget);
                            }
                        }
                        $saleMax = DB::table('product_sales')->where('kind', 'fixed')->max('amount');
                        if ($saleMax !== null) {
                            $this->change->convertPrice(Decimal::trim((string) $saleMax), $domainTarget);
                        }
                        app(PromotionCurrency::class)->validateConversion($domainTarget);
                        app(StickerCurrency::class)->validateConversion($domainTarget);
                        $rates = $this->change->rebasedRates($currencies, $domainTarget);
                        // Every stored primary-currency product amount must fit after conversion, history included.
                        foreach ([['products', 'price'], ['products', 'list_price'], ['products', 'shipping_cost'], ['product_price_history', 'price'], ['product_price_history', 'list_price']] as [$table, $column]) {
                            $max = DB::table($table)->max($column);
                            if ($max !== null) {
                                $this->change->convertPrice(Decimal::trim((string) $max), $domainTarget);
                            }
                        }
                        $maxFixed = DB::table('tax_rates')->max('fixed_amount');
                        if ($maxFixed !== null) {
                            $this->change->convertPrice(Decimal::trim((string) $maxFixed), $domainTarget);
                        }
                        foreach ([['shipping_method_rates', 'base'], ['shipping_method_rates', 'per_unit'], ['shipping_methods', 'carrier_surcharge'], ['shipping_methods', 'max_shipment_value'], ['payment_methods', 'max_subtotal']] as [$table, $column]) {
                            $max = DB::table($table)->max($column);
                            if ($max !== null) {
                                $this->change->convertPrice(Decimal::trim((string) $max), $domainTarget);
                            }
                        }
                    } catch (InvalidCurrencyChange $e) {
                        throw ValidationException::withMessages(['currency' => __($e->getMessage(), $e->parameters)]);
                    }
                    $rateChanges = [];
                    foreach ($records as $record) {
                        $rateChanges[$record->code] = ['from' => Decimal::trim((string) $record->rate), 'to' => $rates[$record->code]];
                    }
                    // Unset the old primary first: the partial unique index allows at most one at any moment.
                    foreach ($records->sortByDesc('is_primary') as $record) {
                        if ($record->id !== $target->id) {
                            $record->update(['is_primary' => false, 'rate' => $rates[$record->code], 'lock_version' => $record->lock_version + 1]);
                        }
                    }
                    $target->update(['is_primary' => true, 'rate' => '1', 'status' => 'active', 'lock_version' => $target->lock_version + 1]);
                    $priced = DB::table('products')->whereNotNull('price')->count();
                    // One statement re-denominates every stored price (including Trash) from the unrounded stored amount.
                    // Every product version advances, so editors opened under the old currency cannot save stale amounts.
                    // List price and shipping cost are primary-currency amounts too; NULL stays NULL.
                    DB::update('UPDATE products SET price = round(price * ?::numeric, ?), list_price = round(list_price * ?::numeric, ?), shipping_cost = round(shipping_cost * ?::numeric, ?), lock_version = lock_version + 1, updated_at = CASE WHEN price IS NULL AND list_price IS NULL AND shipping_cost IS NULL THEN updated_at ELSE now() END', [$domainTarget->rate, $domainTarget->decimals, $domainTarget->rate, $domainTarget->decimals, $domainTarget->rate, $domainTarget->decimals]);
                    app(PromotionCurrency::class)->convert($domainTarget);
                    app(StickerCurrency::class)->convert($domainTarget);
                    DB::update("UPDATE coupons SET minimum = round(minimum * ?::numeric, ?), amount = CASE WHEN type = 'fixed' THEN round(amount * ?::numeric, ?) ELSE amount END, lock_version = lock_version + 1, updated_at = now()", [$domainTarget->rate, $domainTarget->decimals, $domainTarget->rate, $domainTarget->decimals]);
                    DB::update("UPDATE product_sales SET amount = round(amount * ?::numeric, ?), updated_at = now() WHERE kind = 'fixed'", [$domainTarget->rate, $domainTarget->decimals]);
                    // Price history is re-denominated, never recorded as a price change, so the chart stays in one currency.
                    DB::update('UPDATE product_price_history SET price = round(price * ?::numeric, ?), list_price = round(list_price * ?::numeric, ?)', [$domainTarget->rate, $domainTarget->decimals, $domainTarget->rate, $domainTarget->decimals]);
                    // Fixed tax amounts are monetary values in the primary currency and are re-denominated with the prices;
                    // their taxes advance a version so an editor opened under the old currency cannot save stale amounts.
                    $taxAmounts = DB::table('tax_rates')->whereNotNull('fixed_amount')->count();
                    DB::update('UPDATE taxes SET lock_version = lock_version + 1 WHERE id IN (SELECT tax_id FROM tax_rates WHERE fixed_amount IS NOT NULL)');
                    DB::update('UPDATE tax_rates SET fixed_amount = round(fixed_amount * ?::numeric, ?), updated_at = now() WHERE fixed_amount IS NOT NULL', [$domainTarget->rate, $domainTarget->decimals]);
                    // Shipping method amounts likewise; methods are updated first so a concurrent method save finishes before its rates convert.
                    DB::update('UPDATE shipping_methods SET carrier_surcharge = round(carrier_surcharge * ?::numeric, ?), max_shipment_value = round(max_shipment_value * ?::numeric, ?), lock_version = lock_version + 1', [$domainTarget->rate, $domainTarget->decimals, $domainTarget->rate, $domainTarget->decimals]);
                    // Payment limits (cash on delivery above an amount) move with the currency; a limit never rounds away to no limit.
                    DB::update('UPDATE payment_methods SET max_subtotal = GREATEST(round(max_subtotal * ?::numeric, ?), power(10::numeric, ?::int)), lock_version = lock_version + 1 WHERE max_subtotal IS NOT NULL', [$domainTarget->rate, $domainTarget->decimals, -$domainTarget->decimals]);
                    DB::update('UPDATE shipping_method_rates SET base = round(base * ?::numeric, ?), per_unit = round(per_unit * ?::numeric, ?)', [$domainTarget->rate, $domainTarget->decimals, $domainTarget->rate, $domainTarget->decimals]);
                    $this->audit->record($id, $target->code, $actor, 'primary_changed', [
                        'from' => $old->code, 'to' => $target->code, 'rate' => $domainTarget->rate, 'decimals' => $domainTarget->decimals, 'rounding' => 'half_away_from_zero', 'products_converted' => $priced, 'tax_amounts_converted' => $taxAmounts,
                        'rates' => $rateChanges,
                    ]);
                    $this->invalidateAfterCommit();

                    return ['products' => $priced, 'rates' => $rates];
                });
            } catch (Throwable $e) {
                if ($converting) {
                    try {
                        // Recorded after rollback so the failure evidence is not discarded with the transaction.
                        $this->audit->record($id, $code, $actor, 'primary_change_failed', ['reason' => $e instanceof ValidationException ? array_merge(...array_values($e->errors())) : ['error' => $e instanceof QueryException ? $e->getCode() : $e::class]]);
                    } catch (Throwable $auditFailure) {
                        report($auditFailure);
                    }
                }
                throw $e;
            }

        });
    }

    public function delete(string $actor, string $id, int $version): void
    {
        app(AuditLogger::class)->operation('currency', $actor, function () use ($actor, $id, $version): void {
            DB::transaction(function () use ($actor, $id, $version): void {
                $this->lock();
                $this->authorize($actor, 'currencies.delete');
                $currency = $this->record($id, $version);
                if ($currency->is_primary) {
                    throw ValidationException::withMessages(['currency' => __('currencies.primary_cannot_delete')]);
                }
                if (($reason = $this->guards->reason($currency->code)) !== null) {
                    throw ValidationException::withMessages(['currency' => __($reason)]);
                }
                $this->audit->record($id, $currency->code, $actor, 'deleted', ['values' => $this->snapshot($currency)]);
                $currency->delete();
                $this->invalidateAfterCommit();
            });

        });
    }
}
