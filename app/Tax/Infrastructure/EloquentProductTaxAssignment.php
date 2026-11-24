<?php

namespace App\Tax\Infrastructure;

use App\Tax\Application\Contract\ProductTaxAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EloquentProductTaxAssignment implements ProductTaxAssignment
{
    public function assigned(string $productId): array
    {
        return DB::table('product_taxes')->where('product_id', $productId)->orderBy('tax_id')->pluck('tax_id')->map(strval(...))->all();
    }

    public function sync(string $productId, array $taxIds): array
    {
        $requested = array_values(array_unique(array_map(strval(...), $taxIds)));
        $current = $this->assigned($productId);
        $known = DB::table('taxes')->whereIn('id', $requested)->pluck('status', 'id')->mapWithKeys(fn ($status, $id): array => [(string) $id => $status])->all();
        foreach ($requested as $id) {
            // A disabled tax may stay assigned but cannot be newly assigned.
            if (! isset($known[$id]) || ($known[$id] !== 'active' && ! in_array($id, $current, true))) {
                throw ValidationException::withMessages(['tax_ids' => __('taxes.product_tax_invalid')]);
            }
        }
        $add = array_values(array_diff($requested, $current));
        $remove = array_values(array_diff($current, $requested));
        if ($remove !== []) {
            DB::table('product_taxes')->where('product_id', $productId)->whereIn('tax_id', $remove)->delete();
        }
        if ($add !== []) {
            DB::table('product_taxes')->insertOrIgnore(array_map(fn (string $id): array => ['product_id' => $productId, 'tax_id' => $id], $add));
        }

        return ['added' => $add, 'removed' => $remove];
    }

    public function copy(string $fromProductId, string $toProductId): void
    {
        DB::insert('INSERT INTO product_taxes (product_id, tax_id) SELECT ?, tax_id FROM product_taxes WHERE product_id = ? ON CONFLICT DO NOTHING', [$toProductId, $fromProductId]);
    }
}
