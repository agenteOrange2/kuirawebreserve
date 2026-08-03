<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Notificaciones push del panel
    |--------------------------------------------------------------------------
    |
    | Avisan al staff aunque el panel esté cerrado — es lo que la campana no
    | puede hacer por sí sola. Sin llaves VAPID la función queda apagada: el
    | navegador no ofrece suscribirse y no se intenta enviar nada.
    |
    | Las llaves se generan una sola vez con:
    |   php -r 'require "vendor/autoload.php";
    |           print_r(Minishlink\WebPush\VAPID::createVapidKeys());'
    |
    | La pública viaja al navegador; la privada NUNCA sale del servidor.
    |
    */

    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),

    // Contacto que exige el estándar para que los servicios de push (Google,
    // Mozilla, Apple) sepan a quién reclamarle si algo va mal.
    'subject' => env('VAPID_SUBJECT', env('APP_URL', 'https://kuirawebreserve.com')),

    // Vida del aviso en el servicio de push si el dispositivo está apagado.
    // Media hora: un mensaje de hace más no sirve de nada al mostrador.
    'ttl' => (int) env('VAPID_TTL', 1800),
];
