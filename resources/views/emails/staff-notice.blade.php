{{-- Aviso al hotel. El encabezado ya rotula al hotel (logo o nombre). --}}
<x-mail::message audience="staff" :preheader="$intro">
{{ $intro }}

<x-mail::rows :rows="$rows" />

<x-mail::button :url="$url">
Abrir en el panel
</x-mail::button>

<x-slot:subcopy>
Aviso automático. Quién lo recibe se cambia en Ajustes → Avisos al hotel.
</x-slot:subcopy>
</x-mail::message>
