@props(['audience' => 'guest', 'preheader' => null])
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
@if ($brand->isTenant && $audience === 'guest')
{{ $brand->name }}{{ $brand->address ? ' · '.$brand->address : '' }}{{ $brand->phone ? ' · '.$brand->phone : '' }}{{ $brand->email ? ' · '.$brand->email : '' }}
@elseif ($brand->isTenant)
Aviso de {{ $brand->name }} · Con la tecnología de {{ \App\Mail\TenantBranding::platformName() }}
@else
© {{ date('Y') }} {{ $brand->name }}
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
