{{-- Encabezado y pie con la identidad del hotel (logo o nombre), no la de
     la plataforma: ver App\Mail\TenantBranding.
     audience: 'guest' (al huésped: firma solo el hotel, con su contacto) o
     'staff' (al equipo: firma el hotel y "con la tecnología de" la
     plataforma). preheader: la línea gris que el buzón enseña junto al
     asunto antes de abrir el correo. --}}
@props(['audience' => 'guest', 'preheader' => null])
@php($brand = \App\Mail\TenantBranding::resolve())
<x-mail::layout>
@if ($preheader)
<x-slot:preheader>{{ $preheader }}</x-slot:preheader>
@endif
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$brand->url">
@if ($brand->logoUrl)
<img src="{{ $brand->logoUrl }}" alt="{{ $brand->name }}" style="max-height: 64px; max-width: 220px; width: auto; height: auto; margin: 0;">
@else
<span style="color: {{ $brand->accent }}; font-size: 20px; font-weight: 600;">{{ $brand->name }}</span>
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
@if ($brand->isTenant && $audience === 'guest')
**{{ $brand->name }}**
@if ($brand->address)
<br>{{ $brand->address }}
@endif
@php($contact = array_filter([
    $brand->phone ? '<a href="tel:'.e(preg_replace('/[^\d+]/', '', $brand->phone)).'">'.e($brand->phone).'</a>' : null,
    $brand->email ? '<a href="mailto:'.e($brand->email).'">'.e($brand->email).'</a>' : null,
    $brand->website ? '<a href="'.e($brand->website).'">'.e(preg_replace('#^https?://(www\.)?#', '', rtrim($brand->website, '/'))).'</a>' : null,
]))
@if ($contact)
<br>{!! implode(' &nbsp;·&nbsp; ', $contact) !!}
@endif
@elseif ($brand->isTenant)
Aviso de **{{ $brand->name }}** · <span class="powered">Con la tecnología de {{ \App\Mail\TenantBranding::platformName() }}</span>
@else
© {{ date('Y') }} {{ $brand->name }}
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
