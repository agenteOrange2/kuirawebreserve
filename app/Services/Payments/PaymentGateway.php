<?php

namespace App\Services\Payments;

use App\Models\Central\PaymentGatewayLink;
use App\Models\PaymentRequest;
use Illuminate\Http\Request;

/**
 * Contrato único de pasarela (spec-pagos §3.5): el resto del sistema — bot,
 * panel, scheduler, webhook — habla con PaymentRequest, nunca con un
 * proveedor concreto. Solo checkout ALOJADO por el proveedor (§3.2): jamás
 * tocamos datos de tarjeta.
 */
interface PaymentGateway
{
    /**
     * Crea el checkout hospedado para la solicitud.
     *
     * @return array{url: string, gateway_ref: string}
     *
     * @throws \RuntimeException si el proveedor rechaza la operación
     */
    public function createCheckout(PaymentRequest $request, PaymentGatewayLink $link): array;

    /**
     * Valida el webhook (firma / re-consulta server-to-server) y lo
     * normaliza. null = firma inválida (401). status 'ignored' = evento que
     * no nos interesa (200 para que el proveedor no reintente).
     *
     * @return array{event_id: ?string, uuid: ?string, gateway_ref: ?string, status: 'paid'|'expired'|'ignored', ref: ?string, fee: ?float}|null
     */
    public function parseWebhook(Request $request, PaymentGatewayLink $link): ?array;

    /**
     * Prueba de credenciales para el botón "Probar conexión" del panel.
     *
     * @return array{ok: bool, detail: string}
     */
    public function testCredentials(PaymentGatewayLink $link): array;

    /**
     * Reembolsa (total o parcial) un pago cobrado por esta pasarela (F4).
     * El dinero regresa por la misma vía por la que entró.
     *
     * @return array{ok: bool, ref: ?string, detail: string}
     */
    public function refund(\App\Models\Payment $payment, PaymentGatewayLink $link, float $amount): array;
}
