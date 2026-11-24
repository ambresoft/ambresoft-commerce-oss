<?php

namespace App\Website\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\MediaMetadata;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class MenuQueries
{
    private function name(string $table, string $owner, string $id): string
    {
        $codes = array_unique([app()->getLocale(), app(LocaleCatalog::class)->defaultCode(), 'en']);
        $ids = DB::table('locales')->whereIn('code', $codes)->pluck('id', 'code');
        $parts = [];
        foreach ($codes as $code) {
            if (isset($ids[$code])) {
                $locale = (int) $ids[$code];
                $parts[] = "(SELECT name FROM $table t WHERE t.$owner = $id AND t.locale_id = $locale)";
            }
        }

        return 'COALESCE('.implode(',', [...$parts, "''"]).')';
    }

    /** @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function listing(array $filters): array
    {
        $q = DB::table('website_menus')->select('website_menus.*')->selectRaw($this->name('website_menu_translations', 'menu_id', 'website_menus.id').' AS name')
            ->selectRaw('(SELECT COUNT(*) FROM website_menu_items i WHERE i.menu_id = website_menus.id) AS item_count');
        if (($filters['search'] ?? '') !== '') {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('website_menu_translations as t')->whereColumn('t.menu_id', 'website_menus.id')->where('t.name', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%'));
        }
        foreach (['status', 'placement', 'source', 'style'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $q->where($field, $filters[$field]);
            }
        }
        $page = $q->orderBy('website_menus.id')->paginate(25)->withQueryString();
        $page->setCollection($page->getCollection()->map(fn ($r) => $this->serialize($r)));

        return $page->toArray();
    }

    /** @return array<string,mixed> */
    private function serialize(object $r): array
    {
        $values = (array) $r;
        foreach (['id', 'menu_id', 'parent_id', 'category_id', 'image_media_id'] as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = $values[$field] === null ? null : (string) $values[$field];
            }
        }
        $values['version'] = (int) $r->lock_version;
        unset($values['lock_version'], $values['created_at'], $values['updated_at']);

