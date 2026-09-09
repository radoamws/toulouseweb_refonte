<x-mail::message>
# {{ $heading }}

@foreach ($lines as $label => $value)
**{{ $label }}** : {{ $value ?: '—' }}<br>
@endforeach

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
{{ $actionText ?? "Voir dans l'administration" }}
</x-mail::button>
@endif

Merci,<br>
{{ config('app.name') }}
</x-mail::message>
