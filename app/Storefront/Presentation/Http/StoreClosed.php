<?php

namespace App\Storefront\Presentation\Http;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Infrastructure\PublicMedia;
use App\Storefront\Infrastructure\StorefrontAccess;
use App\Storefront\Infrastructure\ThemeColors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** The page shown instead of storefront pages while the store is closed (General settings). */
final class StoreClosed
{
    public static function response(Request $request): ?Response
    {
        $settings = app(GeneralSettings::class)->all();
        if (app(StorefrontAccess::class)->allowed($request)) {
            return null;
        }
        $message = $settings['closed_messages'][app()->getLocale()] ?? $settings['closed_messages'][app(LocaleCatalog::class)->defaultCode()] ?? __('settings.closed_default');

        // The store speaks for itself here: its own name, logo and colours, never the platform's.
        $company = DB::table('company_settings')->where('id', 1)->first(['name', 'logo_media_id']);
        $media = app(PublicMedia::class);
        $logo = $company?->logo_media_id === null ? null : $media->url($media->images([$company->logo_media_id])[$company->logo_media_id] ?? null, 'company', '1');
        $colors = app(ThemeColors::class)->all();

        $response = Inertia::render('StoreClosed', ['storeName' => trim((string) ($company->name ?? '')) ?: config('app.name'), 'logo' => $logo, 'colors' => ['primary' => $colors['primary'], 'on_primary' => $colors['on_primary'], 'background' => $colors['background'], 'text' => $colors['text']], 'message' => $message])->toResponse($request)->setStatusCode(503);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Retry-After', '3600');

        return $response;
    }
}
