<?php

use App\Http\Controllers\LandingController;
use App\Http\Controllers\ProspectDocumentFileController;
use App\Http\Controllers\ProspectRegistrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('home');
Route::post('/solicitar-demo', [LandingController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('prospects.store');

// Registro de prospectos por QR (eventos) y documentos comerciales.
// No nombrar nada "register": Fortify lo reclama si se habilita registration.
Route::get('/registro', [ProspectRegistrationController::class, 'create'])
    ->name('prospects.register');
Route::post('/registro', [ProspectRegistrationController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('prospects.register.store');
Route::get('/documentos/{prospectDocument}', ProspectDocumentFileController::class)
    ->middleware('throttle:60,1')
    ->name('prospects.documents.file');

// Fortify redirige aquí tras el login (config fortify.home); el panel de
// plataforma vive en /admin. En dominios de tenant, /dashboard lo resuelve
// routes/tenant.php hacia el plano.
Route::redirect('/dashboard', '/admin')->name('dashboard');

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
