<?php

namespace App\Services\Payments;

use App\Enums\ReservationStatus;
use App\Models\Conversation;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Services\Channels\DirectGuestMessenger;
use App\Services\Channels\OutboundMessenger;

/**
 * Aviso al huésped cuando su pago se confirma o rechaza (spec-pagos §7):
 * deja el mensaje en el hilo de la conversación ligada a la reserva y lo
 * empuja por el transporte del canal. Lo usan la cola de verificación
 * (transferencias) y el webhook de pasarelas. Sin conversación (reservas
 * del wizard web) el aviso sale directo: WhatsApp al teléfono que dejó el
 * huésped y correo si lo capturó.
 */
class PaymentGuestNotifier
{
    public function __construct(
        protected OutboundMessenger $messenger,
        protected DirectGuestMessenger $direct,
    ) {}

    public function paymentReceived(PaymentRequest $request): void
    {
        // Cobro de experiencia: sin conversación ni reserva de habitación —
        // aviso directo al huésped del tour (WhatsApp + correo).
        if ($request->isForExperience()) {
            $booking = $request->experienceBooking()->with(['session.experience', 'guest'])->first();

            if ($booking) {
                $when = $booking->session?->starts_at?->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm');
                $this->direct->sendToGuestFull(
                    $booking->guest,
                    'Pago recibido',
                    "Recibimos tu pago de {$request->amountLabel()}. Tu lugar en {$booking->session?->experience?->name} ({$booking->displayCode()}) está confirmado para el {$when}. Te esperamos.",
                    $booking->displayCode(),
                    array_values(array_filter([
                        ['label' => 'Experiencia', 'value' => (string) $booking->session?->experience?->name],
                        $when ? ['label' => 'Fecha', 'value' => $when] : null,
                        ['label' => 'Personas', 'value' => (string) $booking->people],
                        ['label' => 'Total', 'value' => $request->amountLabel()],
                    ])),
                );
            }

            return;
        }

        // Cobro consolidado de grupo: en la conversación donde se apartó y,
        // si no hay o no se entregó, directo al responsable.
        if ($request->isForGroup()) {
            $group = $request->group()->with(['guest', 'reservations'])->first();

            if ($group) {
                $this->groupPaymentReceived($group, (float) $request->amount);
            }

            return;
        }

        $reservation = $request->reservation()->first();

        $body = "Recibimos tu pago de {$request->amountLabel()} ({$request->conceptLabel()}).";
        $body .= $reservation->status === ReservationStatus::Confirmed && ! $this->confirmationJustSent($reservation)
            ? " Tu reserva {$reservation->displayCode()} está confirmada. Te esperamos — para tu registro, trae una identificación oficial."
                .$this->guaranteeNotice($reservation)
            : " Quedó registrado en tu reserva {$reservation->displayCode()}.";

        // Lo que falta, dicho en el mismo aviso: con el anticipo pagado el
        // huésped preguntaba después "¿y cuánto me falta?".
        $body .= $this->pendingLine($reservation);

        $confirmed = $reservation->status === ReservationStatus::Confirmed;

        $this->push($request->reservation_id, $body, wonLead: $confirmed, subject: 'Pago recibido', withCalendar: $confirmed, withContract: $confirmed);
    }