        return $values;
    }

    /** @return array<string,mixed> */
    public function editor(string $menu, ?string $item = null): array
    {
        $table = $item === null ? 'website_menus' : 'website_menu_items';
        $translationTable = $item === null ? 'website_menu_translations' : 'website_menu_item_translations';
        $q = DB::table($table)->where('id', $item ?? $menu)->select($table.'.*')
            ->selectRaw($this->name($translationTable, $item === null ? 'menu_id' : 'item_id', $table.'.id').' AS name');
        if ($item !== null) {
            $q->where('menu_id', $menu);
        }
        $row = $q->firstOrFail();
        $translations = DB::table(($item === null ? 'website_menu_translations' : 'website_menu_item_translations').' as t')
            ->join('locales', 'locales.id', '=', 'locale_id')->where($item === null ? 'menu_id' : 'item_id', $item ?? $menu)
            ->get(['locales.code', 't.name', ...($item === null ? [] : ['t.description', 't.image_alt'])])->mapWithKeys(fn ($t) => [$t->code => array_diff_key((array) $t, ['code' => true])]);
        $data = [...$this->serialize($row), 'translations' => (object) $translations->all()];
        if ($item === null) {
            $data['item_count'] = DB::table('website_menu_items')->where('menu_id', $menu)->count();
        } else {
            $data['imageUrl'] = $row->image_media_id === null ? null : '/admin/menus/'.$menu.'/items/'.$item.'/image';
            $data['imageMeta'] = app(MediaMetadata::class)->one($data['image_media_id']);
        }

        return $data;
    }

    /** @return array<string,mixed> */
    public function branch(string $menu, ?string $parent): array
    {
        $q = DB::table('website_menu_items as i')->where('i.menu_id', $menu)->where('i.parent_id', $parent)
            ->select('i.*')->selectRaw($this->name('website_menu_item_translations', 'item_id', 'i.id').' AS name')
            ->selectRaw('(SELECT COUNT(*) FROM website_menu_items c WHERE c.parent_id = i.id) AS children_count');
        $page = $q->orderBy('i.position')->orderBy('i.id')->paginate(40)->withQueryString();
        $page->setCollection($page->getCollection()->map(fn ($r) => $this->serialize($r)));

        return $page->toArray();
    }

    /** @return list<array{id:string,parent_id:?string,name:string,generate_children:bool}> */
    public function parents(string $menu, ?string $item, string $search): array
    {
        $q = DB::table('website_menu_items as i')->where('i.menu_id', $menu)->where('i.generate_children', false);
        if (DB::table('website_menus')->where('id', $menu)->value('style') === 'link_groups') {
            // Only top-level items can have children, and an item that has children stays top-level.
            $q->whereNull('i.parent_id');
            if ($item !== null && DB::table('website_menu_items')->where('parent_id', $item)->exists()) {
                return [];
            }
        }
        if ($item !== null) {
            $q->whereNotIn('i.id', app(MenuAdministration::class)->descendants($item));
        }
        $q->select(['i.id', 'i.parent_id', 'i.generate_children'])->selectRaw($this->name('website_menu_item_translations', 'item_id', 'i.id').' AS name');
        if ($search !== '') {
            $q->whereRaw($this->name('website_menu_item_translations', 'item_id', 'i.id').' ILIKE ?', ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%']);
        }

        return $q->orderBy('i.position')->orderBy('i.id')->limit(50)->get()->map(fn ($r) => ['id' => (string) $r->id, 'parent_id' => $r->parent_id === null ? null : (string) $r->parent_id, 'name' => $r->name, 'generate_children' => false])->all();
    }

    private function categoriesQuery(): Builder
    {
        return DB::table('categories as c')->whereNull('c.system_key')->select(['c.id', 'c.parent_id', 'c.status', 'c.position'])
            ->selectRaw($this->name('category_translations', 'category_id', 'c.id').' AS name');
    }

    /** @return list<array<string,mixed>> */
    public function categories(string $search, ?string $selected): array
    {
        $q = $this->categoriesQuery();
        if ($search !== '') {
            $q->whereRaw($this->name('category_translations', 'category_id', 'c.id').' ILIKE ?', ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%']);
        }
        $rows = $q->orderBy('c.id')->limit(50)->get();
        if ($selected && ! $rows->contains('id', $selected)) {
            $selectedRow = $this->categoriesQuery()->where('c.id', $selected)->first();
            if ($selectedRow) {
                $rows->push($selectedRow);
            }
        }

        return $rows->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->name, 'status' => $r->status])->all();
    }

    /** Active category preview is live and excludes branches with disabled ancestors.
     * @return array<string,mixed>
     */
    public function categoryBranch(?string $parent): array
    {
        $q = $this->categoriesQuery()->where('c.parent_id', $parent)->where('c.status', 'active')
            ->whereRaw("NOT EXISTS (WITH RECURSIVE path AS (SELECT id,parent_id,status FROM categories WHERE id = c.parent_id UNION ALL SELECT a.id,a.parent_id,a.status FROM categories a JOIN path p ON a.id = p.parent_id) SELECT 1 FROM path WHERE status <> 'active')")
            ->selectRaw("(SELECT COUNT(*) FROM categories child WHERE child.parent_id = c.id AND child.status = 'active' AND child.system_key IS NULL) AS children_count");
        $page = $q->orderBy('c.position')->orderBy('c.id')->paginate(40)->withQueryString();
        $page->setCollection($page->getCollection()->map(fn ($r) => ['id' => (string) $r->id, 'parent_id' => $r->parent_id === null ? null : (string) $r->parent_id, 'name' => $r->name, 'children_count' => (int) $r->children_count, 'status' => $r->status, 'position' => (int) $r->position]));

        return $page->toArray();
    }
}
