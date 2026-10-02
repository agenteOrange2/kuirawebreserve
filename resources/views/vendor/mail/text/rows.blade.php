@props(['rows' => [], 'title' => null])
@if ($title)
{{ $title }}
@endif
@foreach ($rows as $label => $value)
@if ($value !== null && $value !== '')
{{ $label }}: {{ $value }}
@endif
@endforeach
