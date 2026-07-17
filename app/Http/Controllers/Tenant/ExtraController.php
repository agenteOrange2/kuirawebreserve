<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Extra;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo de add-ons del módulo `extras` (decoración, desayuno, late
 * checkout). Los precios ya vendidos no se tocan: las reservas congelan
 * nombre/precio en sus líneas al crearse.
 */
class ExtraController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Extra::query()->orderBy('sort_order')->orderBy('name')->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $extra = Extra::create([
            ...$data,
            'property_id' => Property::firstOrFail()->id,
        ]);

        return response()->json($extra, 201);
    }

    public function update(Request $request, Extra $extra): JsonResponse
    {
        $extra->update($request->validate($this->rules(partial: true)));

        return response()->json($extra->fresh());
    }

    public function destroy(Extra $extra): JsonResponse
    {
        // Las reservas existentes conservan sus líneas congeladas (extra_id
        // queda huérfano a propósito: es referencia histórica, no FK).
        $extra->delete();

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    protected function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$presence, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => [$presence, 'numeric', 'min:0', 'max:999999'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }
}
