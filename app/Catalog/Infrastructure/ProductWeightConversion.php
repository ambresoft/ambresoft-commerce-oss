<?php

namespace App\Catalog\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Called inside the settings transaction under the shared Catalog lock. */
final class ProductWeightConversion
{
    public function convert(string $oldGrams, string $newGrams, string $actor): void
    {
        $expression = 'round(weight * ?::numeric / ?::numeric, 6)';
        if (DB::table('products')->whereNotNull('weight')->whereRaw("($expression >= 1000000000 OR (weight > 0 AND $expression = 0))", [$oldGrams, $newGrams, $oldGrams, $newGrams])->exists()) {
            throw ValidationException::withMessages(['grams_per_weight_unit' => __('settings.weight_conversion_invalid')]);
        }
        // Include trash so restoration keeps the same physical weight. Stale editors must reload the converted value.
        DB::update("UPDATE products SET weight = $expression, lock_version = lock_version + 1, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE weight IS NOT NULL", [$oldGrams, $newGrams, $actor]);
    }
}
