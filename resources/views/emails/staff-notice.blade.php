{{-- Aviso al hotel. El encabezado ya rotula al hotel (logo o nombre). --}}
<x-mail::message>
{{ $intro }}

<x-mail::panel>
@foreach ($rows as $label => $value)
**{{ $label }}:** {{ $value }}<br>
@endforeach
</x-mail::panel>

<x-mail::button :url="$url">
Abrir en el panel
</x-mail::button>

Aviso automático de {{ $hotelName }}. Los destinatarios se cambian en Ajustes → Avisos al hotel.
</x-mail::message>
