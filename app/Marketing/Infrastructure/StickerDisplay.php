<?php

namespace App\Marketing\Infrastructure;

use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\ProductCategories;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\PromotionRules;
use App\Marketing\Domain\Service\StickerRules;
use App\Reporting\Infrastructure\ReportOrders;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Fresh request-local batch evaluation. No persisted dynamic assignments or cross-request eligibility cache. */
final class StickerDisplay
{
    /** $products come from StickerProducts' eligibility query; $facts from its shared price/stock snapshot.
     * @param  Collection<int, \stdClass>  $products
     * @param  array<int|string, array<string, mixed>>  $facts
     * @return array<int|string, list<array<string, mixed>>>
     */
    public function forProducts(Collection $products, array $facts, string $locale, string $context = 'card'): array
    {
        if ($products->isEmpty() || ! in_array($context, ['card', 'detail'], true)) {
            return [];
        }
        $instant = now();
        $ids = $products->pluck('id')->all();
        $limits = app(StickerQueries::class)->limits();
        $limit = $limits[$context.'_limit'];
        if ($limit === 0) {
            return [];
        }
        $locales = app(LocaleCatalog::class);
        $activeLocales = $locales->active();
        $default = $activeLocales->firstWhere('is_default', true)->id;
        $current = $activeLocales->firstWhere('code', $locale)->id ?? $default;
        $rows = DB::table('stickers as s')
            ->leftJoin('sticker_translations as c', fn ($j) => $j->on('c.sticker_id', '=', 's.id')->where('c.locale_id', $current))
            ->leftJoin('sticker_translations as d', fn ($j) => $j->on('d.sticker_id', '=', 's.id')->where('d.locale_id', $default))
            ->leftJoin('media_files as m', 'm.id', '=', 's.image_media_id')
            ->where('s.status', 'active')->where('s.show_'.$context, true)
            ->where(fn ($q) => $q->whereNull('s.starts_at')->orWhere('s.starts_at', '<=', $instant))
            ->where(fn ($q) => $q->whereNull('s.ends_at')->orWhere('s.ends_at', '>', $instant))
            ->where(fn ($q) => $q->where('s.type', 'dynamic')->orWhereExists(fn ($a) => $a->selectRaw('1')->from('product_stickers as ps')->whereColumn('ps.sticker_id', 's.id')->whereIn('ps.product_id', $ids)))
            ->select('s.*', 'm.disk', 'm.storage_key', 'm.width', 'm.height')
            // Fallback is a whole locale row. Optional empty short text/tooltip stays deliberately empty.
            ->selectRaw('CASE WHEN c.text IS NOT NULL AND btrim(c.text) <> \'\' THEN c.text ELSE d.text END as display_text')
            ->selectRaw('CASE WHEN c.text IS NOT NULL AND btrim(c.text) <> \'\' THEN c.short_text ELSE d.short_text END as short_text')
            ->selectRaw('CASE WHEN c.text IS NOT NULL AND btrim(c.text) <> \'\' THEN c.tooltip ELSE d.tooltip END as tooltip')
            ->orderBy('s.priority')->orderBy('s.id')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $matches = $this->matcher($products, $rows, $facts);
        $result = [];
        $counts = [];
        $files = [];
        foreach ($rows as $s) {
            if (trim($s->display_text ?? '') === '') {
                continue;
            }
            if ($s->style === 'image') {
                $key = $s->storage_key;
                if ($s->disk !== 'local' || $key === null || ! ($files[$key] ??= Storage::disk('local')->exists($key))) {
                    continue;
                }
            }
            if (! $matches($s)) {
                continue;
            }
            foreach ($products as $p) {
                if (! $matches($s, $p)) {
                    continue;
                }
                $visibility = [];
                foreach (['mobile', 'tablet', 'desktop'] as $device) {
                    $visibility[$device] = $s->{'show_'.$device} && ($counts[$p->id][$device] ?? 0) < $limit;
                    if ($visibility[$device]) {
                        $counts[$p->id][$device] = ($counts[$p->id][$device] ?? 0) + 1;
                    }
                }
                if (! in_array(true, $visibility, true)) {
                    continue;
                }
                $result[$p->id][] = ['id' => (string) $s->id, 'style' => $s->style, 'text' => $context === 'card' && ($s->short_text ?? '') !== '' ? $s->short_text : $s->display_text, 'alt' => $s->display_text, 'tooltip' => $s->tooltip ?? '', 'placement' => $s->placement, 'visibility' => $visibility,
                    'image_media_id' => $s->style === 'image' ? (string) $s->image_media_id : null, 'width' => (int) $s->image_width, 'height' => $s->width ? max(1, (int) round($s->image_width * $s->height / $s->width)) : 1,
                    'background' => $s->background, 'color' => $s->color, 'border_color' => $s->border_color, 'border_width' => (int) $s->border_width, 'radius' => (int) $s->radius, 'padding' => (int) $s->padding, 'font_size' => (int) $s->font_size, 'alignment' => $s->alignment, 'css_class' => $s->css_class];
            }
        }

        return $result;
    }

