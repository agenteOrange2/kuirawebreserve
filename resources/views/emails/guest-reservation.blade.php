{{-- El encabezado ya rotula al hotel (logo o nombre): aquí no se repite. --}}
<x-mail::message :preheader="'Reserva '.$code.' · '.$rows['Llegada']">
{{ $bodyText }}

<x-mail::rows :title="'Reserva '.$code" :rows="$rows" />

@if ($lookupUrl)
<x-mail::button :url="$lookupUrl">
Consultar mi reserva
</x-mail::button>
@endif

@if ($mapsUrl)
<x-mail::button :url="$mapsUrl" color="secondary">
Cómo llegar
</x-mail::button>
@endif

Guarda tu código **{{ $code }}**: te lo pueden pedir en recepción.

Gracias,<br>
{{ $hotelName }}
</x-mail::message>
