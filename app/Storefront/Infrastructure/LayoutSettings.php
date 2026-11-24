<?php

namespace App\Storefront\Infrastructure;

use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Website\Infrastructure\PageQueries;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The page settings of every storefront layout (name, page title, meta description and keywords, custom head HTML),
 * merchant-added layouts and their link to a static page, and the resolution of those settings into what a rendered
 * page shows. A page that owns its SEO (a static page, later a product) passes it as an override and wins.
 */
final class LayoutSettings
{
    /** Layouts whose pages bring their own title; the layout's page title follows it as a suffix. */
    private const SUFFIXED = ['products', 'category_landing', 'category', 'catalog_landing', 'new_products', 'bestsellers', 'offers', 'blog', 'blog_post'];

    private const TEXTS = ['name', 'page_title', 'meta_description', 'meta_keywords'];

    private const NO_CONTROLS = 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/';

    /** @return list<array{key: string, label: string, translate: bool, page_id: string|null, custom: bool}> Tabs in display order. */
    public function tabs(): array
    {
        $registry = app(StorefrontRegistry::class);
        $tabs = [];
        foreach ($registry->pages() as $key => $definition) {
            $tabs[$key] = ['key' => $key, 'label' => $definition['label'], 'translate' => true, 'page_id' => null, 'custom' => false];
        }
        $names = $this->names();
        foreach (DB::table('storefront_layouts')->where('page_type', 'like', StorefrontRegistry::CUSTOM_PREFIX.'%')->orderBy('position')->orderBy('id')->get(['id', 'page_type', 'page_id']) as $row) {
            $tabs[$row->page_type] = ['key' => $row->page_type, 'label' => $names[$row->id] ?? '#'.$row->id, 'translate' => false, 'page_id' => $row->page_id === null ? null : (string) $row->page_id, 'custom' => true];
        }

        return array_values($tabs);
    }

    /** @return array<string, mixed> */
    public function settings(string $page): array
    {
        $row = DB::table('storefront_layouts')->where('page_type', $page)->first() ?? abort(404);
        $codes = DB::table('locales')->pluck('code', 'id');
        $translations = [];
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            $translations[$locale->code] = array_fill_keys(self::TEXTS, '');
        }
        foreach (DB::table('storefront_layout_translations')->where('layout_id', $row->id)->get() as $t) {
            if (isset($translations[$codes[$t->locale_id]])) {
                $translations[$codes[$t->locale_id]] = ['name' => $t->name, 'page_title' => $t->page_title, 'meta_description' => $t->meta_description, 'meta_keywords' => $t->meta_keywords];
            }
        }

