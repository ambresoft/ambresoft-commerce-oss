<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale ?? app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $subject }}</title></head>
<body style="margin:0; padding:0; background:#edf0ef; color:{{ $brand['colors']['text'] }}; font-family:Arial,Helvetica,sans-serif">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#edf0ef; padding:32px 12px">
    <tr><td align="center">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%; max-width:600px; background:{{ $brand['colors']['background'] }}; border-top:6px solid {{ $brand['colors']['primary'] }}; border-radius:12px">
            <tr><td align="center" style="padding:32px 24px 24px">
                @isset($logoHtml)
                    {!! $logoHtml !!}
                @elseif ($logoPath !== null)
                    <img src="{{ $message->embed($logoPath) }}" alt="{{ $brand['name'] }}" width="220" style="display:block; width:auto; max-width:220px; max-height:80px; height:auto; border:0">
                @else
                    <span style="font-size:24px; font-weight:bold">{{ $brand['name'] }}</span>
                @endif
            </td></tr>
            <tr><td style="padding:8px 32px 32px; font-size:16px; line-height:1.6">
                @yield('content')
            </td></tr>
        </table>
        <p style="margin:20px 0 0; color:#56615c; font-size:12px">{{ $brand['name'] }}</p>
    </td></tr>
</table>
</body>
</html>
