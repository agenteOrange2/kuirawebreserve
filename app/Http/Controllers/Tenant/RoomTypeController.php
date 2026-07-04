<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            RoomType::query()
                ->when($request->integer('property_id'), fn ($q, $id) => $q->where('property_id', $id))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'name' => ['required', 'string', 'max:255'],
            ...$this->profileRules(),
        ]);

        return response()->json(RoomType::create($data), 201);
    }

    public function update(Request $request, RoomType $roomType): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            ...$this->profileRules(),
        ]);

        $roomType->update($data);

        return response()->json($roomType);
    }

    public function destroy(RoomType $roomType): JsonResponse
    {
        if ($roomType->rooms()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: hay habitaciones usando este tipo.',
            ], 409);
        }

        $roomType->delete();

        return response()->json(status: 204);
    }

    /**
     * Campos comerciales del tipo (spec-profundidad §3), compartidos
     * entre store y update.
     *
     * @return array<string, mixed>
     */
    protected function profileRules(): array
    {
        return [
            'description' => ['nullable', 'string'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'max_adults' => ['nullable', 'integer', 'min:1', 'max:20'],
            'max_children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'base_price' => ['sometimes', 'numeric', 'min:0'],
            'check_in_time' => ['nullable', 'date_format:H:i'],
            'check_out_time' => ['nullable', 'date_format:H:i'],
            'amenities' => ['sometimes', 'nullable', 'array'],
            'amenities.*' => ['string', 'max:50'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
