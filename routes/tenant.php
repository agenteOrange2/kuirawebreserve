<?php

declare(strict_types=1);

use App\Http\Controllers\Agent\AgentToolsController;
use App\Http\Controllers\Tenant\AgentPageController;
use App\Http\Controllers\Tenant\AgentPlaygroundController;
use App\Http\Controllers\Tenant\AgentTokenController;
use App\Http\Controllers\Tenant\AiProviderController;
use App\Http\Controllers\Tenant\AvailabilityController;
use App\Http\Controllers\Tenant\CashCutController;
use App\Http\Controllers\Tenant\CashCutsPageController;
use App\Http\Controllers\Tenant\CatalogPageController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\FloorPlanController;
use App\Http\Controllers\Tenant\GuestController;
use App\Http\Controllers\Tenant\GuestsPageController;
use App\Http\Controllers\Tenant\HotelSettingsPageController;
use App\Http\Controllers\Tenant\InboxController;
use App\Http\Controllers\Tenant\WebchatController;
use App\Http\Controllers\Tenant\IngredientController;
use App\Http\Controllers\Tenant\InventoryPageController;
use App\Http\Controllers\Tenant\OrderController;
use App\Http\Controllers\Tenant\PosPageController;
use App\Http\Controllers\Tenant\ProductController;
use App\Http\Controllers\Tenant\PropertyController;
use App\Http\Controllers\Tenant\RatePlanController;
use App\Http\Controllers\Tenant\ReservationController;
use App\Http\Controllers\Tenant\ReservationReportsController;
use App\Http\Controllers\Tenant\ReservationsPageController;
use App\Http\Controllers\Tenant\RoomController;
use App\Http\Controllers\Tenant\RoomHistoryController;
use App\Http\Controllers\Tenant\RoomShowController;
use App\Http\Controllers\Tenant\ShiftController;
use App\Http\Controllers\Tenant\ShiftAssignmentController;
use App\Http\Controllers\Tenant\ShiftsPageController;
use App\Http\Controllers\Tenant\ShiftTypeController;
use App\Http\Controllers\Tenant\UserController as TenantUserController;
use App\Http\Controllers\Tenant\UsersPageController;
use App\Http\Controllers\Tenant\RoomsPageController;
use App\Http\Controllers\Tenant\RoomTypeController;
use App\Http\Controllers\Tenant\StayController;
use App\Http\Controllers\Tenant\ZoneController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Rutas del panel de cada hotel. Solo responden en dominios de tenant
| (subdominios); el login lo aporta Fortify en modo universal.
|
*/

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    App\Http\Middleware\EnsureTenantIsActive::class,
])->name('tenant.')->group(function () {
    Route::get('/', function () {
        return auth()->check()
            ? redirect()->route('tenant.dashboard')
            : redirect('/login');
    })->name('home');

    // Webchat público del hotel (visitantes, sin login). La API va aparte
    // (stateless, al final del archivo) para no cargar sesión/CSRF.
    Route::get('/chat', [WebchatController::class, 'page'])->name('webchat');

    // Aterrizaje de impersonación (soporte de plataforma): token de un solo
    // uso emitido desde /admin; inicia sesión como el owner y redirige.
    Route::get('/impersonate/{token}', fn (string $token) => \Stancl\Tenancy\Features\UserImpersonation::makeResponse($token))
        ->name('impersonate');

    Route::middleware('auth')->group(function () {
        // Fortify aterriza aquí tras el login (config fortify.home).
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::middleware('can:rooms.view')->group(function () {
            Route::get('/plano', FloorPlanController::class)->name('plano');
            Route::get('/habitaciones', RoomsPageController::class)->name('rooms');
            Route::get('/habitaciones/{room}', RoomShowController::class)->name('rooms.show');
            Route::get('/habitaciones/{room}/history', RoomHistoryController::class)->name('rooms.history');
            Route::get('/catalogo', CatalogPageController::class)->name('catalog');
        });

        Route::get('/reservas', ReservationsPageController::class)
            ->middleware('can:reservations.view')
            ->name('reservations');

        // Reportes de reservas (resumen por periodo + PDF).
        Route::middleware('can:reservations.view')->group(function () {
            Route::get('/reservas/reportes', ReservationReportsController::class)
                ->name('reservations.reports');
            Route::get('/reservas/reportes/pdf', [ReservationReportsController::class, 'pdf'])
                ->name('reservations.reports.pdf');
        });

        Route::get('/inventario', InventoryPageController::class)
            ->middleware('can:inventory.manage')
            ->name('inventory');

        // CRM de huéspedes.
        Route::middleware('can:guests.view')->group(function () {
            Route::get('/huespedes', [GuestsPageController::class, 'index'])->name('guests');
            Route::get('/huespedes/{guest}', [GuestsPageController::class, 'show'])->name('guests.show');
        });
        Route::get('/huespedes/{guest}/documentos/{media}', [GuestController::class, 'showDocument'])
            ->middleware('can:guests.view-documents')
            ->name('guests.documents.show');

        Route::get('/pos', PosPageController::class)
            ->middleware('can:orders.manage')
            ->name('pos');

        Route::get('/cortes', CashCutsPageController::class)
            ->middleware('can:orders.manage')
            ->name('cashcuts');

        Route::get('/turnos', ShiftsPageController::class)
            ->middleware('can:orders.manage')
            ->name('shifts');

        Route::get('/usuarios', UsersPageController::class)
            ->middleware('can:users.manage')
            ->name('users');

        Route::get('/ajustes', HotelSettingsPageController::class)
            ->middleware('can:properties.manage')
            ->name('hotel-settings');

        Route::get('/asistente', AgentPageController::class)
            ->middleware('can:properties.manage')
            ->name('agent');

        Route::get('/bandeja', [InboxController::class, 'index'])
            ->middleware('can:reservations.view')
            ->name('inbox');
    });

    Route::middleware('auth')->prefix('api')->group(function () {
        Route::apiResource('properties', PropertyController::class)
            ->middleware('can:properties.manage');

        // FAQs del hotel (se administran en /ajustes; alimentan al bot).
        Route::apiResource('faqs', \App\Http\Controllers\Tenant\FaqController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware('can:properties.manage');

        Route::middleware('can:rooms.manage')->group(function () {
            Route::apiResource('zones', ZoneController::class)->except(['show']);
            Route::apiResource('room-types', RoomTypeController::class)->except(['show']);
        });

        Route::apiResource('rooms', RoomController::class)
            ->only(['index', 'show'])
            ->middleware('can:rooms.view');
        Route::apiResource('rooms', RoomController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware('can:rooms.manage');
        Route::patch('rooms/{room}/status', [RoomController::class, 'updateStatus'])
            ->middleware('can:rooms.update-status')
            ->name('rooms.update-status');

        // Tarifas (noche / bloque).
        Route::apiResource('rate-plans', RatePlanController::class)
            ->except(['show'])
            ->middleware('can:rooms.manage');

        // Disponibilidad y reservas (fase 2).
        Route::get('availability', AvailabilityController::class)
            ->middleware('can:reservations.view')
            ->name('availability');

        Route::middleware('can:reservations.view')->group(function () {
            Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
            Route::get('stays', [StayController::class, 'index'])->name('stays.index');
            Route::get('stays/{stay}/folio', [StayController::class, 'folio'])->name('stays.folio');
        });

        Route::middleware('can:reservations.manage')->group(function () {
            Route::post('reservations', [ReservationController::class, 'store'])->name('reservations.store');
            Route::patch('reservations/{reservation}', [ReservationController::class, 'update'])->name('reservations.update');
            Route::patch('reservations/{reservation}/confirm', [ReservationController::class, 'confirm'])->name('reservations.confirm');
            Route::patch('reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('reservations.cancel');
            Route::patch('reservations/{reservation}/check-in', [ReservationController::class, 'checkIn'])->name('reservations.check-in');
            Route::post('reservations/{reservation}/payments', [ReservationController::class, 'registerPayment'])->name('reservations.payments.store');
            Route::post('stays', [StayController::class, 'store'])->name('stays.store');
            Route::patch('stays/{stay}/check-out', [StayController::class, 'checkOut'])->name('stays.check-out');
        });

        // Inventario (fase 3): catálogo y stock.
        Route::middleware('can:inventory.manage')->group(function () {
            Route::apiResource('products', ProductController::class)->except(['show']);
            Route::post('products/{product}/movements', [ProductController::class, 'movement'])->name('products.movement');
            Route::apiResource('ingredients', IngredientController::class)->except(['show']);
            Route::post('ingredients/{ingredient}/movements', [IngredientController::class, 'movement'])->name('ingredients.movement');
        });

        // POS: ventas y cargo a habitación.
        Route::middleware('can:orders.manage')->group(function () {
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
            Route::post('cash-cuts', [CashCutController::class, 'store'])->name('cashcuts.store');
            Route::post('shifts', [ShiftController::class, 'store'])->name('shifts.store');
            Route::patch('shifts/{shift}/close', [ShiftController::class, 'close'])->name('shifts.close');
        });

        // Rol semanal y tipos de turno (planeación).
        Route::middleware('can:shifts.manage')->group(function () {
            Route::post('shift-types', [ShiftTypeController::class, 'store'])->name('shift-types.store');
            Route::patch('shift-types/{shiftType}', [ShiftTypeController::class, 'update'])->name('shift-types.update');
            Route::delete('shift-types/{shiftType}', [ShiftTypeController::class, 'destroy'])->name('shift-types.destroy');
            Route::post('shift-assignments/sync', [ShiftAssignmentController::class, 'sync'])->name('shift-assignments.sync');
            Route::post('shift-assignments/copy-week', [ShiftAssignmentController::class, 'copyWeek'])->name('shift-assignments.copy-week');
        });

        Route::middleware('can:users.manage')->group(function () {
            Route::post('users', [TenantUserController::class, 'store'])->name('users.store');
            Route::patch('users/{user}', [TenantUserController::class, 'update'])->name('users.update');
            Route::delete('users/{user}', [TenantUserController::class, 'destroy'])->name('users.destroy');
        });

        // Asistente IA: tokens y playground (solo owner).
        Route::middleware('can:properties.manage')->group(function () {
            Route::post('agent-tokens', [AgentTokenController::class, 'store'])->name('agent-tokens.store');
            Route::delete('agent-tokens/{tokenId}', [AgentTokenController::class, 'destroy'])->name('agent-tokens.destroy');
            Route::post('agent-playground', AgentPlaygroundController::class)->name('agent-playground');

            // Proveedores LLM del hotel (multitenant, cadena de fallback).
            Route::post('ai-providers', [AiProviderController::class, 'store'])->name('ai-providers.store');
            Route::patch('ai-providers/{aiProvider}', [AiProviderController::class, 'update'])->name('ai-providers.update');
            Route::delete('ai-providers/{aiProvider}', [AiProviderController::class, 'destroy'])->name('ai-providers.destroy');
            Route::post('ai-providers/{aiProvider}/test', [AiProviderController::class, 'test'])->name('ai-providers.test');
        });

        // Bandeja unificada de conversaciones.
        Route::middleware('can:reservations.view')->group(function () {
            Route::get('inbox/{conversation}', [InboxController::class, 'show'])->name('inbox.show');
        });
        Route::middleware('can:reservations.manage')->group(function () {
            Route::post('inbox/{conversation}/reply', [InboxController::class, 'reply'])->name('inbox.reply');
            Route::post('inbox/{conversation}/suggest', [InboxController::class, 'suggest'])->name('inbox.suggest');
            Route::patch('inbox/{conversation}', [InboxController::class, 'update'])->name('inbox.update');
            Route::patch('channels/{channel}', [InboxController::class, 'updateChannel'])->name('channels.update');
        });

        // CRM de huéspedes.
        Route::get('guests/search', [GuestController::class, 'search'])
            ->middleware('can:guests.view')
            ->name('guests.search');
        Route::middleware('can:guests.manage')->group(function () {
            Route::post('guests', [GuestController::class, 'store'])->name('guests.store');
            Route::patch('guests/{guest}', [GuestController::class, 'update'])->name('guests.update');
            Route::delete('guests/{guest}', [GuestController::class, 'destroy'])->name('guests.destroy');
            Route::post('guests/{guest}/documents', [GuestController::class, 'storeDocument'])->name('guests.documents.store');
            Route::delete('guests/{guest}/documents/{media}', [GuestController::class, 'destroyDocument'])->name('guests.documents.destroy');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Agent API (asistentes IA) — spec-pendientes §4.1
|--------------------------------------------------------------------------
|
| Stateless: Bearer token (Sanctum, ability "agent") emitido desde el panel.
| Sin sesión/CSRF. Reutiliza las mismas actions que el panel.
|
*/
Route::middleware([
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    App\Http\Middleware\EnsureTenantIsActive::class,
    'auth:sanctum',
    'abilities:agent',
    'throttle:60,1',
])->prefix('api/agent')->name('tenant.agent-api.')->group(function () {
    Route::get('policies', [AgentToolsController::class, 'policies'])->name('policies');
    Route::get('rate-plans', [AgentToolsController::class, 'ratePlans'])->name('rate-plans');
    Route::get('availability', [AgentToolsController::class, 'availability'])->name('availability');
    Route::get('reservations/{code}', [AgentToolsController::class, 'showReservation'])->name('reservations.show');
    Route::post('holds', [AgentToolsController::class, 'storeHold'])->name('holds.store');
});

// API pública del webchat: stateless (sin sesión/CSRF), identificada por UUID
// de conversación y protegida por throttle.
Route::middleware([
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    App\Http\Middleware\EnsureTenantIsActive::class,
    'throttle:30,1',
])->prefix('api/webchat')->name('tenant.webchat.')->group(function () {
    Route::post('start', [WebchatController::class, 'start'])->name('start');
    Route::get('{uuid}/messages', [WebchatController::class, 'messages'])->name('messages');
    Route::post('{uuid}/messages', [WebchatController::class, 'send'])->name('send');
});