        return ['version' => (int) $row->settings_version, 'custom' => app(StorefrontRegistry::class)->isCustom($page), 'page_id' => $row->page_id === null ? null : (string) $row->page_id,
            'position' => (int) $row->position, 'indexable' => (bool) $row->indexable, 'custom_html' => $row->custom_html, 'translations' => $translations];
    }

    /** Static pages a layout can be linked to, each with the layout that already uses it.
     * @return list<array{id: string, name: string, depth: int, layout: string|null}> */
    public function pageOptions(): array
    {
        $linked = DB::table('storefront_layouts')->whereNotNull('page_id')->pluck('page_type', 'page_id');

        return array_map(fn (array $p): array => ['id' => $p['id'], 'name' => $p['name'], 'depth' => $p['depth'], 'layout' => $linked[$p['id']] ?? null], app(PageQueries::class)->tree());
    }

    /** @param array<string, mixed> $input
     * @return string The new layout's key. */
    public function create(string $actor, array $input): string
    {
        $this->authorize($actor);

        return app(AuditLogger::class)->operation('storefront_layout', $actor, fn () => DB::transaction(function () use ($input): string {
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            $data = $this->validated($input, true, null);
            $key = StorefrontRegistry::CUSTOM_PREFIX.Str::lower(Str::random(10));
            try {
                $id = DB::table('storefront_layouts')->insertGetId(['page_type' => $key, 'page_id' => $data['page_id'], 'position' => $data['position'], 'custom_html' => $data['custom_html'], 'indexable' => $data['indexable'], 'created_at' => now(), 'updated_at' => now()]);
            } catch (UniqueConstraintViolationException) {
                $this->invalid('page_id', 'layouts.page_taken');
            }
            $this->writeTexts($id, $data['translations']);
            $this->syncMainContent($id, $data['page_id'] !== null);

            return $key;
        }));
    }

    /** @param array<string, mixed> $input */
    public function save(string $actor, string $page, array $input): void
    {
        $this->authorize($actor);
        app(AuditLogger::class)->operation('storefront_layout', $actor, fn () => DB::transaction(function () use ($page, $input): void {
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            $row = DB::table('storefront_layouts')->where('page_type', $page)->lockForUpdate()->first() ?? abort(404);
            $custom = app(StorefrontRegistry::class)->isCustom($page);
            Validator::make($input, ['version' => ['required', 'integer']])->validate();
            if ((int) $input['version'] !== (int) $row->settings_version) {
                $this->invalid('version', 'layouts.settings_stale');
            }
            $data = $this->validated($input, $custom, (int) $row->id);
            $values = ['custom_html' => $data['custom_html'], 'indexable' => $data['indexable'], 'settings_version' => $row->settings_version + 1, 'updated_at' => now()];
            if ($custom) {
                $values += ['page_id' => $data['page_id'], 'position' => $data['position']];
            }
            try {
                DB::table('storefront_layouts')->where('id', $row->id)->update($values);
            } catch (UniqueConstraintViolationException) {
                $this->invalid('page_id', 'layouts.page_taken');
            }
            $this->writeTexts((int) $row->id, $data['translations']);
            if ($custom) {
                $this->syncMainContent((int) $row->id, $data['page_id'] !== null);
            }
            $keywords = filter_var($input['copy_meta_keywords'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $html = filter_var($input['copy_custom_html'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($keywords) {
                foreach (DB::table('storefront_layouts')->where('id', '<>', $row->id)->pluck('id') as $id) {
                    foreach ($data['translations'] as $localeId => $texts) {
                        DB::table('storefront_layout_translations')->updateOrInsert(['layout_id' => $id, 'locale_id' => $localeId], ['meta_keywords' => $texts['meta_keywords']]);
                    }
                }
            }
            if ($html) {
                DB::table('storefront_layouts')->where('id', '<>', $row->id)->update(['custom_html' => $data['custom_html']]);
            }
            if ($keywords || $html) {
                DB::table('storefront_layouts')->where('id', '<>', $row->id)->increment('settings_version');
            }
        }));
    }

    public function delete(string $actor, string $page, int $version): void
    {
        $this->authorize($actor);
        abort_unless(app(StorefrontRegistry::class)->isCustom($page), 404);
        app(AuditLogger::class)->operation('storefront_layout', $actor, fn () => DB::transaction(function () use ($page, $version): void {
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            $row = DB::table('storefront_layouts')->where('page_type', $page)->lockForUpdate()->first() ?? abort(404);
            if ((int) $row->settings_version !== $version) {
                $this->invalid('version', 'layouts.settings_stale');
            }
            // Grids, blocks and placements follow through their cascading keys.
            DB::table('storefront_layouts')->where('id', $row->id)->delete();
        }));
    }

    /**
     * The settings a rendered page uses. A non-empty override (the page's own SEO) wins over the layout, which wins
     * over the Default layout; the custom head HTML belongs to the layout alone (copy it to other layouts to share it).
     *
     * @param  array{title?: string|null, description?: string|null, keywords?: string|null, indexable?: bool|null}  $override
     * @return array{title: string, description: string, keywords: string, custom_html: string, indexable: bool}
     */
    public function seo(string $page, string $locale, array $override = []): array
    {
        $catalog = app(LocaleCatalog::class);
        $ids = array_values(array_unique(array_filter([$catalog->active()->firstWhere('code', $locale)?->id, $catalog->active()->firstWhere('is_default', true)?->id])));
        $layout = DB::table('storefront_layouts')->where('page_type', $page)->first(['id', 'custom_html', 'indexable']);
        $default = DB::table('storefront_layouts')->where('page_type', 'default')->value('id');
        // Candidates per column, best first: the override, the layout (current then default language), the Default layout.
        $texts = fn (?int $layoutId): array => $layoutId === null ? [] : DB::table('storefront_layout_translations')->where('layout_id', $layoutId)->whereIn('locale_id', $ids)->get()
            ->sortBy(fn (object $t): int => (int) array_search($t->locale_id, $ids, true))->map(fn (object $t): array => (array) $t)->values()->all();
        $sources = [$texts($layout?->id), $texts($default)];
        $pick = function (string $column, ?string $given) use ($sources): string {
            $candidates = [$given, ...array_merge(...array_map(fn (array $rows): array => array_column($rows, $column), $sources))];
            foreach ($candidates as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            return '';
        };

        $title = $pick('page_title', $override['title'] ?? null);
        // On pages with their own title (a product, a landing category) the layout's page title is a suffix after it,
        // typed as the merchant wants it (for example "- My store"), instead of a fallback.
        if (in_array($page, self::SUFFIXED, true) && trim((string) ($override['title'] ?? '')) !== '') {
            $suffix = $this->layoutTitle($texts($layout?->id));
            $title = trim($override['title'].($suffix === '' ? '' : ' '.$suffix));
        }

        return ['title' => $title, 'description' => $pick('meta_description', $override['description'] ?? null), 'keywords' => $pick('meta_keywords', $override['keywords'] ?? null), 'custom_html' => (string) ($layout->custom_html ?? ''), 'indexable' => $override['indexable'] ?? (bool) ($layout->indexable ?? true)];
    }

    /** The layout's own page title in the current language, else the default language.
     * @param  list<array<string,mixed>>  $rows  Its texts, current language first. */
    private function layoutTitle(array $rows): string
    {
        foreach ($rows as $row) {
            if (trim((string) ($row['page_title'] ?? '')) !== '') {
                return trim((string) $row['page_title']);
            }
        }

        return '';
    }

    /** @return array<int, string> Layout id => name in the current language, else the default language. */
    private function names(): array
    {
        $catalog = app(LocaleCatalog::class);
        $current = $catalog->active()->firstWhere('code', app()->getLocale())?->id;
        $default = $catalog->active()->firstWhere('is_default', true)?->id;
        $names = [];
        foreach (DB::table('storefront_layout_translations')->whereIn('locale_id', array_filter([$default, $current]))->get() as $t) {
            if ($t->name !== '' && ($t->locale_id === $current || ! isset($names[$t->layout_id]))) {
                $names[$t->layout_id] = $t->name;
            }
        }

        return $names;
    }

    /** @param array<string, mixed> $input
     * @return array{translations: array<int, array<string, string>>, custom_html: string, indexable: bool, page_id: int|null, position: int} */
    private function validated(array $input, bool $custom, ?int $ignore): array
    {
        $catalog = app(LocaleCatalog::class);
        $active = $catalog->active();
        $data = Validator::make($input, [
            'translations' => ['required', 'array'], 'copy_meta_keywords' => ['sometimes', 'boolean'], 'copy_custom_html' => ['sometimes', 'boolean'], 'indexable' => ['sometimes', 'boolean'], 'custom_html' => ['present', 'nullable', 'string', 'max:20000'],
            'page_id' => ['nullable', 'integer', 'exists:pages,id'], 'position' => ['nullable', 'integer', 'between:-99999,99999'],
            'translations.*' => ['array:'.implode(',', self::TEXTS)],
            'translations.*.name' => ['nullable', 'string', 'max:100', self::NO_CONTROLS], 'translations.*.page_title' => ['nullable', 'string', 'max:200', self::NO_CONTROLS],
            'translations.*.meta_description' => ['nullable', 'string', 'max:500', self::NO_CONTROLS], 'translations.*.meta_keywords' => ['nullable', 'string', 'max:500', self::NO_CONTROLS],
        ])->validate();
        if (array_diff(array_keys($data['translations']), DB::table('locales')->pluck('code')->all()) !== []) {
            $this->invalid('translations', 'layouts.invalid');
        }
        $html = (string) ($data['custom_html'] ?? '');
        // The HTML is placed inside the page's head; it must not reopen the document structure.
        if (preg_match('/<\/?\s*(html|head|body)[\s>\/]/i', $html) === 1 || str_contains($html, "\0")) {
            $this->invalid('custom_html', 'layouts.custom_html_invalid');
        }
        $byId = [];
        foreach ($active as $locale) {
            $texts = array_map(fn ($v): string => trim((string) $v), $data['translations'][$locale->code] ?? []);
            $byId[$locale->id] = [...array_fill_keys(self::TEXTS, ''), ...$texts];
        }
        if ($custom) {
            $default = $active->firstWhere('is_default', true);
            $name = $byId[$default->id]['name'];
            if ($name === '') {
                $this->invalid('translations.'.$default->code.'.name', 'layouts.name_required');
            }
            $taken = DB::table('storefront_layout_translations')->where('locale_id', $default->id)->whereRaw('lower(name) = lower(?)', [$name])->when($ignore !== null, fn ($q) => $q->where('layout_id', '<>', $ignore))->exists();
            if ($taken) {
                $this->invalid('translations.'.$default->code.'.name', 'layouts.name_taken');
            }
        }

        return ['translations' => $byId, 'custom_html' => $html, 'indexable' => filter_var($data['indexable'] ?? true, FILTER_VALIDATE_BOOLEAN), 'page_id' => $custom && ($data['page_id'] ?? null) !== null ? (int) $data['page_id'] : null, 'position' => (int) ($data['position'] ?? 0)];
    }

    /** @param array<int, array<string, string>> $byLocale */
    private function writeTexts(int $layout, array $byLocale): void
    {
        foreach ($byLocale as $localeId => $texts) {
            DB::table('storefront_layout_translations')->updateOrInsert(['layout_id' => $layout, 'locale_id' => $localeId], $texts);
        }
    }

    private function authorize(string $actor): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('layouts.manage');
    }

    private function invalid(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __($key)]);
    }

    /**
     * A merchant-added layout linked to a static page carries the protected main content block that shows the page;
     * linking adds it as the first row of the content region (staff can move it like any block), unlinking removes it.
     * Either way the structure version moves on, so caches and open editors see the change.
     */
    public function syncMainContent(int $layoutId, bool $linked): void
    {
        $existing = DB::table('storefront_placements as p')->join('storefront_blocks as b', 'b.id', '=', 'p.block_id')
            ->where('p.layout_id', $layoutId)->where('b.type', 'main_content')->get(['p.grid_id', 'b.id']);
        if ($linked === $existing->isNotEmpty()) {
            return;
        }
        if ($linked) {
            DB::table('storefront_grids')->where('layout_id', $layoutId)->where('region', 'content')->whereNull('parent_id')->increment('row');
            $grid = (string) Str::uuid();
            $block = (string) Str::uuid();
            DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layoutId, 'region' => 'content', 'width' => 16, 'row' => 1, 'position' => 1]);
            DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layoutId, 'type' => 'main_content', 'name' => 'Main content', 'template' => 'main_content', 'settings' => '{}']);
            DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layoutId, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
            foreach (DB::table('locales')->pluck('id') as $locale) {
                DB::table('storefront_block_translations')->insert(['block_id' => $block, 'locale_id' => $locale, 'title' => '']);
            }
        } else {
            // Placements and texts follow the block through their cascading keys; an emptied grid goes too.
            DB::table('storefront_blocks')->whereIn('id', $existing->pluck('id'))->delete();
            DB::table('storefront_grids')->whereIn('id', $existing->pluck('grid_id'))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('storefront_placements as p')->whereColumn('p.grid_id', 'storefront_grids.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('storefront_grids as c')->whereColumn('c.parent_id', 'storefront_grids.id'))->delete();
        }
        DB::table('storefront_layouts')->where('id', $layoutId)->increment('lock_version', 1, ['updated_at' => now()]);
    }
}
