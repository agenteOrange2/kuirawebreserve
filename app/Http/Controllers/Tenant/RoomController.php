<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

class RoomController extends Controller
{
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

        return response()->json($room->load(['zone', 'roomType']));
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

        try {
            $action->handle($room, $data['status'], $request->user());
        } catch (CouldNotPerformTransition) {
            return response()->json([
                'message' => "Transición no permitida: {$room->status->label()} → {$data['status']}.",
                'allowed' => $room->status->transitionableStates(),
            ], 422);
        }

        return response()->json([
            ...$room->toArray(),
            'status_color' => $room->status->color(),
            'status_label' => $room->status->label(),
            'transitions' => $room->status->transitionableStates(),
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
