{{-- Botón con el acento del hotel (TenantBranding) en línea: el tema CSS es
     uno para todos y no sabe el color de cada hotel. color: primary (acento),
     success, error o secondary (blanco con borde). --}}
@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
@php($brand = \App\Mail\TenantBranding::resolve())
@php($style = match ($color) {
    'primary' => "background-color: {$brand->accent}; border: 1px solid {$brand->accent}; color: #ffffff;",
    'secondary' => 'background-color: #ffffff; border: 1px solid #cbd5e1; color: #334155;',
    default => '',
})
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener" style="{{ $style }}">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