    /**
     * Pago de un grupo (verificado en /pagos, pasarela o mostrador): UN
     * mensaje por el GRP-, en la conversación donde se apartó. Antes salía
     * solo directo por WhatsApp o correo, la bandeja no se enteraba y el bot
     * seguía hablando de un anticipo que ya estaba pagado.
     */
    public function groupPaymentReceived(\App\Models\ReservationGroup $group, float $amount, ?string $method = null): void
    {
        $group->loadMissing(['guest', 'reservations']);

        $live = $group->reservations->filter(fn (Reservation $r) => in_array($r->status, [
            ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn,
        ], true));
        $allConfirmed = $live->isNotEmpty() && $live->every(fn (Reservation $r) => $r->status !== ReservationStatus::Pending);
        $pending = round($live->sum(fn (Reservation $r) => $r->pendingBalance()), 2);
        $code = $group->displayCode();

        $body = 'Recibimos tu pago de $'.number_format($amount, 2).$this->methodLabel($method).'.';
        $body .= $allConfirmed
            ? " Tu grupo {$code} ({$live->count()} habitaciones) está confirmado. Te esperamos — para tu registro, trae una identificación oficial."
                .$this->guaranteeNotice($live->first())
            : " Quedó registrado en tu grupo {$code}.";
        $body .= $pending > 0 ? ' Saldo pendiente: $'.number_format($pending, 2).'.' : '';

        $conversation = Conversation::query()
            ->whereIn('reservation_id', $group->reservations->pluck('id'))
            ->latest('id')
            ->first();

        if ($conversation) {
            $conversation->messages()->create([
                'direction' => 'out',
                'sender_type' => 'system',
                'body' => $body,
                'created_at' => now(),
            ]);
            $conversation->update(['last_message_at' => now()]);

            if ($allConfirmed) {
                $conversation->markLead(Conversation::LEAD_WON);
            }

            if ($this->messenger->pushToConversation($conversation, $body)) {
                return;
            }
        }

        $this->direct->sendToGuestFull(
            $group->guest,
            'Pago recibido',
            $body,
            $code,
            [
                ['label' => 'Grupo', 'value' => $code],
                ['label' => 'Habitaciones', 'value' => (string) $live->count()],
                ['label' => 'Pago', 'value' => '$'.number_format($amount, 2)],
            ],
        );
    }

    /**
     * Pago registrado en mostrador (efectivo, tarjeta o transferencia ya
     * verificada con folio): el huésped recibe su comprobante por el mismo
     * hilo, con la confirmación y lo que le falta, igual que cuando el pago
     * se aprueba en /pagos. Antes el mostrador registraba y el huésped no se
     * enteraba de nada.
     */
    public function manualPaymentReceived(Reservation $reservation, float $amount, string $method): void
    {
        $reservation->refresh();

        $confirmed = $reservation->status === ReservationStatus::Confirmed;
        $pending = $reservation->pendingBalance();

        $body = 'Recibimos tu pago de $'.number_format($amount, 2).$this->methodLabel($method).'.';
        $body .= $confirmed && ! $this->confirmationJustSent($reservation)
            ? " Tu reserva {$reservation->displayCode()} está confirmada. Te esperamos — para tu registro, trae una identificación oficial."
                .$this->guaranteeNotice($reservation)
            : " Quedó registrado en tu reserva {$reservation->displayCode()}.";
        $body .= $pending > 0
            ? $this->pendingLine($reservation)
            : ' Tu reserva quedó liquidada.';

        $this->push($reservation->id, $body, wonLead: $confirmed, subject: 'Pago recibido', withCalendar: $confirmed);
    }

    /**
     * "Saldo pendiente: $1,500.00, a más tardar el martes 13 de octubre de
     * 2026." El aviso decía cuánto faltaba pero no para cuándo, y el huésped
     * fue a preguntárselo al bot, que se lo inventó (cabañas 2026-09-24,
     * RES-2026-1792).
     */
    protected function pendingLine(Reservation $reservation): string
    {
        $pending = $reservation->pendingBalance();

        if ($pending <= 0) {
            return '';
        }

        $due = app(\App\Services\ReservationPolicy::class)->balanceDueDateLabel($reservation);

        return ' Saldo pendiente: $'.number_format($pending, 2)
            .($due !== null ? ", a liquidar a más tardar el {$due}." : '.');
    }

    /**
     * ¿El aviso de "reserva confirmada" salió hace nada? El personal suele
     * confirmar y luego registrar el pago, y el huésped recibía dos mensajes
     * seguidos diciendo "está confirmada" con el depósito repetido
     * (RES-2026-1792, 10:51 y 10:52).
     */
    protected function confirmationJustSent(Reservation $reservation): bool
    {
        return \Illuminate\Support\Facades\Cache::has($this->confirmationKey($reservation));
    }

