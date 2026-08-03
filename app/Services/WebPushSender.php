<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Envía las notificaciones push del panel. Es lo que hace que el encargado
 * se entere con el panel CERRADO — la campana sola exige tenerlo abierto.
 *
 * Sin llaves VAPID la función queda apagada por completo, sin ruido.
 */
class WebPushSender
{
    public function isConfigured(): bool
    {
        return ! empty(config('webpush.public_key'))
            && ! empty(config('webpush.private_key'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return int cuántas entregas se aceptaron
     */
    public function send(array $payload, ?int $userId = null): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        $subscriptions = PushSubscription::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->get();

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => (string) config('webpush.subject'),
                    'publicKey' => (string) config('webpush.public_key'),
                    'privateKey' => (string) config('webpush.private_key'),
                ],
            ]);
            $webPush->setDefaultOptions(['TTL' => (int) config('webpush.ttl')]);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }

        $byEndpoint = [];

        foreach ($subscriptions as $subscription) {
            try {
                $byEndpoint[$subscription->endpoint] = $subscription;

                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'publicKey' => $subscription->public_key,
                        'authToken' => $subscription->auth_token,
                    ]),
                    json_encode($payload, JSON_UNESCAPED_UNICODE),
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        $sent = 0;

        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                $sent++;
                $byEndpoint[$endpoint]?->forceFill(['last_used_at' => now()])->save();

                continue;
            }

            // 404/410 = el navegador se desuscribió o el dispositivo murió.
            // Guardarlas para siempre haría que cada aviso pague el costo de
            // intentar entregas que nunca van a llegar.
            if ($report->isSubscriptionExpired()) {
                PushSubscription::query()
                    ->where('endpoint_hash', PushSubscription::hashFor($endpoint))
                    ->delete();

                continue;
            }

            Log::warning('Push no entregado', [
                'reason' => $report->getReason(),
            ]);
        }

        return $sent;
    }
}
