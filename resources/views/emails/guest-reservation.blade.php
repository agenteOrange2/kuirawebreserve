{{-- El encabezado ya rotula al hotel (logo o nombre): aquí no se repite. --}}
<x-mail::message>
{{ $bodyText }}

<x-mail::panel>
**Reserva {{ $reservation->displayCode() }}**

{{ $reservation->roomType?->name ?? 'Habitación' }}<br>
Llegada: {{ $reservation->starts_at->locale('es')->isoFormat('dddd D [de] MMMM, HH:mm') }}<br>
Salida: {{ $reservation->ends_at->locale('es')->isoFormat('dddd D [de] MMMM, HH:mm') }}<br>
Total: ${{ number_format((float) $reservation->total_amount, 2) }}
</x-mail::panel>

Guarda tu código de reserva: te lo pueden pedir en recepción.

Gracias,<br>
{{ $hotelName }}
</x-mail::message>
