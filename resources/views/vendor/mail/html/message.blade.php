{{-- Encabezado y pie con la identidad del hotel (logo o nombre), no la de
     la plataforma: ver App\Mail\TenantBranding. --}}
@php($brand = \App\Mail\TenantBranding::resolve())
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$brand->url">
@if ($brand->logoUrl)
<img src="{{ $brand->logoUrl }}" alt="{{ $brand->name }}" style="max-height: 72px; max-width: 240px; width: auto; height: auto; margin: 0;">
@else
<span style="color: {{ $brand->accent }};">{{ $brand->name }}</span>
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
© {{ date('Y') }} {{ $brand->name }}. Todos los derechos reservados.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
