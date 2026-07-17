<?php

namespace App\Console\Commands;

use App\Actions\Payments\IssuePaymentRequest;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Central\PaymentGatewayLink;
use App\Models\Conversation;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Channels\OutboundMessenger;
use App\Services\Evolution\EvolutionApi;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cobro automático de saldos (spec-pagos §7.2): reservas confirmadas con
 * saldo pendiente rumbo a su fecha límite. Emite la solicitud (link de
 * pasarela o transferencia), avisa por el canal de la conversación de
 * origen, recuerda a las 24 h de vencer y — solo si el hotel lo activó —
 * cancela al vencer. El default es NO cancelar: el impago genera alerta al
 * staff (franja de vencidos en la bandeja), no sorpresas. Mensajes de
 * plantilla (sin LLM), cada uno UNA sola vez. Correr por tenant: tenants:run.
 */
class CollectBalancePayments extends Command
{
    protected $signature = 'payments:collect-balance';

    protected $description = 'Solicita saldos pendientes por vencer, recuerda y gestiona vencidos';

    public function handle(IssuePaymentRequest $issuer, TransitionReservation $transition): int
    {
        // Interruptor global del módulo (Métodos de pago): apagado, ni se
        // piden saldos ni se cancela nada — las fechas límite quedan como
        // dato informativo en el panel.
        if (! app(\App\Services\ReservationPolicy::class)->balanceDueEnabled()) {
            $this->info('Fecha límite de pago total desactivada para este hotel; sin acciones.');

            return self::SUCCESS;
        }

        $settings = Property::query()->first()?->settings ?? [];
        $daysBefore = max(1, (int) ($settings['balance_request_days'] ?? 3));
        $autoCancel = (bool) ($settings['cancel_on_balance_overdue'] ?? false);

        $sent = 0;
        $canceled = 0;

        $reservations = Reservation::query()
            ->where('status', ReservationStatus::Confirmed)
            ->where('payment_status', '!=', PaymentStatus::Paid)
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<=', now()->addDays($daysBefore))
            ->get()
            ->filter(fn (Reservation $r) => $r->pendingBalance() > 0);

        $gatewayLink = PaymentGatewayLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('active', true)
            ->orderBy('id')
            ->first();

        foreach ($reservations as $reservation) {
            if ($reservation->payment_due_at->isPast()) {
                $canceled += $this->handleOverdue($reservation, $transition, $autoCancel) ? 1 : 0;

                continue;
            }

            $sent += $this->requestBalance($reservation, $issuer, $gatewayLink) ? 1 : 0;
        }

        $this->info("Avisos de saldo enviados: {$sent} · reservas canceladas por impago: {$canceled}");

        return self::SUCCESS;
    }

    /** Aviso inicial (ventana de N días) o recordatorio (últimas 24 h). */
    protected function requestBalance(Reservation $reservation, IssuePaymentRequest $issuer, ?PaymentGatewayLink $link): bool
    {
        $conversation = $this->conversationFor($reservation);

        // Sin conversación (o tomada por un humano) no hay cobro automático:
        // la franja de la bandeja y el panel siguen mostrando el pendiente.
        if (! $conversation || ! $conversation->bot_enabled) {
            return false;
        }

        $key = $reservation->payment_due_at->lte(now()->addDay()) ? 'balance_reminder' : 'balance_request';

        if ($conversation->followupSent($key)) {
            return false;
        }

        try {
            $request = $issuer->handle($reservation, PaymentRequest::METHOD_TRANSFER, null, $link);
        } catch (\RuntimeException $e) {
            // La pasarela falló: el cobro sale por transferencia (spec §7.1).
            report($e);

            try {
                $request = $issuer->handle($reservation, PaymentRequest::METHOD_TRANSFER);
            } catch (Throwable $inner) {
                report($inner);

                return false;
            }
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        $due = $reservation->payment_due_at->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm');

        $body = $key === 'balance_reminder'
            ? sprintf('Recordatorio: el saldo de %s de tu reserva %s vence el %s.', $request->amountLabel(), $reservation->displayCode(), $due)
            : sprintf('Tu reserva %s tiene un saldo de %s con fecha límite el %s.', $reservation->displayCode(), $request->amountLabel(), $due);

        $body .= $request->method === PaymentRequest::METHOD_GATEWAY
            ? " Puedes pagarlo en este link seguro: {$request->checkout_url}"
            : $this->transferInstructions();

        $this->send($conversation, $key, $body);

        return true;
    }

    /**
     * Saldo vencido: default = solo alerta (franja en la bandeja); cancelar
     * en automático es decisión explícita del hotel (spec-pagos §7.2).
     */
    protected function handleOverdue(Reservation $reservation, TransitionReservation $transition, bool $autoCancel): bool
    {
        if (! $autoCancel) {
            return false;
        }

        try {
            $transition->cancel($reservation, null, reason: 'Saldo no cubierto en la fecha límite (cancelación automática).');
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        $conversation = $this->conversationFor($reservation);

        if ($conversation && ! $conversation->followupSent('balance_cancelled')) {
            $this->send($conversation, 'balance_cancelled', sprintf(
                'Tu reserva %s se canceló porque el saldo no se cubrió en la fecha límite. Si aún te interesa, escríbenos y revisamos disponibilidad; sobre tu anticipo, recepción se pondrá en contacto contigo.',
                $reservation->displayCode(),
            ));
        }

        return true;
    }

    protected function conversationFor(Reservation $reservation): ?Conversation
    {
        return Conversation::query()
            ->where('reservation_id', $reservation->id)
            ->latest('id')
            ->first();
    }

    protected function transferInstructions(): string
    {
        $accounts = collect(Property::query()->first()?->settings['bank_accounts'] ?? [])
            ->filter(fn (array $account) => ! empty($account['active']))
            ->map(fn (array $account) => sprintf('%s, titular %s, cuenta %s', $account['bank'] ?? '', $account['holder'] ?? '', $account['clabe'] ?? ''))
            ->implode(' | ');

        return $accounts === ''
            ? ' Responde este mensaje y te compartimos las opciones de pago.'
            : " Puedes transferir a: {$accounts}. Cuando lo hagas, mándanos el comprobante por aquí para verificarlo.";
    }

    protected function send(Conversation $conversation, string $key, string $body): void
    {
        $conversation->messages()->create([
            'direction' => 'out',
            'sender_type' => 'bot',
            'body' => $body,
            'meta' => ['followup' => $key],
            'created_at' => now(),
        ]);

        $conversation->markFollowup($key);
        $conversation->update(['last_message_at' => now()]);

        app(OutboundMessenger::class)->pushToConversation($conversation, $body, EvolutionApi::humanDelay($body));
    }
}