    protected function confirmationKey(Reservation $reservation): string
    {
        return 'guest-confirmation-sent:'.(tenant('id') ?? 'central').':'.$reservation->id;
    }

    protected function methodLabel(?string $method): string
    {
        return match ($method) {
            'cash' => ' en efectivo',
            'card' => ' con tarjeta',
            'transfer' => ' por transferencia',
            default => '',
        };
    }

    /**
     * El staff generó un cobro desde el panel de reservas: el link o las
     * instrucciones de transferencia viajan solos al huésped (conversación
     * o WhatsApp/correo directo), no se quedan en la pantalla de recepción.
     * El bot y payments:collect-balance NO pasan por aquí — cada uno arma
     * y manda su propio mensaje por su canal.
     */
    public function paymentRequestIssued(PaymentRequest $request): void
    {
        // Solo cobros de reservas de habitación; experiencias y grupos
        // tienen sus propios flujos de aviso.
        if (! $request->reservation_id || $request->isForExperience() || $request->isForGroup()) {
            return;
        }

        $reservation = $request->reservation()->first();

        if (! $reservation) {
            return;
        }

        $body = "Tenemos listo el cobro de tu reserva {$reservation->displayCode()}: {$request->conceptLabel()} de {$request->amountLabel()}.";

        $body .= $request->checkout_url
            ? " Puedes pagarlo en este link seguro: {$request->checkout_url}. Al completar el pago, tu reserva se confirma sola."
            : $this->transferInstructions();

        $this->push($request->reservation_id, $body, subject: 'Opciones de pago de tu reserva');
    }

    /**
     * La fianza en los mensajes de confirmación, solo en hoteles que la
     * cobran (/ajustes/metodos-pago): mismo criterio que guaranteePublic —
     * que el depósito no sea sorpresa en el mostrador. El monto respeta los
     * escalones por volumen de la reserva.
     */
    protected function guaranteeNotice(?Reservation $reservation): string
    {
        $amount = app(\App\Services\ReservationPolicy::class)
            ->guaranteeAmountForReservation($reservation);

        if ($amount <= 0) {
            return '';
        }

        return ' Al llegar se cobra un depósito en garantía de $'.number_format($amount, 2)
            .' por habitación, que se te devuelve al registrar tu salida.';
    }

    /**
     * Cuentas activas del hotel para transferencia (mismo formato que el
     * cobro automático de saldos); sin cuentas capturadas, se invita a
     * responder para recibir las opciones.
     */
    protected function transferInstructions(): string
    {
        // Con la etiqueta correcta: decirle "cuenta" a una tarjeta hace que
        // la transferencia falle en la app del huésped.
        $accounts = collect(\App\Models\Property::query()->first()?->settings['bank_accounts'] ?? [])
            ->filter(fn (array $account) => ! empty($account['active']))
            ->map(fn (array $account) => \App\Support\BankAccountNumber::inlineSummary($account))
            ->implode(' | ');

        return $accounts === ''
            ? ' Respóndenos por aquí y te compartimos las opciones de pago.'
            : " Puedes transferir a: {$accounts}. En cuanto lo hagas, mándanos tu comprobante para verificarlo y dejar todo listo.";
    }

    public function paymentRejected(PaymentRequest $request, string $reason): void
    {
        if ($request->isForExperience()) {
            $booking = $request->experienceBooking()->with('guest')->first();
            $this->direct->sendToGuest(
                $booking?->guest,
                "No pudimos validar tu pago de la experiencia {$booking?->displayCode()}: {$reason}. Contacta al hotel y lo revisamos contigo.",
            );

            return;
        }

        $this->push(
            $request->reservation_id,
            "No pudimos validar tu pago: {$reason}. Si crees que es un error, respóndenos por aquí y lo revisamos contigo.",
            subject: 'Sobre tu pago',
        );
    }

