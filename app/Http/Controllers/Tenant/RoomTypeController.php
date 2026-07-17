<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\RateDurationUnit;
use App\Enums\RatePlanType;
use App\Http\Controllers\Controller;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tipos de habitación. Precio único (spec-plan-maestro E2): el precio se
 * captura UNA vez al crear el tipo y vive en su tarifa "Tarifa base";
 * base_price quedó deprecado (no se escribe ni se muestra). Editar el
 * precio después = editar la tarifa.
 */
class RoomTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            RoomType::query()
                ->when($request->integer('property_id'), fn ($q, $id) => $q->where('property_id', $id))
                ->withMin(['ratePlans as price_from' => fn ($q) => $q->where('active', true)], 'price')
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
            // Precio y modalidad de la tarifa inicial (una sola captura).
            'price' => ['required', 'numeric', 'min:0.01'],
            'rate_type' => ['required', Rule::enum(RatePlanType::class)],
            'duration_unit' => ['required_if:rate_type,block', 'nullable', Rule::enum(RateDurationUnit::class)],
            'duration_value' => ['required_if:rate_type,block', 'nullable', 'integer', 'min:1', 'max:1440'],
        ], [
            'price.required' => 'Captura el precio: se creará como la tarifa base del tipo.',
            'duration_unit.required_if' => 'Las tarifas por periodo necesitan una unidad de duración.',
            'duration_value.required_if' => 'Las tarifas por periodo necesitan la duración.',
            ...$this->profileMessages(),
        ]);

        $roomType = app(\App\Actions\Catalog\CreateRoomTypeWithBaseRate::class)->execute(
            collect($data)->except(['price', 'rate_type', 'duration_unit', 'duration_value'])->all(),
            collect($data)->only(['price', 'rate_type', 'duration_unit', 'duration_value'])->all(),
        );

        return response()->json(
            $roomType->loadCount('rooms')->setAttribute('price_from', $roomType->priceFrom()),
            201,
        );
    }

    public function update(Request $request, RoomType $roomType): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            ...$this->profileRules(),
        ], $this->profileMessages());

        $roomType->update($data);

        return response()->json($roomType);
    }

    /**
     * Duplica el tipo con sus tarifas (alta rápida de catálogos parecidos).
     */
    public function duplicate(RoomType $roomType): JsonResponse
    {
        return response()->json($roomType->duplicateWithRatePlans(), 201);
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
     * entre store y update. El precio NO está aquí: vive en las tarifas.
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
            'check_in_time' => ['nullable', 'date_format:H:i'],
            'check_out_time' => ['nullable', 'date_format:H:i'],
            'amenities' => ['sometimes', 'nullable', 'array'],
            'amenities.*' => ['string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
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
        ];
    }
}
