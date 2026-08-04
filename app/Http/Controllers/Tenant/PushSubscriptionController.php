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

    /**
     * Dispositivos del usuario. Sirve para quitar uno a distancia: si se
     * pierde el celular, desde ese aparato ya no se puede desconectar.
     */
    public function index(Request $request): JsonResponse
    {
        $devices = PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->latest('last_used_at')
            ->get()
            ->map(fn (PushSubscription $s) => [
                'id' => $s->id,
                'name' => $this->deviceName($s->device),
                'last_used_at' => $s->last_used_at?->diffForHumans(short: true),
            ]);

        return response()->json(['devices' => $devices]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Por endpoint (desde el propio aparato) o por id (desde otro,
            // cuando el aparato ya no está a la mano).
            'endpoint' => ['required_without:id', 'string', 'max:1000'],
            'id' => ['required_without:endpoint', 'integer'],
        ]);

        PushSubscription::query()
            // Solo las propias: nadie desconecta el dispositivo de otro.
            ->where('user_id', $request->user()->id)
            ->when(
                isset($data['endpoint']),
                fn ($q) => $q->where('endpoint_hash', PushSubscription::hashFor($data['endpoint'])),
                fn ($q) => $q->whereKey($data['id']),
            )
            ->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Nombre legible a partir del user agent: el staff no reconoce un
     * "Mozilla/5.0 (Linux; Android 13; SM-A536B)…", pero sí "Android".
     */
    protected function deviceName(?string $userAgent): string
    {
        $agent = (string) $userAgent;

        $system = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Dispositivo',
        };

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox') => 'Firefox',
            // Chrome se anuncia como Safari, así que va después.
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            default => null,
        };

        return $browser ? "{$system} · {$browser}" : $system;
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
