<?php

namespace App\Catalog\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\MediaMetadata;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FeatureQueries
{
    public function revision(): int
    {
        return (int) DB::table('feature_catalog_state')->where('id', 1)->value('version');
    }

    public function defaultId(): int
    {
        return app(LocaleCatalog::class)->active()->firstWhere('code', app(LocaleCatalog::class)->defaultCode())->id;
    }

    public function completeSql(string $alias = 'f'): string
    {
        $locale = $this->defaultId();

        return "(EXISTS (SELECT 1 FROM feature_translations ft WHERE ft.feature_id = $alias.id AND ft.locale_id = $locale AND length(btrim(ft.name)) > 0) AND ($alias.type NOT IN ('single','multiple') OR EXISTS (SELECT 1 FROM feature_options fo JOIN feature_option_translations ot ON ot.option_id = fo.id WHERE fo.feature_id = $alias.id AND fo.status = 'active' AND ot.locale_id = $locale AND length(btrim(ot.label)) > 0)))";
    }

    /** Options of a feature retaining any variant landing-page metadata, including hidden preserved data. */
    public function landingMetadataCount(string $feature): int
    {
        return DB::table('feature_options as o')->where('o.feature_id', $feature)->where(fn ($q) => $q->whereNotNull('o.landing_image_media_id')
            ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('feature_option_translations as t')->whereColumn('t.option_id', 'o.id')
                ->whereRaw("(t.slug IS NOT NULL OR t.description <> '' OR t.seo_title <> '' OR t.seo_description <> '' OR t.meta_keywords <> '')")))->count();
    }

    // Arguments are internal SQL aliases/expressions, never request strings.
    public function appliesSql(string $category = 'p.category_id', string $feature = 'f.id'): string
    {
        return "(NOT EXISTS (SELECT 1 FROM feature_categories fc WHERE fc.feature_id = $feature) OR EXISTS (WITH RECURSIVE ancestors AS (SELECT id,parent_id FROM categories WHERE id = $category UNION ALL SELECT c.id,c.parent_id FROM categories c JOIN ancestors ancestor ON c.id = ancestor.parent_id) SELECT 1 FROM ancestors ancestor JOIN feature_categories fc ON fc.category_id = ancestor.id WHERE fc.feature_id = $feature))";
    }

    public function filledSql(): string
    {
        $locale = $this->defaultId();

        return "EXISTS (SELECT 1 FROM product_feature_assignments a WHERE a.product_id = p.id AND a.feature_id = f.id AND a.is_applicable AND ((f.type = 'text' AND EXISTS (SELECT 1 FROM product_feature_texts t WHERE t.assignment_id = a.id AND t.locale_id = $locale AND length(btrim(t.value)) > 0)) OR (f.type = 'number' AND a.number_value IS NOT NULL) OR (f.type = 'boolean' AND a.boolean_value IS NOT NULL) OR (f.type IN ('single','multiple') AND EXISTS (SELECT 1 FROM product_feature_choices c WHERE c.assignment_id = a.id))))";
    }

    public function missing(): Builder
    {
        return DB::table('products as p')->crossJoin('features as f')->whereNull('p.deleted_at')->where('p.status', 'active')->where('f.status', 'active')->where('f.required', true)->whereRaw($this->completeSql())->whereRaw($this->appliesSql())->whereRaw('NOT '.$this->filledSql());
    }

    public function remediationCount(): int
    {
        return $this->missing()->distinct()->count('p.id');
    }

    /** @return list<string> */
    public function missingForProduct(string $product): array
    {
        return DB::table('features as f')->crossJoin('products as p')->where('p.id', $product)->where('f.status', 'active')->where('f.required', true)->whereRaw($this->completeSql())->whereRaw($this->appliesSql())->whereRaw('NOT '.$this->filledSql())->pluck('f.id')->map(strval(...))->all();
    }

    /** @return list<array<string, mixed>> */
    public function groups(): array
    {
        $groups = DB::table('feature_groups')->orderBy('position')->orderBy('id')->get();
        $translations = DB::table('feature_group_translations')->whereIn('group_id', $groups->pluck('id'))->get()->groupBy('group_id');
        $locales = app(LocaleCatalog::class)->active()->pluck('code', 'id')->all();
        $defaultLocale = app(LocaleCatalog::class)->defaultCode();

        return $groups->map(function ($g) use ($translations, $locales, $defaultLocale): array {
            $texts = [];
            foreach ($translations->get($g->id, collect()) as $t) {
                if (isset($locales[$t->locale_id])) {
                    $texts[$locales[$t->locale_id]] = ['name' => $t->name, 'description' => $t->description];
                }
            }

            return ['id' => (string) $g->id, 'version' => $g->lock_version, 'name' => $g->system_key === 'general' ? __('features.general') : $this->label($texts, 'name', $defaultLocale), 'protected' => $g->system_key === 'general', 'translations' => (object) $texts];
        })->all();
    }

    /** @param array<string, array<string, mixed>> $texts */
    public function label(array $texts, string $field, string $defaultLocale): string
    {
        return $texts[app()->getLocale()][$field] ?? $texts[$defaultLocale][$field] ?? $texts['en'][$field] ?? '';
    }

    /** @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    public function definitions(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $features = DB::table('features as f')->join('feature_groups as g', 'g.id', '=', 'f.group_id')->whereIn('f.id', $ids)->select('f.*')->selectRaw($this->completeSql().' as complete')->orderBy('g.position')->orderBy('g.id')->orderBy('f.position')->orderBy('f.id')->get();
        $texts = DB::table('feature_translations')->whereIn('feature_id', $ids)->get()->groupBy('feature_id');
        $options = DB::table('feature_options')->whereIn('feature_id', $ids)->orderBy('position')->orderBy('id')->get();
        $labels = DB::table('feature_option_translations')->whereIn('option_id', $options->pluck('id'))->get()->groupBy('option_id');
        $categories = DB::table('feature_categories')->whereIn('feature_id', $ids)->get()->groupBy('feature_id');
        $locales = app(LocaleCatalog::class)->active()->pluck('code', 'id')->all();
        $defaultLocale = app(LocaleCatalog::class)->defaultCode();
        $optionGroups = $options->groupBy('feature_id');
        $featureProducts = DB::table('product_feature_assignments')->whereIn('feature_id', $ids)->groupBy('feature_id')->selectRaw('feature_id, count(distinct product_id) as n')->pluck('n', 'feature_id')->all();
        $productCounts = DB::table('product_feature_choices')->whereIn('option_id', $options->pluck('id'))->groupBy('option_id')->selectRaw('option_id, count(distinct assignment_id) as n')->pluck('n', 'option_id')->all();
        $groupCounts = DB::table('variation_group_features')->whereIn('feature_id', $ids)->groupBy('feature_id')->selectRaw('feature_id, count(*) as n')->pluck('n', 'feature_id')->all();

        return $features->map(function ($f) use ($texts, $optionGroups, $labels, $categories, $locales, $defaultLocale, $groupCounts, $productCounts, $featureProducts): array {
            $translations = [];
            foreach ($texts->get($f->id, collect()) as $t) {
                if (isset($locales[$t->locale_id])) {
                    $translations[$locales[$t->locale_id]] = array_intersect_key((array) $t, array_flip(FeatureInput::TEXT));
                }
            }
            $opts = [];
            // Variant landing-page metadata is exposed only for the purpose that enables it; hidden values stay stored.
            $landing = $f->purpose === 'landing_pages';
            $fields = $landing ? ['label', ...FeatureInput::LANDING] : ['label'];
            foreach ($optionGroups->get($f->id, collect()) as $o) {
                $ot = [];
                foreach ($labels->get($o->id, collect()) as $t) {
                    if (isset($locales[$t->locale_id])) {
                        $ot[$locales[$t->locale_id]] = array_map(fn ($value): string => (string) $value, array_intersect_key((array) $t, array_flip($fields)));
                    }
                }
                $option = ['id' => (string) $o->id, 'version' => $o->lock_version, 'status' => $o->status, 'label' => $this->label($ot, 'label', $defaultLocale), 'products' => (int) ($productCounts[$o->id] ?? 0), 'translations' => (object) $ot];
                if ($f->purpose === 'variations') {
                    // Swatch data is shared across languages; hidden (but kept) under other purposes.
                    $option['swatch_color'] = $o->swatch_color;
                    $option['swatch_image_url'] = $o->swatch_image_media_id === null ? null : route('admin.features.options.swatch', [$f->id, $o->id], false).'?v='.$o->lock_version;
                    $option['swatch_meta'] = app(MediaMetadata::class)->one($o->swatch_image_media_id === null ? null : (string) $o->swatch_image_media_id);
                }
                if ($landing) {
                    $option['image_url'] = $o->landing_image_media_id === null ? null : route('admin.features.options.image', [$f->id, $o->id], false).'?v='.$o->lock_version;
                    $option['image_meta'] = app(MediaMetadata::class)->one($o->landing_image_media_id === null ? null : (string) $o->landing_image_media_id);
                    // Future page-management target: special purpose, active complete feature and active labeled option.
                    $option['landing_eligible'] = $f->status === 'active' && (bool) $f->complete && $o->status === 'active' && trim($ot[$defaultLocale]['label'] ?? '') !== '';
                }
                $opts[] = $option;
            }

            return ['id' => (string) $f->id, 'code' => $f->code, 'name' => $this->label($translations, 'name', $defaultLocale), 'type' => $f->type, 'purpose' => $f->purpose, 'variation_style' => $f->variation_style, 'variation_groups' => (int) ($groupCounts[$f->id] ?? 0), 'products' => (int) ($featureProducts[$f->id] ?? 0), 'status' => $f->status, 'incomplete' => ! $f->complete, 'version' => $f->lock_version, 'group_id' => (string) $f->group_id, 'unit' => $f->unit ?? '', 'required' => $f->required, 'filterable' => $f->filterable, 'comparison' => $f->comparison, 'translations' => (object) $translations, 'options' => $opts, 'categories' => $categories->get($f->id, collect())->pluck('category_id')->map(strval(...))->all()];
        })->all();
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters): array
    {
        $q = DB::table('features as f')->join('feature_groups as g', 'g.id', '=', 'f.group_id');
        foreach (['type', 'group_id', 'required', 'filterable'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $q->where('f.'.$field, $filters[$field]);
            }
        }
        if (! empty($filters['status'])) {
            $filters['status'] === 'incomplete' ? $q->whereRaw('NOT '.$this->completeSql()) : $q->where('f.status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $locales = app(LocaleCatalog::class)->active()->pluck('id')->all();
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('feature_translations as ft')->whereColumn('ft.feature_id', 'f.id')->whereIn('ft.locale_id', $locales)->where('ft.name', 'ilike', $term));
        }
        if (! empty($filters['category_id'])) {
            $q->whereRaw($this->appliesSql('(SELECT id FROM categories WHERE id = ?)'), [$filters['category_id']]);
        }
        $page = $q->select('f.*')->selectRaw($this->completeSql().' as complete')->orderBy('g.position')->orderBy('g.id')->orderBy('f.position')->orderBy('f.id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $locales = app(LocaleCatalog::class)->active()->pluck('code', 'id')->all();
        $defaultLocale = app(LocaleCatalog::class)->defaultCode();
        $texts = DB::table('feature_translations')->whereIn('feature_id', $page->getCollection()->pluck('id'))->whereIn('locale_id', array_keys($locales))->get(['feature_id', 'locale_id', 'name'])->groupBy('feature_id');
        $page->setCollection($page->getCollection()->map(function ($f) use ($texts, $locales, $defaultLocale): array {
            $names = [];
            foreach ($texts->get($f->id, collect()) as $text) {
                $names[$locales[$text->locale_id]] = ['name' => $text->name];
            }

            return ['id' => (string) $f->id, 'name' => $this->label($names, 'name', $defaultLocale), 'code' => $f->code, 'type' => $f->type, 'status' => $f->status, 'required' => $f->required, 'incomplete' => ! $f->complete];
        }));

        return $page->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function ordering(): array
    {
        $default = $this->defaultId();
        $current = app(LocaleCatalog::class)->active()->firstWhere('code', app()->getLocale())->id ?? $default;

        return DB::table('features as f')->join('feature_groups as g', 'g.id', '=', 'f.group_id')
            ->leftJoin('feature_translations as current', fn ($join) => $join->on('current.feature_id', '=', 'f.id')->where('current.locale_id', $current))
            ->leftJoin('feature_translations as fallback', fn ($join) => $join->on('fallback.feature_id', '=', 'f.id')->where('fallback.locale_id', $default))
            ->selectRaw("f.id::text as id, f.group_id::text as group_id, f.code, f.status, COALESCE(current.name, fallback.name, '') as name")
            ->orderBy('g.position')->orderBy('g.id')->orderBy('f.position')->orderBy('f.id')->get()->map(fn ($row): array => (array) $row)->all();
    }

    /** @return array<string, mixed> */
    public function remediation(?string $feature): array
    {
        $q = $this->missing();
        if ($feature !== null) {
            $q->where('f.id', $feature);
        }
        $locale = $this->defaultId();

        return $q->leftJoin('product_translations as pt', fn ($join) => $join->on('pt.product_id', '=', 'p.id')->where('pt.locale_id', $locale))
            ->leftJoin('feature_translations as ft', fn ($join) => $join->on('ft.feature_id', '=', 'f.id')->where('ft.locale_id', $locale))
            ->selectRaw('p.id::text as product_id, p.sku, pt.name as product_name, f.id::text as feature_id, f.code, ft.name as feature_name')->orderBy('p.id')->orderBy('f.id')->paginate(25)->withQueryString()->toArray();
    }
}
