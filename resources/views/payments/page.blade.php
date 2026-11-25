<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('payments.page_title', ['number' => $number]) }} · {{ $store }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <style>
        :root { color-scheme: light; --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --primary: #047857; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; background: #f8fafc; color: var(--ink); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
        main { width: 100%; max-width: 30rem; background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 32px 28px; }
        .store { margin: 0 0 20px; font-size: 14px; font-weight: 600; color: var(--muted); }
        h1 { margin: 0; font-size: 24px; line-height: 1.25; }
        dl { margin: 24px 0 0; display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; }
        dt { color: var(--muted); }
        dd { margin: 0; text-align: right; font-weight: 600; }
        .notice { margin: 20px 0 0; padding: 12px 14px; border-radius: 10px; font-size: 15px; }
        .success { background: #ecfdf5; color: #065f46; }
        .info { background: #eff6ff; color: #1e3a8a; }
        .warning { background: #fffbeb; color: #92400e; }
        form { margin: 28px 0 0; }
        button { width: 100%; border: 0; border-radius: 10px; padding: 14px 16px; background: var(--primary); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        button:focus-visible { outline: 3px solid #a7f3d0; outline-offset: 2px; }
        .hint { margin: 12px 0 0; font-size: 13px; color: var(--muted); text-align: center; }
    </style>
</head>
<body>
<main>
    <p class="store">{{ $store }}</p>
    <h1>{{ __('payments.page_heading', ['number' => $number]) }}</h1>
    @if ($outcome !== null)
        <p class="notice {{ ['paid' => 'success', 'pending' => 'info'][$outcome] ?? 'warning' }}" role="status">{{ __('payments.page_outcome_'.$outcome, ['store' => $store]) }}</p>
    @endif
    <dl>
        @if ($outcome !== 'paid')
            <dt>{{ __('payments.page_amount') }}</dt>
            <dd>{{ $amount }}</dd>
        @endif
        @if ($method !== null)
            <dt>{{ __('payments.page_method') }}</dt>
            <dd>{{ $method }}</dd>
        @endif
    </dl>
    @if ($canPay)
        <form method="post" action="{{ $action }}">
            @csrf
            <button type="submit">{{ __('payments.page_pay', ['amount' => $amount]) }}</button>
        </form>
        <p class="hint">{{ __('payments.page_redirect_hint', ['method' => $method]) }}</p>
    @endif
</main>
</body>
</html>
