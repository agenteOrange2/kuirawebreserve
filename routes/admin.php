<?php

use App\Http\Controllers\Admin\AiAgentsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin (panel de plataforma · DB central)
|--------------------------------------------------------------------------
|
| Solo para el super-admin (rol platform-admin, spec §12): gestión de
| tenants (hoteles), planes y visión global. La operación de cada hotel
| vive en su subdominio (routes/tenant.php).
|
*/

Route::middleware(['auth', 'role:platform-admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::resource('tenants', TenantController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::get('tenants/{tenant}', [TenantController::class, 'show'])
        ->name('tenants.show');
    Route::patch('tenants/{tenant}/suspend', [TenantController::class, 'toggleSuspend'])
        ->name('tenants.suspend');
    Route::post('tenants/{tenant}/impersonate', [TenantController::class, 'impersonate'])
        ->name('tenants.impersonate');

    // Catálogo de planes: límites, precio e IA (aplican vía config('plans')).
    Route::get('planes', [PlanController::class, 'index'])->name('plans');
    Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
    Route::patch('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
    Route::delete('plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');

    // Agentes IA de plataforma: keys maestras + asignación por tenant.
    Route::get('agentes-ia', [AiAgentsController::class, 'index'])->name('ai');
    Route::post('ai-providers', [AiAgentsController::class, 'storeProvider'])->name('ai.providers.store');
    Route::patch('ai-providers/{platformAiProvider}', [AiAgentsController::class, 'updateProvider'])->name('ai.providers.update');
    Route::delete('ai-providers/{platformAiProvider}', [AiAgentsController::class, 'destroyProvider'])->name('ai.providers.destroy');
    Route::post('ai-providers/{platformAiProvider}/test', [AiAgentsController::class, 'testProvider'])->name('ai.providers.test');
    Route::patch('ai-tenants/{tenant}', [AiAgentsController::class, 'updateTenant'])->name('ai.tenants.update');

    // Canales Meta (WhatsApp/Messenger/Instagram) vinculados por hotel.
    Route::post('meta-channels', [\App\Http\Controllers\Admin\MetaChannelController::class, 'store'])->name('meta.store');
    Route::patch('meta-channels/{metaChannelLink}', [\App\Http\Controllers\Admin\MetaChannelController::class, 'update'])->name('meta.update');
    Route::delete('meta-channels/{metaChannelLink}', [\App\Http\Controllers\Admin\MetaChannelController::class, 'destroy'])->name('meta.destroy');

    // Compatibilidad con la URL vieja del starter.
    Route::redirect('/dashboard', '/admin');
});
