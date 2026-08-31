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

    // Cuestionario de experiencia post-estancia (módulo encuestas,
    // Profesional+): el huésped llega desde el agradecimiento o el QR.
    // QR impreso en la habitación: una sola URL por cuarto que resuelve la
    // estancia en curso y manda a su encuesta por token. Va ANTES de
    // /encuesta/{token} solo por claridad — no compiten (dos segmentos).
    Route::get('/encuesta/habitacion/{room}', [\App\Http\Controllers\Tenant\SurveyPageController::class, 'room'])
        ->middleware(['throttle:30,1', 'module:encuestas'])
        ->whereNumber('room')
        ->name('survey.room');

    Route::get('/encuesta/{token}', [\App\Http\Controllers\Tenant\SurveyPageController::class, 'page'])
        ->middleware(['throttle:30,1', 'module:encuestas'])
        ->name('survey');

    // Menú digital (módulo menu-digital, parte del servicio Inventario y
    // Costos): carta pública con los productos del POS que el hotel curó;
    // el huésped arma su pedido y la solicitud cae en la campana del
    // staff. El QR impreso en la habitación llega con el cuarto ya puesto.
    Route::get('/menu', [\App\Http\Controllers\Tenant\MenuPageController::class, 'page'])
        ->middleware(['throttle:60,1', 'module:menu-digital'])
        ->name('menu');

    Route::get('/menu/habitacion/{room}', [\App\Http\Controllers\Tenant\MenuPageController::class, 'room'])
        ->middleware(['throttle:60,1', 'module:menu-digital'])
        ->whereNumber('room')
        ->name('menu.room');

    // Fotos públicas de tipos de habitación (las consume el wizard, sin
    // login): solo entrega la colección photos de RoomType.
    Route::get('/fotos/habitaciones/{mediaId}', [\App\Http\Controllers\Tenant\RoomTypePhotoController::class, 'show'])
        ->whereNumber('mediaId')
        ->name('room-type-photo');

    // Logo del hotel para el wizard (público, sin login): solo entrega la
    // colección wizard_logo de Property.
    // Foto de perfil del staff: mismo path que en el panel central.
    Route::get('/avatar/{user}', [\App\Http\Controllers\AvatarController::class, 'show'])
        ->middleware('auth')
        ->name('avatar.show');
    Route::get('/fotos/logo', [\App\Http\Controllers\Tenant\PropertyLogoController::class, 'show'])
        ->name('property-logo');

    // Foto de producto del POS. Pública como las de habitaciones: el wizard
    // ofrece productos al huésped sin login.
    Route::get('/fotos/productos/{mediaId}', [\App\Http\Controllers\Tenant\ProductPhotoController::class, 'show'])
        ->whereNumber('mediaId')
        ->name('product-photo');

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

            // Incidencias de mantenimiento (módulo incidencias,
            // Profesional+; los reportes son del avanzado, Empresarial).
            Route::get('/incidencias', \App\Http\Controllers\Tenant\IncidentsPageController::class)
                ->middleware('module:incidencias')
                ->name('incidents');
            // Reportes por periodo (hoy/semana/mes/año/rango, general o por
            // habitación) — antes de {incident} para no resolver "reportes"
            // como id.
            Route::get('/incidencias/reportes', \App\Http\Controllers\Tenant\IncidentReportsController::class)
                ->middleware('module:incidencias-avanzado')
                ->name('incidents.reports');
            Route::get('/incidencias/reportes/pdf', [\App\Http\Controllers\Tenant\IncidentReportsController::class, 'pdf'])
                ->middleware('module:incidencias-avanzado')
                ->name('incidents.reports.pdf');
            Route::get('/incidencias/{incident}', \App\Http\Controllers\Tenant\IncidentShowController::class)
                ->middleware('module:incidencias')
                ->whereNumber('incident')
                ->name('incidents.show');
            Route::get('/incidencias/{incident}/fotos/{media}', [\App\Http\Controllers\Tenant\IncidentController::class, 'showPhoto'])
                ->middleware('module:incidencias')
                ->name('incidents.photos.show');
        });

        Route::get('/reservas', ReservationsPageController::class)
            ->middleware('can:reservations.view')
            ->name('reservations');

        // Calendario de ocupación (rack) como vista propia.
        Route::get('/reservas/calendario', ReservationsPageController::class)
            ->middleware('can:reservations.view')
            ->name('reservations.calendar');
        Route::redirect('/reservas/calendar', '/reservas/calendario');

        // Historial COMPLETO de reservas: /reservas solo muestra un asomo;
        // aquí vive todo con buscador, filtro y paginación.
        Route::get('/reservas/historial', \App\Http\Controllers\Tenant\ReservationHistoryPageController::class)
            ->middleware('can:reservations.view')
            ->name('reservations.history');

        // Próximas COMPLETAS: /reservas pinta las 30 llegadas más cercanas,
        // aquí está todo lo apartado a futuro, paginado y con buscador.
        Route::get('/reservas/proximas', \App\Http\Controllers\Tenant\ReservationUpcomingPageController::class)
            ->middleware('can:reservations.view')
            ->name('reservations.upcoming');

        // Huéspedes alojados ahora, completo y paginado.
        Route::get('/reservas/alojados', \App\Http\Controllers\Tenant\InHouseStaysPageController::class)
            ->middleware('can:reservations.view')
            ->name('reservations.in-house');

        // Apariencia del wizard público (logo, colores, modo oscuro de
        // /reservar) — se entra desde /ajustes/wizard (botón Apariencia);
        // el comportamiento vive en /ajustes/wizard.
        Route::get('/reservas/ajustes', \App\Http\Controllers\Tenant\WizardAppearancePageController::class)
            ->middleware(['can:properties.manage', 'module:motor-web'])
            ->name('reservations.settings');

        // Reportes de reservas (resumen por periodo + PDF).
        Route::middleware('can:reservations.view')->group(function () {
            Route::get('/reservas/reportes', ReservationReportsController::class)
                ->name('reservations.reports');
            Route::get('/reservas/reportes/pdf', [ReservationReportsController::class, 'pdf'])
                ->name('reservations.reports.pdf');
            // La cuenta de una estancia en PDF: para imprimirla en el
            // mostrador o mandársela al huésped. Es informativa, no fiscal.
            Route::get('/estancias/{stay}/cuenta.pdf', [StayController::class, 'folioPdf'])
                ->name('stays.folio.pdf');
        });

        // Resultados del cuestionario de experiencia post-estancia.
        Route::get('/encuestas', \App\Http\Controllers\Tenant\SurveysPageController::class)
            ->middleware(['can:reports.view', 'module:encuestas'])
            ->name('surveys');

        // PDF del reporte de satisfacción (satisfacción avanzada,
        // Empresarial).
        Route::get('/encuestas/pdf', [\App\Http\Controllers\Tenant\SurveysPageController::class, 'pdf'])
            ->middleware(['can:reports.view', 'module:encuestas-avanzado'])
            ->name('surveys.pdf');

        // Bitácora global de acciones: quién hizo qué y cuándo, en todo el
        // hotel (reservas, pagos, semáforo, incidencias, cupones).
        Route::get('/actividad', \App\Http\Controllers\Tenant\ActivityLogPageController::class)
            ->middleware(['can:reports.view', 'module:bitacora'])
            ->name('activity');

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

        // Módulo Lista de espera: interesados sin disponibilidad que dejan
        // contacto en el wizard; se les avisa cuando una cancelación libera
        // sus fechas.
        Route::get('/lista-espera', \App\Http\Controllers\Tenant\WaitlistPageController::class)
            ->middleware(['can:reservations.manage', 'module:lista-espera'])
            ->name('waitlist');

        // Módulo Cupones: códigos de descuento que acepta el wizard.
        // Mismo permiso que Extras: es catálogo/config del hotel.
        Route::get('/cupones', \App\Http\Controllers\Tenant\CouponsPageController::class)
            ->middleware(['can:properties.manage', 'module:cupones'])
            ->name('coupons');

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

        // Registro de vehículos (caseta): la placa es la ficha y sus estancias
        // el historial. En motel y en "ambos" — a un hotel puro no le aplica,
        // ahí quien llega se registra en el CRM de huéspedes.
        Route::middleware(['can:guests.view', 'mode:motel,both'])->group(function () {
            Route::get('/vehiculos', [\App\Http\Controllers\Tenant\VehiclesPageController::class, 'index'])
                ->name('vehicles');
            // Ficha de una llegada a pie: no hay entidad propia, la visita ES
            // la estancia. Va antes que /{vehiculo} y ese exige número, así
            // que no se pisan.
            Route::get('/vehiculos/a-pie/{stay}', [\App\Http\Controllers\Tenant\VehiclesPageController::class, 'arrival'])
                ->whereNumber('stay')
                ->name('vehicles.arrival');
            Route::get('/vehiculos/{vehicle}', [\App\Http\Controllers\Tenant\VehiclesPageController::class, 'show'])
                ->whereNumber('vehicle')
                ->withTrashed() // la ficha de una placa archivada sigue visible
                ->name('vehicles.show');
        });

        // CRM de huéspedes.
        Route::middleware('can:guests.view')->group(function () {
            Route::get('/huespedes', [GuestsPageController::class, 'index'])->name('guests');
            Route::get('/huespedes/{guest}', [GuestsPageController::class, 'show'])
                ->withTrashed() // el perfil de un huésped archivado sigue visible
                ->name('guests.show');
        });
        Route::get('/huespedes/{guest}/documentos/{media}', [GuestController::class, 'showDocument'])
            ->middleware(['can:guests.view-documents', 'module:crm-avanzado'])
            ->name('guests.documents.show');

        // Foto del documento de una estancia (registro exprés motel): mismo
        // permiso que las INE del CRM, pero SIN gate de módulo — el exprés
        // funciona en cualquier plan.
        Route::get('/estancias/{stay}/documento/{media}', [\App\Http\Controllers\Tenant\StayController::class, 'showDocument'])
            ->middleware('can:guests.view-documents')
            ->name('stays.document.show');

        Route::get('/pos', PosPageController::class)
            ->middleware(['can:orders.manage', 'module:pos'])
            ->name('pos');

        // Historial completo de ventas: la pantalla del POS solo muestra
        // las últimas diez.
        Route::get('/pos/historial', \App\Http\Controllers\Tenant\SalesHistoryPageController::class)
            ->middleware(['can:orders.manage', 'module:pos'])
            ->name('pos.history');

        // Ticket imprimible de una venta (se abre en pestaña nueva y manda
        // solo el diálogo de impresión).
        Route::get('/pos/ticket/{order}', \App\Http\Controllers\Tenant\PosTicketController::class)
            ->middleware(['can:orders.manage', 'module:pos'])
            ->whereNumber('order')
            ->name('pos.ticket');

        // Menú digital: curación de la carta pública, liga/QR y las
        // solicitudes de los huéspedes (el cobro real sigue en el POS).
        Route::get('/menu-digital', [\App\Http\Controllers\Tenant\MenuDigitalPageController::class, 'index'])
            ->middleware(['can:orders.manage', 'module:menu-digital'])
            ->name('menu-digital');

        // Vista de cocina: solo ver solicitudes y despacharlas.
        Route::get('/menu-digital/solicitudes', [\App\Http\Controllers\Tenant\MenuDigitalPageController::class, 'kitchen'])
            ->middleware(['can:orders.manage', 'module:menu-digital'])
            ->name('menu-kitchen');

        // Comanda imprimible de un pedido (80 mm, patrón del ticket POS).
        Route::get('/menu-digital/comanda/{menuRequest}', [\App\Http\Controllers\Tenant\MenuDigitalPageController::class, 'comanda'])
            ->middleware(['can:orders.manage', 'module:menu-digital'])
            ->whereNumber('menuRequest')
            ->name('menu-comanda');

        // Cortes de caja y turnos: módulo corte-caja (escalonado comercial,
        // Profesional+). El ámbito POS dentro de la página sigue exigiendo
        // además el módulo pos.
        Route::get('/cortes', CashCutsPageController::class)
            ->middleware(['can:orders.manage', 'module:corte-caja'])
            ->name('cashcuts');

        // PDF de un corte guardado (para imprimir y firmar el arqueo).
        Route::get('/cortes/{cashCut}/pdf', [CashCutsPageController::class, 'pdf'])
            ->middleware(['can:orders.manage', 'module:corte-caja'])
            ->whereNumber('cashCut')
            ->name('cashcuts.pdf');

        // Movimientos de un corte guardado (los carga el modal de detalle).
        Route::get('/cortes/{cashCut}/movimientos', [CashCutsPageController::class, 'movements'])
            ->middleware(['can:orders.manage', 'module:corte-caja'])
            ->whereNumber('cashCut')
            ->name('cashcuts.movements');

        Route::get('/turnos', ShiftsPageController::class)
            ->middleware(['can:orders.manage', 'module:corte-caja'])
            ->name('shifts');

        Route::get('/usuarios', UsersPageController::class)
            ->middleware('can:users.manage')
            ->name('users');

        // Ficha de un usuario: sus datos, sus turnos y todo lo que ha hecho
        // (la bitácora global vive en /actividad; esta es por persona).
        Route::get('/usuarios/{user}', [\App\Http\Controllers\Tenant\UserShowController::class, 'show'])
            ->middleware('can:users.manage')
            ->whereNumber('user')
            ->name('users.show');

        Route::get('/ajustes', HotelSettingsPageController::class)
            ->middleware('can:properties.manage')
            ->name('hotel-settings');

        // Área aislada de datos generales: contacto, redes, horarios y
        // moneda, políticas y preguntas frecuentes — misma regla de superficie
        // propia que wizard/pagos/mails (feedback isolated-settings-areas).
        // Hub con una pantalla por tema (mismo patrón que metodos-pago):
        // era una sola página con todo apilado.
        Route::middleware('can:properties.manage')->group(function () {
            Route::get('/ajustes/general', [\App\Http\Controllers\Tenant\GeneralSettingsPageController::class, 'index'])
                ->name('general-settings');
            Route::get('/ajustes/general/contacto', [\App\Http\Controllers\Tenant\GeneralSettingsPageController::class, 'contact'])
                ->name('general-settings.contact');
            Route::get('/ajustes/general/operacion', [\App\Http\Controllers\Tenant\GeneralSettingsPageController::class, 'operation'])
                ->name('general-settings.operation');
            Route::get('/ajustes/general/politicas', [\App\Http\Controllers\Tenant\GeneralSettingsPageController::class, 'policies'])
                ->name('general-settings.policies');
            Route::get('/ajustes/general/preguntas-frecuentes', [\App\Http\Controllers\Tenant\GeneralSettingsPageController::class, 'faqs'])
                ->name('general-settings.faqs');
        });

        // Cuenta de quien usa el panel del hotel: hasta ahora el staff no
        // tenía dónde cambiar ni su propia contraseña (solo el admin de
        // plataforma). Mismos controladores y mismas pantallas que el
        // central; cambian los nombres de ruta.
        Route::prefix('perfil')->name('profile.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Settings\ProfileController::class, 'edit'])->name('edit');
            Route::patch('/', [\App\Http\Controllers\Settings\ProfileController::class, 'update'])->name('update');
            Route::get('contrasena', [\App\Http\Controllers\Settings\PasswordController::class, 'edit'])->name('password');
            Route::put('contrasena', [\App\Http\Controllers\Settings\PasswordController::class, 'update'])
                ->middleware('throttle:6,1')
                ->name('password.update');
            Route::get('dos-pasos', [\App\Http\Controllers\Settings\TwoFactorAuthenticationController::class, 'show'])->name('two-factor');
        });

        // Apariencia del PANEL del hotel: acento y colores del menú lateral
        // por tenant (no confundir con la apariencia del wizard público).
        Route::get('/ajustes/general/apariencia', \App\Http\Controllers\Tenant\PanelAppearancePageController::class)
            ->middleware('can:properties.manage')
            ->name('panel-appearance');

        // Área aislada del wizard público (modalidad/huéspedes, extras del
        // POS, resumen de pago) — separada de Ajustes general a propósito.
        Route::get('/ajustes/wizard', \App\Http\Controllers\Tenant\WizardSettingsPageController::class)
            ->middleware(['can:properties.manage', 'module:motor-web'])
            ->name('wizard-settings');

        // Buscador del selector de extras: la pantalla ya no se trae el
        // catálogo completo, lo consulta conforme se escribe.
        Route::get('/ajustes/wizard/productos', [\App\Http\Controllers\Tenant\WizardSettingsPageController::class, 'searchProducts'])
            ->middleware(['can:properties.manage', 'module:motor-web'])
            ->name('wizard-settings.products');

        // Área aislada de métodos de pago: pasarelas, cuentas para
        // transferencia, confirmación automática y modo de pago del wizard.
        // Sin gate de módulo: las transferencias existen en todos los planes;
        // la sección de pasarelas se bloquea sola si falta el módulo cobros.
        // Hub + sub-páginas: cada tema de cobro encapsulado en su URL.
        Route::middleware('can:properties.manage')->group(function () {
            Route::get('/ajustes/metodos-pago', [\App\Http\Controllers\Tenant\PaymentMethodsPageController::class, 'index'])
                ->name('payment-methods');
            Route::get('/ajustes/metodos-pago/pasarela-pago', [\App\Http\Controllers\Tenant\PaymentMethodsPageController::class, 'gateways'])
                ->name('payment-methods.gateways');
            Route::get('/ajustes/metodos-pago/pagos-transferencia', [\App\Http\Controllers\Tenant\PaymentMethodsPageController::class, 'transfers'])
                ->name('payment-methods.transfers');
            Route::get('/ajustes/metodos-pago/plazos-y-saldo', [\App\Http\Controllers\Tenant\PaymentMethodsPageController::class, 'terms'])
                ->middleware('module:anticipos')
                ->name('payment-methods.terms');
            Route::get('/ajustes/metodos-pago/politicas', [\App\Http\Controllers\Tenant\PaymentMethodsPageController::class, 'policies'])
                ->name('payment-methods.policies');
        });

        // Área aislada de avisos al huésped: canal directo de WhatsApp,
        // recordatorios de llegada y agradecimiento post-estancia (encuesta
        // y reseñas). Vivía dentro de Métodos de pago; nada de eso es dinero.
        // Catálogo de daños que se cobran al revisar la habitación al salir.
        Route::get('/ajustes/danos', \App\Http\Controllers\Tenant\DamageCatalogPageController::class)
            ->middleware('can:properties.manage')
            ->name('damage-catalog');

        Route::get('/ajustes/avisos', \App\Http\Controllers\Tenant\GuestNoticesPageController::class)
            ->middleware('can:properties.manage')
            ->name('guest-notices');

        // Área aislada de correo saliente: SMTP propio del hotel para
        // confirmaciones y avisos al huésped. Misma regla que wizard y
        // métodos de pago: config con superficie propia, página propia.
        Route::get('/ajustes/mails', \App\Http\Controllers\Tenant\MailSettingsPageController::class)
            ->middleware('can:properties.manage')
            ->name('mail-settings');

        // Área aislada de limpieza y cierre de día: flujo sucia → limpieza
        // → disponible (manual/automático/ambos) y qué pasa con reservadas
        // cuya salida venció sin check-in.
        // Limpieza con personal: panel del día y camaristas. Ver el panel
        // exige el mismo permiso que mover el semáforo; registrar exige
        // housekeeping.manage (lo valida cada endpoint).
        Route::get('/limpieza', [\App\Http\Controllers\Tenant\HousekeepingPageController::class, 'index'])
            ->middleware(['can:rooms.update-status', 'module:limpieza'])
            ->name('housekeeping');

        Route::get('/limpieza/personal', [\App\Http\Controllers\Tenant\HousekeepingPageController::class, 'staff'])
            ->middleware(['can:housekeeping.manage', 'module:limpieza'])
            ->name('housekeeping.staff');

        // El reporte mide desempeño de personas: exige ver reportes, no solo
        // registrar (propietario y gerente).
        Route::get('/limpieza/reportes', [\App\Http\Controllers\Tenant\HousekeepingReportsController::class, 'index'])
            ->middleware(['can:reports.view', 'module:limpieza'])
            ->name('housekeeping.reports');

        Route::get('/limpieza/reportes/pdf', [\App\Http\Controllers\Tenant\HousekeepingReportsController::class, 'pdf'])
            ->middleware(['can:reports.view', 'module:limpieza'])
            ->name('housekeeping.reports.pdf');

        Route::get('/ajustes/limpieza', \App\Http\Controllers\Tenant\HousekeepingSettingsPageController::class)
            ->middleware('can:properties.manage')
            ->name('housekeeping-settings');

        // Área aislada del cuestionario de experiencia: los aspectos que
        // pregunta la encuesta post-estancia de este hotel.
        Route::get('/ajustes/encuestas', \App\Http\Controllers\Tenant\SurveySettingsPageController::class)
            ->middleware(['can:properties.manage', 'module:encuestas'])
            ->name('survey-settings');

        // Integración con sitios (spec-integracion-sitios): tokens, catálogo
        // vivo e importador. Detrás del módulo motor-web.
        Route::get('/integracion', \App\Http\Controllers\Tenant\IntegrationPageController::class)
            ->middleware(['can:properties.manage', 'module:motor-web'])
            ->name('integration');

        // Área de mensajería (bandeja, canales y asistente): detrás del
        // módulo mensajeria — el Esencial no la incluye y sin el gate se le
        // daba acceso de facto al asistente IA.
        Route::get('/asistente', AgentPageController::class)
            ->middleware(['can:properties.manage', 'module:mensajeria'])
            ->name('agent');

        Route::get('/asistente/contexto', \App\Http\Controllers\Tenant\AgentContextPageController::class)
            ->middleware(['can:properties.manage', 'module:mensajeria'])
            ->name('agent-context');

        // Aprendizajes del bot: área aislada, habilitada por el super-admin
        // (guidelines_editable) — mismo patrón que /asistente/contexto.
        Route::get('/asistente/aprendizajes', \App\Http\Controllers\Tenant\AgentLearningsPageController::class)
            ->middleware(['can:reservations.manage', 'module:mensajeria'])
            ->name('agent-learnings');

        Route::get('/bandeja', [InboxController::class, 'index'])
            ->middleware(['can:reservations.view', 'module:mensajeria'])
            ->name('inbox');

        // Historial completo de la campana. Sin gate: igual que la campana,
        // todo el staff lo ve y el contenido ya viene acotado a cada quien.
        Route::get('/bandeja/avisos', \App\Http\Controllers\Tenant\StaffNotificationsPageController::class)
            ->name('staff-notifications');

        // Comentarios de publicaciones de Facebook e Instagram atendidos por
        // el asistente (módulo redes-sociales, addon reservas-m3-ia-redes).
        Route::get('/redes', \App\Http\Controllers\Tenant\SocialPageController::class)
            ->middleware(['can:reservations.view', 'module:redes-sociales'])
            ->name('social');

        // Una publicación con sus comentarios: el trabajo fino tiene su
        // propia pantalla, no una columna dentro del índice.
        Route::get('/redes/publicaciones/{post}', \App\Http\Controllers\Tenant\SocialPostPageController::class)
            ->middleware(['can:reservations.view', 'module:redes-sociales'])
            ->name('social.post');

        // Copia local de la imagen: las URLs del CDN de Meta caducan.
        Route::get('/redes/publicaciones/{post}/imagen', \App\Http\Controllers\Tenant\SocialPostImageController::class)
            ->middleware(['can:reservations.view', 'module:redes-sociales'])
            ->name('social.post.image');

        Route::get('/redes/ajustes', \App\Http\Controllers\Tenant\SocialSettingsPageController::class)
            ->middleware(['can:properties.manage', 'module:redes-sociales'])
            ->name('social-settings');

        Route::middleware(['can:reservations.manage', 'module:redes-sociales'])->group(function () {
            Route::patch('/redes/ajustes', [\App\Http\Controllers\Tenant\SocialSettingsPageController::class, 'update'])
                ->middleware('can:properties.manage')
                ->name('social-settings.update');
            Route::post('/redes/sincronizar', [\App\Http\Controllers\Tenant\SocialSyncController::class, 'store'])
                ->name('social.sync');
            Route::post('/redes/comentarios/{comment}/responder', [\App\Http\Controllers\Tenant\SocialCommentController::class, 'reply'])
                ->name('social.comments.reply');
            Route::post('/redes/comentarios/{comment}/privado', [\App\Http\Controllers\Tenant\SocialCommentController::class, 'privateReply'])
                ->name('social.comments.private');
            Route::post('/redes/comentarios/{comment}/ia', [\App\Http\Controllers\Tenant\SocialCommentController::class, 'rerun'])
                ->name('social.comments.rerun');
            Route::patch('/redes/comentarios/{comment}/ocultar', [\App\Http\Controllers\Tenant\SocialCommentController::class, 'hide'])
                ->name('social.comments.hide');
        });

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
        // Campana del panel: sin gate de permiso, todo el staff la ve (el
        // contenido ya viene acotado a lo que le toca a cada quien).
        // Búsqueda rápida del header (⌘K): reservas, huéspedes y
        // habitaciones. Sin gate de ruta: cada bloque revisa su permiso.
        Route::get('quick-search', \App\Http\Controllers\Tenant\QuickSearchController::class)->name('quick-search');
        Route::post('avatar', [\App\Http\Controllers\AvatarController::class, 'store'])->name('avatar.store');
        Route::delete('avatar', [\App\Http\Controllers\AvatarController::class, 'destroy'])->name('avatar.destroy');
        Route::get('staff-notifications', [\App\Http\Controllers\Tenant\StaffNotificationController::class, 'index'])->name('staff-notifications.index');
        Route::post('staff-notifications/read-all', [\App\Http\Controllers\Tenant\StaffNotificationController::class, 'readAll'])->name('staff-notifications.read-all');
        Route::post('staff-notifications/{notification}/read', [\App\Http\Controllers\Tenant\StaffNotificationController::class, 'read'])->name('staff-notifications.read');
        Route::delete('staff-notifications', [\App\Http\Controllers\Tenant\StaffNotificationController::class, 'destroyBulk'])->name('staff-notifications.destroy-bulk');
        Route::delete('staff-notifications/{notification}', [\App\Http\Controllers\Tenant\StaffNotificationController::class, 'destroy'])->name('staff-notifications.destroy');

        // Push del panel: cada quien conecta y desconecta SUS dispositivos.
        Route::get('push-subscriptions', [\App\Http\Controllers\Tenant\PushSubscriptionController::class, 'index'])->name('push-subscriptions.index');
        Route::post('push-subscriptions', [\App\Http\Controllers\Tenant\PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
        Route::delete('push-subscriptions', [\App\Http\Controllers\Tenant\PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');
        Route::post('push-subscriptions/test', [\App\Http\Controllers\Tenant\PushSubscriptionController::class, 'test'])->name('push-subscriptions.test');

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

        // Módulo Lista de espera: seguimiento desde el panel (avisar a
        // mano, ligar la reserva convertida, eliminar). El alta viene del
        // wizard público y el aviso automático de la cancelación.
        Route::middleware(['can:reservations.manage', 'module:lista-espera'])->group(function () {
            Route::post('waitlist-entries/{entry}/notify', [\App\Http\Controllers\Tenant\WaitlistEntryController::class, 'notify'])->name('waitlist-entries.notify');
            Route::get('waitlist-entries/{entry}/candidates', [\App\Http\Controllers\Tenant\WaitlistEntryController::class, 'candidates'])->name('waitlist-entries.candidates');
            Route::patch('waitlist-entries/{entry}/convert', [\App\Http\Controllers\Tenant\WaitlistEntryController::class, 'convert'])->name('waitlist-entries.convert');
            Route::delete('waitlist-entries/{entry}', [\App\Http\Controllers\Tenant\WaitlistEntryController::class, 'destroy'])->name('waitlist-entries.destroy');
        });

        // Seguimiento de las respuestas del cuestionario: cerrar el caso,
        // levantar la incidencia que destapó la queja, responderle al
        // huésped o borrar una respuesta de prueba.
        Route::middleware(['can:properties.manage', 'module:encuestas'])->group(function () {
            Route::patch('stay-surveys/{survey}/handle', [\App\Http\Controllers\Tenant\StaySurveyController::class, 'handle'])->name('stay-surveys.handle');
            Route::post('stay-surveys/{survey}/incident', [\App\Http\Controllers\Tenant\StaySurveyController::class, 'raiseIncident'])->name('stay-surveys.incident');
            Route::post('stay-surveys/{survey}/reply', [\App\Http\Controllers\Tenant\StaySurveyController::class, 'reply'])->name('stay-surveys.reply');
            Route::delete('stay-surveys/{survey}', [\App\Http\Controllers\Tenant\StaySurveyController::class, 'destroy'])->name('stay-surveys.destroy');
        });

        // Módulo Cupones: CRUD del catálogo de códigos de descuento.
        Route::middleware(['can:properties.manage', 'module:cupones'])->group(function () {
            Route::apiResource('coupons', \App\Http\Controllers\Tenant\CouponController::class)
                ->only(['index', 'store', 'update', 'destroy']);
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
        // Quién ha pasado por la habitación: lo pide el tab de historial del
        // modal del plano. El detalle de cada estancia sale de su folio.
        Route::get('rooms/{room}/stays', [RoomController::class, 'stays'])
            ->middleware('can:rooms.view')
            ->name('rooms.stays');
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
        // Reset del contador de usos (candado de rotación): mismo permiso
        // que el semáforo — recepción lo libera sin pasar por administración.
        Route::post('rooms/{room}/usage-reset', [RoomController::class, 'resetUsage'])
            ->middleware('can:rooms.update-status')
            ->name('rooms.usage-reset');

        // Bloqueos por fechas (mantenimiento programado): mismo permiso que
        // mover el semáforo — front-desk y housekeeping los programan.
        Route::apiResource('rooms.blocks', \App\Http\Controllers\Tenant\RoomBlockController::class)
            ->only(['index', 'store', 'destroy'])
            ->middleware('can:rooms.update-status');

        // Incidencias de mantenimiento: reportar y dar seguimiento con el
        // mismo permiso que mover el semáforo (housekeeping y front-desk
        // las levantan); eliminar exige administrar habitaciones.
        Route::middleware(['can:rooms.update-status', 'module:incidencias'])->group(function () {
            Route::post('incidents', [\App\Http\Controllers\Tenant\IncidentController::class, 'store'])->name('incidents.store');
            Route::patch('incidents/{incident}', [\App\Http\Controllers\Tenant\IncidentController::class, 'update'])->name('incidents.update');
            // Bitácora de seguimiento: dejar constancia ("pedí la
            // refacción", "el técnico vuelve el jueves") sin tener que
            // mover el estado del ticket.
            Route::post('incidents/{incident}/notes', [\App\Http\Controllers\Tenant\IncidentController::class, 'addNote'])->name('incidents.notes.store');
            Route::post('incidents/{incident}/photos', [\App\Http\Controllers\Tenant\IncidentController::class, 'storePhoto'])->name('incidents.photos.store');
            Route::delete('incidents/{incident}/photos/{media}', [\App\Http\Controllers\Tenant\IncidentController::class, 'destroyPhoto'])->name('incidents.photos.destroy');
            // Tiempos objetivo por prioridad (ajuste del hotel, no del ticket).
            Route::patch('incidents-sla', [\App\Http\Controllers\Tenant\IncidentController::class, 'updateSla'])->name('incidents.sla');
        });
        // Antes de {incident}: 'bulk' no es un id.
        Route::delete('incidents/bulk', [\App\Http\Controllers\Tenant\IncidentController::class, 'destroyBulk'])
            ->middleware(['can:rooms.manage', 'module:incidencias'])
            ->name('incidents.destroy-bulk');
        Route::delete('incidents/{incident}', [\App\Http\Controllers\Tenant\IncidentController::class, 'destroy'])
            ->middleware(['can:rooms.manage', 'module:incidencias'])
            ->whereNumber('incident')
            ->name('incidents.destroy');

        // Catálogo de quién repara (personal de casa y proveedores): va con
        // incidencias avanzadas, igual que el costo de la reparación.
        Route::middleware(['can:rooms.update-status', 'module:incidencias-avanzado'])->group(function () {
            Route::post('technicians', [\App\Http\Controllers\Tenant\TechnicianController::class, 'store'])
                ->name('technicians.store');
            Route::patch('technicians/{technician}', [\App\Http\Controllers\Tenant\TechnicianController::class, 'update'])
                ->name('technicians.update');
            Route::delete('technicians/{technician}', [\App\Http\Controllers\Tenant\TechnicianController::class, 'destroy'])
                ->name('technicians.destroy');
        });

        // Limpieza con personal (módulo limpieza): registro del trabajo de
        // las camaristas. Mismo permiso para quien lo captura — gerente,
        // recepción o la supervisora de limpieza.
        Route::middleware(['can:housekeeping.manage', 'module:limpieza'])->group(function () {
            Route::post('rooms/{room}/cleanings', [\App\Http\Controllers\Tenant\RoomCleaningController::class, 'store'])
                ->name('cleanings.store');
            Route::patch('cleanings/{cleaning}', [\App\Http\Controllers\Tenant\RoomCleaningController::class, 'update'])
                ->name('cleanings.update');
            Route::post('cleanings', [\App\Http\Controllers\Tenant\RoomCleaningController::class, 'storeManual'])
                ->name('cleanings.manual');
            Route::delete('cleanings/{cleaning}', [\App\Http\Controllers\Tenant\RoomCleaningController::class, 'destroy'])
                ->name('cleanings.destroy');

            Route::post('housekeepers', [\App\Http\Controllers\Tenant\HousekeeperController::class, 'store'])
                ->name('housekeepers.store');
            Route::patch('housekeepers/{housekeeper}', [\App\Http\Controllers\Tenant\HousekeeperController::class, 'update'])
                ->name('housekeepers.update');
            Route::delete('housekeepers/{housekeeper}', [\App\Http\Controllers\Tenant\HousekeeperController::class, 'destroy'])
                ->name('housekeepers.destroy');
        });

        // Tarifas (noche / bloque).
        Route::apiResource('rate-plans', RatePlanController::class)
            ->except(['show'])
            ->middleware('can:rooms.manage');

        // Temporadas y promos por tarifa (módulo promos, Profesional+).
        Route::apiResource('rate-plans.seasons', RatePlanSeasonController::class)
            ->except(['show'])
            ->middleware(['can:rooms.manage', 'module:promos']);

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
            // Foto del documento del huésped a pie (registro exprés motel):
            // se sube tras crear la estancia, con el id devuelto.
            Route::post('stays/{stay}/id-document', [StayController::class, 'storeDocument'])->name('stays.document.store');
            // Con el huésped adentro: "una noche más" y cambio de cuarto sin
            // tener que registrar su salida y volver a darle entrada.
            // Segundo momento de la caseta de motel: placa, marca, modelo y
            // color (o identificación) más el cobro que hizo el encargado.
            Route::patch('stays/{stay}/arrival', [StayController::class, 'completeArrival'])->name('stays.arrival');
            // Cargo extra sobre la estancia (daños al revisar la habitación).
            Route::post('stays/{stay}/charges', [StayController::class, 'addCharge'])->name('stays.charges');
            Route::patch('stays/{stay}/extend', [StayController::class, 'extend'])->name('stays.extend');
            Route::patch('stays/{stay}/room', [StayController::class, 'changeRoom'])->name('stays.change-room');
            Route::patch('stays/{stay}/check-out', [StayController::class, 'checkOut'])->name('stays.check-out');
        });

        // Inventario (fase 3): catálogo y stock.
        Route::middleware(['can:inventory.manage', 'module:pos'])->group(function () {
            Route::apiResource('products', ProductController::class)->except(['show']);
            Route::post('products/{product}/movements', [ProductController::class, 'movement'])->name('products.movement');
            // Foto del producto (una sola; subir reemplaza la anterior).
            Route::post('products/{product}/photo', [\App\Http\Controllers\Tenant\ProductPhotoController::class, 'store'])->name('products.photo.store');
            Route::delete('products/{product}/photo', [\App\Http\Controllers\Tenant\ProductPhotoController::class, 'destroy'])->name('products.photo.destroy');
            Route::apiResource('ingredients', IngredientController::class)->except(['show']);
            Route::post('ingredients/{ingredient}/movements', [IngredientController::class, 'movement'])->name('ingredients.movement');
        });

        // POS: ventas y cargo a habitación.
        Route::middleware(['can:orders.manage', 'module:pos'])->group(function () {
            // Catálogo para vender: lo pide el panel de consumos del plano.
            // Va aparte de /api/products porque ese exige inventory.manage
            // (administrar el catálogo) y quien cobra en la caseta no lo
            // tiene ni tiene por qué tenerlo.
            Route::get('pos/catalog', [OrderController::class, 'catalog'])->name('pos.catalog');
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
            // Cancelar una venta: devuelve la mercancía al inventario y la
            // saca del corte y del folio (ambos solo suman completadas).
            Route::post('orders/{order}/void', [OrderController::class, 'void'])->name('orders.void');
        });

        // Menú digital: curar la carta y atender solicitudes del huésped.
        Route::middleware(['can:orders.manage', 'module:menu-digital'])->group(function () {
            Route::patch('menu-products/{product}', [\App\Http\Controllers\Tenant\MenuDigitalPageController::class, 'toggleProduct'])->name('menu-products.update');
            Route::patch('menu-requests/{menuRequest}', [\App\Http\Controllers\Tenant\MenuDigitalPageController::class, 'updateRequest'])->name('menu-requests.update');
        });

        // Hotel o motel (cómo se paga el pedido): decisión del dueño.
        Route::patch('menu-settings', [\App\Http\Controllers\Tenant\MenuDigitalPageController::class, 'updateSettings'])
            ->middleware(['can:properties.manage', 'module:menu-digital'])
            ->name('menu-settings.update');

        // Cortes de caja y turnos: módulo corte-caja. El ámbito POS del
        // corte se valida en el controlador, no aquí.
        Route::middleware(['can:orders.manage', 'module:corte-caja'])->group(function () {
            // Cómo va la caja ahora, en JSON: lo pide el panel del plano.
            Route::get('cash-cuts/current', [CashCutController::class, 'current'])->name('cashcuts.current');
            Route::post('cash-cuts', [CashCutController::class, 'store'])->name('cashcuts.store');
            Route::post('shifts', [ShiftController::class, 'store'])->name('shifts.store');
            Route::patch('shifts/{shift}/close', [ShiftController::class, 'close'])->name('shifts.close');
        });

        // Rol semanal y tipos de turno (planeación).
        Route::middleware(['can:shifts.manage', 'module:corte-caja'])->group(function () {
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
            // Registro incrustado (popup oficial de Facebook, con coexistencia).
            Route::post('meta-channels/embedded-signup', [\App\Http\Controllers\Tenant\MetaChannelController::class, 'embeddedSignup'])->name('meta-channels.embedded-signup');
            Route::patch('meta-channels/{linkId}', [\App\Http\Controllers\Tenant\MetaChannelController::class, 'update'])->name('meta-channels.update');
            Route::delete('meta-channels/{linkId}', [\App\Http\Controllers\Tenant\MetaChannelController::class, 'destroy'])->name('meta-channels.destroy');
            Route::post('meta-channels/{linkId}/test', [\App\Http\Controllers\Tenant\MetaChannelController::class, 'test'])->name('meta-channels.test');
            Route::post('meta-channels/{linkId}/resubscribe', [\App\Http\Controllers\Tenant\MetaChannelController::class, 'resubscribe'])->name('meta-channels.resubscribe');

            // Bot de Telegram (token de BotFather; el webhook se registra solo).
            Route::post('telegram-channels', [\App\Http\Controllers\Tenant\TelegramChannelController::class, 'store'])->name('telegram-channels.store');
            Route::patch('telegram-channels/{linkId}', [\App\Http\Controllers\Tenant\TelegramChannelController::class, 'update'])->name('telegram-channels.update');
            Route::delete('telegram-channels/{linkId}', [\App\Http\Controllers\Tenant\TelegramChannelController::class, 'destroy'])->name('telegram-channels.destroy');
            Route::post('telegram-channels/{linkId}/test', [\App\Http\Controllers\Tenant\TelegramChannelController::class, 'test'])->name('telegram-channels.test');

            // Cuenta de TikTok vía Business Messaging API.
            Route::post('tiktok-channels', [\App\Http\Controllers\Tenant\TiktokChannelController::class, 'store'])->name('tiktok-channels.store');
            Route::patch('tiktok-channels/{linkId}', [\App\Http\Controllers\Tenant\TiktokChannelController::class, 'update'])->name('tiktok-channels.update');
            Route::delete('tiktok-channels/{linkId}', [\App\Http\Controllers\Tenant\TiktokChannelController::class, 'destroy'])->name('tiktok-channels.destroy');
            Route::post('tiktok-channels/{linkId}/test', [\App\Http\Controllers\Tenant\TiktokChannelController::class, 'test'])->name('tiktok-channels.test');
        });

        // Bandeja unificada de conversaciones (módulo mensajeria, igual que
        // la página /bandeja).
        Route::middleware(['can:reservations.view', 'module:mensajeria'])->group(function () {
            Route::get('inbox/{conversation}', [InboxController::class, 'show'])->name('inbox.show');
            // Adjuntos entrantes de WhatsApp (privados: solo staff logueado).
            Route::get('inbox/{conversation}/attachments/{media}', [InboxController::class, 'attachment'])->name('inbox.attachment');
        });
        Route::middleware(['can:reservations.manage', 'module:mensajeria'])->group(function () {
            Route::post('inbox/archive-resolved', [InboxController::class, 'archiveResolved'])->name('inbox.archive-resolved');
            // Antes de inbox/{conversation} para que 'archived' no se
            // intente resolver como id de conversación.
            Route::delete('inbox/archived', [InboxController::class, 'destroyArchived'])->name('inbox.archived.destroy');
            Route::post('inbox/{conversation}/reply', [InboxController::class, 'reply'])->name('inbox.reply');
            Route::post('inbox/{conversation}/suggest', [InboxController::class, 'suggest'])->name('inbox.suggest');
            Route::patch('inbox/{conversation}', [InboxController::class, 'update'])->name('inbox.update');
            Route::delete('inbox/{conversation}', [InboxController::class, 'destroy'])->name('inbox.destroy');
            Route::patch('channels/{channel}', [InboxController::class, 'updateChannel'])->name('channels.update');
        });
        Route::middleware('can:reservations.manage')->group(function () {
            // Cola de verificación de pagos (transferencias, spec-pagos §7.4).
            // Fuera del gate de mensajería: las transferencias con
            // verificación van en todos los planes.
            Route::get('payment-requests', [PaymentRequestController::class, 'index'])->name('payment-requests.index');
            Route::get('payment-requests/{paymentRequest}', [PaymentRequestController::class, 'show'])->name('payment-requests.show');
            Route::get('payment-requests/{paymentRequest}/receipt', [PaymentRequestController::class, 'receipt'])->name('payment-requests.receipt');
            Route::post('payment-requests/{paymentRequest}/approve', [PaymentRequestController::class, 'approve'])->name('payment-requests.approve');
            Route::post('payment-requests/{paymentRequest}/reject', [PaymentRequestController::class, 'reject'])->name('payment-requests.reject');
            Route::post('payment-requests/{paymentRequest}/reissue', [PaymentRequestController::class, 'reissue'])->name('payment-requests.reissue');
            // Cancelar cualquier cobro vivo desde el centro de pagos
            // (reserva, grupo o experiencia).
            Route::delete('payment-requests/{paymentRequest}', [PaymentRequestController::class, 'cancel'])->name('payment-requests.cancel');
        });

        // Registro de vehículos (motel): el lookup por placa es lo que hace
        // útil el registro en la caseta — al teclearla, el cajero ve si ese
        // carro ya vino y si está vetado.
        Route::get('vehicles/search', [\App\Http\Controllers\Tenant\VehicleController::class, 'search'])
            ->middleware(['can:guests.view', 'mode:motel,both'])
            ->name('vehicles.search');
        // El lookup por placa acompaña al registro exprés, no a la sección:
        // por eso también vive en modo "ambos", donde el exprés existe.
        Route::get('vehicles/lookup', [\App\Http\Controllers\Tenant\VehicleController::class, 'lookup'])
            ->middleware(['can:reservations.manage', 'mode:motel,both'])
            ->name('vehicles.lookup');
        Route::middleware(['can:guests.manage', 'mode:motel,both'])->group(function () {
            Route::patch('vehicles/{vehicle}', [\App\Http\Controllers\Tenant\VehicleController::class, 'update'])
                ->name('vehicles.update');
            Route::delete('vehicles/{vehicle}', [\App\Http\Controllers\Tenant\VehicleController::class, 'destroy'])
                ->withTrashed()
                ->name('vehicles.destroy');
            Route::post('vehicles/{vehicle}/restore', [\App\Http\Controllers\Tenant\VehicleController::class, 'restore'])
                ->withTrashed()
                ->name('vehicles.restore');
        });

        // CRM de huéspedes.
        Route::get('guests/search', [GuestController::class, 'search'])
            ->middleware('can:guests.view')
            ->name('guests.search');
        Route::middleware('can:guests.manage')->group(function () {
            Route::post('guests', [GuestController::class, 'store'])->name('guests.store');
            Route::delete('guests', [GuestController::class, 'destroyBulk'])->name('guests.destroy-bulk');
            Route::patch('guests/{guest}', [GuestController::class, 'update'])->name('guests.update');
            Route::delete('guests/{guest}', [GuestController::class, 'destroy'])
                ->withTrashed()
                ->name('guests.destroy');
            Route::post('guests/{guest}/restore', [GuestController::class, 'restore'])
                ->withTrashed()
                ->name('guests.restore');
            Route::post('guests/{guest}/documents', [GuestController::class, 'storeDocument'])
                ->middleware('module:crm-avanzado')
                ->name('guests.documents.store');
            Route::delete('guests/{guest}/documents/{media}', [GuestController::class, 'destroyDocument'])
                ->middleware('module:crm-avanzado')
                ->name('guests.documents.destroy');
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
    // Acentos sin escapar: estas respuestas se le pegan al prompt del bot.
    App\Http\Middleware\UnescapedJson::class,
    'auth:sanctum',
    'abilities:agent',
    'throttle:60,1',
])->prefix('api/agent')->name('tenant.agent-api.')->group(function () {
    Route::get('policies', [AgentToolsController::class, 'policies'])->name('policies');
    Route::get('rate-plans', [AgentToolsController::class, 'ratePlans'])->name('rate-plans');
    Route::get('availability', [AgentToolsController::class, 'availability'])->name('availability');
    Route::get('availability-overview', [AgentToolsController::class, 'availabilityOverview'])->name('availability-overview');
    Route::get('reservations/{code}', [AgentToolsController::class, 'showReservation'])->name('reservations.show');
    Route::post('holds', [AgentToolsController::class, 'storeHold'])->name('holds.store');
    // El propio controlador exige el módulo `grupos` (mismo criterio que el
    // toolset del bot), para que la Agent API responda igual que el asistente.
    Route::post('group-holds', [AgentToolsController::class, 'storeGroupHold'])->name('group-holds.store');
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

// API pública del cuestionario de experiencia: stateless (sin sesión/CSRF),
// identificada por el token único de la encuesta y con throttle corto.
Route::middleware([
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    App\Http\Middleware\EnsureTenantIsActive::class,
    'throttle:10,1',
])->prefix('api/encuesta')->name('tenant.survey-api.')->group(function () {
    Route::post('{token}', [\App\Http\Controllers\Tenant\SurveyPageController::class, 'store'])
        ->middleware('module:encuestas')
        ->name('store');
});

// API pública del menú digital: stateless (sin sesión/CSRF), con throttle
// corto — el huésped manda su pedido desde /menu sin login.
Route::middleware([
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    App\Http\Middleware\EnsureTenantIsActive::class,
    'throttle:10,1',
])->prefix('api/menu')->name('tenant.menu-api.')->group(function () {
    Route::post('solicitudes', [\App\Http\Controllers\Tenant\MenuRequestController::class, 'store'])
        ->middleware('module:menu-digital')
        ->name('store');
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
    // Cupones (módulo cupones): validación previa para mostrar el
    // descuento; la verdad se revalida al crear el hold.
    Route::post('coupons/check', [\App\Http\Controllers\Tenant\BookingCouponController::class, 'check'])
        ->middleware(['module:cupones', 'throttle:30,1'])
        ->name('coupons.check');
    // Lista de espera (módulo lista-espera): captura pública cuando el
    // wizard no encontró disponibilidad.
    Route::post('waitlist', [\App\Http\Controllers\Tenant\WaitlistPublicController::class, 'store'])
        ->middleware(['module:lista-espera', 'throttle:10,1'])
        ->name('waitlist.store');
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
    // Pre-registro en línea: el huésped completa sus datos (correo,
    // vehículo, hora estimada, notas) antes de llegar — mismas llaves.
    Route::post('reservation/pre-register', [\App\Http\Controllers\Tenant\BookingLookupController::class, 'preRegister'])
        ->middleware('throttle:10,1')
        ->name('reservation.pre-register');
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
