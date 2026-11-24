<?php

namespace App\Catalog\Infrastructure\Persistence;

use App\Catalog\Domain\Model\Product;
use App\Catalog\Infrastructure\ProductInput;
use App\Shared\Domain\Model\LocalizedContent;

final class ProductMapper
{
    /** @param array<int, string> $localeCodes Preloaded reference data, no per-row queries. */
    public function toDomain(ProductRecord $record, array $localeCodes): Product
    {
        $translations = [];
        foreach ($record->translations as $row) {
            if (isset($localeCodes[$row->locale_id])) {
                $code = $localeCodes[$row->locale_id];
                $translations[$code] = new LocalizedContent($code, $row->only(ProductInput::TEXT));
            }
        }

        return new Product((string) $record->id, $translations);
    }
}
