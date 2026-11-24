<?php

namespace App\Tax\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\DB;

/** Resolves merchant names from locale-linked translation rows: current language, then the default language, then any. */
final class TaxNames
{
    /**
     * @param  list<int|string>  $ids
     * @return array<string, string> Keyed by owner ID.
     */
    public function for(string $table, string $owner, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $active = app(LocaleCatalog::class)->active();
        $current = $active->firstWhere('code', app()->getLocale())?->id;
        $default = $active->firstWhere('is_default', true)?->id;
        $names = [];
        foreach (DB::table($table)->whereIn($owner, $ids)->orderBy('locale_id')->get() as $row) {
            $rank = $row->locale_id === $current ? 0 : ($row->locale_id === $default ? 1 : 2);
            $key = (string) $row->{$owner};
            if (! isset($names[$key]) || $rank < $names[$key][0]) {
                $names[$key] = [$rank, (string) $row->name];
            }
        }

        return array_map(fn (array $entry): string => $entry[1], $names);
    }
}
