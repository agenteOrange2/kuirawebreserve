<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

// Fortify redirige aquí tras el login (config fortify.home); el panel de
// plataforma vive en /admin. En dominios de tenant, /dashboard lo resuelve
// routes/tenant.php hacia el plano.
Route::redirect('/dashboard', '/admin')->name('dashboard');

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