    public function refundIssued(\App\Models\Refund $refund): void
    {
        if (! $refund->reservation_id) {
            return;
        }

        $code = $refund->reservation?->displayCode();
        $amount = '$'.number_format((float) $refund->amount, 2);
        $via = $refund->gateway
            ? 'por la misma vía en la que pagaste (puede tardar unos días en reflejarse)'
            : 'directamente por el hotel';

        $this->push(
            $refund->reservation_id,
            "Procesamos tu reembolso de {$amount} de la reserva {$code}, {$via}. Cualquier duda, respóndenos por aquí.",
            subject: 'Reembolso procesado',
        );
    }

    /**
     * Recordatorio de llegada (scheduler, 24 h antes): dónde, cuándo y el
     * código que le van a pedir en recepción.
     */
    public function arrivalReminder(Reservation $reservation): void
    {
        $arrival = $reservation->starts_at->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm');

        $this->push(
            $reservation->id,
            "Te esperamos: tu reserva {$reservation->displayCode()} ({$reservation->roomType?->name}) llega el {$arrival}. Presenta tu código y una identificación oficial en recepción. Si algo cambió, respóndenos por aquí o llama al hotel.",
            subject: 'Te esperamos pronto',
        );
    }

    /**
     * Aviso el día de la llegada (scheduler, horas antes de la entrada):
     * su habitación lo espera hoy, con código y hora.
     */
    public function arrivalSoonReminder(Reservation $reservation): void
    {
        $time = $reservation->starts_at->format('H:i');

        $this->push(
            $reservation->id,
            "Hoy es el día: tu habitación ({$reservation->roomType?->name}) te espera a partir de las {$time}. Presenta tu código {$reservation->displayCode()} y una identificación oficial en recepción. Te esperamos.",
            subject: 'Tu habitación te espera hoy',
        );
    }

    /**
     * Reserva confirmada (sin pago de por medio o confirmación manual del
     * staff): el huésped se entera, no solo el panel.
     */
    public function reservationConfirmed(Reservation $reservation): void
    {
        // Parte de un grupo: un solo aviso por el folio GRP- y con TODAS las
        // habitaciones. Caso real cabañas 2026-09-14 (Nancy, GRP-2026-0151):
        // apartó 3 cabañas y el aviso decía "Tu reserva RES-2026-1743 está
        // confirmada: Cabaña Sencilla 2" — una sola, con folio de reserva
        // suelta, y encima se repetía por cada habitación del grupo.
        if ($reservation->reservation_group_id !== null && $this->groupConfirmed($reservation)) {
            return;
        }

        $arrival = $reservation->starts_at->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm');

        $body = "Tu reserva {$reservation->displayCode()} está confirmada: {$reservation->roomType?->name}, llegada el {$arrival}. Te esperamos — para tu registro, trae una identificación oficial."
            .$this->guaranteeNotice($reservation);

        // Consulta pública /reserva: ver la reserva y sus pagos, y adelantar
        // el registro de llegada. El texto anterior la presentaba solo como
        // "pre-registro" sin decir qué era, y el dueño no entendía qué tenía
        // que ver (2026-09-13); ahora dice qué se hace ahí y con qué código.
        if ($lookup = $this->bookingLookupUrl()) {
            $body .= " Puedes consultar tu reserva y adelantar tu registro de llegada (hora estimada y placa del vehículo) en {$lookup}: entra con tu código {$reservation->displayCode()} y tu número de teléfono.";
        }

        \Illuminate\Support\Facades\Cache::put($this->confirmationKey($reservation), true, now()->addMinutes(30));

        $this->push(
            $reservation->id,
            $body,
            wonLead: true,
            subject: 'Reserva confirmada',
            withCalendar: true,
            // El contrato de hospedaje va aquí: es el momento en que el bot
            // lleva meses prometiéndolo.
            withContract: true,
        );
    }

