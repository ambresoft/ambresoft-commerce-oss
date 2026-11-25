<?php

namespace App\Storefront\Infrastructure;

use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Infrastructure\Cache\StorefrontCache;
use App\Shared\Infrastructure\Content\RichTextSanitizer;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Domain\Service\LayoutRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class LayoutStore
{
    public const SOURCES = ['variation_groups' => 'variation_group_id', 'reels' => 'reel_id', 'coupons' => 'coupon_id', 'promotions' => 'promotion_id', 'brands' => 'brand_id', 'products' => 'product_id', 'categories' => 'category_id', 'banners' => 'banner_id', 'banner_groups' => 'banner_group_id', 'website_menus' => 'menu_id', 'link_menus' => 'menu_id', 'newsletter_lists' => 'newsletter_list_id', 'search_terms' => 'search_term_id', 'pages' => 'page_id', 'blogs' => 'blog_id', 'blog_posts' => 'blog_post_id'];

    /** Selection sources that read another table, with the menu style each one may pick. */
    public const MENU_STYLES = ['website_menus' => 'standard', 'link_menus' => 'link_groups'];

    /** @return array<string,mixed> */
    public function read(string $page, bool $cached = false): array
    {
        return $cached
            ? app(StorefrontCache::class)->remember('blocks', ['layout', $page], fn () => $this->readLive($page))
            : $this->readLive($page);
    }

    /** @return array<string,mixed> */
    private function readLive(string $page): array
    {
        $row = DB::table('storefront_layouts')->where('page_type', $page)->firstOrFail();
        $read = function () use ($row): array {
            $blocks = DB::table('storefront_blocks')->where('layout_id', $row->id)->get()->mapWithKeys(fn ($b) => [(string) $b->id => ['id' => (string) $b->id, 'type' => $b->type, 'name' => $b->name, 'template' => $b->template, 'css_class' => $b->css_class, 'settings' => json_decode($b->settings, true), 'translations' => [], 'selections' => [], 'image_media_id' => $b->image_media_id === null ? null : (string) $b->image_media_id,
                'image_mobile_media_id' => $b->image_mobile_media_id === null ? null : (string) $b->image_mobile_media_id]])->all();
            $codes = DB::table('locales')->pluck('code', 'id');
            foreach (DB::table('storefront_block_translations')->whereIn('block_id', array_keys($blocks))->get() as $t) {
                $blocks[$t->block_id]['translations'][$codes[$t->locale_id]] = ['title' => $t->title, 'html' => $t->html, 'html_right' => $t->html_right, 'link_text' => $t->link_text, ...($blocks[$t->block_id]['type'] === 'identity' ? ['mobile_links' => $t->mobile_links === null ? MobileMenuLinks::defaults($codes[$t->locale_id]) : json_decode($t->mobile_links, true)] : [])];
            }
            foreach ($blocks as &$block) {
                if ($block['type'] === 'identity') {
                    foreach (app(LocaleCatalog::class)->active() as $locale) {
                        $block['translations'][$locale->code] ??= ['title' => '', 'html' => '', 'mobile_links' => MobileMenuLinks::defaults($locale->code)];
                    }
                }
            }
            unset($block);
            foreach (DB::table('storefront_block_selections')->whereIn('block_id', array_keys($blocks))->orderBy('position')->get() as $s) {
                // Several sources can share one column (both kinds of menus use menu_id): read each column once.
                foreach (array_unique(array_values(self::SOURCES)) as $column) {
                    if ($s->$column !== null) {
                        $blocks[$s->block_id]['selections'][$s->field][] = (string) $s->$column;
                    }
                }
            }

            return ['id' => (string) $row->id, 'page_type' => $row->page_type, 'version' => (int) $row->lock_version,
                'grids' => DB::table('storefront_grids')->where('layout_id', $row->id)->orderBy('row')->orderBy('position')->orderBy('id')->get()->map(fn ($g) => array_diff_key((array) $g, ['layout_id' => true]))->all(),
                'placements' => DB::table('storefront_placements')->where('layout_id', $row->id)->orderBy('position')->orderBy('id')->get()->map(fn ($p) => [...array_diff_key((array) $p, ['layout_id' => true, 'block_id' => true]), 'block' => $blocks[$p->block_id]])->all(),
                'overrides' => DB::table('storefront_overrides')->where('layout_id', $row->id)->orderBy('placement_id')->get()->map(fn ($o) => ['placement_id' => $o->placement_id, 'enabled' => $o->enabled, 'block' => $o->block_id === null ? null : $blocks[$o->block_id]])->all()];
        };

        return $read();
    }

    /** @param array<string,mixed> $input */
    public function save(string $actor, string $page, array $input): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('layouts.manage');
        $registry = app(StorefrontRegistry::class);
        $definition = $registry->definition($page) ?? abort(404);
        app(AuditLogger::class)->operation('storefront_layout', $actor, fn () => DB::transaction(function () use ($page, $input, $definition, $actor): void {
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            $current = $this->read($page);
            $rules = ['version' => ['required', 'integer'], 'grids' => ['present', 'array', 'max:100'], 'placements' => ['present', 'array', 'max:150'], 'overrides' => ['present', 'array', 'max:150'],
                'grids.*' => ['array:id,parent_id,region,width,row,position'], 'placements.*' => ['array:id,grid_id,position,block,enabled,mobile,tablet,desktop'], 'overrides.*' => ['array:placement_id,enabled,block'],
                'grids.*.id' => ['required', 'uuid', 'distinct'], 'grids.*.parent_id' => ['present', 'nullable', 'uuid'], 'grids.*.region' => ['required', Rule::in($definition['regions'])],
                'grids.*.width' => ['required', 'integer', 'between:1,16'], 'grids.*.row' => ['required', 'integer', 'between:1,1000'], 'grids.*.position' => ['required', 'integer', 'between:1,1000'],
                'placements.*.id' => ['required', 'uuid', 'distinct'], 'placements.*.grid_id' => ['required', 'uuid'], 'placements.*.position' => ['required', 'integer', 'between:1,1000'], 'placements.*.block' => ['required', 'array'],
                'overrides.*.placement_id' => ['required', 'uuid', 'distinct'], 'overrides.*.enabled' => ['required', 'boolean'], 'overrides.*.block' => ['present', 'nullable', 'array']];
            foreach (['enabled', 'mobile', 'tablet', 'desktop'] as $field) {
                $rules['placements.*.'.$field] = ['required', 'boolean'];
            }
            $data = Validator::make($input, $rules)->validate();
            if ((int) $data['version'] !== $current['version']) {
                $this->invalid('version', 'layouts.stale');
            }
            try {
                app(LayoutRules::class)->validate($data['grids'], $definition['regions']);
            } catch (\DomainException) {
                $this->invalid('grids', 'layouts.invalid_grids');
            }
            $gridIds = array_column($data['grids'], 'id');
            $taken = [];
            foreach ($data['placements'] as $n => &$p) {
                if (! in_array($p['grid_id'], $gridIds, true) || isset($taken[$p['grid_id'].'/'.$p['position']])) {
                    $this->invalid("placements.$n", 'layouts.invalid');
                }
                $taken[$p['grid_id'].'/'.$p['position']] = true;
                $p['block'] = $this->block($p['block'], $page, $actor, "placements.$n.block");
            }
            unset($p);
            $old = array_column($current['placements'], null, 'id');
            $next = array_column($data['placements'], null, 'id');
            foreach ($old as $id => $p) {
                $moved = isset($next[$id]) && $this->gridPath($p['grid_id'], $current['grids']) !== $this->gridPath($next[$id]['grid_id'], $data['grids']);
                if ($p != ($next[$id] ?? null) || $moved) {
                    Gate::forUser(UserRecord::findOrFail($actor))->authorize(app(StorefrontRegistry::class)->type($p['block']['type'])?->definition()['ability'] ?? 'layouts.manage');
                }
                $this->protect($p, $next[$id] ?? null);
                $policy = app(StorefrontRegistry::class)->type($p['block']['type'])?->definition()['policy'] ?? [];
                if (! ($policy['move'] ?? true) && $moved) {
                    $this->invalid('grids', 'layouts.protected');
                }
            }
            $shared = $definition['inherits'] ? array_column($this->read('default')['placements'], null, 'id') : [];
            foreach ($data['overrides'] as $n => &$o) {
                $source = $shared[$o['placement_id']] ?? null;
                if ($source === null) {
                    $this->invalid("overrides.$n", 'layouts.invalid');
                }
                if ($o['block'] !== null) {
                    $o['block'] = $this->block($o['block'], $page, $actor, "overrides.$n.block");
                    if ($source['block']['type'] !== $o['block']['type']) {
                        $this->invalid("overrides.$n", 'layouts.invalid');
                    }
                }
                Gate::forUser(UserRecord::findOrFail($actor))->authorize(app(StorefrontRegistry::class)->type($source['block']['type'])?->definition()['ability'] ?? 'layouts.manage');
                $this->protect($source, [...$source, 'enabled' => $o['enabled'], 'block' => $o['block'] ?? $source['block']]);
            }
            unset($o);
            foreach ($current['overrides'] as $previous) {
                if ($previous['block'] !== null && ! in_array($previous, $data['overrides'])) {
                    Gate::forUser(UserRecord::findOrFail($actor))->authorize(app(StorefrontRegistry::class)->type($previous['block']['type'])?->definition()['ability'] ?? 'layouts.manage');
                }
            }
            // Submitted UUIDs can refer only to existing objects of this layout or new objects.
            foreach (['storefront_grids' => array_column($data['grids'], 'id'), 'storefront_placements' => array_column($data['placements'], 'id')] as $table => $ids) {
                if (DB::table($table)->whereIn('id', $ids)->where('layout_id', '<>', $current['id'])->exists()) {
                    $this->invalid('version', 'layouts.invalid');
                }
            }
            $blockIds = [...array_column(array_column($data['placements'], 'block'), 'id'), ...array_column(array_filter(array_column($data['overrides'], 'block')), 'id')];
            if (count($blockIds) !== count(array_unique($blockIds)) || DB::table('storefront_blocks')->whereIn('id', $blockIds)->where('layout_id', '<>', $current['id'])->exists()) {
                $this->invalid('placements', 'layouts.copy_required');
            }
            foreach (DB::table('storefront_blocks')->whereIn('id', $blockIds)->get(['id', 'type']) as $existing) {
                $submitted = [...array_column($data['placements'], 'block'), ...array_filter(array_column($data['overrides'], 'block'))];
                foreach ($submitted as $block) {
                    if ($block['id'] === $existing->id && $block['type'] !== $existing->type) {
                        $this->invalid('placements', 'layouts.invalid');
                    }
                }
            }
            foreach ($data['grids'] as $g) {
                DB::table('storefront_grids')->updateOrInsert(['id' => $g['id']], [...$g, 'layout_id' => $current['id']]);
            }
            foreach ($data['placements'] as $p) {
                $this->writeBlock($p['block'], $current['id']);
                DB::table('storefront_placements')->updateOrInsert(['id' => $p['id']], [...array_diff_key($p, ['block' => true]), 'block_id' => $p['block']['id'], 'layout_id' => $current['id']]);
            }
            DB::table('storefront_overrides')->where('layout_id', $current['id'])->delete();
            foreach ($data['overrides'] as $o) {
                if ($o['block'] !== null) {
                    $this->writeBlock($o['block'], $current['id']);
                }
                DB::table('storefront_overrides')->insert(['layout_id' => $current['id'], 'placement_id' => $o['placement_id'], 'enabled' => $o['enabled'], 'block_id' => $o['block']['id'] ?? null]);
            }
            // Removing a shared source removes its page-specific copies and invalidates those editors/structures.
            $removed = array_values(array_diff(array_keys($old), array_keys($next)));
            $dependent = DB::table('storefront_overrides')->whereIn('placement_id', $removed)->get(['layout_id', 'block_id']);
            DB::table('storefront_blocks')->whereIn('id', $dependent->pluck('block_id')->filter()->all())->delete();
            DB::table('storefront_layouts')->whereIn('id', $dependent->pluck('layout_id')->unique()->all())->increment('lock_version', 1, ['updated_at' => now()]);
            DB::table('storefront_placements')->where('layout_id', $current['id'])->whereNotIn('id', array_keys($next))->delete();
            DB::table('storefront_grids')->where('layout_id', $current['id'])->whereNotIn('id', $gridIds)->delete();
            DB::table('storefront_blocks')->where('layout_id', $current['id'])->whereNotIn('id', $blockIds)->delete();
            DB::table('storefront_layouts')->where('id', $current['id'])->update(['lock_version' => $current['version'] + 1, 'updated_at' => now()]);
        }));
    }

    /** @param list<array<string,mixed>> $grids
     * @return list<array<string,mixed>> */
    private function gridPath(string $id, array $grids): array
    {
        $path = [];
        $byId = array_column($grids, null, 'id');
        while (isset($byId[$id])) {
            $g = $byId[$id];
            $path[] = $g;
            if ($g['parent_id'] === null) {
                break;
            }
            $id = $g['parent_id'];
        }

        return $path;
    }

    /** @param array<string,mixed> $before
     * @param array<string,mixed>|null $after */
    private function protect(array $before, ?array $after): void
    {
        $policy = app(StorefrontRegistry::class)->type($before['block']['type'])?->definition()['policy'] ?? [];
        if (($after === null && ! ($policy['delete'] ?? true)) || ($after !== null && ((! ($policy['disable'] ?? true) && (! $after['enabled'] || ! $after['mobile'] || ! $after['tablet'] || ! $after['desktop'])) || (! ($policy['move'] ?? true) && ($after['grid_id'] !== $before['grid_id'] || $after['position'] !== $before['position'])) || (! ($policy['configure'] ?? true) && $after['block'] != $before['block'])))) {
            $this->invalid('placements', 'layouts.protected');
        }
    }

    /** @param array<string,mixed> $block
     * @return array<string,mixed> */
    private function block(array $block, string $page, string $actor, string $path): array
    {
        try {
            $data = Validator::make($block, ['id' => ['required', 'uuid'], 'type' => ['required', 'string'], 'name' => ['required', 'string', 'max:128'], 'template' => ['required', 'string'], 'css_class' => ['present', 'nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_ -]*$/D'], 'settings' => ['present', 'array'], 'translations' => ['present', 'array'], 'translations.*' => ['array:title,html,html_right,link_text,mobile_links'], 'selections' => ['present', 'array'], 'image_media_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'image_mobile_media_id' => ['sometimes', 'nullable', 'integer', 'min:1']])->validate();
            $type = app(StorefrontRegistry::class)->type($data['type']);
            $theme = app(StorefrontRegistry::class)->activeTheme(config('storefront.theme'));
            if ($type === null || ! in_array(app(StorefrontRegistry::class)->contentPage($page), $type->definition()['pages'], true) || ! in_array($data['template'], $type->definition()['templates'], true) || ! in_array($data['template'], $theme['templates'], true)) {
                $this->invalid('type', 'layouts.invalid');
            }
            if (! ($type->definition()['creatable'] ?? true) && ! DB::table('storefront_blocks')->where('id', $data['id'])->where('type', $data['type'])->exists()) {
                $this->invalid('type', 'layouts.protected');
            }
            Gate::forUser(UserRecord::findOrFail($actor))->authorize($type->definition()['ability'] ?? 'layouts.manage');
            $data['settings'] = $type->validate($data['settings']);
            // Only pictures of a block that offers them: ones this editor uploaded, or ones a block already shows (a copy).
            foreach (['image_media_id', 'image_mobile_media_id'] as $column) {
                $image = isset($data[$column]) ? (string) $data[$column] : null;
                if ($image !== null && (! in_array('image', array_column($type->definition()['fields'], 'kind'), true) || ! (in_array($image, session('layouts.uploads', []), true) || self::shownImage($image)))) {
                    $this->invalid($column, 'layouts.invalid');
                }
                $data[$column] = $image;
            }
            $allowed = [];
            foreach ($type->definition()['fields'] as $field) {
                if ($field['kind'] !== 'selection') {
                    continue;
                }
                $allowed[] = $field['key'];
                $ids = $data['selections'][$field['key']] ?? [];
                Validator::make(['ids' => $ids], ['ids' => ['array', 'max:'.(($field['single'] ?? false) ? 1 : 100)], 'ids.*' => ['integer', 'min:1', 'distinct', $field['source'] === 'promotions' ? Rule::exists('promotions', 'id')->where('mode', 'automatic') : (isset(self::MENU_STYLES[$field['source']]) ? Rule::exists('website_menus', 'id')->where('style', self::MENU_STYLES[$field['source']]) : Rule::exists($field['source'], 'id'))]])->validate();
                $data['selections'][$field['key']] = array_values(array_map(strval(...), $ids));
            }
            if ($data['type'] === 'banners' && $data['settings']['source'] === 'group' && ($data['selections']['group'] ?? []) === []) {
                $this->invalid('selections.group', 'layouts.invalid');
            }
            if ($data['type'] === 'brand_products') {
                $brand = $data['selections']['brand'][0] ?? null;
                if ($brand === null) {
                    $this->invalid('selections.brand', 'brands.select_brand');
                }
                $selected = $data['selections']['items'] ?? [];
                if ($data['settings']['source'] === 'manual' && DB::table('products')->whereIn('id', $selected)->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', '<>', $brand))->exists()) {
                    $this->invalid('selections.items', 'brands.products_mismatch');
                }
            }
            if (array_diff(array_keys($data['selections']), $allowed) !== []) {
                $this->invalid('selections', 'layouts.invalid');
            }
            $activeCodes = app(LocaleCatalog::class)->active()->pluck('code')->all();
            $savedCodes = DB::table('storefront_block_translations')->join('locales', 'locales.id', '=', 'locale_id')->where('block_id', $data['id'])->pluck('code')->all();
            // Unknown language codes are rejected. Texts of known but inactive languages are kept only for blocks
            // that already store them; a copy (new block id) simply leaves them behind.
            if (array_diff(array_keys($data['translations']), DB::table('locales')->pluck('code')->all()) !== []) {
                $this->invalid('translations', 'layouts.invalid');
            }
            $data['translations'] = array_intersect_key($data['translations'], array_flip([...$activeCodes, ...$savedCodes]));
            foreach (app(LocaleCatalog::class)->active() as $locale) {
                $translation = $data['translations'][$locale->code] ?? ['title' => '', 'html' => ''];
                Validator::make($translation, ['title' => ['present', 'nullable', 'string', 'max:200'], 'html' => ['present', 'nullable', 'string', 'max:100000'], 'html_right' => ['sometimes', 'nullable', 'string', 'max:100000'], 'link_text' => ['sometimes', 'nullable', 'string', 'max:100', 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f<>]/']])->validate();
                $data['translations'][$locale->code] = ['title' => $translation['title'] ?? '', 'html' => app(RichTextSanitizer::class)->sanitize($translation['html'] ?? ''), 'html_right' => app(RichTextSanitizer::class)->sanitize($translation['html_right'] ?? ''), 'link_text' => trim((string) ($translation['link_text'] ?? ''))];
                if ($data['type'] === 'identity') {
                    try {
                        $data['translations'][$locale->code]['mobile_links'] = MobileMenuLinks::validate($translation['mobile_links'] ?? MobileMenuLinks::defaults($locale->code));
                    } catch (ValidationException $e) {
                        throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ['translations.'.$locale->code.'.'.$field => $messages])->all());
                    }
                }

            }
            $data['css_class'] ??= '';

            return $data;
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => [$path.'.'.$field => $messages])->all());
        }
    }

    /** @param array<string,mixed> $b */
    private function writeBlock(array $b, string $layout): void
    {
        DB::table('storefront_blocks')->updateOrInsert(['id' => $b['id']], ['layout_id' => $layout, 'type' => $b['type'], 'name' => $b['name'], 'template' => $b['template'], 'css_class' => $b['css_class'], 'settings' => json_encode((object) $b['settings']), 'image_media_id' => $b['image_media_id'] ?? null, 'image_mobile_media_id' => $b['image_mobile_media_id'] ?? null]);
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            $translation = $b['translations'][$locale->code];
            if (isset($translation['mobile_links'])) {
                $translation['mobile_links'] = json_encode($translation['mobile_links']);
            }
            DB::table('storefront_block_translations')->updateOrInsert(['block_id' => $b['id'], 'locale_id' => $locale->id], $translation);
        }
        DB::table('storefront_block_selections')->where('block_id', $b['id'])->delete();
        foreach (app(StorefrontRegistry::class)->type($b['type'])->definition()['fields'] as $field) {
            if ($field['kind'] !== 'selection') {
                continue;
            }
            foreach ($b['selections'][$field['key']] ?? [] as $n => $id) {
                DB::table('storefront_block_selections')->insert(['block_id' => $b['id'], 'field' => $field['key'], 'position' => $n + 1, self::SOURCES[$field['source']] => $id]);
            }
        }
    }

    private function invalid(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __($key)]);
    }

    /** Whether a block shows this picture (desktop or phone). */
    public static function shownImage(string $media): bool
    {
        return DB::table('storefront_blocks')->where(fn ($q) => $q->where('image_media_id', $media)->orWhere('image_mobile_media_id', $media))->exists();
    }
}
