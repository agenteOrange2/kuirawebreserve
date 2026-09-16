<?php

namespace App\Console\Commands;

use App\Models\PaymentRequest;
use Illuminate\Console\Command;

/**
 * Higiene de solicitudes de cobro vencidas (spec-pagos §4.1): una solicitud
 * que nadie pagó dentro de su vigencia deja de ser cobrable. El hold de la
 * reserva expira por su cuenta (reservations:expire-holds) — aquí solo se
 * cierra la solicitud para que la cola de verificación no muestre muertos.
 * Correr por tenant: tenants:run.
 *
 * EXCEPCIÓN: el cobro que YA trae comprobante no vence solo. La cola de
 * /pagos solo lista cobros PENDIENTES, así que vencerlo escondía el
 * comprobante que el huésped mandó y que nadie alcanzó a verificar: el
 * dinero quedaba en el banco y la reserva sin confirmar, sin que nadie se
 * enterara (cabañas 2026-09-15). Se sostiene en tramos de 12 h y el
 * personal recibe el aviso de nuevo.
 */
class ExpirePaymentRequests extends Command
{
    protected $signature = 'payments:expire-requests';

    protected $description = 'Marca vencidas las solicitudes de cobro pendientes cuya vigencia pasó';

    public function handle(): int
    {
        $sostenidos = $this->keepRequestsWithReceipt();

        $expired = PaymentRequest::query()
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->update(['status' => PaymentRequest::STATUS_EXPIRED, 'updated_at' => now()]);

        $this->info("Solicitudes de cobro vencidas: {$expired} · sostenidas con comprobante por verificar: {$sostenidos}");

        return self::SUCCESS;
    }

    /**
     * Cobros vencidos que traen comprobante: se les mueve la vigencia y
     * vuelve a sonar la campana en vez de desaparecer de la cola.
     */
    protected function keepRequestsWithReceipt(): int
    {
        $pendientes = PaymentRequest::query()
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->whereHas('media', fn ($query) => $query->where('collection_name', 'receipt'))
            ->with(['reservation', 'group', 'media'])
            ->get();

        $sostenidos = 0;

        foreach ($pendientes as $request) {
            $recibido = $request->getFirstMedia('receipt')?->created_at;

            // Tope: una semana. Un comprobante que nadie miró en siete días
            // ya es un caso del mostrador, no del reloj.
            if ($recibido === null || $recibido->lt(now()->subDays(7))) {
                continue;
            }

            $request->update(['expires_at' => now()->addHours(12)]);
            $sostenidos++;

            try {
                app(\App\Services\StaffNotifier::class)->notify(
                    type: \App\Models\StaffNotification::TYPE_PAYMENT,
                    title: 'Comprobante sin verificar',
                    body: 'El cobro de '.$request->subjectCode().' por '.$request->amountLabel()
                        .' tiene un comprobante desde hace '.$recibido->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE)
                        .' y sigue sin aprobarse. Revísalo en Pagos.',
                    url: '/pagos',
                    subject: $request->reservation ?? $request->group,
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $sostenidos;
    }
}
