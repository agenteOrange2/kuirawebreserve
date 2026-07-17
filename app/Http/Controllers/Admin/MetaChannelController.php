<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\MetaChannelLink;
use App\Models\Tenant;
use App\Services\Meta\MetaApi;
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
            'waba_id' => ['nullable', 'string', 'max:100'],
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
            // Editable para corregir un id mal capturado sin recrear el canal.
            'external_id' => ['sometimes', 'string', 'max:100'],
            'waba_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        // Token vacío al editar = conservar el actual.
        if (array_key_exists('access_token', $data) && blank($data['access_token'])) {
            unset($data['access_token']);
        }

        // El mismo número/página/cuenta no puede vivir en dos hoteles.
        if (isset($data['external_id'])) {
            $taken = MetaChannelLink::query()
                ->where('type', $metaChannelLink->type)
                ->where('external_id', $data['external_id'])
                ->whereKeyNot($metaChannelLink->id)
                ->exists();

            if ($taken) {
                return response()->json(['message' => 'Ese número/página ya está vinculado a un hotel.'], 422);
            }
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
     * Radiografía del canal: WhatsApp (número/calidad/callback/WABA) o
     * página de Messenger/Instagram (identidad + subscribed_apps).
     */
    public function diagnose(MetaChannelLink $metaChannelLink, MetaApi $api): JsonResponse
    {
        return response()->json($api->diagnose($metaChannelLink));
    }

    /**
     * Repara la suscripción de la app: WABA (WhatsApp) o página con el
     * campo messages (Messenger/Instagram) — la causa clásica de "el
     * webhook está verificado pero los mensajes reales no llegan".
     */
    public function resubscribe(MetaChannelLink $metaChannelLink, MetaApi $api): JsonResponse
    {
        if ($metaChannelLink->type === 'whatsapp' && ! $metaChannelLink->waba_id) {
            return response()->json([
                'message' => 'Captura el WhatsApp Business Account ID del canal para poder reparar la suscripción.',
            ], 422);
        }

        // La ruta Instagram Login (token IGAA…) se suscribe sola por su API;
        // la ruta vía página necesita el page_id vinculado.
        if (
            $metaChannelLink->type === 'instagram'
            && ! $metaChannelLink->waba_id
            && ! str_starts_with((string) $metaChannelLink->access_token, 'IG')
        ) {
            return response()->json([
                'message' => 'Captura el page_id de la página de Facebook vinculada (campo WABA/Página) para poder suscribirla.',
            ], 422);
        }

        $ok = $api->resubscribe($metaChannelLink);

        return response()->json([
            'ok' => $ok,
            'message' => $ok
                ? 'Suscripción reparada: la app del token quedó suscrita a la cuenta/página.'
                : 'No se pudo re-suscribir; revisa que el token esté vigente.',
        ], $ok ? 200 : 422);
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
            'waba_id' => $link->waba_id,
            'masked_token' => $link->maskedToken(),
            'name' => $link->name,
            'active' => $link->active,
            'last_event_at' => $link->last_event_at?->diffForHumans(short: true),
        ];
    }
}
