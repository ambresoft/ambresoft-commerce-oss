<?php

namespace App\Geography\Infrastructure\Import;

use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\DB;

final class RegionImportFields
{
    /** @return list<string> */
    public function columns(): array
    {
        return ['region_id', 'country_code', 'code', 'status', ...app(LocaleCatalog::class)->active()->map(fn ($locale): string => 'name.'.$locale->code)->all()];
    }

    /** @return array<string, string> */
    public function example(): array
    {
        $row = array_fill_keys($this->columns(), '');
        $row['country_code'] = DB::table('shipping_countries')->orderBy('country_code')->value('country_code') ?? 'GR';
        $row['code'] = 'EXAMPLE';
        $row['status'] = 'disabled';
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            $row['name.'.$locale->code] = $locale->code === 'el' ? 'Περιφέρεια παραδείγματος' : 'Example region';
        }

        return $row;
    }
}
