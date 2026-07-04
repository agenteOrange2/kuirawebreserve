<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ZoneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Zone::query()
                ->when($request->integer('property_id'), fn ($q, $id) => $q->where('property_id', $id))
                ->orderBy('sort_order')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['sometimes', Rule::in(array_keys(Zone::KINDS))],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        return response()->json(Zone::create($data), 201);
    }

    public function update(Request $request, Zone $zone): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'kind' => ['sometimes', Rule::in(array_keys(Zone::KINDS))],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $zone->update($data);

        return response()->json($zone);
    }

    public function destroy(Zone $zone): JsonResponse
    {
        $zone->delete();

        return response()->json(status: 204);
    }
}
