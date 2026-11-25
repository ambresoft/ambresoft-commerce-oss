@php
    // Shared view state must never carry storefront tags into identity, error or admin responses.
    if (! str_starts_with($page['component'] ?? '', 'Storefront/')) {
        unset($storefrontSeo);
    }
@endphp
@php($documentPalette = app(\App\Storefront\Infrastructure\StorefrontDocumentPalette::class)->current())
<!DOCTYPE html>
<html @if ($documentPalette) style="color-scheme: {{ $documentPalette['scheme'] }}; background-color: {{ $documentPalette['background'] }}" @endif lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app(\App\Localization\Infrastructure\LocaleCatalog::class)->active()->firstWhere('code', app()->getLocale())?->direction ?? 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="generator" content="Ambresoft Commerce">
        @if (! isset($storefrontSeo['head']) && (\App\Storefront\Infrastructure\SearchEngineVisibility::applies(request()) || \App\Storefront\Infrastructure\SearchEngineVisibility::storefrontIndexingDisabled(request())))
            <meta name="robots" content="{{ \App\Http\Middleware\KeepOutOfSearchEngines::DIRECTIVES }}">
        @elseif (! isset($storefrontSeo['head']) && ! empty($storefrontSeo) && ! $storefrontSeo['indexable'])
            <meta name="robots" content="noindex, nofollow">
        @endif
        @if (! empty($storefrontSeo))
            <title>{{ $storefrontSeo['title'] !== '' ? $storefrontSeo['title'] : app(\App\Storefront\Infrastructure\StoreBrand::class)->all()['name'] }}</title>
            @if (isset($storefrontSeo['head']))
                @foreach ($storefrontSeo['head'] as $tag)
                    {!! $tag !!}
                @endforeach
            @else
            @if ($storefrontSeo['description'] !== '')
                <meta name="description" content="{{ $storefrontSeo['description'] }}">
            @endif
            @if ($storefrontSeo['keywords'] !== '')
                <meta name="keywords" content="{{ $storefrontSeo['keywords'] }}">
            @endif
            @if (! empty($storefrontSeo['canonical']))
                <link rel="canonical" href="{{ $storefrontSeo['canonical'] }}">
            @endif
            @if (! empty($storefrontSeo['og']))
                @foreach ($storefrontSeo['og'] as $property => $value)
                    @if ($value !== null && $value !== '')
                        <meta property="og:{{ $property }}" content="{{ $value }}">
                    @endif
                @endforeach
            @endif
            @endif
        @else
            <title>{{ config('app.name') }}</title>
        @endif
        @php($storeIcon = request()->is('admin', 'admin/*') ? null : \Illuminate\Support\Facades\DB::table('company_settings')->where('id', 1)->value('favicon_media_id'))
        @if ($storeIcon)
            <link rel="icon" href="/storefront/favicon?v={{ $storeIcon }}">
            <link rel="apple-touch-icon" href="/storefront/favicon?v={{ $storeIcon }}">
        @else
            <link rel="icon" href="/favicon.ico" sizes="any">
            <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
            <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
            <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        @endif
        <link rel="manifest" href="/site.webmanifest">
        @foreach ($storefrontPreloads ?? [] as $preload)
            <link rel="preload" as="image" href="{{ $preload['href'] }}" media="{{ $preload['media'] }}" fetchpriority="high">
        @endforeach
        @unless (request()->is('admin', 'admin/*'))
            @foreach (app(\App\Storefront\Infrastructure\LogoPreload::class)->links() as $logo)
                <link rel="preload" as="image" href="{{ $logo['href'] }}" fetchpriority="high"@if ($logo['media']) media="{{ $logo['media'] }}"@endif>
            @endforeach
        @endunless
        {{-- The browser's dark/light preference belongs to the administration; storefront palettes come from enabled add-ons. --}}
        @if (request()->is('admin', 'admin/*'))
            <script>try{if(localStorage.getItem('cms-theme')==='dark')document.documentElement.dataset.theme='dark'}catch(e){}</script>
        @endif
        @if (! empty($storefrontSeo) && $storefrontSeo['custom_html'] !== '')
            {{-- Trusted HTML from staff with the layouts.manage capability (analytics tags and similar). --}}
            {!! $storefrontSeo['custom_html'] !!}
        @endif
        @viteReactRefresh
        @vite('resources/js/app.tsx')
        <x-inertia::head />
    </head>
    <body @if ($documentPalette) style="background-color: {{ $documentPalette['background'] }}" @endif>
        <x-inertia::app />
    </body>
</html>
