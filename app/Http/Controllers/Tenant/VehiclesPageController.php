<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Stay;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registro de vehículos del motel (docs/spec-modo-motel.md), calcado del CRM
 * de huéspedes: en caseta el cliente es el carro, así que la placa es la ficha
 * y sus estancias son el historial.
 *
 * Vive detrás de `mode:motel`: a un hotel no se le vende esto, sencillamente
 * no le aplica — ahí el registro de quien llega es el CRM de huéspedes.
 *
 * La segunda pestaña, "Llegadas a pie", no es una tabla nueva: son las
 * estancias que entraron sin vehículo y dejaron identificación. El número del
 * documento está cifrado y NO se puede buscar en SQL, así que ahí se busca
 * por nombre.
 */
class VehiclesPageController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim($request->string('q')->toString());
        $tab = $request->string('tab')->toString() === 'pie' ? 'pie' : 'vehiculos';

        return Inertia::render('tenant/vehicles/Index', [
            'tab' => $tab,
            'vehicles' => $tab === 'vehiculos' ? $this->vehicles($request, $search) : null,
            'arrivals' => $tab === 'pie' ? $this->footArrivals($request, $search) : null,
            'counts' => [
                'vehicles' => Vehicle::count(),
                'archived' => Vehicle::onlyTrashed()->count(),
                'inside' => Vehicle::whereHas('stays', fn ($q) => $q->where('status', Stay::STATUS_ACTIVE))->count(),
                'foot' => Stay::whereNull('vehicle_id')->whereNotNull('id_document_type')->count(),
            ],
            'filters' => [
                'q' => $search,
                'inside' => $request->boolean('inside'),
                'blacklisted' => $request->boolean('blacklisted'),
                'archived' => $request->boolean('archived'),
            ],
            'canManage' => $request->user()->can('guests.manage'),
            'canViewDocuments' => $request->user()->can('guests.view-documents'),
        ]);
    }

    public function show(Request $request, Vehicle $vehicle): Response
    {
        $canViewDocuments = $request->user()->can('guests.view-documents');

        $stays = $vehicle->stays()
            ->with(['room:id,number', 'ratePlan:id,name', 'guest:id,first_name,last_name'])
            ->orderByDesc('check_in_at')
            ->take(30)
            ->get();

        // Mismo truco que la ficha del huésped: una sola query para los
        // consumos de todas las estancias listadas.
        $consumosPorStay = Order::whereIn('stay_id', $stays->pluck('id'))
            ->where('status', Order::STATUS_COMPLETED)
            ->selectRaw('stay_id, SUM(total) AS total')
            ->groupBy('stay_id')
            ->pluck('total', 'stay_id');

        // Consumos del POS, solo si el hotel tiene el módulo: es la otra mitad
        // de lo que dejó ese carro. Ya entran al corte de caja por su cuenta
        // (CashCutService los suma como venta cobrada, o como pendiente si se
        // cargaron a la habitación), así que aquí se muestran con su estado
        // para que quien revise después sepa si ese dinero ya se cobró.
        $hasPos = (bool) tenant()?->hasModule('pos');
        $orders = $hasPos ? $this->ordersFor($stays->pluck('id')) : collect();

        return Inertia::render('tenant/vehicles/Show', [
            'vehicle' => [
                'id' => $vehicle->id,
                'plate' => $vehicle->plate,
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'color' => $vehicle->color,
                'year' => $vehicle->year,
                'label' => $vehicle->label(),
                'notes' => $vehicle->notes,
                'is_blacklisted' => $vehicle->is_blacklisted,
                'blacklist_reason' => $vehicle->blacklist_reason,
                'is_archived' => $vehicle->trashed(),
                'guest' => $vehicle->guest ? [
                    'id' => $vehicle->guest->id,
                    'full_name' => $vehicle->guest->full_name ?? 'Sin nombre',
                ] : null,
                'created_at' => $vehicle->created_at->format('d/m/Y'),
            ],
            'metrics' => $vehicle->metrics(),
            'stays' => $stays->map(fn (Stay $stay) => [
                'id' => $stay->id,
                'room' => $stay->room?->number,
                'rate_plan' => $stay->ratePlan?->name,
                'guest' => $stay->guest?->full_name ?? $stay->guest_name,
                'guest_id' => $stay->guest_id,
                'plate_used' => $stay->vehicle_plate,
                'check_in_at' => $stay->check_in_at->format('d/m/Y H:i'),
                'check_out_at' => $stay->check_out_at?->format('d/m/Y H:i'),
                'status' => $stay->status,
                'amount' => (float) $stay->amount,
                'consumos' => (float) ($consumosPorStay[$stay->id] ?? 0),
                'id_document_type' => $stay->id_document_type,
                'documents' => $canViewDocuments ? $this->documentLinks($stay) : [],
            ]),
            'orders' => $orders,
            'hasPos' => $hasPos,
            'ordersTotal' => round((float) $orders->sum('total'), 2),
            'ordersPending' => round((float) $orders->where('settled', false)->sum('total'), 2),
            'canManage' => $request->user()->can('guests.manage'),
            'canViewDocuments' => $canViewDocuments,
        ]);
    }

    /**
     * Ficha de una llegada a pie. No hay entidad propia: quien entra a pie es
     * su estancia (nombre + identificación), así que la ficha es la de esa
     * visita — con la misma estructura que la del vehículo para que el
     * mostrador no aprenda dos lenguajes distintos.
     */
    public function arrival(Request $request, Stay $stay): Response
    {
        // Solo llegadas a pie: con vehículo la ficha correcta es la del carro.
        abort_if($stay->vehicle_id !== null || $stay->id_document_type === null, 404);

        $canViewDocuments = $request->user()->can('guests.view-documents');
        $stay->load(['room:id,number', 'ratePlan:id,name', 'guest:id,first_name,last_name', 'media']);

        $hasPos = (bool) tenant()?->hasModule('pos');
        $orders = $hasPos ? $this->ordersFor(collect([$stay->id])) : collect();

        return Inertia::render('tenant/vehicles/Arrival', [
            'arrival' => [
                'id' => $stay->id,
                'guest_name' => $stay->guest_name ?: 'Sin nombre',
                'guest' => $stay->guest ? [
                    'id' => $stay->guest->id,
                    'full_name' => $stay->guest->full_name ?? 'Sin nombre',
                ] : null,
                // El número va cifrado y nunca sale en payloads de lectura.
                'id_document_type' => $stay->id_document_type,
                'documents' => $canViewDocuments ? $this->documentLinks($stay) : [],
                'room' => $stay->room?->number,
                'rate_plan' => $stay->ratePlan?->name,
                'num_people' => $stay->num_people,
                'check_in_at' => $stay->check_in_at->format('d/m/Y H:i'),
                'check_out_at' => $stay->check_out_at?->format('d/m/Y H:i'),
                'planned_end_at' => $stay->planned_end_at?->format('d/m/Y H:i'),
                'status' => $stay->status,
                'is_inside' => $stay->status === Stay::STATUS_ACTIVE,
                'amount' => (float) $stay->amount,
                'notes' => $stay->notes,
            ],
            'orders' => $orders,
            'hasPos' => $hasPos,
            'ordersTotal' => round((float) $orders->sum('total'), 2),
            'ordersPending' => round((float) $orders->where('settled', false)->sum('total'), 2),
            'canViewDocuments' => $canViewDocuments,
        ]);
    }

    /**
     * Consumos del POS de un grupo de estancias, con su estado de cobro: ya
     * entran al corte de caja por su cuenta (CashCutService los suma como
     * venta cobrada, o como pendiente si se cargaron a la habitación), y aquí
     * se muestran para que quien revise después sepa si ese dinero ya se
     * cobró.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $stayIds
     */
    protected function ordersFor(\Illuminate\Support\Collection $stayIds): \Illuminate\Support\Collection
    {
        return Order::query()
            ->whereIn('stay_id', $stayIds)
            ->where('status', Order::STATUS_COMPLETED)
            ->with(['stay.room:id,number', 'lines:id,order_id,product_id,qty', 'lines.product:id,name'])
            ->orderByDesc('created_at')
            ->take(50)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'stay_id' => $order->stay_id,
                'room' => $order->stay?->room?->number,
                'created_at' => $order->created_at->format('d/m/Y H:i'),
                'total' => (float) $order->total,
                'items' => $order->lines
                    ->map(fn ($line) => trim(((float) $line->qty).' x '.($line->product?->name ?? 'Producto')))
                    ->take(4)
                    ->all(),
                'items_total' => $order->lines->count(),
                'payment_method' => $order->payment_method,
                // 'room' = cargado a la habitación: se cobra al salir y hasta
                // entonces viaja en el corte como pendiente.
                'charged_to_room' => $order->payment_method === 'room',
                'settled' => $order->settled_at !== null || $order->payment_method !== 'room',
            ]);
    }

    /** Listado paginado de fichas con visitas y si está adentro ahora. */
    protected function vehicles(Request $request, string $search): \Illuminate\Pagination\LengthAwarePaginator
    {
        return Vehicle::query()
            ->when($request->boolean('archived'), fn ($q) => $q->onlyTrashed())
            ->when($search !== '', fn ($q) => $q->search($search))
            ->when($request->boolean('blacklisted'), fn ($q) => $q->where('is_blacklisted', true))
            ->when(
                $request->boolean('inside'),
                fn ($q) => $q->whereHas('stays', fn ($s) => $s->where('status', Stay::STATUS_ACTIVE)),
            )
            ->withCount('stays as visits')
            ->withMax('stays as last_seen_at', 'check_in_at')
            ->withCount(['stays as inside' => fn ($q) => $q->where('status', Stay::STATUS_ACTIVE)])
            ->orderByDesc('last_seen_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Vehicle $vehicle) => [
                'id' => $vehicle->id,
                'plate' => $vehicle->plate,
                'label' => $vehicle->label(),
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'color' => $vehicle->color,
                'year' => $vehicle->year,
                'notes' => $vehicle->notes,
                'blacklist_reason' => $vehicle->blacklist_reason,
                'is_archived' => $vehicle->trashed(),
                'created_at' => $vehicle->created_at->format('d/m/Y'),
                'visits' => $vehicle->visits,
                'last_seen_at' => $vehicle->last_seen_at
                    ? \Illuminate\Support\Carbon::parse($vehicle->last_seen_at)->format('d/m/Y H:i')
                    : null,
                'is_inside' => $vehicle->inside > 0,
                'is_blacklisted' => $vehicle->is_blacklisted,
            ]);
    }

    /** Llegadas a pie: estancias sin vehículo que dejaron identificación. */
    protected function footArrivals(Request $request, string $search): \Illuminate\Pagination\LengthAwarePaginator
    {
        $canViewDocuments = $request->user()->can('guests.view-documents');

        return Stay::query()
            ->whereNull('vehicle_id')
            ->whereNotNull('id_document_type')
            ->when($search !== '', fn ($q) => $q->where('guest_name', 'like', "%{$search}%"))
            ->with(['room:id,number', 'ratePlan:id,name', 'media'])
            ->orderByDesc('check_in_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Stay $stay) => [
                'id' => $stay->id,
                'guest_name' => $stay->guest_name ?: 'Sin nombre',
                'guest_id' => $stay->guest_id,
                // El número va cifrado y jamás sale en payloads de lectura:
                // solo el tipo y las fotos, con el permiso de siempre.
                'id_document_type' => $stay->id_document_type,
                'documents' => $canViewDocuments ? $this->documentLinks($stay) : [],
                'room' => $stay->room?->number,
                'rate_plan' => $stay->ratePlan?->name,
                'check_in_at' => $stay->check_in_at->format('d/m/Y H:i'),
                'check_out_at' => $stay->check_out_at?->format('d/m/Y H:i'),
                'planned_end_at' => $stay->planned_end_at?->format('d/m/Y H:i'),
                'status' => $stay->status,
                'amount' => (float) $stay->amount,
                'num_people' => $stay->num_people,
                'notes' => $stay->notes,
            ]);
    }

    /** @return list<string> */
    protected function documentLinks(Stay $stay): array
    {
        return $stay->getMedia('id_document')
            ->map(fn ($media) => route('tenant.stays.document.show', [$stay->id, $media->id], false))
            ->all();
    }
}
