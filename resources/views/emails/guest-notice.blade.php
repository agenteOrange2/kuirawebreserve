{{-- El encabezado ya rotula al hotel (logo o nombre): aquí no se repite. --}}
<x-mail::message :preheader="$code ? 'Folio '.$code : null">
{{ $bodyText }}

@if (count($details))
<x-mail::rows :title="$code ? 'Folio '.$code : null" :rows="collect($details)->mapWithKeys(fn ($detail) => [$detail['label'] => $detail['value']])->all()" />
@endif

@if ($code)
Guarda tu folio **{{ $code }}**: te lo pueden pedir en el hotel.
@endif

Gracias,<br>
{{ $hotelName }}
</x-mail::message>
