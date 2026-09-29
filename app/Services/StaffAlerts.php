<?php

namespace App\Services;

use App\Jobs\SendStaffNoticeMail;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\StaffNotification;
use App\Models\Stay;
use Throwable;

/**
 * Avisos al HOTEL (no al huésped) de lo que pasa con sus reservas: campana y
 * push del panel, más correo a los destinatarios de /ajustes/avisos-hotel.
 *
 * Hasta 2026-09-29 el hotel solo se enteraba por la campana, y solo de las
 * reservas que no entraban por mostrador: ni pagos, ni cancelaciones, ni
 * salidas. El dueño de cabañas pidió que le llegue todo por correo, a él y
 * a quien él ponga.
 *
 * Avisar es cortesía: nada de aquí puede romper la acción que lo dispara.
 */
class StaffAlerts
{
    public const EVENT_RESERVATION_NEW = 'reservation_new';

    public const EVENT_PAYMENT = 'payment';

    public const EVENT_CANCELLATION = 'cancellation';

    public const EVENT_CHECKOUT = 'checkout';

    public const EVENT_SURVEY = 'survey';

    public const EVENTS = [
        self::EVENT_RESERVATION_NEW,
        self::EVENT_PAYMENT,
        self::EVENT_CANCELLATION,
        self::EVENT_CHECKOUT,
        self::EVENT_SURVEY,
    ];

    /** @param  bool  $bell  la campana calla lo que capturó el propio mostrador */
    public function reservationCreated(Reservation $reservation, bool $bell = true): void
    {
        $this->safely(function () use ($reservation, $bell) {
            if ($bell) {
                app(StaffNotifier::class)->notify(
                    type: StaffNotification::TYPE_RESERVATION,
                    title: 'Reserva nueva · '.$reservation->displayCode(),
                    body: trim(sprintf(
                        '%s · %s · %s',
                        $reservation->guest_name ?: 'Sin nombre',
                        $reservation->starts_at->format('d/m/Y H:i'),
                        $reservation->channelLabel(),
                    )),
                    url: '/reservas/'.$reservation->id,
                    subject: $reservation,
                );
            }

            // Con retraso: el bot liga su conversación a la reserva justo
            // DESPUÉS de crearla, y sin ella el correo no sabría decir si
            // entró por WhatsApp, Messenger o Instagram.
            $this->mail(self::EVENT_RESERVATION_NEW, ['reservation_id' => $reservation->id], delaySeconds: 30);
        });
    }

    public function groupCreated(ReservationGroup $group, bool $bell = true): void
    {
        $this->safely(function () use ($group, $bell) {
            $first = $group->reservations()->oldest('id')->first();

            if ($bell && $first !== null) {
                app(StaffNotifier::class)->notify(
                    type: StaffNotification::TYPE_RESERVATION,
                    title: 'Reserva de grupo nueva · '.$group->displayCode(),
                    body: trim(sprintf(
                        '%s · %d habitaciones · %s · %s',
                        $group->guest_name ?: 'Sin nombre',
                        $group->reservations()->count(),
                        $first->starts_at->format('d/m/Y H:i'),
                        $first->channelLabel(),
                    )),
                    url: '/reservas/'.$first->id,
                    subject: $group,
                );
            }

            $this->mail(self::EVENT_RESERVATION_NEW, ['group_id' => $group->id], delaySeconds: 30);
        });
    }

    /** @param  float|null  $amount  cobro de grupo: el monto completo, no la parte de esta habitación */
    public function paymentReceived(Reservation $reservation, Payment $payment, ?float $amount = null): void
    {
        $this->safely(function () use ($reservation, $payment, $amount) {
            $amount ??= (float) $payment->amount;

            app(StaffNotifier::class)->notify(
                type: StaffNotification::TYPE_PAYMENT,
                title: 'Pago recibido · '.$reservation->displayCode(),
                body: sprintf(
                    '%s · $%s · saldo $%s',
                    $reservation->guest_name ?: 'Sin nombre',
                    number_format($amount, 2),
                    number_format($reservation->fresh()->pendingBalance(), 2),
                ),
                url: '/reservas/'.$reservation->id,
                subject: $payment,
            );

            $this->mail(self::EVENT_PAYMENT, ['reservation_id' => $reservation->id, 'payment_id' => $payment->id, 'amount' => $amount]);
        });
    }

