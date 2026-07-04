<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\MetaChannelLink;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Canales Meta conectados por hotel (entorno de prueba: alta manual del
 * phone_number_id/page_id + token; el Embedded Signup de producción los
 * creará solo). El webhook enruta con estas filas.
 */
class MetaChannelController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', Rule::exists('tenants', 'id')],
            'type' => ['required', Rule::in(MetaChannelLink::TYPES)],
            'external_id' => ['required', 'string', 'max:100'],
            'access_token' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:100'],
        ], [
            'external_id.required' => 'El phone_number_id (WhatsApp) o page_id (FB/IG) es obligatorio.',
        ]);

        if (MetaChannelLink::query()->where('type', $data['type'])->where('external_id', $data['external_id'])->exists()) {
            return response()->json(['message' => 'Ese número/página ya está vinculado a un hotel.'], 422);
        }

        $link = MetaChannelLink::create($data + ['active' => true]);

        return response()->json($this->serialize($link), 201);
    }

    public function update(Request $request, MetaChannelLink $metaChannelLink): JsonResponse
    {
        $data = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'access_token' => ['sometimes', 'nullable', 'string'],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        // Token vacío al editar = conservar el actual.
        if (array_key_exists('access_token', $data) && blank($data['access_token'])) {
            unset($data['access_token']);
        }

        $metaChannelLink->update($data);

        return response()->json($this->serialize($metaChannelLink->refresh()));
    }

    public function destroy(MetaChannelLink $metaChannelLink): JsonResponse
    {
        $metaChannelLink->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(MetaChannelLink $link): array
    {
        return [
            'id' => $link->id,
            'tenant_id' => $link->tenant_id,
            'tenant_name' => Tenant::find($link->tenant_id)?->name ?? $link->tenant_id,
            'type' => $link->type,
            'type_label' => $link->typeLabel(),
            'external_id' => $link->external_id,
            'masked_token' => $link->maskedToken(),
            'name' => $link->name,
            'active' => $link->active,
        ];
    }
}
