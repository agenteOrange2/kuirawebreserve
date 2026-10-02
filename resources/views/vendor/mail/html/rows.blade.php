{{-- Datos en renglones etiqueta / valor (folio, fechas, montos). Más
     legible que "Etiqueta: valor<br>" dentro de un panel. --}}
@props(['rows' => [], 'title' => null])
<table class="rows" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@if ($title)
<tr>
<td class="rows-title" colspan="2">{{ $title }}</td>
</tr>
@endif
@foreach ($rows as $label => $value)
@if ($value !== null && $value !== '')
<tr>
<td class="rows-label">{{ $label }}</td>
<td class="rows-value">{!! nl2br(e($value)) !!}</td>
</tr>
@endif
@endforeach
</table>
