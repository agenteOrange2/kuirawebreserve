<x-mail::message>
# {{ $hotelName }}

{{ $bodyText }}

@if (count($details))
<x-mail::panel>
@if ($code)
**{{ $code }}**

@endif
@foreach ($details as $detail)
{{ $detail['label'] }}: {{ $detail['value'] }}<br>
@endforeach
</x-mail::panel>
@endif

Guarda tu folio: te lo pueden pedir en el hotel.

Gracias,<br>
{{ $hotelName }}
</x-mail::message>
