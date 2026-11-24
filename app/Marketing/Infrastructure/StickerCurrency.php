<?php

namespace App\Marketing\Infrastructure;

use App\Pricing\Domain\Model\Currency;
use App\Pricing\Domain\Service\PrimaryCurrencyChange;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StickerCurrency
{
    public function assertPrecision(int $scale): void
    {
        if (DB::table('sticker_conditions')->whereIn('type', ['price', 'discount_amount'])->whereRaw('(minimum <> round(minimum, ?) OR maximum <> round(maximum, ?))', [$scale, $scale])->exists()) {
            throw ValidationException::withMessages(['decimals' => __('stickers.precision_in_use')]);
        }
    }

    public function validateConversion(Currency $target): void
    {
        foreach (['minimum', 'maximum'] as $column) {
            $maximum = DB::table('sticker_conditions')->whereIn('type', ['price', 'discount_amount'])->max($column);
            if ($maximum !== null) {
                app(PrimaryCurrencyChange::class)->convertPrice(Decimal::trim($maximum), $target);
            }
        }
    }

    public function convert(Currency $target): void
    {
        DB::table('stickers')->whereIn('id', DB::table('sticker_conditions')->whereIn('type', ['price', 'discount_amount'])->select('sticker_id'))->update(['lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
        DB::update("UPDATE sticker_conditions SET minimum = round(minimum * ?::numeric, ?), maximum = round(maximum * ?::numeric, ?) WHERE type IN ('price','discount_amount')", [$target->rate, $target->decimals, $target->rate, $target->decimals]);
    }
}
