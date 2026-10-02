<x-mail::message audience="staff" preheader="Usa este enlace en los próximos {{ $minutes }} minutos para crear tu contraseña nueva.">
# Crea tu contraseña nueva

Hola{{ $name ? ' '.$name : '' }}, pidieron recuperar el acceso de **{{ $email }}** al panel de {{ $brandName }}.

<x-mail::button :url="$url">
Crear contraseña nueva
</x-mail::button>

<x-mail::notice>
El enlace sirve una sola vez y vence en **{{ $minutes }} minutos**. Si se te pasa, pide otro desde la pantalla de inicio de sesión.
</x-mail::notice>

Si no fuiste tú, ignora este correo: tu contraseña actual sigue funcionando y nadie puede cambiarla sin este enlace.

<x-slot:subcopy>
¿El botón no abre? Copia esta dirección en tu navegador:<br>
<span class="break-all">{{ $url }}</span>
</x-slot:subcopy>
</x-mail::message>
