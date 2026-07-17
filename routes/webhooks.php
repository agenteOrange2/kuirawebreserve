<?php

use App\Http\Controllers\Webhooks\EvolutionWebhookController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks (dominio central · stateless)
|--------------------------------------------------------------------------
|
| Sin sesión ni CSRF: Meta y Evolution llaman por servidor. La URL de Meta
| se pega en el dashboard de la app junto con META_VERIFY_TOKEN; la de
| Evolution se configura por instancia con su token único en la ruta.
|
*/

Route::get('/webhooks/meta', [MetaWebhookController::class, 'verify'])->name('webhooks.meta.verify');
Route::post('/webhooks/meta', [MetaWebhookController::class, 'receive'])->name('webhooks.meta');

// {event?} tolera instalaciones con "webhook por eventos" (Evolution agrega
// /messages-upsert y similares a la URL base).
Route::post('/webhooks/evolution/{token}/{event?}', [EvolutionWebhookController::class, 'receive'])
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->name('webhooks.evolution');

// Pasarelas de pago (spec-pagos §3.4): token → tenant + firma del proveedor.
// Mercado Pago también manda GET/IPN con query params en algunas configs.
Route::match(['get', 'post'], '/webhooks/payments/{token}', [\App\Http\Controllers\Webhooks\PaymentGatewayWebhookController::class, 'receive'])
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->name('webhooks.payments');
