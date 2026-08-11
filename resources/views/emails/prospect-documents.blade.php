<x-mail::message>
# {{ $brandName }}

Hola {{ $prospect->name }}, gracias por registrarte. Nos da gusto tu interés en:

@foreach ($serviceLabels as $label)
- {{ $label }}
@endforeach

Te compartimos la información de cada servicio. Va adjunta a este correo y también puedes abrirla desde estos enlaces:

<x-mail::panel>
@foreach ($documents as $document)
[{{ $document->title }}]({{ $document->publicUrl() }})<br>
@endforeach
</x-mail::panel>

En breve nos pondremos en contacto contigo para resolver cualquier duda y acompañarte en los siguientes pasos.

Gracias,<br>
{{ $brandName }}
</x-mail::message>