    /** Products carrying each given sticker by its assignment or its conditions, whatever the display limits, devices
     * or card/detail switches: what a shopping filter on the sticker selects.
     *
     * @param  Collection<int, \stdClass>  $products
     * @param  array<int|string, array<string, mixed>>  $facts
     * @param  list<int|string>  $stickerIds
     * @return array<string, list<int>>
     */
    public function holders(Collection $products, array $facts, array $stickerIds): array
    {
        if ($products->isEmpty() || $stickerIds === []) {
            return [];
        }
        $rows = DB::table('stickers')->whereIn('id', $stickerIds)->get(['id', 'type', 'match']);
        $matches = $this->matcher($products, $rows, $facts);
        $result = [];
        foreach ($rows as $s) {
            $result[(string) $s->id] = $matches($s) ? $products->filter(fn (object $p): bool => $matches($s, $p))->pluck('id')->map(intval(...))->values()->all() : [];
        }

        return $result;
    }

    /** One batch read of what the stickers' rules need, as a test: with a sticker only, whether its definition can
     * apply at all; with a product too, whether that product carries it.
     *
     * @param  Collection<int, \stdClass>  $products
     * @param  Collection<int, \stdClass>  $rows
     * @param  array<int|string, array<string, mixed>>  $facts
     * @return \Closure(object, ?object=): bool
     */
    private function matcher(Collection $products, Collection $rows, array $facts): \Closure
    {
        $ids = $products->pluck('id')->all();
        $conditions = DB::table('sticker_conditions as c')->leftJoin('categories as cat', 'cat.id', '=', 'c.category_id')
            ->leftJoin('features as f', 'f.id', '=', 'c.feature_id')->leftJoin('feature_options as o', 'o.id', '=', 'c.option_id')
            ->whereIn('c.sticker_id', $rows->pluck('id'))->select('c.*', 'cat.status as category_status', 'f.type as feature_type', 'f.status as feature_status', 'o.feature_id as option_feature_id', 'o.status as option_status')->orderBy('c.id')->get()->groupBy('sticker_id');
        $manual = DB::table('product_stickers')->whereIn('product_id', $ids)->whereIn('sticker_id', $rows->pluck('id'))->get()->groupBy('product_id');
        $featureIds = $conditions->flatten(1)->pluck('feature_id')->filter()->unique()->all();
        $assignments = $featureIds === [] ? collect() : DB::table('product_feature_assignments')->whereIn('product_id', $ids)->whereIn('feature_id', $featureIds)->where('is_applicable', true)->get()->groupBy('product_id');
        $choices = $featureIds === [] ? collect() : DB::table('product_feature_choices')->whereIn('assignment_id', $assignments->flatten(1)->pluck('id'))->get()->groupBy('assignment_id');
        $assignedFeatures = $selectedChoices = [];
        foreach ($assignments->flatten(1) as $a) {
            $assignedFeatures[$a->product_id][$a->feature_id] = $a;
        }
        foreach ($choices->flatten(1) as $choice) {
            $selectedChoices[$choice->assignment_id][$choice->option_id] = true;
        }
        if ($conditions->flatten(1)->contains('type', 'sales')) {
            $sales = app(ReportOrders::class)->completedProductUnits($ids);
            foreach ($facts as $id => &$fact) {
                $fact['sales'] = (string) ($sales[$id] ?? 0);
            }
            unset($fact);
        }
        // A category condition matches the product's main or any extra category.
        $productCategories = app(ProductCategories::class)->all($ids);
        $ancestors = app(CategoryHierarchy::class)->activeAncestorsFor(array_values(array_unique(array_merge([], ...array_values($productCategories)))));
        $rules = app(PromotionRules::class);
        $productRules = app(StickerRules::class);
        $hiddenPrices = app(GeneralSettings::class)->pricesHidden();

        return function (object $s, ?object $p = null) use ($conditions, $manual, $assignedFeatures, $selectedChoices, $ancestors, $productCategories, $rules, $productRules, $hiddenPrices, $facts): bool {
            $cs = collect($conditions[$s->id] ?? []);
            if ($p === null) {
                // Deleted targets remain NULL tombstones. Even an OR cannot turn a broken definition into a label.
                return ! ($s->type === 'dynamic' && ($cs->isEmpty() || $cs->contains(fn (object $c): bool => ! $this->valid($c))))
                    && ! ($hiddenPrices && $cs->contains(fn (object $c): bool => in_array($c->type, ['price', 'discount_amount', 'discount_percent'], true)));
            }
            if ($p->status !== 'active' || $p->deleted_at !== null || ! isset($facts[$p->id])) {
                return false;
            }
            if ($s->type === 'manual') {
                return collect($manual[$p->id] ?? [])->contains('sticker_id', $s->id);
            }
            $results = [];
            foreach ($cs as $c) {
                $results[] = match ($c->type) {
                    'category' => collect($productCategories[(string) $p->id] ?? [])->contains(fn (string $category): bool => $c->include_descendants ? in_array((string) $c->category_id, $ancestors[$category] ?? [], true) : (string) $c->category_id === $category),
                    'feature' => $this->feature($c, $assignedFeatures[$p->id][$c->feature_id] ?? null, $selectedChoices),
                    default => $productRules->condition((array) $c, $facts[$p->id]),
                };
            }

            return $rules->matches($s->match, $results);
        };
    }

