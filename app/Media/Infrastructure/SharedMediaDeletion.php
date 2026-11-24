<?php

namespace App\Media\Infrastructure;

use App\Media\Infrastructure\Persistence\MediaFileRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SharedMediaDeletion
{
    // Media maintenance boundary; no browser deletion permission/interface added in Phase 4.
    public function assertUnreferenced(MediaFileRecord $media): void
    {
        if (DB::table('brands')->where('image_media_id', $media->id)->orWhere('banner_image_media_id', $media->id)->exists()) {
            throw ValidationException::withMessages(['media' => __('brands.media_referenced')]);
        }
        if (DB::table('reels')->where('cover_media_id', $media->id)->exists()) {
            throw ValidationException::withMessages(['media' => __('reels.media_referenced')]);
        }
        $ids = DB::table('product_images')->where('media_file_id', $media->id)->pluck('product_id')->all();
        if ($ids !== []) {
            throw ValidationException::withMessages(['media' => __('products.media_referenced', ['ids' => implode(', ', $ids)])]);
        }
        if (DB::table('stickers')->where('image_media_id', $media->id)->exists()) {
            throw ValidationException::withMessages(['media' => __('stickers.media_referenced')]);
        }
        if (DB::table('website_menu_items')->where('image_media_id', $media->id)->exists()) {
            throw ValidationException::withMessages(['media' => __('menus.media_referenced')]);
        }
        if (DB::table('company_links')->where('media_file_id', $media->id)->exists()) {
            throw ValidationException::withMessages(['media' => __('company.social_icon_referenced')]);
        }
        if (DB::table('company_payment_icons')->where('media_file_id', $media->id)->exists()) {
            throw ValidationException::withMessages(['media' => __('company.logo_referenced')]);
        }
        if (DB::table('categories')->where('image_media_id', $media->id)->orWhere('block_image_media_id', $media->id)->orWhere('banner_image_media_id', $media->id)->orWhere('menu_icon_media_id', $media->id)->exists()) {
            throw ValidationException::withMessages(['media' => __('products.media_category_reference')]);
        }
        if (DB::table('feature_options')->where('landing_image_media_id', $media->id)->exists()) {
            throw ValidationException::withMessages(['media' => __('features.media_variant_reference')]);
        }
        if (DB::table('feature_options')->where('swatch_image_media_id', $media->id)->exists()) {
            throw ValidationException::withMessages(['media' => __('features.media_swatch_reference')]);
        }
        if (DB::table('company_settings')->where('logo_media_id', $media->id)->orWhere('logo_mobile_media_id', $media->id)->orWhere('favicon_media_id', $media->id)->orWhere('logo_email_media_id', $media->id)->exists()) {
            throw ValidationException::withMessages(['media' => __('company.logo_referenced')]);
        }
        if (DB::table('banner_contents')->where('main_image_media_id', $media->id)->orWhere('background_image_media_id', $media->id)->exists()) {
            throw ValidationException::withMessages(['media' => __('banners.media_referenced')]);
        }
    }

    public function referenced(string $media): bool
    {
        return DB::table('reels')->where('cover_media_id', $media)->exists()
            || DB::table('brands')->where('image_media_id', $media)->orWhere('banner_image_media_id', $media)->exists()
            || DB::table('stickers')->where('image_media_id', $media)->exists()
            || DB::table('website_menu_items')->where('image_media_id', $media)->exists()
            || DB::table('product_images')->where('media_file_id', $media)->exists()
            || DB::table('company_payment_icons')->where('media_file_id', $media)->exists()
            || DB::table('company_links')->where('media_file_id', $media)->exists()
            || DB::table('categories')->where('image_media_id', $media)->orWhere('block_image_media_id', $media)->orWhere('banner_image_media_id', $media)->orWhere('menu_icon_media_id', $media)->exists()
            || DB::table('feature_options')->where('landing_image_media_id', $media)->exists()
            || DB::table('feature_options')->where('swatch_image_media_id', $media)->exists()
            || DB::table('company_settings')->where('logo_media_id', $media)->orWhere('logo_mobile_media_id', $media)->orWhere('favicon_media_id', $media)->orWhere('logo_email_media_id', $media)->exists()
            || DB::table('banner_contents')->where('main_image_media_id', $media)->orWhere('background_image_media_id', $media)->exists();
    }
}
