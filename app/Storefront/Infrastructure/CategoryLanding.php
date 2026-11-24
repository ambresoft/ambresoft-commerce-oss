<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\CategoryUrls;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Shared\Infrastructure\Content\RichTextSanitizer;
use App\Website\Infrastructure\BlogLinks;
use Illuminate\Support\Facades\DB;

/**
 * Category landing pages: a category marked as a landing page shows its visible subcategories as cards at its own
 * storefront address (the category path, e.g. /technologia/gaming). Only categories the visitor may see are found. A category that requires an age (its own or the
 * strictest of its parents') shows only an age gate until the visitor confirms that age; the subcategories and the
 * description are not sent before that. Product listing categories use the same trail, texts, banner and FAQs.
 */
final class CategoryLanding
{
    /** The visible landing page category (or, with $landing false, product listing category) at this address, if any. */
    public function resolve(string $path, string $locale, ?string $customer, bool $landing = true): ?string
    {
        $path = '/'.trim($path, '/');
        $slug = rawurldecode(substr($path, strrpos($path, '/') + 1));
        if ($path === '/' || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $slug) !== 1) {
            return null;
        }
        $ids = DB::table('category_translations')->where('slug', $slug)->distinct()->pluck('category_id')->all();
        if ($ids === []) {
            return null;
        }
        $visible = app(PublicCatalog::class)->categories($customer)->whereIn('categories.id', $ids)->where('categories.is_landing_page', $landing)
            ->pluck('categories.id')->map(strval(...))->all();
        foreach (app(CategoryUrls::class)->paths($visible, $locale) as $id => $url) {
            if ($url === $path) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * What the landing page's main content block shows: the category, its trail and its subcategory cards.
     *
     * @param  array{locale:string,customer:?string,age?:int}  $context  age: the age the visitor has confirmed, if any
     * @return array<string,mixed>
     */
    public function data(string $id, array $context): array
    {
        $locale = $context['locale'];
        $content = app(BlockContent::class);
        $trailIds = app(CategoryHierarchy::class)->pathIds($id);
        $children = app(PublicCatalog::class)->categories($context['customer'])->where('categories.parent_id', $id)
            ->orderBy('categories.position')->orderBy('categories.id')->get(['categories.id', 'categories.image_media_id', 'categories.block_image_media_id']);
        $ids = array_values(array_unique([...$trailIds, ...$children->pluck('id')->map(strval(...))->all()]));
        $texts = $content->translations('category_translations', 'category_id', $ids);
        $urls = app(CategoryUrls::class)->paths($ids, $locale);
        $media = app(PublicMedia::class);
        // The dedicated block image wins; the category image remains the fallback, as on category blocks.
        $images = $media->images([...$children->pluck('block_image_media_id')->all(), ...$children->pluck('image_media_id')->all()]);
        $items = [];
        foreach ($children as $c) {
            $name = $content->text($texts[$c->id] ?? [], $locale, 'name');
            if ($name === '') {
                continue;
            }
            $items[] = ['id' => (string) $c->id, 'name' => $name, 'url' => $urls[(string) $c->id] ?? null,
                'image' => $media->url($images[$c->block_image_media_id] ?? $images[$c->image_media_id] ?? null, 'category', (string) $c->id),
                'image_alt' => $content->text($texts[$c->id] ?? [], $locale, 'image_alt') ?: $name];
        }
        $trail = array_map(fn (string $c): array => ['name' => $content->text($texts[$c] ?? [], $locale, 'name'), 'url' => $urls[$c] ?? null], $trailIds);

        // The description is sanitized again on output, like product descriptions.
        $description = app(RichTextSanitizer::class)->sanitize($content->text($texts[$id] ?? [], $locale, 'description'));
        $gate = app(AgeGates::class)->forCategory($id, $locale, $context['age'] ?? 0);
        if ($gate !== null) {
            $items = [];
            $description = '';
        }
        $faqs = $gate === null ? app(StorefrontFaqs::class)->for('category', $id, $locale) : [];
        // Linked blog posts a visitor may see in a list, in the merchant's order, above the questions.
        $posts = [];
        if ($gate === null) {
            $blogs = app(StorefrontBlogs::class);
            $linked = app(BlogLinks::class)->assigned($id);
            $shown = $blogs->posts($blogs->blogs()->pluck('blogs.id')->map(strval(...))->all(), true)->whereIn('blog_posts.id', $linked)->pluck('blog_posts.id')->map(strval(...))->all();
            $posts = array_values($blogs->cards(array_values(array_intersect($linked, $shown)), $locale));
        }
        // The banner image sits under the title; it is the category's own picture, so the gate holds it back too.
        $bannerRow = $gate === null ? DB::table('categories')->where('id', $id)->first(['banner_image_media_id', 'banner_link']) : null;
        $bannerMedia = $bannerRow?->banner_image_media_id;
        $banner = $bannerMedia === null ? null : $media->url($media->images([$bannerMedia])[$bannerMedia] ?? null, 'category', $id);

        return ['age_gate' => $gate, 'id' => $id, 'name' => $content->text($texts[$id] ?? [], $locale, 'name'), 'description' => trim(strip_tags($description)) === '' ? '' : $description, 'url' => $urls[$id] ?? null, 'path' => $trail, 'banner' => $banner, 'banner_link' => $banner === null ? null : $bannerRow->banner_link, 'banner_alt' => $content->text($texts[$id] ?? [], $locale, 'image_alt') ?: $content->text($texts[$id] ?? [], $locale, 'name'), 'items' => $items, 'posts' => $posts, 'faqs' => $faqs];
    }
}
