<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\PublicCatalog;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class StorefrontReels
{
    public function eligible(): Builder
    {
        $default = app(LocaleCatalog::class)->active()->firstWhere('is_default', true)->id;

        return DB::table('reels')->where('reels.status', 'active')->whereExists(fn ($q) => $q->selectRaw('1')->from('reel_translations')->whereColumn('reel_id', 'reels.id')->where('locale_id', $default)->whereRaw('length(btrim(name)) > 0'));
    }

    /** @param array<string,array<string,mixed>> $blocks
     * @param array{locale:string,customer:?string} $context
     * @return array<string,array<string,mixed>> */
    public function resolve(array $blocks, array $context): array
    {
        $since = now()->subDays((int) app(GeneralSettings::class)->all()['reels_days']);
        $selections = [];
        $automatic = [];
        foreach ($blocks as $id => $b) {
            $limit = $b['template'] === 'reel_single' ? 1 : (int) $b['settings']['limit'];
            $source = $b['settings']['source'];
            if ($source === 'manual') {
                $selections[$id] = array_slice($b['selections']['items'] ?? [], 0, $limit);
            } else {
                $automatic[$source] = max($automatic[$source] ?? 0, $limit);
            }
        }
        $rankings = [];
        foreach ($automatic as $source => $limit) {
            $q = $this->eligible();
            if ($source === 'most_viewed') {
                $views = DB::table('reel_views')->where('viewed_at', '>=', $since)->selectRaw('reel_id, count(*) as views')->groupBy('reel_id');
                $q->leftJoinSub($views, 'v', 'v.reel_id', 'reels.id')->orderByRaw('coalesce(v.views,0) desc');
            } else {
                $q->where('reels.created_at', '>=', $since);
            }
            $rankings[$source] = $q->orderByDesc('reels.created_at')->orderByDesc('reels.id')->limit($limit)->pluck('reels.id')->map(strval(...))->all();
        }
        foreach ($blocks as $id => $b) {
            $selections[$id] ??= array_slice($rankings[$b['settings']['source']] ?? [], 0, $b['template'] === 'reel_single' ? 1 : (int) $b['settings']['limit']);
        }
        $ids = array_values(array_unique(array_merge([], ...array_values($selections))));
        $cards = $this->cards($ids, $context['locale']);
        $result = [];
        foreach ($selections as $id => $picked) {
            $items = array_values(array_filter(array_map(fn ($id) => $cards[$id] ?? null, $picked)));
            if ($items !== []) {
                $result[$id] = ['items' => $items, 'display' => ['per_row' => $blocks[$id]['settings']['per_row'], 'navigation' => $blocks[$id]['settings']['navigation'], 'hide_arrows_mobile' => $blocks[$id]['settings']['hide_arrows_mobile'] ?? false]];
            }
        }

        return $result;
    }

    /** @param list<string> $ids
     * @return array<string,array<string,mixed>> */
    public function cards(array $ids, string $locale): array
    {
        $rows = $this->eligible()->whereIn('reels.id', $ids)->get();
        $media = app(PublicMedia::class);
        $images = $media->images($rows->pluck('cover_media_id')->all());
        $videos = $media->videos($rows->pluck('video_id')->all());
        $texts = app(BlockContent::class)->translations('reel_translations', 'reel_id', $rows->pluck('id')->all());
        $counts = DB::table('reel_views')->whereIn('reel_id', $rows->pluck('id'))->selectRaw('reel_id, count(*) as views')->groupBy('reel_id')->pluck('views', 'reel_id');
        $cards = [];
        foreach ($rows as $r) {
            if (! isset($images[$r->cover_media_id], $videos[$r->video_id])) {
                continue;
            }
            $cards[(string) $r->id] = ['id' => (string) $r->id, 'name' => app(BlockContent::class)->text($texts[$r->id] ?? [], $locale, 'name'), 'image' => $media->url($images[$r->cover_media_id], 'reel', (string) $r->id), 'views' => (int) ($counts[$r->id] ?? 0)];
        }

        return $cards;
    }

    /** @param array{locale:string,customer:?string} $context
     * @return array<string,mixed>|null */
    public function detail(string $id, array $context): ?array
    {
        $card = $this->cards([$id], $context['locale'])[$id] ?? null;
        if ($card === null) {
            return null;
        }
        $row = $this->eligible()->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $ids = DB::table('reel_products')->where('reel_id', $id)->orderBy('position')->pluck('product_id')->map(strval(...))->all();
        $products = app(BlockContent::class)->productCards($ids, $context, app(PublicCatalog::class)->listed($context['customer']));

        return [...$card, 'video' => '/storefront/reels/'.$id.'/video/'.$row->video_id, 'products' => array_values(array_filter(array_map(fn ($id) => $products['cards'][$id] ?? null, $ids))), 'currency_format' => $products['format']];
    }
}
