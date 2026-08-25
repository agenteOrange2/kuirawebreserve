<?php

use App\Http\Controllers\Admin\AiAgentsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\PlanProspectController;
use App\Http\Controllers\Admin\TenantAreaController;
use App\Http\Controllers\Admin\TenantController;
use App\Models\Tenant;
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
    // Ficha del hotel: portada + un área por sub-vista. Las sub-vistas
    // van ANTES de 'tenants/{tenant}' para que "plan" o "modulos" no se
    // resuelvan como un id de tenant.
    Route::prefix('tenants/{tenant}')->name('tenants.')->group(function () {
        Route::get('plan', [TenantAreaController::class, 'plan'])->name('plan');
        Route::get('modulos', [TenantAreaController::class, 'modules'])->name('modules');
        Route::get('equipo', [TenantAreaController::class, 'team'])->name('team');
        Route::get('asistente', [TenantAreaController::class, 'assistant'])->name('assistant');
        Route::get('canales', [TenantAreaController::class, 'channels'])->name('channels');
        Route::get('cobros', [TenantAreaController::class, 'payments'])->name('payments');
    });
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

    // Usuarios del propio panel de plataforma (BD central, rol platform-admin).
    Route::get('usuarios', [\App\Http\Controllers\Admin\AdminUserController::class, 'index'])
        ->name('users');
    Route::post('usuarios', [\App\Http\Controllers\Admin\AdminUserController::class, 'store'])
        ->name('users.store');
    Route::patch('usuarios/{user}', [\App\Http\Controllers\Admin\AdminUserController::class, 'update'])
        ->name('users.update');
    Route::delete('usuarios/{user}', [\App\Http\Controllers\Admin\AdminUserController::class, 'destroy'])
        ->name('users.destroy');

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

    // Servicios adicionales: catálogo con precios y contratación por hotel
    // (se cobran por encima del plan; sus módulos aplican vía hasModule).
    Route::get('servicios', [\App\Http\Controllers\Admin\AddonServiceController::class, 'index'])->name('services');
    Route::patch('addon-services/{addonService}', [\App\Http\Controllers\Admin\AddonServiceController::class, 'update'])->name('services.update');
    Route::patch('tenants/{tenant}/addon-services/{addonService}', [\App\Http\Controllers\Admin\AddonServiceController::class, 'updateTenant'])->name('tenants.addon-services');

    // Documentos comerciales que se envían a prospectos (antes de las rutas
    // con {planProspect} para que "documentos" no caiga en el parámetro).
    Route::get('prospectos/documentos', [\App\Http\Controllers\Admin\ProspectDocumentController::class, 'index'])->name('prospects.documents');
    Route::post('prospectos/documentos', [\App\Http\Controllers\Admin\ProspectDocumentController::class, 'store'])->name('prospects.documents.store');
    Route::patch('prospectos/documentos/{prospectDocument}', [\App\Http\Controllers\Admin\ProspectDocumentController::class, 'update'])->name('prospects.documents.update');
    Route::delete('prospectos/documentos/{prospectDocument}', [\App\Http\Controllers\Admin\ProspectDocumentController::class, 'destroy'])->name('prospects.documents.destroy');

    Route::get('prospectos', [PlanProspectController::class, 'index'])->name('prospects');
    Route::delete('prospectos', [PlanProspectController::class, 'destroyBulk'])->name('prospects.destroyBulk');
    Route::patch('prospectos/{planProspect}', [PlanProspectController::class, 'update'])->name('prospects.update');
    Route::post('prospectos/{planProspect}/enviar-documentos', [PlanProspectController::class, 'sendDocuments'])->name('prospects.sendDocuments');
    Route::patch('prospectos/{planProspect}/whatsapp-enviado', [PlanProspectController::class, 'markWhatsappSent'])->name('prospects.markWhatsapp');

    // Agentes IA de plataforma: keys maestras + asignación por tenant.
    Route::get('agentes-ia', [AiAgentsController::class, 'index'])->name('ai');
    Route::post('ai-providers', [AiAgentsController::class, 'storeProvider'])->name('ai.providers.store');
    Route::patch('ai-providers/{platformAiProvider}', [AiAgentsController::class, 'updateProvider'])->name('ai.providers.update');
    Route::delete('ai-providers/{platformAiProvider}', [AiAgentsController::class, 'destroyProvider'])->name('ai.providers.destroy');
    Route::post('ai-providers/{platformAiProvider}/test', [AiAgentsController::class, 'testProvider'])->name('ai.providers.test');
    Route::patch('ai-tenants/{tenant}', [AiAgentsController::class, 'updateTenant'])->name('ai.tenants.update');
    Route::get('ai-tenants/{tenant}/prompt', [AiAgentsController::class, 'promptPreview'])->name('ai.tenants.prompt');
    // El contexto del bot y los canales se mudaron a la ficha del hotel:
    // son de ESE hotel, no del catálogo de la plataforma. Las URLs viejas
    // siguen vivas para no romper ligas guardadas.
    Route::get('agentes-ia/{tenant}/contexto', fn (Tenant $tenant) => redirect()
        ->route('admin.tenants.assistant', $tenant))->name('ai.tenants.context');
    Route::get('agentes-ia/{tenant}/canales', fn (Tenant $tenant) => redirect()
        ->route('admin.tenants.channels', $tenant))->name('ai.channels');

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

    // Bots de Telegram por hotel (alta con token de BotFather).
    Route::post('telegram-channels', [\App\Http\Controllers\Admin\TelegramChannelController::class, 'store'])->name('telegram.store');
    Route::patch('telegram-channels/{telegramChannelLink}', [\App\Http\Controllers\Admin\TelegramChannelController::class, 'update'])->name('telegram.update');
    Route::delete('telegram-channels/{telegramChannelLink}', [\App\Http\Controllers\Admin\TelegramChannelController::class, 'destroy'])->name('telegram.destroy');
    Route::post('telegram-channels/{telegramChannelLink}/test', [\App\Http\Controllers\Admin\TelegramChannelController::class, 'test'])->name('telegram.test');

    // Cuentas de TikTok (Business Messaging) por hotel.
    Route::post('tiktok-channels', [\App\Http\Controllers\Admin\TiktokChannelController::class, 'store'])->name('tiktok.store');
    Route::patch('tiktok-channels/{tiktokChannelLink}', [\App\Http\Controllers\Admin\TiktokChannelController::class, 'update'])->name('tiktok.update');
    Route::delete('tiktok-channels/{tiktokChannelLink}', [\App\Http\Controllers\Admin\TiktokChannelController::class, 'destroy'])->name('tiktok.destroy');
    Route::post('tiktok-channels/{tiktokChannelLink}/test', [\App\Http\Controllers\Admin\TiktokChannelController::class, 'test'])->name('tiktok.test');

    // Compatibilidad con la URL vieja del starter.
    Route::redirect('/dashboard', '/admin');
});
