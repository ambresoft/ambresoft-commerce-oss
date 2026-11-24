{!! $brand['name'] !!}
@if (! empty($greeting))
{!! $greeting !!}
@endif
@foreach ($introLines as $line)
{!! $line !!}

@endforeach
@if (! empty($actionText))
{!! $actionText !!}:
{!! $actionUrl !!}
@endif
@foreach ($outroLines as $line)

{!! $line !!}
@endforeach
@if (! empty($salutation))
{!! $salutation !!}
@endif
