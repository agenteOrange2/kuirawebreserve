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

    // Instagram vía "API con inicio de sesión de Instagram" (tokens IGAA…):
    // es una app anidada con SU propia clave secreta — los webhooks de esa
    // ruta se firman con esta, no con el app_secret de Facebook.
    'ig_app_secret' => env('META_IG_APP_SECRET'),

    // Host de la ruta Instagram Login (los tokens IGAA… no hablan con
    // graph.facebook.com).
    'ig_graph_url' => env('META_IG_GRAPH_URL', 'https://graph.instagram.com/v21.0'),

    // Token arbitrario que se pega igual aquí y en el dashboard de Meta al
    // suscribir el webhook (verificación GET con hub.challenge).
    'verify_token' => env('META_VERIFY_TOKEN', 'kuira-meta-webhook'),

    'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com/v21.0'),
];
