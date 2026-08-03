<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\WebPushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alta y baja del navegador en las notificaciones push del panel.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request, WebPushSender $sender): JsonResponse
    {
        if (! $sender->isConfigured()) {
            return response()->json([
                'message' => 'Las notificaciones push no están configuradas en el servidor.',
            ], 422);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        // El endpoint es la identidad del dispositivo: si vuelve a
        // suscribirse (reinstaló, cambió de perfil), se actualiza en lugar
        // de duplicar y mandarle el mismo aviso dos veces.
        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'device' => substr((string) $request->userAgent(), 0, 255),
                'last_used_at' => now(),
            ],
        );

        return response()->json(['ok' => true], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
        ]);

        PushSubscription::query()
            ->where('endpoint_hash', PushSubscription::hashFor($data['endpoint']))
            // Solo las propias: nadie desconecta el dispositivo de otro.
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['ok' => true]);
    }

    /** Aviso de prueba, para comprobar que de verdad llega. */
    public function test(Request $request, WebPushSender $sender): JsonResponse
    {
        $sent = $sender->send([
            'title' => 'Notificaciones activadas',
            'body' => 'Así te van a llegar los avisos del hotel.',
            'url' => '/dashboard',
            'tag' => 'kuira-test',
        ], $request->user()->id);

        return response()->json(['sent' => $sent]);
    }
}