    public function reservationCancelled(Reservation $reservation, ?string $reason): void
    {
        $this->safely(function () use ($reservation, $reason) {
            app(StaffNotifier::class)->notify(
                type: StaffNotification::TYPE_RESERVATION,
                title: 'Reserva cancelada · '.$reservation->displayCode(),
                body: trim(($reservation->guest_name ?: 'Sin nombre').($reason ? ' · '.$reason : '')),
                url: '/reservas/'.$reservation->id,
                subject: $reservation,
            );

            $this->mail(self::EVENT_CANCELLATION, ['reservation_id' => $reservation->id, 'reason' => $reason]);
        });
    }

    /**
     * La hora de salida de una estancia: toca revisar la habitación. Un solo
     * aviso por estancia — el comando corre cada 5 minutos.
     */
    public function checkoutDue(Stay $stay): bool
    {
        if ($this->checkoutAlreadyNotified($stay)) {
            return false;
        }

        $this->safely(function () use ($stay) {
            app(StaffNotifier::class)->notify(
                type: StaffNotification::TYPE_RESERVATION,
                title: 'Salida · Hab. '.($stay->room?->number ?? '?'),
                body: $this->checkoutBody($stay),
                url: $stay->reservation_id ? '/reservas/'.$stay->reservation_id : '/plano',
                subject: $stay,
            );

            $this->mail(self::EVENT_CHECKOUT, ['stay_id' => $stay->id]);
        });

        return true;
    }

    /** El reloj cerró la estancia solo: la habitación ya está en sucia. */
    public function stayAutoClosed(Stay $stay): void
    {
        $this->safely(function () use ($stay) {
            app(StaffNotifier::class)->notify(
                type: StaffNotification::TYPE_RESERVATION,
                title: 'Salida · Hab. '.($stay->room?->number ?? '?').' se cerró sola',
                body: 'Pasó su hora de salida y quedó en sucia. '.$this->checkoutBody($stay),
                url: $stay->reservation_id ? '/reservas/'.$stay->reservation_id : '/plano',
                subject: $stay,
            );
        });
    }

    /**
     * Un huésped contestó su encuesta. Solo correo: la campana ya avisa de
     * las evaluaciones bajas (SurveyPageController, encuestas avanzadas).
     */
    public function surveyAnswered(\App\Models\StaySurvey $survey): void
    {
        $this->safely(fn () => $this->mail(self::EVENT_SURVEY, ['survey_id' => $survey->id]));
    }

    public function checkoutAlreadyNotified(Stay $stay): bool
    {
        return StaffNotification::query()
            ->where('subject_type', $stay->getMorphClass())
            ->where('subject_id', $stay->getKey())
            ->exists();
    }

    /** @return array<int, string> */
    public function recipients(): array
    {
        $settings = Property::query()->first()?->settings ?? [];

        return collect($settings['staff_notice_emails'] ?? [])
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    public function eventEnabled(string $event): bool
    {
        $settings = Property::query()->first()?->settings ?? [];

        return (bool) (($settings['staff_notice_events'] ?? [])[$event] ?? true);
    }

    protected function checkoutBody(Stay $stay): string
    {
        $stay->loadMissing('reservation');
        $pending = (float) ($stay->folio()['grand_pending'] ?? 0);

        return trim(sprintf(
            '%s · salida %s%s',
            $stay->reservation?->guest_name ?: ($stay->guest?->full_name ?? 'Huésped'),
            $stay->planned_end_at?->format('H:i') ?? '',
            $pending > 0 ? ' · saldo $'.number_format($pending, 2) : '',
        ));
    }

    /** @param  array<string, mixed>  $context */
    protected function mail(string $event, array $context, int $delaySeconds = 0): void
    {
        if (! $this->eventEnabled($event) || $this->recipients() === []) {
            return;
        }

        $job = SendStaffNoticeMail::dispatch($event, $context);

        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
    }

    protected function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
