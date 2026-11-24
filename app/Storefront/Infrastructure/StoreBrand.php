<?php

namespace App\Storefront\Infrastructure;

use Illuminate\Support\Facades\DB;

/** The store's public identity for pages outside the layout editor (customer sign-in and registration). */
final class StoreBrand
{
    /** @return array{name:string,logo:?string,colors:array<string,string>,auth_header:array{background:string,text:string}} */
    public function all(): array
    {
        $company = DB::table('company_settings')->where('id', 1)->first(['name', 'logo_media_id']);
        $name = trim((string) ($company->name ?? '')) !== '' ? (string) $company->name : (string) config('app.name');
        $logo = null;
        if ($company?->logo_media_id !== null) {
            $images = app(PublicMedia::class)->images([$company->logo_media_id]);
            $logo = app(PublicMedia::class)->url($images[$company->logo_media_id] ?? null, 'company', '1')['url'] ?? null;
        }

        $colors = app(ThemeColors::class);
        $background = $colors->authHeader() ?? $colors->all()['primary'];

        // The sign-in header: its own colour (else the store's) with text that reads on it.
        return ['name' => $name, 'logo' => $logo, 'colors' => $colors->all(), 'auth_header' => ['background' => $background, 'text' => ThemeColors::textOn($background)]];
    }
}