    private function valid(object $c): bool
    {
        if (! in_array($c->type, StickerRules::TYPES, true)) {
            return false;
        }
        if ($c->type === 'category') {
            return $c->category_id !== null && $c->category_status === 'active';
        }
        if ($c->type === 'feature') {
            return $c->feature_id !== null && $c->feature_status === 'active' && match ($c->feature_type) {
                'single', 'multiple' => $c->option_id !== null && $c->option_status === 'active' && (string) $c->option_feature_id === (string) $c->feature_id,
                'number' => $c->minimum !== null || $c->maximum !== null,
                'boolean' => $c->boolean_value !== null,
                default => false,
            };
        }

        return true;
    }

    /** @param array<int|string, array<int|string, bool>> $choices */
    private function feature(object $c, ?object $assignment, array $choices): bool
    {
        if ($assignment === null || $assignment->type !== $c->feature_type) {
            return false;
        }

        return match ($c->feature_type) {
            'single', 'multiple' => isset($choices[$assignment->id][$c->option_id]),
            'number' => $assignment->number_value !== null && app(PromotionRules::class)->range($assignment->number_value, $c->minimum, $c->maximum),
            'boolean' => $assignment->boolean_value !== null && (bool) $assignment->boolean_value === (bool) $c->boolean_value,
            default => false,
        };
    }
}
