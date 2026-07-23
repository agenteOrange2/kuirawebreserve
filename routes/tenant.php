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
use App\Http\Controllers\Tenant\EvolutionChannelController;
use App\Http\Controllers\Tenant\FloorPlanController;
use App\Http\Controllers\Tenant\GuestController;
use App\Http\Controllers\Tenant\GuestsPageController;
use App\Http\Controllers\Tenant\HotelSettingsPageController;
use App\Http\Controllers\Tenant\InboxController;
use App\Http\Controllers\Tenant\IngredientController;
use App\Http\Controllers\Tenant\InventoryPageController;
use App\Http\Controllers\Tenant\OrderController;
use App\Http\Controllers\Tenant\PaymentRequestController;
use App\Http\Controllers\Tenant\PosPageController;
use App\Http\Controllers\Tenant\ProductController;
use App\Http\Controllers\Tenant\PropertyController;
use App\Http\Controllers\Tenant\RatePlanController;
use App\Http\Controllers\Tenant\RatePlanSeasonController;
use App\Http\Controllers\Tenant\ReservationController;
use App\Http\Controllers\Tenant\ReservationReportsController;
use App\Http\Controllers\Tenant\ReservationsPageController;
use App\Http\Controllers\Tenant\RoomController;
use App\Http\Controllers\Tenant\RoomHistoryController;
use App\Http\Controllers\Tenant\RoomShowController;
use App\Http\Controllers\Tenant\RoomsPageController;
use App\Http\Controllers\Tenant\RoomTypeController;
use App\Http\Controllers\Tenant\ShiftAssignmentController;
use App\Http\Controllers\Tenant\ShiftController;
use App\Http\Controllers\Tenant\ShiftsPageController;
use App\Http\Controllers\Tenant\ShiftTypeController;
use App\Http\Controllers\Tenant\StayController;
use App\Http\Controllers\Tenant\UserController as TenantUserController;
use App\Http\Controllers\Tenant\UsersPageController;
use App\Http\Controllers\Tenant\WebchatController;
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

    // Wizard público de reservas (spec-motor-reservas-web E0): mismo
    // patrón que el webchat, detrás del módulo motor-web.
    Route::get('/reservar', \App\Http\Controllers\Tenant\BookingWizardController::class)
        ->middleware('module:motor-web')
        ->name('booking.wizard');

    // Consulta pública de reserva: código + teléfono → resumen y estado
    // de pago, con link de cobro vigente si hay uno.
    Route::get('/reserva', [\App\Http\Controllers\Tenant\BookingLookupController::class, 'page'])
        ->middleware('module:motor-web')
        ->name('booking.lookup');

    // Fotos públicas de tipos de habitación (las consume el wizard, sin
    // login): solo entrega la colección photos de RoomType.
    Route::get('/fotos/habitaciones/{mediaId}', [\App\Http\Controllers\Tenant\RoomTypePhotoController::class, 'show'])
        ->whereNumber('mediaId')
        ->name('room-type-photo');

    // Logo del hotel para el wizard (público, sin login): solo entrega la
    // colección wizard_logo de Property.
    Route::get('/fotos/logo', [\App\Http\Controllers\Tenant\PropertyLogoController::class, 'show'])
        ->name('property-logo');

    // Wizard público de experiencias (tours con horario y cupo propios) y
    // sus fotos — módulo `experiencias`, independiente del motor-web.
    Route::get('/reservar/experiencias', [\App\Http\Controllers\Tenant\ExperienceWizardController::class, 'page'])
        ->middleware('module:experiencias')
        ->name('booking.experiences');

    // Wizard público de GRUPOS: varias habitaciones, un folio GRP- y un
    // solo cobro consolidado.
    Route::get('/reservar/grupos', [\App\Http\Controllers\Tenant\GroupWizardController::class, 'page'])
        ->middleware('module:grupos')
        ->name('booking.groups');

    // Loader de widgets incrustables (script para WP o cualquier sitio):
    // inyecta el wizard elegido como iframe con alto autoajustable.
    Route::get('/widget.js', \App\Http\Controllers\Tenant\WidgetScriptController::class)
        ->name('widget-script');
    Route::get('/fotos/experiencias/{mediaId}', [\App\Http\Controllers\Tenant\ExperiencePhotoController::class, 'show'])
        ->whereNumber('mediaId')
        ->name('experience-photo');

    // Aterrizaje público del checkout de pasarela (success/cancel URL): el
    // huésped ve el estado de su pago; la verdad la pone el webhook.
    Route::get('/pago/{uuid}', \App\Http\Controllers\Tenant\PaymentReturnController::class)
        ->whereUuid('uuid')
        ->name('payment.return');

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

        // Calendario de ocupación (rack) como vista propia.
        Route::get('/reservas/calendario', ReservationsPageController::class)
            ->middleware('can:reservations.view')
            ->name('reservations.calendar');
        Route::redirect('/reservas/calendar', '/reservas/calendario');

        // Historial COMPLETO de reservas: /reservas solo muestra las
        // últimas 20; aquí vive todo con buscador, filtro y paginación.
        Route::get('/reservas/historial', \App\Http\Controllers\Tenant\ReservationHistoryPageController::class)
            ->middleware('can:reservations.view')
            ->name('reservations.history');

        // Apariencia del wizard público (logo, colores, modo oscuro de
        // /reservar) — área aislada bajo /reservas, separada a propósito
        // del comportamiento que vive en /ajustes/wizard.
        Route::get('/reservas/ajustes', \App\Http\Controllers\Tenant\WizardAppearancePageController::class)
            ->middleware(['can:properties.manage', 'module:motor-web'])
            ->name('reservations.settings');

        // Reportes de reservas (resumen por periodo + PDF).
        Route::middleware('can:reservations.view')->group(function () {
            Route::get('/reservas/reportes', ReservationReportsController::class)
                ->name('reservations.reports');
            Route::get('/reservas/reportes/pdf', [ReservationReportsController::class, 'pdf'])
                ->name('reservations.reports.pdf');
        });

        Route::get('/inventario', InventoryPageController::class)
            ->middleware(['can:inventory.manage', 'module:pos'])
            ->name('inventory');

        // Módulo Extras de reserva: catálogo de add-ons (decoración,
        // desayuno...) que el wizard ofrece y suman al total.
        Route::get('/extras', \App\Http\Controllers\Tenant\ExtrasPageController::class)
            ->middleware(['can:properties.manage', 'module:extras'])
            ->name('extras');

        // Módulo Experiencias: catálogo, sesiones con cupo y sus reservas.
        Route::get('/experiencias', \App\Http\Controllers\Tenant\ExperiencesPageController::class)
            ->middleware(['can:reservations.view', 'module:experiencias'])
            ->name('experiences');

        // Módulo Reservas grupales: varias habitaciones bajo un folio GRP-.
        Route::get('/grupos', \App\Http\Controllers\Tenant\GroupsPageController::class)
            ->middleware(['can:reservations.view', 'module:grupos'])
            ->name('groups');
        // Detalle del grupo: edición real (habitaciones, personas,
        // recorridos) y su dinero (cobros, pagado, pendiente).
        Route::get('/grupos/{group}', \App\Http\Controllers\Tenant\GroupShowController::class)
            ->middleware(['can:reservations.view', 'module:grupos'])
            ->whereNumber('group')
            ->name('groups.show');

        // CRM de huéspedes.
        Route::middleware('can:guests.view')->group(function () {
            Route::get('/huespedes', [GuestsPageController::class, 'index'])->name('guests');
            Route::get('/huespedes/{guest}', [GuestsPageController::class, 'show'])->name('guests.show');
        });
        Route::get('/huespedes/{guest}/documentos/{media}', [GuestController::class, 'showDocument'])
            ->middleware('can:guests.view-documents')
            ->name('guests.documents.show');

        Route::get('/pos', PosPageController::class)
            ->middleware(['can:orders.manage', 'module:pos'])
            ->name('pos');

        Route::get('/cortes', CashCutsPageController::class)
            ->middleware(['can:orders.manage', 'module:pos'])
            ->name('cashcuts');

        Route::get('/turnos', ShiftsPageController::class)
            ->middleware(['can:orders.manage', 'module:pos'])
            ->name('shifts');

        Route::get('/usuarios', UsersPageController::class)
            ->middleware('can:users.manage')
            ->name('users');

        Route::get('/ajustes', HotelSettingsPageController::class)
            ->middleware('can:properties.manage')
            ->name('hotel-settings');

        // Área aislada de datos generales: contacto, redes, horarios y
        // moneda, políticas y preguntas frecuentes — misma regla de superficie
        // propia que wizard/pagos/mails (feedback isolated-settings-areas).
        Route::get('/ajustes/general', \App\Http\Controllers\Tenant\GeneralSettingsPageController::class)
            ->middleware('can:properties.manage')
            ->name('general-settings');

        // Área aislada del wizard público (modalidad/huéspedes, extras del
        // POS, resumen de pago) — separada de Ajustes general a propósito.
        Route::get('/ajustes/wizard', \App\Http\Controllers\Tenant\WizardSettingsPageController::class)
            ->middleware(['can:properties.manage', 'module:motor-web'])
            ->name('wizard-settings');

        // Área aislada de métodos de pago: pasarelas, cuentas para
        // transferencia, confirmación automática y modo de pago del wizard.
        // Sin gate de módulo: las transferencias existen en todos los planes;
        // la sección de pasarelas se bloquea sola si falta el módulo cobros.
        Route::get('/ajustes/metodos-pago', \App\Http\Controllers\Tenant\PaymentMethodsPageController::class)
            ->middleware('can:properties.manage')
            ->name('payment-methods');

        // Área aislada de correo saliente: SMTP propio del hotel para
        // confirmaciones y avisos al huésped. Misma regla que wizard y
        // métodos de pago: config con superficie propia, página propia.
        Route::get('/ajustes/mails', \App\Http\Controllers\Tenant\MailSettingsPageController::class)
            ->middleware('can:properties.manage')
            ->name('mail-settings');

        // Integración con sitios (spec-integracion-sitios): tokens, catálogo
        // vivo e importador. Detrás del módulo motor-web.
        Route::get('/integracion', \App\Http\Controllers\Tenant\IntegrationPageController::class)
            ->middleware(['can:properties.manage', 'module:motor-web'])
            ->name('integration');

        Route::get('/asistente', AgentPageController::class)
            ->middleware('can:properties.manage')
            ->name('agent');

        Route::get('/asistente/contexto', \App\Http\Controllers\Tenant\AgentContextPageController::class)
            ->middleware('can:properties.manage')
            ->name('agent-context');

        // Aprendizajes del bot: área aislada, habilitada por el super-admin
        // (guidelines_editable) — mismo patrón que /asistente/contexto.
        Route::get('/asistente/aprendizajes', \App\Http\Controllers\Tenant\AgentLearningsPageController::class)
            ->middleware('can:reservations.manage')
            ->name('agent-learnings');

        Route::get('/bandeja', [InboxController::class, 'index'])
            ->middleware('can:reservations.view')
            ->name('inbox');

        // Conciliación de pasarelas y transferencias (spec-pagos §9.4).
        // Centro de pagos: transferencias por verificar, saldos vencidos,
        // links vivos y últimos pagos — todo el dinero en un solo lugar.
        Route::get('/pagos', \App\Http\Controllers\Tenant\PaymentsPageController::class)
            ->middleware('can:reservations.view')
            ->name('payments');
        Route::get('/cobros-en-linea', \App\Http\Controllers\Tenant\OnlinePaymentsPageController::class)
            ->middleware('can:reservations.view')
            ->name('online-payments');
    });

    Route::middleware('auth')->prefix('api')->group(function () {
        Route::apiResource('properties', PropertyController::class)
            ->middleware('can:properties.manage');

        // Logo del wizard público (/reservas/ajustes → Apariencia).
        Route::post('property-logo', [\App\Http\Controllers\Tenant\PropertyLogoController::class, 'store'])
            ->middleware('can:properties.manage')
            ->name('property-logo.store');
        Route::delete('property-logo', [\App\Http\Controllers\Tenant\PropertyLogoController::class, 'destroy'])
            ->middleware('can:properties.manage')
            ->name('property-logo.destroy');

        // FAQs del hotel (se administran en /ajustes; alimentan al bot).
        Route::apiResource('faqs', \App\Http\Controllers\Tenant\FaqController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware('can:properties.manage');

        // Solicitud de activación de un módulo (tarjeta Tu plan en /ajustes).
        Route::post('module-requests', \App\Http\Controllers\Tenant\ModuleRequestController::class)
            ->middleware('can:properties.manage')
            ->name('module-requests.store');

        // Prueba del SMTP del hotel (/ajustes/mails → Correo saliente).
        Route::post('smtp-test', \App\Http\Controllers\Tenant\SmtpTestController::class)
            ->middleware('can:properties.manage')
            ->name('smtp-test');

        // Integración con sitios: tokens + agente importador (validación
        // humana). Mismo gate que la página.
        Route::middleware(['can:properties.manage', 'module:motor-web'])->group(function () {
            Route::post('site-integrations', [\App\Http\Controllers\Tenant\SiteIntegrationController::class, 'store'])->name('site-integrations.store');
            Route::patch('site-integrations/{integrationId}', [\App\Http\Controllers\Tenant\SiteIntegrationController::class, 'update'])->name('site-integrations.update');
            Route::delete('site-integrations/{integrationId}', [\App\Http\Controllers\Tenant\SiteIntegrationController::class, 'destroy'])->name('site-integrations.destroy');

            Route::post('site-import', [\App\Http\Controllers\Tenant\SiteImportController::class, 'store'])->name('site-import.store');
            Route::post('site-import/{suggestion}/apply', [\App\Http\Controllers\Tenant\SiteImportController::class, 'apply'])->name('site-import.apply');
            Route::post('site-import/{suggestion}/discard', [\App\Http\Controllers\Tenant\SiteImportController::class, 'discard'])->name('site-import.discard');
        });

        Route::middleware('can:rooms.manage')->group(function () {
            Route::apiResource('zones', ZoneController::class)->except(['show']);
            Route::apiResource('room-types', RoomTypeController::class)->except(['show']);
            Route::post('room-types/{room_type}/duplicate', [RoomTypeController::class, 'duplicate'])
                ->name('room-types.duplicate');

            // Fotos del tipo (galería del wizard).
            Route::post('room-types/{room_type}/photos', [\App\Http\Controllers\Tenant\RoomTypePhotoController::class, 'store'])
                ->name('room-types.photos.store');
            Route::patch('room-types/{room_type}/photos/order', [\App\Http\Controllers\Tenant\RoomTypePhotoController::class, 'reorder'])
                ->name('room-types.photos.order');
            Route::delete('room-types/{room_type}/photos/{mediaId}', [\App\Http\Controllers\Tenant\RoomTypePhotoController::class, 'destroy'])
                ->whereNumber('mediaId')
                ->name('room-types.photos.destroy');
        });

        // Módulo Extras de reserva: catálogo de add-ons.
        Route::middleware(['can:properties.manage', 'module:extras'])->group(function () {
            Route::apiResource('extras', \App\Http\Controllers\Tenant\ExtraController::class)
                ->only(['index', 'store', 'update', 'destroy']);
        });

        // Módulo Experiencias: catálogo/sesiones/fotos son config del hotel;
        // registrar y mover reservas es operación diaria del staff.
        Route::middleware('module:experiencias')->group(function () {
            Route::middleware('can:properties.manage')->group(function () {
                Route::apiResource('experiences', \App\Http\Controllers\Tenant\ExperienceController::class)
                    ->only(['store', 'update', 'destroy']);
                Route::post('experiences/{experience}/sessions', [\App\Http\Controllers\Tenant\ExperienceSessionController::class, 'store'])->name('experiences.sessions.store');
                Route::patch('experiences/{experience}/sessions/{session}', [\App\Http\Controllers\Tenant\ExperienceSessionController::class, 'update'])->name('experiences.sessions.update');
                Route::delete('experiences/{experience}/sessions/{session}', [\App\Http\Controllers\Tenant\ExperienceSessionController::class, 'destroy'])->name('experiences.sessions.destroy');
                // Programación semanal: flota de vehículos (de la propiedad)
                // y horarios recurrentes por experiencia.
                Route::apiResource('experience-vehicles', \App\Http\Controllers\Tenant\ExperienceVehicleController::class)
                    ->only(['store', 'update', 'destroy'])
                    ->parameters(['experience-vehicles' => 'vehicle']);
                Route::post('experiences/{experience}/slots', [\App\Http\Controllers\Tenant\ExperienceSlotController::class, 'store'])->name('experiences.slots.store');
                Route::patch('experiences/{experience}/slots/{slot}', [\App\Http\Controllers\Tenant\ExperienceSlotController::class, 'update'])->name('experiences.slots.update');
                Route::delete('experiences/{experience}/slots/{slot}', [\App\Http\Controllers\Tenant\ExperienceSlotController::class, 'destroy'])->name('experiences.slots.destroy');
                Route::post('experiences/{experience}/photos', [\App\Http\Controllers\Tenant\ExperiencePhotoController::class, 'store'])->name('experiences.photos.store');
                Route::patch('experiences/{experience}/photos/order', [\App\Http\Controllers\Tenant\ExperiencePhotoController::class, 'reorder'])->name('experiences.photos.order');
                Route::delete('experiences/{experience}/photos/{mediaId}', [\App\Http\Controllers\Tenant\ExperiencePhotoController::class, 'destroy'])
                    ->whereNumber('mediaId')
                    ->name('experiences.photos.destroy');
            });
            Route::middleware('can:reservations.manage')->group(function () {
                Route::post('experience-bookings', [\App\Http\Controllers\Tenant\ExperienceBookingController::class, 'store'])->name('experience-bookings.store');
                // Borrado en masa del historial (canceladas/completadas).
                Route::delete('experience-bookings', [\App\Http\Controllers\Tenant\ExperienceBookingController::class, 'destroyBulk'])->name('experience-bookings.destroy-bulk');
                Route::patch('experience-bookings/{booking}/status', [\App\Http\Controllers\Tenant\ExperienceBookingController::class, 'updateStatus'])->name('experience-bookings.status');
                Route::post('experience-bookings/{booking}/payment-request', [\App\Http\Controllers\Tenant\ExperienceBookingController::class, 'issuePayment'])->name('experience-bookings.payment-request');
            });
        });

        // Módulo Reservas grupales: alta todo-o-nada y cancelación de grupo.
        Route::middleware(['can:reservations.manage', 'module:grupos'])->group(function () {
            Route::post('group-reservations', [\App\Http\Controllers\Tenant\GroupReservationController::class, 'store'])->name('group-reservations.store');
            Route::post('group-reservations/{group}/cancel', [\App\Http\Controllers\Tenant\GroupReservationController::class, 'cancel'])->name('group-reservations.cancel');
            Route::patch('group-reservations/{group}', [\App\Http\Controllers\Tenant\GroupReservationController::class, 'update'])->name('group-reservations.update');
            Route::delete('group-reservations/{group}', [\App\Http\Controllers\Tenant\GroupReservationController::class, 'destroy'])->name('group-reservations.destroy');
            // Edición real del grupo: agregar habitaciones/recorridos y
            // emitir el cobro consolidado desde el panel.
            Route::post('group-reservations/{group}/rooms', [\App\Http\Controllers\Tenant\GroupReservationController::class, 'addRooms'])->name('group-reservations.rooms');
            Route::post('group-reservations/{group}/experiences', [\App\Http\Controllers\Tenant\GroupReservationController::class, 'addExperience'])->name('group-reservations.experiences');
            Route::post('group-reservations/{group}/payment-request', [\App\Http\Controllers\Tenant\GroupReservationController::class, 'issuePayment'])->name('group-reservations.payment-request');
        });

        Route::apiResource('rooms', RoomController::class)
            ->only(['index', 'show'])
            ->middleware('can:rooms.view');
        Route::apiResource('rooms', RoomController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware('can:rooms.manage');
        // Alta guiada (spec-plan-maestro E3): rango masivo, habitación única
        // (tipo + tarifa + habitación) y duplicado.
        Route::middleware('can:rooms.manage')->group(function () {
            // Borrado en masa (path propio para no chocar con DELETE rooms/{room}).
            Route::delete('rooms/bulk', [RoomController::class, 'destroyBulk'])->name('rooms.destroy-bulk');
            Route::post('rooms/bulk', [RoomController::class, 'storeBulk'])->name('rooms.bulk');
            Route::post('rooms/single-unit', [RoomController::class, 'storeSingleUnit'])->name('rooms.single-unit');
            Route::post('rooms/{room}/duplicate', [RoomController::class, 'duplicate'])->name('rooms.duplicate');
        });
        Route::patch('rooms/{room}/status', [RoomController::class, 'updateStatus'])
            ->middleware('can:rooms.update-status')
            ->name('rooms.update-status');

        // Tarifas (noche / bloque).
        Route::apiResource('rate-plans', RatePlanController::class)
            ->except(['show'])
            ->middleware('can:rooms.manage');

        // Temporadas y promos por tarifa (spec-motor-reservas-web E0.5).
        Route::apiResource('rate-plans.seasons', RatePlanSeasonController::class)
            ->except(['show'])
            ->middleware('can:rooms.manage');

        // Disponibilidad y reservas (fase 2).
        Route::get('availability', AvailabilityController::class)
            ->middleware('can:reservations.view')
            ->name('availability');

        Route::middleware('can:reservations.view')->group(function () {
            Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
            // Rack de ocupación habitaciones × días (spec-plan-maestro E4).
            Route::get('reservations/rack', \App\Http\Controllers\Tenant\ReservationRackController::class)->name('reservations.rack');
            Route::get('stays', [StayController::class, 'index'])->name('stays.index');
            Route::get('stays/{stay}/folio', [StayController::class, 'folio'])->name('stays.folio');
        });

        Route::middleware('can:reservations.manage')->group(function () {
            Route::post('reservations', [ReservationController::class, 'store'])->name('reservations.store');
            Route::patch('reservations/{reservation}', [ReservationController::class, 'update'])->name('reservations.update');
            Route::patch('reservations/{reservation}/confirm', [ReservationController::class, 'confirm'])->name('reservations.confirm');
            Route::patch('reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('reservations.cancel');
            Route::patch('reservations/{reservation}/check-in', [ReservationController::class, 'checkIn'])->name('reservations.check-in');
            // Borrado en masa desde el Historial (solo estados terminales).
            Route::delete('reservations', [ReservationController::class, 'destroyBulk'])->name('reservations.destroy-bulk');
            Route::post('reservations/{reservation}/payments', [ReservationController::class, 'registerPayment'])->name('reservations.payments.store');
            // Cobros en línea desde el panel (spec-pagos §7.5): link/transferencia.
            Route::post('reservations/{reservation}/payment-request', [ReservationController::class, 'issuePayment'])->name('reservations.payment-request');
            Route::delete('reservations/{reservation}/payment-request/{paymentRequest}', [ReservationController::class, 'cancelPayment'])->name('reservations.payment-request.cancel');
            // Reembolsos (spec-pagos F4): siempre decisión humana.
            Route::post('reservations/{reservation}/payments/{payment}/refund', [ReservationController::class, 'refundPayment'])->name('reservations.payments.refund');
            Route::post('stays', [StayController::class, 'store'])->name('stays.store');
            Route::patch('stays/{stay}/check-out', [StayController::class, 'checkOut'])->name('stays.check-out');
        });

        // Inventario (fase 3): catálogo y stock.
        Route::middleware(['can:inventory.manage', 'module:pos'])->group(function () {
            Route::apiResource('products', ProductController::class)->except(['show']);
            Route::post('products/{product}/movements', [ProductController::class, 'movement'])->name('products.movement');
            Route::apiResource('ingredients', IngredientController::class)->except(['show']);
            Route::post('ingredients/{ingredient}/movements', [IngredientController::class, 'movement'])->name('ingredients.movement');
        });

        // POS: ventas y cargo a habitación.
        Route::middleware(['can:orders.manage', 'module:pos'])->group(function () {
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
            Route::post('cash-cuts', [CashCutController::class, 'store'])->name('cashcuts.store');
            Route::post('shifts', [ShiftController::class, 'store'])->name('shifts.store');
            Route::patch('shifts/{shift}/close', [ShiftController::class, 'close'])->name('shifts.close');
        });

        // Rol semanal y tipos de turno (planeación).
        Route::middleware(['can:shifts.manage', 'module:pos'])->group(function () {
            Route::post('shift-types', [ShiftTypeController::class, 'store'])->name('shift-types.store');
            Route::patch('shift-types/{shiftType}', [ShiftTypeController::class, 'update'])->name('shift-types.update');
            Route::delete('shift-types/{shiftType}', [ShiftTypeController::class, 'destroy'])->name('shift-types.destroy');
            Route::post('shift-assignments/sync', [ShiftAssignmentController::class, 'sync'])->name('shift-assignments.sync');
            Route::post('shift-assignments/copy-week', [ShiftAssignmentController::class, 'copyWeek'])->name('shift-assignments.copy-week');
        });

        Route::middleware('can:users.manage')->group(function () {
            Route::post('users', [TenantUserController::class, 'store'])->name('users.store');
            Route::delete('users', [TenantUserController::class, 'destroyBulk'])->name('users.destroy-bulk');
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

            // Pasarelas de pago del hotel (llaves propias, spec-pagos §9.1).
            // Detrás del módulo cobros: sin él no se conectan pasarelas (las
            // transferencias con verificación siguen en todos los planes).
            Route::middleware('module:cobros')->group(function () {
                Route::post('payment-gateways', [\App\Http\Controllers\Tenant\PaymentGatewayController::class, 'store'])->name('payment-gateways.store');
                Route::patch('payment-gateways/{linkId}', [\App\Http\Controllers\Tenant\PaymentGatewayController::class, 'update'])->name('payment-gateways.update');
                Route::delete('payment-gateways/{linkId}', [\App\Http\Controllers\Tenant\PaymentGatewayController::class, 'destroy'])->name('payment-gateways.destroy');
                Route::post('payment-gateways/{linkId}/test', [\App\Http\Controllers\Tenant\PaymentGatewayController::class, 'test'])->name('payment-gateways.test');
            });

            // Aprendizajes del asistente: el staff que atiende la bandeja
            // captura correcciones y el bot las recibe como reglas del prompt.
            Route::middleware('can:reservations.manage')->group(function () {
                Route::post('agent-guidelines', [\App\Http\Controllers\Tenant\AgentGuidelineController::class, 'store'])->name('agent-guidelines.store');
                Route::patch('agent-guidelines/{guideline}', [\App\Http\Controllers\Tenant\AgentGuidelineController::class, 'update'])->name('agent-guidelines.update');
                Route::delete('agent-guidelines/{guideline}', [\App\Http\Controllers\Tenant\AgentGuidelineController::class, 'destroy'])->name('agent-guidelines.destroy');
            });

            // WhatsApp vía Evolution API (instancias self-hosted del hotel).
            Route::post('evolution-channels', [EvolutionChannelController::class, 'store'])->name('evolution-channels.store');
            Route::patch('evolution-channels/{linkId}', [EvolutionChannelController::class, 'update'])->name('evolution-channels.update');
            Route::delete('evolution-channels/{linkId}', [EvolutionChannelController::class, 'destroy'])->name('evolution-channels.destroy');
            Route::post('evolution-channels/{linkId}/test', [EvolutionChannelController::class, 'test'])->name('evolution-channels.test');

            // WhatsApp vía Cloud API oficial de Meta (número propio del hotel).
            Route::post('meta-channels', [\App\Http\Controllers\Tenant\MetaChannelController::class, 'store'])->name('meta-channels.store');
            Route::patch('meta-channels/{linkId}', [\App\Http\Controllers\Tenant\MetaChannelController::class, 'update'])->name('meta-channels.update');
            Route::delete('meta-channels/{linkId}', [\App\Http\Controllers\Tenant\MetaChannelController::class, 'destroy'])->name('meta-channels.destroy');
            Route::post('meta-channels/{linkId}/test', [\App\Http\Controllers\Tenant\MetaChannelController::class, 'test'])->name('meta-channels.test');
            Route::post('meta-channels/{linkId}/resubscribe', [\App\Http\Controllers\Tenant\MetaChannelController::class, 'resubscribe'])->name('meta-channels.resubscribe');
        });

        // Bandeja unificada de conversaciones.
        Route::middleware('can:reservations.view')->group(function () {
            Route::get('inbox/{conversation}', [InboxController::class, 'show'])->name('inbox.show');
        });
        Route::middleware('can:reservations.manage')->group(function () {
            Route::post('inbox/{conversation}/reply', [InboxController::class, 'reply'])->name('inbox.reply');
            Route::post('inbox/{conversation}/suggest', [InboxController::class, 'suggest'])->name('inbox.suggest');
            Route::patch('inbox/{conversation}', [InboxController::class, 'update'])->name('inbox.update');
            Route::delete('inbox/{conversation}', [InboxController::class, 'destroy'])->name('inbox.destroy');
            Route::patch('channels/{channel}', [InboxController::class, 'updateChannel'])->name('channels.update');

            // Cola de verificación de pagos (transferencias, spec-pagos §7.4).
            Route::get('payment-requests', [PaymentRequestController::class, 'index'])->name('payment-requests.index');
            Route::post('payment-requests/{paymentRequest}/approve', [PaymentRequestController::class, 'approve'])->name('payment-requests.approve');
            Route::post('payment-requests/{paymentRequest}/reject', [PaymentRequestController::class, 'reject'])->name('payment-requests.reject');
            // Cancelar cualquier cobro vivo desde el centro de pagos
            // (reserva, grupo o experiencia).
            Route::delete('payment-requests/{paymentRequest}', [PaymentRequestController::class, 'cancel'])->name('payment-requests.cancel');
        });

        // CRM de huéspedes.
        Route::get('guests/search', [GuestController::class, 'search'])
            ->middleware('can:guests.view')
            ->name('guests.search');
        Route::middleware('can:guests.manage')->group(function () {
            Route::post('guests', [GuestController::class, 'store'])->name('guests.store');
            Route::delete('guests', [GuestController::class, 'destroyBulk'])->name('guests.destroy-bulk');
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
    Route::post('payment-requests', [AgentToolsController::class, 'requestPayment'])->name('payment-requests.store');
});

// API pública de sitio (spec-integracion-sitios §3): catálogo con precio
// vivo para sitios conectados. Stateless; exige token de integración
// (Bearer) y el módulo motor-web. El plugin WP la consulta desde servidor.
Route::middleware([
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    App\Http\Middleware\EnsureTenantIsActive::class,
    App\Http\Middleware\ForceJsonResponse::class,
    'throttle:60,1',
])->prefix('api/site')->name('tenant.site.')->group(function () {
    Route::get('catalog', \App\Http\Controllers\Tenant\SiteCatalogController::class)->name('catalog');
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

// API pública del wizard de reservas (spec-motor-reservas-web E0):
// stateless, sin sesión/CSRF, detrás del módulo motor-web. Los holds
// llevan throttle más estricto que la sola consulta de disponibilidad.
Route::middleware([
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    App\Http\Middleware\EnsureTenantIsActive::class,
    'module:motor-web',
])->prefix('api/booking')->name('tenant.booking.')->group(function () {
    Route::get('availability', [\App\Http\Controllers\Tenant\BookingController::class, 'availability'])
        ->middleware('throttle:60,1')
        ->name('availability');
    Route::post('holds', [\App\Http\Controllers\Tenant\BookingController::class, 'holds'])
        ->middleware('throttle:20,1')
        ->name('holds.store');
    Route::post('holds/{code}/payment', [\App\Http\Controllers\Tenant\BookingController::class, 'payment'])
        ->middleware('throttle:20,1')
        ->name('holds.payment');
    // "Pagar en el hotel" (efectivo): extiende el apartado al plazo de
    // efectivo del hotel en vez de emitir un cobro.
    Route::post('holds/{code}/pay-later', [\App\Http\Controllers\Tenant\BookingController::class, 'payLater'])
        ->middleware('throttle:20,1')
        ->name('holds.pay-later');
    // Catálogo de extras (POS) y opciones de pago — /ajustes/wizard.
    Route::get('products', [\App\Http\Controllers\Tenant\BookingExtrasController::class, 'products'])
        ->middleware('throttle:60,1')
        ->name('products');
    // Experiencias con sesiones en las fechas de la estancia (módulo
    // `experiencias`): el paso Extras las ofrece como plus de la reserva.
    Route::get('experiences', [\App\Http\Controllers\Tenant\BookingExtrasController::class, 'experiences'])
        ->middleware('throttle:60,1')
        ->name('experiences');
    Route::get('payment-options', [\App\Http\Controllers\Tenant\BookingExtrasController::class, 'paymentOptions'])
        ->middleware('throttle:60,1')
        ->name('payment-options');
    // Búsqueda de reserva del huésped (código + teléfono): throttle corto,
    // es la superficie más golpeable por curiosos.
    Route::get('reservation', [\App\Http\Controllers\Tenant\BookingLookupController::class, 'find'])
        ->middleware('throttle:15,1')
        ->name('reservation.find');
    // Cancelación autoservicio: mismas llaves (código + teléfono), solo
    // sin dinero en riesgo o dentro de la ventana sin costo.
    Route::post('reservation/cancel', [\App\Http\Controllers\Tenant\BookingLookupController::class, 'cancel'])
        ->middleware('throttle:10,1')
        ->name('reservation.cancel');
});

// API pública del wizard de experiencias — módulo propio, independiente
// del motor-web (un hotel puede vender tours sin wizard de habitaciones).
Route::middleware([
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    App\Http\Middleware\EnsureTenantIsActive::class,
    'module:experiencias',
])->prefix('api/experiencias')->name('tenant.experiences-public.')->group(function () {
    Route::get('list', [\App\Http\Controllers\Tenant\ExperienceWizardController::class, 'list'])
        ->middleware('throttle:60,1')
        ->name('list');
    // Horarios con cupo de una experiencia en UNA fecha (el huésped elige
    // el día primero; el horizonte anual haría gigante la lista completa).
    Route::get('sessions', [\App\Http\Controllers\Tenant\ExperienceWizardController::class, 'sessions'])
        ->middleware('throttle:60,1')
        ->name('sessions');
    Route::post('bookings', [\App\Http\Controllers\Tenant\ExperienceWizardController::class, 'book'])
        ->middleware('throttle:15,1')
        ->name('book');
    Route::get('payment-options', [\App\Http\Controllers\Tenant\ExperienceWizardController::class, 'paymentOptions'])
        ->middleware('throttle:60,1')
        ->name('payment-options');
    Route::post('bookings/{code}/payment', [\App\Http\Controllers\Tenant\ExperienceWizardController::class, 'payment'])
        ->middleware('throttle:20,1')
        ->name('payment');
});

// API pública del wizard de grupos: disponibilidad (el mismo endpoint del
// wizard normal: precios y cupos por tipo), hold todo-o-nada y cobro
// consolidado.
Route::middleware([
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    App\Http\Middleware\EnsureTenantIsActive::class,
    'module:grupos',
])->prefix('api/grupos')->name('tenant.groups-public.')->group(function () {
    Route::get('availability', [\App\Http\Controllers\Tenant\BookingController::class, 'availability'])
        ->middleware('throttle:60,1')
        ->name('availability');
    // Experiencias en las fechas del grupo — mismo controlador que el
    // wizard normal (responde vacío si falta el módulo `experiencias`).
    // Ruta propia porque api/booking exige motor-web y grupos no.
    Route::get('experiences', [\App\Http\Controllers\Tenant\BookingExtrasController::class, 'experiences'])
        ->middleware('throttle:60,1')
        ->name('experiences');
    Route::post('holds', [\App\Http\Controllers\Tenant\GroupWizardController::class, 'hold'])
        ->middleware('throttle:10,1')
        ->name('holds');
    Route::get('payment-options', [\App\Http\Controllers\Tenant\GroupWizardController::class, 'paymentOptions'])
        ->middleware('throttle:60,1')
        ->name('payment-options');
    Route::post('holds/{code}/payment', [\App\Http\Controllers\Tenant\GroupWizardController::class, 'payment'])
        ->middleware('throttle:20,1')
        ->name('payment');
    Route::post('holds/{code}/pay-later', [\App\Http\Controllers\Tenant\GroupWizardController::class, 'payLater'])
        ->middleware('throttle:20,1')
        ->name('pay-later');
});
