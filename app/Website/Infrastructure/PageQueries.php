<?php

namespace App\Website\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Website\Domain\Service\PageRules;
use Illuminate\Support\Facades\DB;

/** Read projections for the page admin screens. Page counts are small, so the tree is loaded whole. */
final class PageQueries
{
    private const LIMIT = 2000;

    /**
     * Pages in tree order (parent before children, siblings by position then name) with their depth.
     *
     * @return list<array{id: string, parent_id: string|null, name: string, slug: string, status: string, position: int, depth: int, state: string, version: int, children: int}>
     */
    public function tree(): array
    {
        [$current, $default] = $this->localeIds();
        $rows = DB::table('pages as p')
            ->leftJoin('page_translations as c', fn ($j) => $j->on('c.page_id', '=', 'p.id')->where('c.locale_id', $current))
            ->leftJoin('page_translations as d', fn ($j) => $j->on('d.page_id', '=', 'p.id')->where('d.locale_id', $default))
            ->selectRaw("p.id, p.parent_id, p.position, p.status, p.available_from, p.available_until, p.lock_version, coalesce(c.name, d.name, '#' || p.id) as name, coalesce(c.slug, d.slug, '') as slug")
            ->limit(self::LIMIT)->get();
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[(string) ($row->parent_id ?? '')][] = $row;
        }
        foreach ($byParent as &$siblings) {
            usort($siblings, fn ($a, $b): int => [$a->position, mb_strtolower($a->name), $a->id] <=> [$b->position, mb_strtolower($b->name), $b->id]);
        }
        unset($siblings);
        $out = [];
        $walk = function (string $parent, int $depth) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $row) {
                $out[] = [
                    'id' => (string) $row->id, 'parent_id' => $row->parent_id === null ? null : (string) $row->parent_id, 'name' => $row->name, 'slug' => $row->slug,
                    'status' => $row->status, 'position' => (int) $row->position, 'depth' => $depth, 'version' => (int) $row->lock_version,
                    'state' => $this->state($row->status, $row->available_from, $row->available_until), 'children' => count($byParent[(string) $row->id] ?? []),
                ];
                $walk((string) $row->id, $depth + 1);
            }
        };
        $walk('', 0);

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function editor(string $id): ?array
    {
        $page = DB::table('pages')->where('id', $id)->first();
        if ($page === null) {
            return null;
        }
        $codes = DB::table('locales')->pluck('code', 'id');
        $translations = [];
        foreach (DB::table('page_translations')->where('page_id', $id)->get() as $row) {
            $translations[$codes[$row->locale_id]] = ['name' => $row->name, 'content' => $row->content, 'slug' => $row->slug, 'seo_title' => $row->seo_title, 'meta_description' => $row->meta_description, 'meta_keywords' => $row->meta_keywords];
        }

        return [
            'id' => (string) $page->id, 'version' => (int) $page->lock_version, 'parent_id' => $page->parent_id === null ? null : (string) $page->parent_id,
            'position' => (int) $page->position, 'status' => $page->status, 'created_on' => (string) $page->created_on, 'facebook_type' => $page->facebook_type,
            'available_from' => $this->iso($page->available_from), 'available_until' => $this->iso($page->available_until),
            'state' => $this->state($page->status, $page->available_from, $page->available_until),
            'translations' => (object) $translations,
            'faq_ids' => app(FaqLinks::class)->assigned('page', $id),
            // Linked to a layout, the page is on the storefront at its SEO name; staff preview it whatever its state.
            'preview_url' => DB::table('storefront_layouts')->where('page_id', $id)->exists() && ($slug = $translations[app()->getLocale()]['slug'] ?? $translations[app(LocaleCatalog::class)->defaultCode()]['slug'] ?? '') !== '' ? '/'.$slug.'?preview=1' : null,
            'customer_groups' => DB::table('page_customer_groups')->where('page_id', $id)->orderBy('group_id')->pluck('group_id')->map(fn ($v): string => (string) $v)->all(),
            'form' => $this->form($id, $codes->all()),
        ];
    }

    /**
     * The page's form definition with every language, or null when none was saved yet.
     *
     * @param  array<int, string>  $codes  locale id => code
     * @return array<string, mixed>|null
     */
    private function form(string $page, array $codes): ?array
    {
        $form = DB::table('page_forms')->where('page_id', $page)->first();
        if ($form === null) {
            return null;
        }
        $translations = [];
        foreach (DB::table('page_form_translations')->where('page_id', $page)->get() as $row) {
            $translations[$codes[$row->locale_id]] = ['submit_text' => $row->submit_text, 'custom_subject' => $row->custom_subject];
        }
        $fields = DB::table('page_form_fields')->where('page_id', $page)->orderBy('position')->orderBy('id')->get();
        $fieldIds = $fields->pluck('id')->all();
        $names = [];
        foreach (DB::table('page_form_field_translations')->whereIn('field_id', $fieldIds)->get() as $row) {
            $names[$row->field_id][$codes[$row->locale_id]] = $row->name;
        }
        $variants = DB::table('page_form_variants')->whereIn('field_id', $fieldIds)->orderBy('position')->orderBy('id')->get();
        $labels = [];
        foreach (DB::table('page_form_variant_translations')->whereIn('variant_id', $variants->pluck('id')->all())->get() as $row) {
            $labels[$row->variant_id][$codes[$row->locale_id]] = $row->label;
        }
        $byField = [];
        foreach ($variants as $variant) {
            $byField[$variant->field_id][] = ['position' => (int) $variant->position, 'labels' => (object) ($labels[$variant->id] ?? [])];
        }

        return [
            'enabled' => (bool) $form->enabled, 'placement' => $form->placement, 'subject_mode' => $form->subject_mode,
            'subject_field' => $form->subject_field_id === null ? '' : (string) $form->subject_field_id, 'recipients' => $form->recipients,
            'translations' => (object) $translations,
            'fields' => $fields->map(fn ($field): array => [
                'key' => (string) $field->id, 'id' => (string) $field->id, 'position' => (int) $field->position, 'type' => $field->type,
                'required' => (bool) $field->required, 'status' => $field->status, 'names' => (object) ($names[$field->id] ?? []),
                'variants' => $byField[$field->id] ?? [],
            ])->all(),
        ];
    }

    /** @return list<array{id: string, name: string}> */
    public function customerGroups(): array
    {
        [$current, $default] = $this->localeIds();

        return DB::table('customer_groups as g')
            ->leftJoin('customer_group_translations as c', fn ($j) => $j->on('c.group_id', '=', 'g.id')->where('c.locale_id', $current))
            ->leftJoin('customer_group_translations as d', fn ($j) => $j->on('d.group_id', '=', 'g.id')->where('d.locale_id', $default))
            ->selectRaw("g.id, coalesce(c.name, d.name, '#' || g.id) as name")->orderBy('g.id')->get()
            ->map(fn ($row): array => ['id' => (string) $row->id, 'name' => $row->name])->all();
    }

    /** @return array<string, list<string>> */
    public function facebookTypes(): array
    {
        return PageRules::FACEBOOK_TYPES;
    }

    /** live | scheduled | expired | hidden | disabled */
    private function state(string $status, mixed $from, mixed $until): string
    {
        if ($status === 'disabled') {
            return 'disabled';
        }
        if ($from !== null && now()->lt($from)) {
            return 'scheduled';
        }
        if ($until !== null && now()->gte($until)) {
            return 'expired';
        }

        return $status === 'hidden' ? 'hidden' : 'live';
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : now()->parse($value)->utc()->toIso8601ZuluString();
    }

    /** @return array{0: int|null, 1: int|null} */
    private function localeIds(): array
    {
        $locales = app(LocaleCatalog::class)->active();
        $default = $locales->firstWhere('is_default', true)?->id;

        return [$locales->where('code', app()->getLocale())->pluck('id')->first() ?? $default, $default];
    }
}
