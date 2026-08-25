<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Rooms\ChangeRoomStatus;
use App\Actions\Rooms\SyncRoomUsageLock;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

class RoomController extends Controller
{
    /**
     * Quién ha pasado por esta habitación. Lo pide el tab de historial del
     * modal del plano, donde cada estancia se abre para ver su cuenta.
     *
     * Trae el consumo agregado por estancia para que la lista ya diga algo sin
     * tener que abrir una por una; el detalle (qué se consumió y qué se pagó)
     * sale del folio de la estancia, que ya existe.
     */
    public function stays(Room $room): JsonResponse
    {
        $stays = Stay::query()
            ->where('room_id', $room->id)
            ->with(['guest:id,first_name,last_name', 'ratePlan:id,name'])
            ->withSum(
                ['orders as consumos_total' => fn ($query) => $query->where('status', Order::STATUS_COMPLETED)],
                'total',
            )
            ->latest('check_in_at')
            ->take(10)
            ->get()
            ->map(fn (Stay $stay) => [
                'id' => $stay->id,
                'guest_name' => $stay->guest?->full_name ?? $stay->guest_name ?? 'Anónimo',
                'rate_plan' => $stay->ratePlan?->name,
                'channel' => $stay->channel,
                'check_in_at' => $stay->check_in_at?->format('d/m/Y H:i'),
                'check_out_at' => $stay->check_out_at?->format('d/m/Y H:i'),
                'active' => $stay->status === Stay::STATUS_ACTIVE,
                'amount' => (float) $stay->amount,
                'consumos_total' => round((float) ($stay->consumos_total ?? 0), 2),
                // El motel identifica la visita por la placa, no por el nombre.
                'vehicle_plate' => $stay->vehicle_plate,
                // Ficha del vehículo, si la llegada entró en carro.
                'vehicle_id' => $stay->vehicle_id,
            ]);

        // Lo que viene para esta habitación: sirve para saber hasta cuándo se
        // puede extender a quien está adentro sin pisar a nadie.
        $upcoming = Reservation::query()
            ->where('room_id', $room->id)
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
            ->where('ends_at', '>=', now())
            ->with(['guest:id,first_name,last_name', 'ratePlan:id,name'])
            ->orderBy('starts_at')
            ->take(10)
            ->get()
            ->map(fn (Reservation $reservation) => [
                'id' => $reservation->id,
                'code' => $reservation->displayCode(),
                'guest_name' => $reservation->guest?->full_name ?? $reservation->guest_name ?? 'Anónimo',
                'rate_plan' => $reservation->ratePlan?->name,
                'status_label' => $reservation->status->label(),
                'starts_at' => $reservation->starts_at->format('d/m/Y H:i'),
                'ends_at' => $reservation->ends_at->format('d/m/Y H:i'),
                'starts_today' => $reservation->starts_at->isToday(),
                'total_amount' => (float) $reservation->total_amount,
            ]);

        return response()->json(['stays' => $stays, 'upcoming' => $upcoming]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Room::query()
                ->with(['zone:id,name', 'roomType:id,name,capacity'])
                ->when($request->integer('property_id'), fn ($q, $id) => $q->where('property_id', $id))
                ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
                ->orderBy('number')
                ->get()
        );
    }

