<x-mail::message audience="staff" preheader="Si fuiste tú, no tienes que hacer nada. Si no, recupera tu cuenta ahora.">
# Tu contraseña cambió

Hola{{ $name ? ' '.$name : '' }}, la contraseña de **{{ $email }}** en el panel de {{ $brandName }} se acaba de cambiar.

<x-mail::rows :rows="[
    'Cuándo' => ucfirst($when),
    'Cómo' => $how,
    'Dispositivo' => $device,
    'Dirección IP' => $ip,
]" />

Si fuiste tú, no tienes que hacer nada. Por seguridad cerramos las demás sesiones abiertas con la contraseña anterior.

<x-mail::notice>
**¿No fuiste tú?** Recupera tu cuenta ahora con el botón de abajo y avisa a la gerencia del hotel: alguien más conoce tu correo.
</x-mail::notice>

<x-mail::button :url="$forgotUrl" color="secondary">
Recuperar mi cuenta
</x-mail::button>
</x-mail::message>
