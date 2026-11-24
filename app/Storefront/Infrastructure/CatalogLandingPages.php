<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\BrandQueries;
use App\Catalog\Infrastructure\FeatureQueries;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Infrastructure\Cache\StorefrontCache;
use App\Shared\Infrastructure\Content\RichTextSanitizer;
use App\Website\Domain\Service\MenuRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** One root URL owner: dedicated brands by default, or legacy feature-option landing pages. */
final class CatalogLandingPages
{
    /** Batch URL eligibility keeps brand carousels independent of the number of cards.
     * @param list<string> $codes
     * @return array<string,string> */
    public function brandUrls(array $codes): array
    {
        return app(GeneralSettings::class)->all()['brand_landing_pages'] ? $this->availableUrls($codes) : [];
    }

    /** @return array<string,mixed>|null */
    public function resolve(string $path, string $locale): ?array
    {
        return app(StorefrontCache::class)->remember('brands', ['landing', $path, $locale], fn () => $this->loadResolve($path, $locale));
    }

    /** @return array<string,mixed>|null */
    private function loadResolve(string $path, string $locale): ?array
    {
        $slug = substr($path, 1);
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,199}$/D', $slug) !== 1 || ! isset($this->availableUrls([$slug])[$slug])) {
            return null;
        }
        $brands = app(GeneralSettings::class)->all()['brand_landing_pages'];
        if ($brands) {
            $row = app(BrandQueries::class)->named($locale)->where('b.code', $slug)->where('b.status', 'active')->first();
            if ($row === null || trim($row->name) === '') {
                return null;
            }
            $name = $row->name;
            $description = $row->description;
            $image = $row->image_media_id;
            $alt = $row->image_alt ?: $name;
            $seo = ['title' => $name];
        } else {
            $locales = app(LocaleCatalog::class)->active();
            $default = $locales->firstWhere('is_default', true)->id;
            $current = $locales->firstWhere('code', $locale)->id ?? $default;
            $q = DB::table('feature_options as o')->join('features as f', 'f.id', 'o.feature_id')
                ->join('feature_option_translations as d', fn ($j) => $j->on('d.option_id', 'o.id')->where('d.locale_id', $default))
                ->leftJoin('feature_option_translations as c', fn ($j) => $j->on('c.option_id', 'o.id')->where('c.locale_id', $current))
                ->where('f.purpose', 'landing_pages')->where('f.status', 'active')->where('o.status', 'active')
                ->whereRaw(app(FeatureQueries::class)->completeSql())->whereRaw('length(btrim(d.label)) > 0')
                ->whereRaw('coalesce(c.slug, d.slug) = ?', [$slug])->select('o.*');
            foreach (['label', 'description', 'seo_title', 'seo_description', 'meta_keywords'] as $field) {
                $q->selectRaw("coalesce(nullif(c.$field, ''), d.$field, '') as $field");
            }
            // A localized slug wins over a different option falling back to the default-language slug.
            $row = $q->orderByRaw('CASE WHEN c.slug = ? THEN 0 ELSE 1 END', [$slug])->orderBy('o.id')->first();
            if ($row === null) {
                return null;
            }
            $name = $row->label;
            $description = $row->description;
            $image = $row->landing_image_media_id;
            $alt = $name;
            $seo = ['title' => $row->seo_title ?: $name, 'description' => $row->seo_description, 'keywords' => $row->meta_keywords];
        }
        $media = app(PublicMedia::class);
        $bannerId = $brands ? $row->banner_image_media_id : null;
        $images = $media->images([$image, $bannerId]);
        $banner = $media->url($images[$bannerId] ?? null, 'brand', (string) $row->id);
        $bannerLink = $brands ? $row->banner_link : null;

        return ['id' => (string) $row->id, 'source' => $brands ? 'brand' : 'feature_option', 'name' => $name, 'url' => '/'.$slug,
            'description' => app(RichTextSanitizer::class)->sanitize($description), 'image' => $media->url($images[$image] ?? null, $brands ? 'brand' : 'feature-option', (string) $row->id), 'image_alt' => $alt, 'banner' => $banner, 'banner_alt' => $brands ? ($row->banner_alt ?: $name) : '', 'banner_link' => $banner !== null && $bannerLink !== null && MenuRules::safeUrl($bannerLink) ? $bannerLink : null, 'seo' => $seo];
    }

    /** Reserve saved page/category URLs even if currently unavailable; changing source must never expose another page.
     * @param list<string> $slugs
     * @return array<string,string> */
    private function availableUrls(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }
        $reserved = DB::table('page_translations')->whereIn('slug', $slugs)->pluck('slug')->all();
        $reserved = [...$reserved, ...DB::table('category_translations as t')->join('categories as c', 'c.id', 't.category_id')->whereNull('c.parent_id')->whereIn('t.slug', $slugs)->pluck('t.slug')->all()];
        $urls = [];
        foreach (array_diff($slugs, $reserved) as $slug) {
            try {
                if (app('router')->getRoutes()->match(Request::create('/'.$slug))->isFallback) {
                    $urls[$slug] = '/'.$slug;
                }
            } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
                // A POST-only route also keeps its address.
            }
        }

        return $urls;
    }
}
