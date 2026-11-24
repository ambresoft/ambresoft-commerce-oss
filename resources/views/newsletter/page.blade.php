<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('newsletters.page_title_'.$state) }} · {{ $store }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; background: #f8fafc; color: #0f172a; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
        main { width: 100%; max-width: 30rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 32px 28px; }
        .store { margin: 0 0 20px; font-size: 14px; font-weight: 600; color: #64748b; }
        h1 { margin: 0; font-size: 24px; line-height: 1.25; }
        p { margin: 16px 0 0; color: #334155; }
        form { margin: 24px 0 0; }
        button { width: 100%; border: 0; border-radius: 10px; padding: 14px 16px; background: #047857; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        button:focus-visible { outline: 3px solid #a7f3d0; outline-offset: 2px; }
    </style>
</head>
<body>
<main>
    <p class="store">{{ $store }}</p>
    <h1>{{ __('newsletters.page_title_'.$state) }}</h1>
    <p>{{ __('newsletters.page_text_'.$state, ['store' => $store]) }}</p>
    @if ($action !== null)
        <form method="post" action="{{ $action }}">
            @csrf
            <button type="submit">{{ __('newsletters.page_button_'.$state) }}</button>
        </form>
    @endif
</main>
</body>
</html>
