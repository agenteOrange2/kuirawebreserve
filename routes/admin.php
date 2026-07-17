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

    // Módulos por hotel: heredar del plan o forzar on/off (tenant_modules).
    Route::patch('tenants/{tenant}/modules', [TenantController::class, 'updateModule'])
        ->name('tenants.modules');
    Route::delete('tenants/{tenant}/module-requests/{module}', [TenantController::class, 'dismissModuleRequest'])
        ->name('tenants.module-requests.dismiss');

    // Usuarios (datos de acceso del personal) de cada hotel.
    Route::post('tenants/{tenant}/users', [\App\Http\Controllers\Admin\TenantUserController::class, 'store'])
        ->name('tenants.users.store');
    Route::patch('tenants/{tenant}/users/{userId}', [\App\Http\Controllers\Admin\TenantUserController::class, 'update'])
        ->name('tenants.users.update');
    Route::delete('tenants/{tenant}/users/{userId}', [\App\Http\Controllers\Admin\TenantUserController::class, 'destroy'])
        ->name('tenants.users.destroy');

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
    Route::get('ai-tenants/{tenant}/prompt', [AiAgentsController::class, 'promptPreview'])->name('ai.tenants.prompt');
    Route::get('agentes-ia/{tenant}/contexto', [AiAgentsController::class, 'context'])->name('ai.tenants.context');
    Route::get('agentes-ia/{tenant}/canales', [AiAgentsController::class, 'channels'])->name('ai.channels');

    // Apariencia de la plataforma (branding del login, nombre, favicon).
    Route::get('apariencia', [\App\Http\Controllers\Admin\BrandingController::class, 'index'])->name('branding');
    Route::post('apariencia', [\App\Http\Controllers\Admin\BrandingController::class, 'update'])->name('branding.update');

    // Métodos de pago: interruptores de plataforma + override por hotel.
    Route::get('payments', [\App\Http\Controllers\Admin\PaymentSettingsController::class, 'index'])->name('payments');
    Route::patch('payments/methods', [\App\Http\Controllers\Admin\PaymentSettingsController::class, 'updateMethod'])->name('payments.methods');
    Route::patch('tenants/{tenant}/payment-methods', [\App\Http\Controllers\Admin\PaymentSettingsController::class, 'updateTenant'])->name('payments.tenant');

    // Canales Meta (WhatsApp/Messenger/Instagram) vinculados por hotel.
    Route::post('meta-channels', [\App\Http\Controllers\Admin\MetaChannelController::class, 'store'])->name('meta.store');
    Route::patch('meta-channels/{metaChannelLink}', [\App\Http\Controllers\Admin\MetaChannelController::class, 'update'])->name('meta.update');
    Route::delete('meta-channels/{metaChannelLink}', [\App\Http\Controllers\Admin\MetaChannelController::class, 'destroy'])->name('meta.destroy');
    Route::post('meta-channels/{metaChannelLink}/diagnose', [\App\Http\Controllers\Admin\MetaChannelController::class, 'diagnose'])->name('meta.diagnose');
    Route::post('meta-channels/{metaChannelLink}/resubscribe', [\App\Http\Controllers\Admin\MetaChannelController::class, 'resubscribe'])->name('meta.resubscribe');

    // Compatibilidad con la URL vieja del starter.
    Route::redirect('/dashboard', '/admin');
});
