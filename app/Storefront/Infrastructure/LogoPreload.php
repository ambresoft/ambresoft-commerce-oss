<?php

namespace App\Storefront\Infrastructure;

use Illuminate\Support\Facades\DB;

/** Preload hints for the store logos, so the header logo is already in the browser cache when the page renders. */
final class LogoPreload
{
    /** @return list<array{href:string,media:?string}> */
    public function links(): array
    {
        $company = DB::table('company_settings')->where('id', 1)->first(['logo_media_id', 'logo_mobile_media_id']);
        if ($company === null || $company->logo_media_id === null) {
            return [];
        }
        $images = app(PublicMedia::class)->images([$company->logo_media_id, $company->logo_mobile_media_id]);
        $main = app(PublicMedia::class)->url($images[$company->logo_media_id] ?? null, 'company', '1');
        $mobile = app(PublicMedia::class)->url($images[$company->logo_mobile_media_id] ?? null, 'company', '1');

        // The same switch point as the header's <picture>: the mobile logo below 768 px, the main logo otherwise.
        return array_values(array_filter([
            $mobile === null ? null : ['href' => $mobile['url'], 'media' => '(max-width: 767px)'],
            $main === null ? null : ['href' => $main['url'], 'media' => $mobile === null ? null : '(min-width: 768px)'],
        ]));
    }
}
