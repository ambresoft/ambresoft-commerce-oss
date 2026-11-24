<?php

namespace App\Reporting\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\DB;

/** The category tree as reports need it: descendants of a category and display paths, from the current assignment. */
final class ReportCategories
{
    /** @var array<int, list<int>> */
    private array $descendants = [];

    /** @var array<int, array{parent: ?int, name: string, system: bool}>|null */
    private ?array $all = null;

    /**
     * The category and all categories below it (a missing category yields only itself, so a filter simply matches nothing).
     *
     * @return list<int>
     */
    public function descendants(int $id): array
    {
        if (isset($this->descendants[$id])) {
            return $this->descendants[$id];
        }
        $rows = DB::select('WITH RECURSIVE tree(id) AS (SELECT ?::bigint UNION ALL SELECT c.id FROM categories c JOIN tree t ON c.parent_id = t.id) SELECT id FROM tree', [$id]);

        return $this->descendants[$id] = array_map(fn (object $r): int => (int) $r->id, $rows);
    }

    /**
     * Every category with its parent and localized name (current language, then the default), for paths and listing.
     *
     * @return array<int, array{parent: ?int, name: string, system: bool}>
     */
    public function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }
        $catalog = app(LocaleCatalog::class);
        $current = $catalog->active()->firstWhere('code', app()->getLocale())->id ?? 0;
        $default = $catalog->active()->firstWhere('code', $catalog->defaultCode())->id ?? 0;
        $names = DB::table('category_translations')->whereIn('locale_id', [$current, $default])->get(['category_id', 'locale_id', 'name'])->groupBy('category_id');
        $result = [];
        foreach (DB::table('categories')->get(['id', 'parent_id', 'system_key']) as $c) {
            $byLocale = collect($names[$c->id] ?? [])->pluck('name', 'locale_id');
            $name = trim((string) ($byLocale[$current] ?? '')) !== '' ? $byLocale[$current] : (string) ($byLocale[$default] ?? '');
            $result[(int) $c->id] = ['parent' => $c->parent_id === null ? null : (int) $c->parent_id, 'name' => $name === '' ? '#'.$c->id : $name, 'system' => $c->system_key !== null];
        }

        return $this->all = $result;
    }

    /** "Parent › Child" display path of a category. */
    public function path(int $id): string
    {
        $all = $this->all();
        $parts = [];
        for ($guard = 0; $id !== null && isset($all[$id]) && $guard < 30; $guard++) {
            array_unshift($parts, $all[$id]['name']);
            $id = $all[$id]['parent'];
        }

        return implode(' › ', $parts);
    }
}