    /**
     * Agradecimiento al completar la estancia (check-out manual o
     * automático, TransitionReservation::checkOut): despedida cálida y, si
     * el hotel capturó su URL de reseñas (/ajustes/metodos-pago), la
     * invitación a dejar una.
     */
    public function postStayThanks(\App\Models\Stay $stay): void
    {
        $hotel = \App\Models\Property::query()->first()?->name;
        $policy = app(\App\Services\ReservationPolicy::class);

        $body = 'Gracias por hospedarte'.($hotel ? " en {$hotel}" : ' con nosotros')
            .'. Fue un gusto atenderte y esperamos que hayas disfrutado tu estancia.';

        // Cuestionario de experiencia: una encuesta por estancia con token
        // público; se crea aquí porque este aviso sale UNA vez (el sello
        // thanks_sent_at vive en TransitionReservation). Requiere el módulo
        // encuestas (Profesional+); sin contexto de tenant (tests) aplica.
        $tenant = tenant();
        if (($tenant === null || $tenant->hasModule('encuestas')) && $policy->postStaySurveyEnabled()) {
            $survey = \App\Models\StaySurvey::forStay($stay);
            $url = $this->absoluteTenantUrl(route('tenant.survey', ['token' => $survey->token], false));
            $body .= " ¿Cómo te fue? Cuéntanos en un minuto: {$url}.";
        }

        if ($review = $policy->reviewUrl()) {
            $body .= " Si tienes un minuto, tu opinión nos ayuda mucho: puedes dejarnos una reseña aquí: {$review}.";
        }

        $body .= ' Te esperamos pronto de vuelta.';

        // Con reserva de por medio, el aviso sigue el hilo o el canal
        // directo de siempre; un walk-in sin reserva sale directo al
        // contacto del Guest. Sin contacto no hay a quién agradecer.
        if ($stay->reservation_id) {
            $this->push($stay->reservation_id, $body, subject: 'Gracias por tu visita');

            return;
        }

        $guest = $stay->guest;

        if (! $guest || (blank($guest->phone) && blank($guest->email))) {
            return;
        }

        $this->direct->sendToGuestFull($guest, 'Gracias por tu visita', $body);
    }

    /**
     * URL pública de la consulta de reserva (/reserva), SIEMPRE en el
     * dominio del hotel — mismo criterio que PaymentRequest::publicReturnUrl
     * (los avisos también salen de webhooks y schedulers, donde route() a
     * secas hereda un host equivocado). null si el hotel no tiene el módulo
     * motor-web: la página respondería 403.
     */
    protected function bookingLookupUrl(): ?string
    {
        $tenant = tenant();

        if (! $tenant || ! $tenant->hasModule('motor-web')) {
            return null;
        }

        return $this->absoluteTenantUrl(route('tenant.booking.lookup', [], false));
    }

    /**
     * URL pública SIEMPRE en el dominio del hotel — los avisos también
     * salen de webhooks y schedulers, donde url() a secas hereda un host
     * equivocado (mismo criterio que PaymentRequest::publicReturnUrl).
     */
    protected function absoluteTenantUrl(string $relative): string
    {
        $domain = tenant()?->domains()->value('domain');

        if (! $domain) {
            return url($relative);
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$domain}{$relative}";
    }

