<?php

namespace App\Marketing\Infrastructure;

use App\Pricing\Domain\Model\Currency;
use App\Pricing\Domain\Service\PrimaryCurrencyChange;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PromotionCurrency
{
    public function assertPrecision(int $scale): void
    {
        if (DB::table('promotions')->where('benefit', 'fixed')->whereRaw('amount <> round(amount, ?)', [$scale])->exists()
            || DB::table('promotion_conditions')->whereRaw('(minimum <> round(minimum, ?) OR maximum <> round(maximum, ?))', [$scale, $scale])->exists()) {
            throw ValidationException::withMessages(['decimals' => __('promotions.precision_in_use')]);
        }
    }

    public function validateConversion(Currency $target): void
    {
        if (DB::table('promotions')->where('benefit', 'fixed')->whereRaw('round(amount * ?::numeric, ?) = 0', [$target->rate, $target->decimals])->exists()) {
            throw ValidationException::withMessages(['currency' => __('promotions.conversion_zero')]);
        }
        foreach ([['promotions', 'amount'], ['promotion_conditions', 'minimum'], ['promotion_conditions', 'maximum']] as [$table, $column]) {
            $q = DB::table($table);
            if ($table === 'promotions') {
                $q->where('benefit', 'fixed');
            }
            $maximum = $q->max($column);
            if ($maximum !== null) {
                app(PrimaryCurrencyChange::class)->convertPrice(Decimal::trim((string) $maximum), $target);
            }
        }
    }

    public function convert(Currency $target): void
    {
        DB::update("UPDATE promotions SET amount = CASE WHEN benefit = 'fixed' THEN round(amount * ?::numeric, ?) ELSE amount END, lock_version = lock_version + 1, updated_at = now()", [$target->rate, $target->decimals]);
        DB::update('UPDATE promotion_conditions SET minimum = round(minimum * ?::numeric, ?), maximum = round(maximum * ?::numeric, ?)', [$target->rate, $target->decimals, $target->rate, $target->decimals]);
    }
}
