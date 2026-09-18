@php($brand = \App\Mail\TenantBranding::resolve())
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$brand->url">
{{ $brand->name }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
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
