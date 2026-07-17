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
 */
class ExpirePaymentRequests extends Command
{
    protected $signature = 'payments:expire-requests';

    protected $description = 'Marca vencidas las solicitudes de cobro pendientes cuya vigencia pasó';

    public function handle(): int
    {
        $expired = PaymentRequest::query()
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->update(['status' => PaymentRequest::STATUS_EXPIRED, 'updated_at' => now()]);

        $this->info("Solicitudes de cobro vencidas: {$expired}");

        return self::SUCCESS;
    }
}
