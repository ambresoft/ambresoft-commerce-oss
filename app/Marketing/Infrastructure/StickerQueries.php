<?php

namespace App\Marketing\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Settings\Infrastructure\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class StickerQueries
{
    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters): array
    {
        $q = DB::table('stickers as s')->select('s.*')
            ->selectSub(DB::table('product_stickers')->whereColumn('sticker_id', 's.id')->selectRaw('count(*)'), 'assigned_count');
        if (! empty($filters['search'])) {
            $q->where('s.name', 'ilike', '%'.addcslashes($filters['search'], '%_\\').'%');
        }
        foreach (['status', 'type', 'style'] as $field) {
            if (! empty($filters[$field])) {
                $q->where('s.'.$field, $filters[$field]);
            }
        }
        $sort = in_array($filters['sort'] ?? '', ['name', 'priority', 'updated_at', 'starts_at', 'ends_at'], true) ? $filters['sort'] : 'priority';
        $page = $q->orderBy('s.'.$sort, ($filters['direction'] ?? '') === 'desc' ? 'desc' : 'asc')->orderBy('s.id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $conditions = DB::table('sticker_conditions')->whereIn('sticker_id', collect($page->items())->pluck('id'))->orderBy('id')->get()->groupBy('sticker_id');
        $flat = $conditions->flatten(1);
        $categoryNames = collect(app(CouponQueries::class)->options('category', '', $flat->pluck('category_id')->filter()->unique()->map(strval(...))->all()))->pluck('name', 'id');
        $featureNames = collect($this->choices('features', '', $flat->pluck('feature_id')->filter()->unique()->map(strval(...))->all()))->pluck('name', 'id');
        $optionNames = collect($this->choices('options', '', $flat->pluck('option_id')->filter()->unique()->map(strval(...))->all()))->pluck('name', 'id');
        $page->setCollection(collect($page->items())->map(fn (object $s): array => [...$this->row($s), 'assigned_count' => (int) $s->assigned_count, 'conditions' => collect($conditions[$s->id] ?? [])->map(fn (object $c): array => [...$this->condition($c), 'reference' => $c->type === 'category' ? ($categoryNames[$c->category_id] ?? __('promotions.deleted_reference')) : ($c->type === 'feature' ? ($featureNames[$c->feature_id] ?? __('promotions.deleted_reference')).($c->option_id === null ? '' : ' · '.($optionNames[$c->option_id] ?? __('promotions.deleted_reference'))) : '')])->all()]));

        return $page->toArray();
    }

    /** @return array<string, mixed> */
    public function editor(string $id): array
    {
        $s = DB::table('stickers')->where('id', $id)->first();
        abort_if($s === null, 404);
        $translations = [];
        foreach (DB::table('sticker_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.sticker_id', $id)->get() as $t) {
            $translations[$t->code] = ['text' => $t->text, 'short_text' => $t->short_text, 'tooltip' => $t->tooltip];
        }

        return [...$this->row($s), 'translations' => $translations, 'image' => $s->image_media_id === null ? null : '/admin/stickers/'.$id.'/image?v='.$s->image_media_id,
            'products' => DB::table('product_stickers')->where('sticker_id', $id)->pluck('product_id')->map(strval(...))->all(),
            'conditions' => DB::table('sticker_conditions')->where('sticker_id', $id)->orderBy('id')->get()->map(fn (object $c): array => $this->condition($c))->all()];
    }

    /** @return array<string, mixed> */
    private function condition(object $c): array
    {
        return ['type' => $c->type, 'minimum' => (string) ($c->minimum ?? ''), 'maximum' => (string) ($c->maximum ?? ''), 'category_id' => (string) ($c->category_id ?? ''), 'feature_id' => (string) ($c->feature_id ?? ''), 'option_id' => (string) ($c->option_id ?? ''), 'include_descendants' => (bool) $c->include_descendants, 'boolean_value' => $c->boolean_value];
    }

    /** @return array<string, mixed> */
    private function row(object $s): array
    {
        $data = array_intersect_key((array) $s, array_flip(StickerInput::FIELDS));
        $timezone = app(GeneralSettings::class)->all()['timezone'];
        $start = $s->starts_at === null ? null : CarbonImmutable::parse($s->starts_at);
        $end = $s->ends_at === null ? null : CarbonImmutable::parse($s->ends_at);

        return [...$data, 'id' => (string) $s->id, 'filter_slug' => $s->filter_slug ?? '', 'version' => (int) $s->lock_version, 'image_media_id' => (string) ($s->image_media_id ?? ''), 'starts_at' => $start?->timezone($timezone)->format('Y-m-d\TH:i'), 'ends_at' => $end?->timezone($timezone)->format('Y-m-d\TH:i'), 'updated_at' => CarbonImmutable::parse($s->updated_at)->timezone($timezone)->format('Y-m-d H:i'),
            'state' => $s->status !== 'active' ? 'disabled' : ($end !== null && now()->gte($end) ? 'expired' : ($start !== null && now()->lt($start) ? 'scheduled' : 'active'))];
    }

    /** @return array{version: int, card_limit: int, detail_limit: int} */
    public function limits(): array
    {
        $s = DB::table('sticker_display_settings')->where('id', 1)->first();

        return ['version' => (int) $s->lock_version, 'card_limit' => (int) $s->card_limit, 'detail_limit' => (int) $s->detail_limit];
    }

    /** @param list<string> $selected
     * @return list<array{id: string, name: string}>
     */
    public function manualOptions(string $search = '', array $selected = []): array
    {
        $q = DB::table('stickers')->where('type', 'manual')->select('id', 'name');
        $chosen = (clone $q)->whereIn('id', $selected)->get();
        if ($search !== '') {
            $q->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }

        return $chosen->concat($q->orderBy('name')->limit(30)->get())->unique('id')->map(fn (object $s): array => ['id' => (string) $s->id, 'name' => $s->name])->values()->all();
    }

    /** Bounded localized existing feature/option selectors; never return the whole catalog.
     * @param  list<string>  $selected
     * @return list<array<string, mixed>>
     */
    public function choices(string $kind, string $search, array $selected, ?string $featureId = null): array
    {
        $table = $kind === 'features' ? 'features' : 'feature_options';
        $translations = $kind === 'features' ? 'feature_translations' : 'feature_option_translations';
        $key = $kind === 'features' ? 'feature_id' : 'option_id';
        $field = $kind === 'features' ? 'name' : 'label';
        $ids = DB::table('locales')->pluck('id', 'code');
        $q = DB::table($table.' as e')->leftJoin($translations.' as c', fn ($j) => $j->on('c.'.$key, '=', 'e.id')->where('c.locale_id', $ids[app()->getLocale()] ?? 0))
            ->leftJoin($translations.' as d', fn ($j) => $j->on('d.'.$key, '=', 'e.id')->where('d.locale_id', $ids[app(LocaleCatalog::class)->defaultCode()] ?? 0))->where('e.status', 'active')->select('e.id')->selectRaw("coalesce(nullif(c.$field, ''), nullif(d.$field, ''), '#' || e.id::text) as name");
        if ($kind === 'features') {
            $q->whereIn('e.type', ['number', 'boolean', 'single', 'multiple'])->addSelect('e.type');
        } else {
            $q->addSelect('e.feature_id');
            if ($featureId !== null) {
                $q->where('e.feature_id', $featureId);
            }
        }
        $chosen = (clone $q)->whereIn('e.id', $selected)->get();
        $q = DB::query()->fromSub($q, 'choices');
        if ($search !== '') {
            $q->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }

        return $chosen->concat($q->orderBy('id')->limit(30)->get())->unique('id')->map(fn (object $r): array => [...(array) $r, 'id' => (string) $r->id, ...($kind === 'options' ? ['feature_id' => (string) $r->feature_id] : [])])->values()->all();
    }
}
