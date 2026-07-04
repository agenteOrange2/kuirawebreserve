<?php

/*
|--------------------------------------------------------------------------
| Meta (WhatsApp Cloud API, Messenger, Instagram DM)
|--------------------------------------------------------------------------
|
| UNA sola app de Meta para toda la plataforma; cada hotel conecta su
| número/página (tabla central meta_channel_links). Entornos:
|
| - test: app de Meta en modo desarrollo (número de prueba de WhatsApp,
|   páginas/testers). Sin app_secret configurado NO se valida la firma
|   del webhook (útil con túneles). Todo el flujo funciona igual.
| - production: app aprobada (Business Verification + App Review);
|   app_secret OBLIGATORIO para validar X-Hub-Signature-256.
|
*/

return [
    'mode' => env('META_MODE', 'test'), // test | production

    'app_id' => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),

    // Token arbitrario que se pega igual aquí y en el dashboard de Meta al
    // suscribir el webhook (verificación GET con hub.challenge).
    'verify_token' => env('META_VERIFY_TOKEN', 'kuira-meta-webhook'),

    'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com/v21.0'),
];
