@extends('mail.layout')

@section('content')
@if (! empty($greeting))
    <h1 style="font-size:22px; margin:0 0 24px">{{ $greeting }}</h1>
@endif
@foreach ($introLines as $line)
    <p style="margin:0 0 24px">{{ $line }}</p>
@endforeach
@if (! empty($actionText))
<table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto 24px"><tr><td align="center" bgcolor="{{ $brand['colors']['primary'] }}" style="border-radius:6px">
    <a href="{{ $actionUrl }}" style="display:inline-block; padding:14px 24px; color:{{ $brand['colors']['on_primary'] }}; background:{{ $brand['colors']['primary'] }}; border-radius:6px; font-weight:bold; text-decoration:none">{{ $actionText }}</a>
</td></tr></table>
@endif
@foreach ($outroLines as $line)
    <p style="margin:0 0 24px">{{ $line }}</p>
@endforeach
@if (! empty($salutation))
    <p style="margin:0 0 24px">{{ $salutation }}</p>
@endif
@if (! empty($actionUrl))
<p style="margin:0; font-size:12px; line-height:1.5; overflow-wrap:anywhere; word-break:break-all"><a href="{{ $actionUrl }}" style="color:{{ $brand['colors']['text'] }}">{{ $actionUrl }}</a></p>
@endif
@endsection
