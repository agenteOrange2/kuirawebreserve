<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('admin/settings')->name('admin.settings.')->group(function () {
    Route::redirect('/', '/admin/settings/profile');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.update');

    Route::inertia('appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('two-factor', [TwoFactorAuthenticationController::class, 'show'])->name('two-factor.show');

    // SMTP de plataforma: solo el super-admin (los correos a prospectos salen de aquí).
    Route::middleware('role:platform-admin')->group(function () {
        Route::get('correo', [\App\Http\Controllers\Admin\EmailSettingsController::class, 'edit'])->name('email.edit');
        Route::patch('correo', [\App\Http\Controllers\Admin\EmailSettingsController::class, 'update'])->name('email.update');
        Route::post('correo/prueba', [\App\Http\Controllers\Admin\EmailSettingsController::class, 'test'])->name('email.test');
    });
});