    /**
     * Confirmación de un grupo: un aviso, el folio GRP- y las habitaciones
     * por su nombre. Devuelve false si todavía no aplica (falta confirmar
     * alguna) para que el aviso suelto siga su curso.
     */
    protected function groupConfirmed(Reservation $reservation): bool
    {
        $group = $reservation->group()->with(['reservations.roomType', 'reservations.room'])->first();

        if (! $group) {
            return false;
        }

        $live = $group->reservations->filter(fn (Reservation $r) => in_array($r->status, [
            ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn,
        ], true));

        // Todo o nada: mientras alguna siga pendiente, el grupo no está
        // confirmado y decir que sí sería mentira.
        if ($live->isEmpty() || $live->contains(fn (Reservation $r) => $r->status === ReservationStatus::Pending)) {
            return false;
        }

        // Las habitaciones se confirman uno a uno en el mismo segundo: sin
        // esto el huésped recibiría el mismo aviso tres veces.
        if (! \Illuminate\Support\Facades\Cache::add('group-confirmed:'.tenant('id').':'.$group->id, true, now()->addDay())) {
            return true;
        }

        $rooms = $live
            ->map(fn (Reservation $r) => $r->room?->name ?: $r->roomType?->name)
            ->filter()
            ->unique()
            ->values();

        $arrival = $live->min('starts_at');
        $arrival = $arrival instanceof \Carbon\CarbonInterface
            ? $arrival->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm')
            : $reservation->starts_at->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm');

        $code = $group->displayCode();
        $body = "Tu grupo {$code} está confirmado: {$live->count()} habitaciones"
            .($rooms->isNotEmpty() ? ' ('.$rooms->join(', ', ' y ').')' : '')
            .", llegada el {$arrival}. Te esperamos — para tu registro, trae una identificación oficial."
            .$this->guaranteeNotice($live->first());

        if ($lookup = $this->bookingLookupUrl()) {
            $body .= " Puedes consultar tu reserva y adelantar tu registro de llegada en {$lookup}: entra con tu folio {$code} y tu número de teléfono.";
        }

        // El hilo puede estar colgado de cualquiera de las habitaciones del
        // grupo; se avisa por esa, no por la que disparó la confirmación.
        $threaded = Conversation::query()
            ->whereIn('reservation_id', $live->pluck('id'))
            ->latest('id')
            ->first();

        $this->push(
            $threaded?->reservation_id ?: $reservation->id,
            $body,
            wonLead: true,
            subject: 'Reserva confirmada',
            withCalendar: true,
            withContract: true,
        );

        return true;
    }

    protected function push(int $reservationId, string $body, bool $wonLead = false, string $subject = 'Sobre tu reserva', bool $withCalendar = false, bool $withContract = false): void
    {
        $conversation = Conversation::query()
            ->where('reservation_id', $reservationId)
            ->latest('id')
            ->first();

        if (! $conversation) {
            // Reserva sin hilo (wizard web): aviso directo por WhatsApp/correo.
            $reservation = Reservation::find($reservationId);

            if ($reservation) {
                $this->direct->send($reservation, $body, $subject, $withCalendar, $withContract);
            }

            return;
        }

        $conversation->messages()->create([
            'direction' => 'out',
            'sender_type' => 'system',
            'body' => $body,
            'created_at' => now(),
        ]);
        $conversation->update(['last_message_at' => now()]);

        if ($wonLead) {
            $conversation->markLead(Conversation::LEAD_WON);
        }

        $delivered = $this->messenger->pushToConversation($conversation, $body);

        // Hilo sin transporte real (webchat) o envío rechazado (p. ej. Meta
        // fuera de la ventana de 24 h): el mensaje quedaba solo guardado en
        // la bandeja y el huésped no se enteraba de nada — ni WhatsApp ni
        // correo. Respaldo directo por los canales del huésped.
        if (! $delivered) {
            $reservation = Reservation::find($reservationId);

            if ($reservation) {
                $this->direct->send($reservation, $body, $subject, $withCalendar, $withContract);
            }

            return;
        }

        // Entregado por el chat, pero la confirmación TAMBIÉN va por correo:
        // ahí viaja el contrato y el respaldo escrito. Antes, quien apartaba
        // por WhatsApp no recibía ningún correo y lo reclamaba (cabañas
        // 2026-09-13 Montserrat, 2026-09-15 Damaris).
        if ($withContract) {
            $reservation = Reservation::find($reservationId);

            if ($reservation) {
                $this->direct->mailTo($reservation, $body, $subject, $withCalendar, true);
            }
        }
    }
}