    /**
     * Alta masiva por rango (spec-plan-maestro E3): "del 101 al 110". Los
     * números que ya existen se omiten y se reportan; el límite del plan se
     * valida contra los que SÍ se van a crear.
     */
    public function storeBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'room_type_id' => ['required', Rule::exists('room_types', 'id')->where('property_id', $request->integer('property_id'))],
            'zone_id' => ['nullable', Rule::exists('zones', 'id')->where('property_id', $request->integer('property_id'))],
            'number_from' => ['required', 'integer', 'min:1'],
            'number_to' => ['required', 'integer', 'gte:number_from'],
        ], [
            'number_to.gte' => 'El número final debe ser mayor o igual al inicial.',
        ]);

        if ($data['number_to'] - $data['number_from'] >= 100) {
            return response()->json(['message' => 'Máximo 100 habitaciones por rango.'], 422);
        }

        $numbers = array_map('strval', range($data['number_from'], $data['number_to']));
        $existing = Room::query()
            ->where('property_id', $data['property_id'])
            ->whereIn('number', $numbers)
            ->count();
        $newCount = count($numbers) - $existing;

        if ($newCount === 0) {
            return response()->json(['message' => 'Todos los números del rango ya existen.'], 422);
        }

        $max = tenant()->planLimit('max_rooms');
        if ($max !== null && Room::count() + $newCount > $max) {
            $available = max(0, $max - Room::count());

            return response()->json([
                'message' => "Tu plan permite {$max} habitaciones y este rango crearía {$newCount}; solo caben {$available} más. Ajusta el rango o mejora el plan.",
            ], 422);
        }

        $result = app(\App\Actions\Rooms\CreateRoomRange::class)->execute(
            (int) $data['property_id'],
            (int) $data['room_type_id'],
            $data['zone_id'] !== null ? (int) $data['zone_id'] : null,
            (int) $data['number_from'],
            (int) $data['number_to'],
        );

        return response()->json($result, 201);
    }

    /**
     * Alta rápida "habitación única" (caso motel, spec-plan-maestro E3):
     * crea tipo + tarifa base + habitación en una sola captura.
     */
    public function storeSingleUnit(Request $request): JsonResponse
    {
        $max = tenant()->planLimit('max_rooms');
        if ($max !== null && Room::count() >= $max) {
            return response()->json([
                'message' => "Límite del plan alcanzado: máximo {$max} habitaciones. Actualiza el plan para agregar más.",
            ], 422);
        }

        $data = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'zone_id' => ['nullable', Rule::exists('zones', 'id')->where('property_id', $request->integer('property_id'))],
            'name' => ['required', 'string', 'max:255'],
            'number' => [
                'required', 'string', 'max:20',
                Rule::unique('rooms')->where('property_id', $request->integer('property_id')),
            ],
            'capacity' => ['required', 'integer', 'min:1', 'max:20'],
            'price' => ['required', 'numeric', 'min:0.01'],
            'rate_type' => ['required', Rule::enum(\App\Enums\RatePlanType::class)],
            'duration_unit' => ['required_if:rate_type,block', 'nullable', Rule::enum(\App\Enums\RateDurationUnit::class)],
            'duration_value' => ['required_if:rate_type,block', 'nullable', 'integer', 'min:1', 'max:1440'],
        ], [
            'number.unique' => 'Ya existe una habitación con ese número.',
            'duration_unit.required_if' => 'Las tarifas por periodo necesitan una unidad de duración.',
            'duration_value.required_if' => 'Las tarifas por periodo necesitan la duración.',
        ]);

        $room = DB::transaction(function () use ($data) {
            $roomType = app(\App\Actions\Catalog\CreateRoomTypeWithBaseRate::class)->execute(
                [
                    'property_id' => $data['property_id'],
                    'name' => $data['name'],
                    'capacity' => $data['capacity'],
                    'sort_order' => (int) \App\Models\RoomType::query()
                        ->where('property_id', $data['property_id'])
                        ->max('sort_order') + 1,
                ],
                collect($data)->only(['price', 'rate_type', 'duration_unit', 'duration_value'])->all(),
            );

            $i = Room::query()->where('property_id', $data['property_id'])->count();

            return Room::create([
                'property_id' => $data['property_id'],
                'room_type_id' => $roomType->id,
                'zone_id' => $data['zone_id'] ?? null,
                'number' => $data['number'],
                'name' => $data['name'],
                'pos_x' => 40 + ($i % 5) * 160,
                'pos_y' => 40 + intdiv($i, 5) * 120,
            ]);
        });

        return response()->json($room->load('roomType:id,name'), 201);
    }

    /**
     * Duplica la habitación con el siguiente número libre.
     */
    public function duplicate(Room $room): JsonResponse
    {
        $max = tenant()->planLimit('max_rooms');
        if ($max !== null && Room::count() >= $max) {
            return response()->json([
                'message' => "Límite del plan alcanzado: máximo {$max} habitaciones. Actualiza el plan para agregar más.",
            ], 422);
        }

        return response()->json($room->duplicateAsNew(), 201);
    }

    public function store(Request $request): JsonResponse
    {
        $max = tenant()->planLimit('max_rooms');
        if ($max !== null && Room::count() >= $max) {
            return response()->json([
                'message' => "Límite del plan alcanzado: máximo {$max} habitaciones. Actualiza el plan para agregar más.",
            ], 422);
        }

        $data = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'zone_id' => ['nullable', Rule::exists('zones', 'id')->where('property_id', $request->integer('property_id'))],
            'room_type_id' => ['required', Rule::exists('room_types', 'id')->where('property_id', $request->integer('property_id'))],
            'number' => [
                'required', 'string', 'max:20',
                Rule::unique('rooms')->where('property_id', $request->integer('property_id')),
            ],
            ...$this->profileRules(),
            'pos_x' => ['sometimes', 'integer'],
            'pos_y' => ['sometimes', 'integer'],
            'width' => ['sometimes', 'integer', 'min:20'],
            'height' => ['sometimes', 'integer', 'min:20'],
            'notes' => ['nullable', 'string'],
        ], $this->profileMessages());

        return response()->json(Room::create($data)->refresh()->load(['zone', 'roomType']), 201);
    }

    public function show(Room $room): JsonResponse
    {
        return response()->json($room->load(['zone', 'roomType', 'property']));
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate([
            'zone_id' => ['nullable', Rule::exists('zones', 'id')->where('property_id', $room->property_id)],
            'room_type_id' => ['sometimes', Rule::exists('room_types', 'id')->where('property_id', $room->property_id)],
            'number' => [
                'sometimes', 'string', 'max:20',
                Rule::unique('rooms')->where('property_id', $room->property_id)->ignore($room->id),
            ],
            ...$this->profileRules(),
            'pos_x' => ['sometimes', 'integer'],
            'pos_y' => ['sometimes', 'integer'],
            'width' => ['sometimes', 'integer', 'min:20'],
            'height' => ['sometimes', 'integer', 'min:20'],
            'notes' => ['nullable', 'string'],
        ], $this->profileMessages());

        $room->update($data);

        // El candado sigue al contador: editar a mano el conteo o el límite
        // puede activarlo (con salvaguarda) o retirarlo en el mismo guardado.
        if (array_key_exists('usage_count', $data) || array_key_exists('usage_limit', $data)) {
            app(SyncRoomUsageLock::class)->handle($room, $request->user(), ['manual' => true]);
        }

        return response()->json($room->load(['zone', 'roomType']));
    }

    /**
     * Botón "Resetear contador": usos a cero y candado fuera; la habitación
     * vuelve a entrar en disponibilidad y en la rotación.
     */
    public function resetUsage(Request $request, Room $room, SyncRoomUsageLock $usage): JsonResponse
    {
        $usage->reset($room, $request->user());

        return response()->json([
            'id' => $room->id,
            'usage_count' => (int) $room->usage_count,
            'usage_limit' => $room->usage_limit,
            'usage_locked' => $room->usageLocked(),
        ]);
    }

    /**
     * Cambio de estado del semáforo: transición validada por la máquina de
     * estados, log en room_status_logs y broadcast por Reverb.
     */
    public function updateStatus(Request $request, Room $room, ChangeRoomStatus $action): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(RoomStatus::class)],
        ]);

        // Con huésped adentro el semáforo no se mueve a mano: liberar la
        // habitación sin cerrar la estancia deja el check-out atorado.
        $activeStay = $room->activeStay;
        if ($activeStay !== null) {
            $guest = $activeStay->guest_name ? " ({$activeStay->guest_name})" : '';

            return response()->json([
                'message' => "La {$room->number} tiene una estancia activa{$guest}; registra el check-out en Reservas para liberarla.",
            ], 422);
        }

        // Reservada/ocupada sin reserva real es un semáforo que miente:
        // nadie sabe quién viene ni quién está adentro, y disponibilidad y
        // cobros quedan desconectados del plano.
        if ($data['status'] === RoomStatus::Reserved->value) {
            return response()->json([
                'message' => "Reservada no se marca a mano: crea la reserva de la {$room->number} (botón Reservar) y el semáforo se mueve solo.",
            ], 422);
        }

        if ($data['status'] === RoomStatus::Occupied->value) {
            return response()->json([
                'message' => "Ocupada no se marca a mano: registra un Walk-in o haz el check-in de la reserva de la {$room->number} para saber quién entra.",
            ], 422);
        }

        // Liberar una reservada por debajo de su reserva viva la dejaría
        // vendible dos veces; el camino es cancelar la reserva.
        if (
            $data['status'] === RoomStatus::Available->value
            && $room->status->getMorphClass() === RoomStatus::Reserved->value
            && $room->hasLiveReservation()
        ) {
            $reservation = $room->upcomingReservation;

            return response()->json([
                'message' => "La {$room->number} está apartada por la reserva {$reservation->displayCode()}"
                    .($reservation->guest_name ? " ({$reservation->guest_name})" : '')
                    .'; cancélala en Reservas para liberarla.',
            ], 422);
        }

        try {
            $action->handle($room, $data['status'], $request->user());
        } catch (CouldNotPerformTransition) {
            return response()->json([
                'message' => "Transición no permitida: {$room->status->label()} → {$data['status']}.",
                'allowed' => $room->manualStatusTransitions(),
            ], 422);
        }

        return response()->json([
            ...$room->toArray(),
            'status_color' => $room->status->color(),
            'status_label' => $room->status->label(),
            'transitions' => $room->manualStatusTransitions(),
        ]);
    }

    public function destroy(Room $room): JsonResponse
    {
        if ($room->activeStay()->exists()) {
            return response()->json([
                'message' => "La {$room->number} tiene una estancia activa; registra el check-out antes de borrarla.",
            ], 422);
        }

        $upcoming = $room->reservations()
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
            ->count();

        if ($upcoming > 0) {
            return response()->json([
                'message' => "La {$room->number} tiene {$upcoming} reserva(s) próximas asignadas; cancélalas o muévelas de habitación antes de borrarla.",
            ], 422);
        }

        // Las estancias y reservas históricas sobreviven al borrado: sus FK
        // son SET NULL, así que conservan huésped y montos sin la habitación.
        $room->delete();

        return response()->json(status: 204);
    }

    /**
     * Borrado en masa: elimina las habitaciones LIBRES (sin estancia activa
     * ni reservas próximas); las ocupadas/comprometidas se conservan y se
     * reportan como omitidas.
     */
    public function destroyBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $deleted = 0;
        $skipped = 0;

        foreach (Room::query()->whereIn('id', $data['ids'])->get() as $room) {
            $blocked = $room->activeStay()->exists()
                || $room->reservations()->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])->exists();

            if ($blocked) {
                $skipped++;

                continue;
            }

            $room->delete();
            $deleted++;
        }

        return response()->json(['deleted' => $deleted, 'skipped' => $skipped]);
    }

    /**
     * Ficha comercial/operativa de la habitación (spec-profundidad §2.1),
     * compartida entre store y update.
     *
     * @return array<string, mixed>
     */
    protected function profileRules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'beds' => ['sometimes', 'nullable', 'array'],
            'beds.*.type' => ['required_with:beds', Rule::in(array_keys(Room::BED_TYPES))],
            'beds.*.qty' => ['required_with:beds', 'integer', 'min:1', 'max:10'],
            'max_occupancy' => ['nullable', 'integer', 'min:1', 'max:50'],
            'included_occupancy' => ['nullable', 'integer', 'min:1', 'max:50'],
            'size_m2' => ['nullable', 'numeric', 'min:1', 'max:2000'],
            'view' => ['nullable', 'string', 'max:100'],
            'amenities' => ['sometimes', 'nullable', 'array'],
            'amenities.*' => ['string', 'max:100'],
            'smoking' => ['sometimes', 'boolean'],
            'accessible' => ['sometimes', 'boolean'],
            'price_modifier' => ['nullable', 'numeric', 'min:-99999', 'max:99999'],
            'extra_guest_fee' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'optional_charges' => ['sometimes', 'nullable', 'array', 'max:20'],
            'optional_charges.*.concept' => ['required', 'string', 'max:100'],
            'optional_charges.*.amount' => ['required', 'numeric', 'min:0', 'max:99999'],
            // Contador de usos: el límite es opcional (sin límite = sin
            // candado) y el conteo se puede ajustar a mano.
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'usage_count' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'maintenance_notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Mensajes en español para los campos de la ficha (los de arreglo salen
     * con clave `amenities.N` y el texto default de Laravel está en inglés).
     *
     * @return array<string, string>
     */
    protected function profileMessages(): array
    {
        return [
            'amenities.*.max' => 'Cada amenidad debe tener máximo 100 caracteres.',
            'optional_charges.*.concept.required' => 'Cada cargo opcional necesita un concepto.',
            'optional_charges.*.concept.max' => 'El concepto del cargo debe tener máximo 100 caracteres.',
            'optional_charges.*.amount.required' => 'Cada cargo opcional necesita un precio.',
            'optional_charges.*.amount.min' => 'El precio del cargo no puede ser negativo.',
        ];
    }
}
