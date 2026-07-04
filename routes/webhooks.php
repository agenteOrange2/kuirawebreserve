<?php

use App\Http\Controllers\Webhooks\MetaWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks (dominio central · stateless)
|--------------------------------------------------------------------------
|
| Sin sesión ni CSRF: Meta llama por servidor. La URL se pega en el
| dashboard de la app de Meta junto con META_VERIFY_TOKEN.
|
*/

Route::get('/webhooks/meta', [MetaWebhookController::class, 'verify'])->name('webhooks.meta.verify');
Route::post('/webhooks/meta', [MetaWebhookController::class, 'receive'])->name('webhooks.meta');
