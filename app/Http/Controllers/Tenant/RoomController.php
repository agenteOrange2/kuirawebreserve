<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

class RoomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Room::query()
                ->with(['zone:id,name', 'roomType:id,name,capacity,base_price'])
                ->when($request->integer('property_id'), fn ($q, $id) => $q->where('property_id', $id))
                ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
                ->orderBy('number')
                ->get()
        );
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
        ]);

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
        ]);

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
        $room->delete();

        return response()->json(status: 204);
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
            'size_m2' => ['nullable', 'numeric', 'min:1', 'max:2000'],
            'view' => ['nullable', 'string', 'max:100'],
            'amenities' => ['sometimes', 'nullable', 'array'],
            'amenities.*' => ['string', 'max:50'],
            'smoking' => ['sometimes', 'boolean'],
            'accessible' => ['sometimes', 'boolean'],
            'price_modifier' => ['nullable', 'numeric', 'min:-99999', 'max:99999'],
            'maintenance_notes' => ['nullable', 'string'],
        ];
    }
}
