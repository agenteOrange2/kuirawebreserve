<?php

namespace App\Jobs;

use App\Mail\StaffNoticeMail;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\Stay;
use App\Services\StaffAlerts;
use App\Services\TenantMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Correo al hotel (StaffAlerts). El contenido se arma AQUÍ y no al
 * despacharlo: el aviso de reserva nueva sale con retraso para que el canal
 * del bot ya esté ligado, y así también lee el saldo más reciente.
 *
 * Corre bajo el tenant que lo despachó (stancl serializa el contexto).
 */
class SendStaffNoticeMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @param  array<string, mixed>  $context */
    public function __construct(public string $event, public array $context) {}

    public function handle(StaffAlerts $alerts, TenantMailer $tenantMailer): void
    {
        $recipients = $alerts->recipients();

        if ($recipients === [] || ! $alerts->eventEnabled($this->event)) {
            return;
        }

        $mail = $this->build();

        if ($mail === null) {
            return;
        }

        // SMTP propio del hotel si lo configuró; si no, el de la plataforma.
        ($tenantMailer->mailer() ?? Mail::mailer())->to($recipients)->send($mail);
    }

    public function build(): ?StaffNoticeMail
    {
        return match ($this->event) {
            StaffAlerts::EVENT_RESERVATION_NEW => isset($this->context['group_id'])
                ? $this->groupCreated()
                : $this->reservationCreated(),
            StaffAlerts::EVENT_PAYMENT => $this->paymentReceived(),
            StaffAlerts::EVENT_CANCELLATION => $this->reservationCancelled(),
            StaffAlerts::EVENT_CHECKOUT => $this->checkoutDue(),
            StaffAlerts::EVENT_SURVEY => $this->surveyAnswered(),
            default => null,
        };
    }

    protected function reservationCreated(): ?StaffNoticeMail
    {
        $reservation = Reservation::find($this->context['reservation_id'] ?? null);

        if ($reservation === null) {
            return null;
        }

        return new StaffNoticeMail(
            subjectLine: 'Reserva nueva '.$reservation->displayCode(),
            intro: 'Entró una reserva nueva por '.$reservation->channelLabel().'.',
            lines: [
                'Canal' => $reservation->channelLabel(),
                ...$this->reservationLines($reservation),
                'Anticipo' => $this->money((float) $reservation->deposit_amount),
                'Pagado' => $this->money($reservation->paidTotal()),
                'Estado' => $this->statusLabel($reservation),
            ],
            url: $this->reservationUrl($reservation),
        );
    }

    protected function groupCreated(): ?StaffNoticeMail
    {
        $group = ReservationGroup::with('reservations.room')->find($this->context['group_id'] ?? null);
        $first = $group?->reservations->first();

        if ($group === null || $first === null) {
            return null;
        }

        return new StaffNoticeMail(
            subjectLine: 'Reserva de grupo nueva '.$group->displayCode(),
            intro: 'Entró una reserva de grupo por '.$first->channelLabel().' con '.$group->reservations->count().' habitaciones.',
            lines: [
                'Canal' => $first->channelLabel(),
                'Grupo' => $group->displayCode(),
                'Huésped' => $group->guest_name ?: ($first->guest_name ?: 'Sin nombre'),
                'Teléfono' => $first->guest?->phone ?: 'Sin teléfono',
                'Habitaciones' => $group->reservations->map(fn (Reservation $r) => $r->room?->number ?? '?')->implode(', '),
                'Llegada' => $this->date($first->starts_at),
                'Salida' => $this->date($first->ends_at),
                'Total' => $this->money((float) $group->reservations->sum('total_amount')),
                'Anticipo' => $this->money((float) $group->reservations->sum('deposit_amount')),
            ],
            url: $this->reservationUrl($first),
        );
    }

    protected function paymentReceived(): ?StaffNoticeMail
    {
        $reservation = Reservation::find($this->context['reservation_id'] ?? null);
        $payment = Payment::find($this->context['payment_id'] ?? null);

        if ($reservation === null || $payment === null) {
            return null;
        }

        $amount = (float) ($this->context['amount'] ?? $payment->amount);

        $method = match ($payment->method) {
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            Payment::METHOD_ONLINE => 'Pago en línea',
            default => (string) $payment->method,
        };

        return new StaffNoticeMail(
            subjectLine: 'Pago recibido '.$reservation->displayCode(),
            intro: 'Se registró un pago de '.$this->money($amount).' ('.$method.').',
            lines: [
                'Monto' => $this->money($amount),
                'Método' => $method,
                'Folio' => $payment->reference ?: null,
                ...$this->reservationLines($reservation),
                'Pagado' => $this->money($reservation->paidTotal()),
                'Saldo' => $this->money($reservation->pendingBalance()),
                'Estado' => $this->statusLabel($reservation),
            ],
            url: $this->reservationUrl($reservation),
        );
    }

    protected function reservationCancelled(): ?StaffNoticeMail
    {
        $reservation = Reservation::find($this->context['reservation_id'] ?? null);

        if ($reservation === null) {
            return null;
        }

        $paid = $reservation->paidTotal();

        return new StaffNoticeMail(
            subjectLine: 'Reserva cancelada '.$reservation->displayCode(),
            intro: $paid > 0
                ? 'Se canceló una reserva que tenía '.$this->money($paid).' pagado. Revisa si hay que devolver o conservar ese dinero.'
                : 'Se canceló una reserva.',
            lines: [
                'Motivo' => ($this->context['reason'] ?? null) ?: 'Sin motivo capturado',
                ...$this->reservationLines($reservation),
                'Pagado' => $this->money($paid),
                'Canal' => $reservation->channelLabel(),
            ],
            url: $this->reservationUrl($reservation),
        );
    }

    protected function checkoutDue(): ?StaffNoticeMail
    {
        $stay = Stay::with(['room', 'reservation', 'guest'])->find($this->context['stay_id'] ?? null);

        if ($stay === null) {
            return null;
        }

        $pending = (float) ($stay->folio()['grand_pending'] ?? 0);
        $room = $stay->room?->number ?? '?';

        return new StaffNoticeMail(
            subjectLine: 'Salida hab. '.$room,
            intro: "La habitación {$room} llegó a su hora de salida: toca revisarla.",
            lines: [
                'Habitación' => $room,
                'Huésped' => $stay->reservation?->guest_name ?: ($stay->guest?->full_name ?? 'Huésped'),
                'Salida' => $this->date($stay->planned_end_at),
                'Reserva' => $stay->reservation?->displayCode(),
                'Saldo' => $pending > 0 ? $this->money($pending).' por cobrar' : 'Sin saldo',
            ],
            url: $stay->reservation ? $this->reservationUrl($stay->reservation) : $this->panelUrl('/plano'),
        );
    }

    protected function surveyAnswered(): ?StaffNoticeMail
    {
        $survey = \App\Models\StaySurvey::with(['stay.room', 'stay.reservation', 'guest'])->find($this->context['survey_id'] ?? null);

        if ($survey === null || $survey->rating === null) {
            return null;
        }

        $stay = $survey->stay;
        $room = $stay?->room?->number;
        $low = (int) $survey->rating <= 2
            || collect($survey->answers ?? [])->contains(fn ($value) => (int) $value <= 2);

        $aspects = collect(\App\Models\StaySurvey::aspects())
            ->mapWithKeys(fn (array $aspect) => [
                $aspect['label'] => ($value = $survey->answerFor($aspect['key'])) !== null ? $value.'/5' : null,
            ])
            ->all();

        return new StaffNoticeMail(
            subjectLine: ($low ? 'Evaluación baja ' : 'Encuesta contestada ').$survey->rating.'/5'.($room ? ' · hab. '.$room : ''),
            intro: $low
                ? 'Un huésped calificó mal su estancia. Conviene contactarlo antes de que deje una reseña pública.'
                : 'Un huésped contestó la encuesta de su estancia.',
            lines: [
                'Calificación general' => $survey->rating.'/5',
                ...$aspects,
                'Comentario' => $survey->comment ?: 'Sin comentario',
                'Huésped' => $stay?->reservation?->guest_name ?: ($survey->guest?->full_name ?? $stay?->guest_name ?? 'Huésped'),
                'Habitación' => $room,
                'Reserva' => $stay?->reservation?->displayCode(),
                'Salida' => $this->date($stay?->check_out_at),
            ],
            url: $this->panelUrl('/encuestas'),
        );
    }

    /** @return array<string, string|null> */
    protected function reservationLines(Reservation $reservation): array
    {
        return [
            'Reserva' => $reservation->displayCode(),
            'Huésped' => $reservation->guest_name ?: 'Sin nombre',
            'Teléfono' => $reservation->guest?->phone ?: 'Sin teléfono',
            'Habitación' => trim(($reservation->roomType?->name ?? '').' '.($reservation->room ? '· Hab. '.$reservation->room->number : '')) ?: null,
            'Llegada' => $this->date($reservation->starts_at),
            'Salida' => $this->date($reservation->ends_at),
            'Personas' => (string) ($reservation->num_people ?? 1),
            'Total' => $this->money((float) $reservation->total_amount),
        ];
    }

    protected function statusLabel(Reservation $reservation): string
    {
        return $reservation->status->label();
    }

    protected function reservationUrl(Reservation $reservation): string
    {
        return $this->panelUrl('/reservas/'.$reservation->id);
    }

    /** El correo se lee fuera del panel: la liga tiene que ser absoluta. */
    protected function panelUrl(string $path): string
    {
        $domain = tenant()?->domains()->value('domain');

        return ($domain ? 'https://'.$domain : rtrim((string) config('app.url'), '/')).$path;
    }

    protected function date($moment): string
    {
        return $moment ? $moment->locale('es')->isoFormat('ddd D [de] MMM YYYY, HH:mm') : '';
    }

    protected function money(float $amount): string
    {
        return '$'.number_format($amount, 2);
    }
}
