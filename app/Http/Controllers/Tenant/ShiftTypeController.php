<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\ShiftType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShiftTypeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $type = ShiftType::create($data + ['property_id' => Property::firstOrFail()->id]);

        return response()->json($type, 201);
    }

    public function update(Request $request, ShiftType $shiftType): JsonResponse
    {
        $shiftType->update($this->validated($request, $shiftType));

        return response()->json($shiftType->refresh());
    }

    public function destroy(ShiftType $shiftType): JsonResponse
    {
        if ($shiftType->assignments()->exists()) {
            return response()->json([
                'message' => 'Este tipo de turno tiene días asignados en el rol; quítalos antes de eliminarlo.',
            ], 409);
        }

        $shiftType->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?ShiftType $type = null): array
    {
        return $request->validate([
            'name' => [
                $type ? 'sometimes' : 'required', 'string', 'max:50',
                Rule::unique('shift_types', 'name')->ignore($type?->id),
            ],
            'starts_at' => [$type ? 'sometimes' : 'required', 'date_format:H:i'],
            'ends_at' => [$type ? 'sometimes' : 'required', 'date_format:H:i'],
            'color' => ['sometimes', Rule::in(ShiftType::COLORS)],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
