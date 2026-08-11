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
                'roomType:id,name,capacity,amenities,check_in_time,check_out_time',
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
            // Registro exprés (modo motel, spec-modo-motel): el modal cobra
            // en la llegada y necesita saber si hay fianza configurada.
            'expressCheckin' => app(\App\Services\PropertyMode::class)->expressCheckInEnabled(),
            'guaranteeAmount' => app(\App\Services\ReservationPolicy::class)->guaranteeEnabled()
                ? app(\App\Services\ReservationPolicy::class)->guaranteeAmount()
                : 0,
            // Fotos de identificación en la ficha: mismo permiso que las
            // INE del CRM.
            'canViewDocuments' => $request->user()->can('guests.view-documents'),
        ]);
    }
}
