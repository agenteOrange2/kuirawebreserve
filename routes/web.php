<?php

use App\Http\Controllers\LandingController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('home');
Route::post('/solicitar-demo', [LandingController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('prospects.store');

// Fortify redirige aquí tras el login (config fortify.home); el panel de
// plataforma vive en /admin. En dominios de tenant, /dashboard lo resuelve
// routes/tenant.php hacia el plano.
Route::redirect('/dashboard', '/admin')->name('dashboard');

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
