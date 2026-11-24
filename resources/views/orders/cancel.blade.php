<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('orders.cancel_page_title', ['number' => $number]) }} · {{ $store }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <style>
        :root { color-scheme: light; --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --danger: #b91c1c; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; background: #f8fafc; color: var(--ink); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
        main { width: 100%; max-width: 30rem; background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 32px 28px; }
        .store { margin: 0 0 20px; font-size: 14px; font-weight: 600; color: var(--muted); }
        h1 { margin: 0; font-size: 24px; line-height: 1.25; }
        p { margin: 16px 0 0; }
        .notice { padding: 12px 14px; border-radius: 10px; font-size: 15px; }
        .success { background: #ecfdf5; color: #065f46; }
        .warning { background: #fffbeb; color: #92400e; }
        form { margin: 28px 0 0; }
        button { width: 100%; border: 0; border-radius: 10px; padding: 14px 16px; background: var(--danger); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        button:focus-visible { outline: 3px solid #fecaca; outline-offset: 2px; }
    </style>
</head>
<body>
<main>
    <p class="store">{{ $store }}</p>
    <h1>{{ __('orders.cancel_page_heading', ['number' => $number]) }}</h1>
    @if ($cancelled)
        <p class="notice success" role="status">{{ __('orders.cancel_done') }}</p>
    @elseif ($message !== null)
        <p class="notice warning" role="alert">{{ $message }}</p>
    @elseif ($allowed)
        <p>{{ __('orders.cancel_confirm') }}</p>
        <form method="post">
            @csrf
            <button type="submit">{{ __('orders.cancel_button') }}</button>
        </form>
    @else
        <p class="notice warning" role="status">{{ __('orders.cancel_not_allowed') }}</p>
    @endif
</main>
</body>
</html>
