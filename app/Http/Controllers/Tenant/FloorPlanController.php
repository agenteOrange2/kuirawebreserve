<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Property;
use App\Models\Room;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plano visual de habitaciones (fase 1): canvas drag-and-drop con el
 * semáforo en vivo.
 */
class FloorPlanController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $property = Property::query()
            ->when($request->integer('property_id'), fn ($q, $id) => $q->whereKey($id))
            ->firstOrFail();

        $rooms = Room::query()
            ->where('property_id', $property->id)
            ->with([
                'zone:id,name,kind,color',
                'roomType:id,name,icon,capacity,amenities,check_in_time,check_out_time',
                // Fotos del tipo para el modal: sin este eager load, getMedia
                // dispara una consulta por habitación.
                'roomType.media',
                'roomType.ratePlans' => fn ($query) => $query
                    ->select(['id', 'room_type_id', 'name', 'type', 'price', 'duration_minutes', 'duration_unit', 'duration_value', 'active'])
                    ->where('active', true)
                    ->orderBy('price'),
                'activeStay' => fn ($query) => $query
                    ->with([
                        'guest:id,first_name,last_name',
                        'ratePlan:id,name',
                        // Fotos del documento (registro exprés motel): sin
                        // esto getMedia dispara una query por cuarto.
                        'media',
                        // Para el saldo pendiente del huésped en casa: lo
                        // pagado de la reserva se agrega aquí y el payload
                        // calcula el neto sin una consulta por cuarto.
                        'reservation' => fn ($r) => $r->withSum('payments as paid_total', 'amount'),
                    ])
                    ->withSum(
                        ['orders as consumos_total' => fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)],
                        'total',
                    )
                    // Hospedaje ya liquidado en folio (walk-in sin reserva).
                    ->withSum(
                        ['payments as lodging_paid_total' => fn ($payQuery) => $payQuery->where('kind', \App\Models\Payment::KIND_LODGING)],
                        'amount',
                    )
                    // Consumos cargados a habitación aún sin liquidar.
                    ->withSum(
                        ['orders as room_pending_total' => fn ($orderQuery) => $orderQuery
                            ->where('status', Order::STATUS_COMPLETED)
                            ->where('payment_method', 'room')
                            ->whereNull('settled_at')],
                        'total',
                    ),
                'upcomingReservation' => fn ($query) => $query
                    ->with(['guest:id,first_name,last_name', 'ratePlan:id,name']),
                'statusLogs' => fn ($query) => $query
                    ->where('created_at', '>=', now()->startOfDay())
                    ->latest('created_at')
                    ->limit(8)
                    ->with('changedBy:id,name'),
                // Mantenimiento programado: no mueve el semáforo, así que sin
                // traerlo el plano pinta como libre un cuarto que no se puede
                // vender esas fechas.
                'blocks' => fn ($query) => $query
                    ->currentOrFuture()
                    ->orderBy('starts_at'),
                // Limpieza abierta: el plano muestra quién la trabaja y
                // ofrece cerrarla sin ir a /limpieza.
                'openCleaning.housekeeper:id,name',
                // Fallas sin resolver: marcan el nodo y se listan al abrir
                // la habitación, para no venderla averiada.
                'openIncidents',
            ])
            ->orderBy('number')
            ->get()
            ->map(fn (Room $room) => $room->toFloorPlanPayload());

        return Inertia::render('tenant/FloorPlan', [
            'tenantId' => tenant('id'),
            'property' => $property->only(['id', 'name']),
            'properties' => Property::query()->get(['id', 'name']),
            'rooms' => $rooms,
            'canManage' => $request->user()->can('rooms.update-status'),
            'canManageReservations' => $request->user()->can('reservations.manage'),
            'canManageOrders' => $request->user()->can('orders.manage'),
            // En modo de check-in "automático" puro (/ajustes/limpieza) la
            // llegada la registra el reloj: el botón manual se oculta.
            'manualCheckinAllowed' => app(\App\Services\HousekeepingPolicy::class)->manualCheckInAllowed(),
            // El modo de operación NO viaja como prop: ya va en el share de
            // Inertia (panelTenant.property_mode) y el plano lo lee con
            // usePropertyMode. Una segunda copia por página era justo la
            // divergencia que hacía que "ambos" se comportara como motel.
            'guaranteeAmount' => app(\App\Services\ReservationPolicy::class)->guaranteeEnabled()
                ? app(\App\Services\ReservationPolicy::class)->guaranteeAmount()
                : 0,
            // Walk-in con cobro al llegar (/ajustes/metodos-pago): el modal de
            // "Llegó sin reserva" del plano pide método de pago y cobra ahí
            // mismo; apagado, la estancia nace con saldo para el check-out.
            'walkinChargeOnCheckin' => app(\App\Services\ReservationPolicy::class)->walkinChargeOnCheckIn(),
            // Fotos de identificación en la ficha: mismo permiso que las
            // INE del CRM.
            'canViewDocuments' => $request->user()->can('guests.view-documents'),
            // Abrir la cuenta de una estancia (folio) exige ver reservas: sin
            // esto el historial ofrecería un detalle que responde 403.
            'canViewStays' => $request->user()->can('reservations.view'),
            // Catálogo de daños (/ajustes/danos) para la revisión de la
            // habitación al registrar la salida.
            'damageCatalog' => DamageCatalogPageController::catalog($property),
            // Catálogo de fallas para reportar una incidencia desde el modal.
            // Vacío sin el módulo: la regla vive en el servidor, no en la UI.
            'incidentCategories' => (tenant()?->hasModule('incidencias') ?? true)
                ? collect(\App\Models\Incident::CATEGORIES)
                    ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                    ->values()
                : [],
            // Registro de limpieza (módulo limpieza): con él, iniciar y
            // terminar una limpieza desde el plano pide camarista y checklist
            // en vez de solo mover el color. Sin el módulo van vacíos y el
            // plano se comporta exactamente como antes.
            'housekeepingEnabled' => (bool) (tenant()?->hasModule('limpieza') ?? false)
                && $request->user()->can('housekeeping.manage'),
            'housekeepers' => (tenant()?->hasModule('limpieza') ?? false)
                ? \App\Models\Housekeeper::query()->active()->ordered()->get(['id', 'name'])
                    ->map(fn (\App\Models\Housekeeper $h) => ['id' => $h->id, 'name' => $h->name])
                    ->values()
                : [],
            'cleaningChecklist' => (tenant()?->hasModule('limpieza') ?? false)
                ? app(\App\Services\HousekeepingChecklist::class)->tasks(onlyActive: true)
                : [],
            'cleaningLinens' => (tenant()?->hasModule('limpieza') ?? false)
                ? app(\App\Services\HousekeepingChecklist::class)->linens()
                : [],
            'cleaningKinds' => \App\Models\RoomCleaning::KINDS,

            // Catálogos para dar de alta o editar una habitación desde el
            // panel del plano (módulo plano-operativo). Son dos listas de
            // nombres: el perfil completo sigue viviendo en /habitaciones.
            'roomTypes' => $request->user()->can('rooms.manage')
                ? \App\Models\RoomType::query()
                    ->where('property_id', $property->id)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
            'zones' => $request->user()->can('rooms.manage')
                ? \App\Models\Zone::query()
                    ->where('property_id', $property->id)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
        ]);
    }
}
