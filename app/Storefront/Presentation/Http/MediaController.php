<?php

namespace App\Storefront\Presentation\Http;

use App\Catalog\Infrastructure\FeatureQueries;
use App\Catalog\Infrastructure\PublicCatalog;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Infrastructure\BlockContent;
use App\Storefront\Infrastructure\StorefrontAccess;
use App\Storefront\Infrastructure\StorefrontBlogs;
use App\Storefront\Infrastructure\StorefrontReels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MediaController
{
    public function __invoke(Request $request, string $kind, string $owner, string $media): StreamedResponse
    {
        // Guests retain the closed-page logo; staff preview still checks each media owner's public eligibility.
        abort_unless(app(StorefrontAccess::class)->allowed($request) || $kind === 'company', 404);
        $customer = $request->user() === null ? null : DB::table('customers')->where('user_id', $request->user()->getAuthIdentifier())->where('status', 'active')->value('id');
        $customer = $customer === null ? null : (string) $customer;
        $allowed = match ($kind) {
            'reel' => app(StorefrontReels::class)->eligible()->where('id', $owner)->where('cover_media_id', $media)->exists(),
            'product' => app(PublicCatalog::class)->products($customer)->where('products.id', $owner)->exists() && DB::table('product_images')->where('product_id', $owner)->where('media_file_id', $media)->exists(),
            'feature-option' => ! app(GeneralSettings::class)->all()['brand_landing_pages'] && DB::table('feature_options as o')->join('features as f', 'f.id', 'o.feature_id')->where('o.id', $owner)->where('o.status', 'active')->where('f.status', 'active')->where('f.purpose', 'landing_pages')->whereRaw(app(FeatureQueries::class)->completeSql())->where('o.landing_image_media_id', $media)->whereExists(fn ($q) => $q->selectRaw('1')->from('feature_option_translations as t')->whereColumn('t.option_id', 'o.id')->where('t.locale_id', app(FeatureQueries::class)->defaultId())->whereRaw('length(btrim(t.label)) > 0'))->exists(),
            'brand' => DB::table('brands')->where('id', $owner)->where('status', 'active')->where(fn ($q) => $q->where('image_media_id', $media)->orWhere('banner_image_media_id', $media))->exists(),
            'category' => app(PublicCatalog::class)->categories($customer)->where('categories.id', $owner)->where(fn ($q) => $q->where('image_media_id', $media)->orWhere('block_image_media_id', $media)->orWhere('banner_image_media_id', $media)->orWhere('menu_icon_media_id', $media))->exists(),
            'sticker' => DB::table('stickers')->where('id', $owner)->where('status', 'active')->where('image_media_id', $media)->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->exists(),
            'menu-item' => DB::table('website_menu_items as i')->join('website_menus as m', 'm.id', '=', 'i.menu_id')->where('i.id', $owner)->where('i.status', 'active')->where('m.status', 'active')->where('i.image_media_id', $media)->exists(),
            'blog-post' => app(StorefrontBlogs::class)->posts(app(StorefrontBlogs::class)->blogs()->pluck('blogs.id')->map(strval(...))->all(), false)->where('blog_posts.id', $owner)->where(fn ($q) => $q->where('blog_posts.cover_media_id', $media)->orWhere('blog_posts.cover_mobile_media_id', $media))->exists(),
            'social-icon' => DB::table('company_links')->where('id', $owner)->where('media_file_id', $media)->exists(),
            'payment-method' => DB::table('payment_methods')->where('id', $owner)->where('is_active', true)->where('image_media_id', $media)->exists(),
            'shipping-method' => DB::table('shipping_methods')->where('id', $owner)->where('is_active', true)->where('image_media_id', $media)->exists(),
            'payment-icon' => DB::table('company_payment_icons')->where('id', $owner)->where('media_file_id', $media)->exists(),
            'banner' => app(BlockContent::class)->eligibleBanners($customer)->where('id', $owner)->exists() && DB::table('banner_contents')->where('banner_id', $owner)->where(fn ($q) => $q->where('main_image_media_id', $media)->orWhere('background_image_media_id', $media))->exists(),
            'banner-video' => app(BlockContent::class)->eligibleBanners($customer)->where('id', $owner)->exists() && DB::table('banner_contents')->where('banner_id', $owner)->where('background_video_id', $media)->exists(),
            // A product carousel's picture: only the one its block shows.
            'block' => DB::table('storefront_blocks')->where('id', $owner)->where(fn ($q) => $q->where('image_media_id', $media)->orWhere('image_mobile_media_id', $media))->exists(),
            'company' => $owner === '1' && DB::table('company_settings')->where('id', 1)->where(fn ($q) => $q->where('logo_media_id', $media)->orWhere('logo_mobile_media_id', $media))->exists(),
            default => false,
        };
        abort_unless($allowed, 404);
        $video = $kind === 'banner-video';
        $file = DB::table($video ? 'media_videos' : 'media_files')->where('id', $media)->first();
        abort_unless($file !== null && $file->disk === 'local' && in_array($file->mime_type, $video ? ['video/mp4'] : ['image/png', 'image/jpeg', 'image/webp'], true) && Storage::disk('local')->exists($file->storage_key), 404);

        return Storage::disk('local')->response($file->storage_key, $video ? 'video.mp4' : 'image', ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => in_array($kind, ['company', 'payment-icon', 'payment-method', 'shipping-method'], true) ? 'private, max-age=31536000, immutable' : 'private, no-store']);
    }
}
