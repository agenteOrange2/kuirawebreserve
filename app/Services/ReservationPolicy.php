<?php

namespace App\Services;

use App\Enums\RateDurationUnit;
use App\Models\Property;
use App\Models\RatePlan;
use Carbon\CarbonInterface;

/**
 * Plazos de reservas y cobros configurables por hotel (settings del
 * Property, se administran en /ajustes/metodos-pago). Un solo lugar lee y
 * traduce valor+unidad a minutos/fechas; los defaults son los mismos que
 * regían cuando esto era config fija — un hotel sin ajustes guardados se
 * comporta idéntico que antes.
 */
class ReservationPolicy
{
    /** @var array<string, mixed>|null */
    protected ?array $settings = null;

    /**
     * Cuánto vive un apartado (reserva pendiente) antes de liberarse solo.
     */
    public function holdMinutes(): int
    {
        $minutes = $this->minutesFrom('hold_value', 'hold_unit');

        return $minutes ?? (int) config('reservations.hold_minutes', 30);
    }

    /**
     * Vigencia de un cobro por transferencia (hay banco de por medio; la
     * de pasarela se queda en config: la limita el proveedor, no el hotel).
     */
    public function transferMinutes(): int
    {
        $minutes = $this->minutesFrom('transfer_valid_value', 'transfer_valid_unit');

        return $minutes ?? ((int) config('payments.transfer_hours', 24)) * 60;
    }

    /**
     * ¿Se reciben transferencias a esta hora? Horario opcional por hotel
     * (cabañas 2026-09-11: solo de 9 a 5; fuera de ese horario solo se
     * cobra en línea, porque de noche nadie verifica depósitos y el apartado
     * vence antes). Sin horario configurado, siempre.
     */
    public function transferOpenNow(?CarbonInterface $at = null): bool
    {
        $settings = $this->settings();

        if (empty($settings['transfer_hours_enabled'])) {
            return true;
        }

        $at ??= now();
        $minutes = $at->hour * 60 + $at->minute;

        return $minutes >= $this->clockMinutes((string) ($settings['transfer_hours_open'] ?? '09:00'))
            && $minutes < $this->clockMinutes((string) ($settings['transfer_hours_close'] ?? '17:00'));
    }

    /**
     * Las cuentas que se le pueden enseñar al huésped EN ESTE MOMENTO, listas
     * para pintar. Vacías si el hotel apagó la transferencia o si está fuera
     * de su horario.
     *
     * Antes cada wizard armaba su propia lista y solo el de habitaciones, al
     * cobrar, miraba el horario: las opciones de pago enseñaban
     * "Transferencia bancaria" a medianoche en los tres wizards, y grupos y
     * experiencias hasta la aceptaban (cabañas, 17-sep-2026).
     *
     * @return \Illuminate\Support\Collection<int, array{banco: string, titular: string, cuenta: string, tipo: string, aviso: ?string, alternativa: ?array}>
     */
    public function guestTransferAccounts(bool $transferEnabled): \Illuminate\Support\Collection
    {
        if (! $transferEnabled || ! $this->transferOpenNow()) {
            return collect();
        }

        return $this->guestAccounts();
    }

    /**
     * Las cuentas activas como las lee el huésped, SIN mirar el horario: la
     * consulta de reserva las enseña mientras su cobro por transferencia siga
     * vivo, aunque sea de noche.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function guestAccounts(): \Illuminate\Support\Collection
    {
        return collect($this->settings()['bank_accounts'] ?? [])
            ->filter(fn (array $account) => ! empty($account['active']))
            ->map(fn (array $account) => self::guestAccountPayload($account))
            ->values();
    }

    /**
     * Una cuenta contada al huésped: el número que se le da PRIMERO (la CLABE
     * si la hay), qué es, cómo usarlo, y la tarjeta como alternativa para
     * quien en su app solo puede transferir a tarjeta. El número de cuenta
     * capturado aparte es interno y NUNCA sale por aquí.
     *
     * @param  array<string, mixed>  $account
     * @return array{banco: string, titular: string, cuenta: string, tipo: string, aviso: ?string, alternativa: ?array{cuenta: string, tipo: string, aviso: ?string}}
     */
    public static function guestAccountPayload(array $account): array
    {
        $datos = \App\Support\BankAccountNumber::normalize($account);

        return [
            'banco' => $datos['bank'],
            'titular' => $datos['holder'],
            'cuenta' => $datos['primary']['number'] ?? '',
            'tipo' => $datos['primary']['label'] ?? 'Cuenta',
            'aviso' => $datos['primary']['hint'] ?? null,
            'alternativa' => $datos['alternate'] === null ? null : [
                'cuenta' => $datos['alternate']['number'],
                'tipo' => $datos['alternate']['label'],
                'aviso' => $datos['alternate']['hint'],
            ],
        ];
    }

    /**
     * Por qué no hay transferencia ahora, dicho al huésped, o null si no es
     * cuestión de horario (el hotel la apagó o no tiene cuentas). Sin esto el
     * wizard respondía "el hotel aún no tiene métodos de cobro" a quien
     * llegaba de noche a un hotel que sí cobra por transferencia de día.
     */
    public function transferClosedNotice(): ?string
    {
        $hasAccounts = collect($this->settings()['bank_accounts'] ?? [])
            ->contains(fn (array $account) => ! empty($account['active']));

        if (! $hasAccounts || $this->transferOpenNow() || ($label = $this->transferHoursLabel()) === null) {
            return null;
        }

        // Sin prometer "paga en línea": un hotel sin pasarela no lo permite.
        return "Las transferencias se reciben {$label}.";
    }

    /**
     * Un plazo dicho como lo diría una persona: "20 minutos", "1 hora",
     * "1 h 30 min", "3 horas". Convertirlo a horas enteras decía "Vigente por
     * 0 horas" a un cobro de 20 o 60 minutos.
     */
    public static function durationLabel(int $minutes): string
    {
        $minutes = max(0, $minutes);

        if ($minutes < 60) {
            return $minutes === 1 ? '1 minuto' : "{$minutes} minutos";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($rest > 0) {
            return "{$hours} h {$rest} min";
        }

        return $hours === 1 ? '1 hora' : "{$hours} horas";
    }

    /** "de 9:00 AM a 5:00 PM", o null si el hotel no tiene horario. */
    public function transferHoursLabel(): ?string
    {
        $settings = $this->settings();

        if (empty($settings['transfer_hours_enabled'])) {
            return null;
        }

        return 'de '.$this->clockLabel((string) ($settings['transfer_hours_open'] ?? '09:00'))
            .' a '.$this->clockLabel((string) ($settings['transfer_hours_close'] ?? '17:00'));
    }

    protected function clockMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time.':0'));

        return $hours * 60 + $minutes;
    }

    protected function clockLabel(string $time): string
    {
        $minutes = $this->clockMinutes($time);

        return now()->startOfDay()->addMinutes($minutes)->format('g:i A');
    }

    /**
     * Cuánto se sostiene un apartado cuando el huésped YA mandó su
     * comprobante por el chat: el reloj se detiene mientras el hotel
     * verifica el depósito. Caso real cabañas 2026-09-10: el comprobante
     * llegó a las 20:19, el apartado venció a las 20:27 y el bot le dijo
     * "venció" a quien ya había pagado. De noche nadie verifica hasta el día
     * siguiente, por eso el default es de un día.
     */
    public function proofReviewMinutes(): int
    {
        return max(60, (int) config('reservations.proof_review_hours', 24) * 60);
    }

    /**
     * ¿El hotel exige el pago total antes de la llegada? (interruptor
     * global del módulo de fecha límite / cobro automático de saldos).
     */
    public function balanceDueEnabled(): bool
    {
        return (bool) ($this->settings()['balance_due_enabled'] ?? true);
    }

    /**
     * WhatsApps a los que el huésped manda su comprobante de transferencia
     * — cada uno con su lada explícita (México, EE. UU...), listos para
     * link wa.me. Lista vacía = el wizard dice "el hotel te contactará".
     *
     * @return array<int, string>
     */
    public function transferWhatsapps(): array
    {
        $entries = $this->settings()['transfer_whatsapps'] ?? null;

        // Compatibilidad: el campo viejo de un solo número sin lada propia.
        if (! is_array($entries)) {
            $legacy = preg_replace('/\D+/', '', (string) ($this->settings()['transfer_whatsapp'] ?? ''));

            if ($legacy === '') {
                return [];
            }

            $entries = [[
                'code' => $this->settings()['phone_country_code'] ?? '52',
                'number' => $legacy,
            ]];
        }

        return collect($entries)
            ->map(function ($entry) {
                $code = preg_replace('/\D+/', '', (string) ($entry['code'] ?? '52')) ?: '52';
                $number = preg_replace('/\D+/', '', (string) ($entry['number'] ?? ''));

                return $number === '' ? null : $code.$number;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Cuánta antelación exige el hotel para liquidar ("una semana antes de
     * llegar"), en palabras. Sale de /ajustes/metodos-pago/plazos-y-saldo.
     * null = el hotel no exige pago total anticipado.
     */
    public function balanceDueLabel(): ?string
    {
        if (! $this->balanceDueEnabled()) {
            return null;
        }

        $value = (int) ($this->settings()['balance_due_value'] ?? 5);
        $unit = RateDurationUnit::tryFrom((string) ($this->settings()['balance_due_unit'] ?? 'day')) ?? RateDurationUnit::Day;

        return $value < 1 ? null : $unit->label($value).' antes de la llegada';
    }

    /**
     * Aviso de liquidación listo para decírselo al huésped, con la FECHA
     * concreta cuando la hay. Lo usa el asistente al cotizar: el plazo vivía
     * solo en una FAQ y el bot cotizaba sin mencionarlo, así que la gente se
     * enteraba tarde de que el saldo vence antes de llegar.
     *
     * Sin fecha calculable (llegada demasiado próxima para abrir un plazo)
     * el compromiso sigue en pie, pero se enuncia sin fecha.
     */
    public function balanceDueNotice(RatePlan $ratePlan, CarbonInterface $start): ?string
    {
        $label = $this->balanceDueLabel();

        if ($label === null) {
            return null;
        }

        $due = $this->paymentDueAt($ratePlan, $start);

        // Sin fecha, o con una que YA pasó (llegada más próxima que la
        // ventana: la tarifa fija su propio plazo sin mirar el calendario),
        // el compromiso sigue pero no se puede citar un día — anunciar "a
        // más tardar el martes" cuando el martes fue ayer es peor que no
        // decir fecha.
        if ($due === null || $due->isPast()) {
            return 'El pago total debe quedar liquidado antes de tu llegada.';
        }

        return 'El pago total debe quedar liquidado a más tardar el '
            .$due->translatedFormat('l j \d\e F')
            ." ({$label}).";
    }

    /**
     * Hasta cuándo sigue apartada una reserva, en palabras que el huésped
     * entiende: "hoy a las 8:50 PM", "mañana a las 10:00 AM", "el lunes 14
     * de septiembre a las 9:00 AM". El bot recibía la hora como timestamp
     * ISO y la traducía mal (o se inventaba "20 minutos").
     */
    public function holdDeadlineLabel(CarbonInterface $at): string
    {
        $now = now();

        $when = match (true) {
            $at->isSameDay($now) => 'hoy',
            $at->isSameDay($now->addDay()) => 'mañana',
            default => 'el '.$at->locale('es')->isoFormat('dddd D [de] MMMM'),
        };

        return $when.' a las '.$at->format('g:i A');
    }

    /**
     * La verdad sobre un apartado vivo, lista para decírsela al huésped:
     * hasta qué hora se sostiene, que se libera si no se paga antes, y que
     * después se puede retomar con el mismo código si la habitación sigue
     * libre.
     *
     * Una sola frase para las tres superficies que hablan del vencimiento
     * (la respuesta de solicitar_pago, el guardián del bot y el recordatorio
     * automático). Caso real cabañas 2026-09-13 (RES-2026-1727): el apartado
     * vencía a las 8:50 PM y el bot le dijo al huésped que podía transferir
     * "mañana" — y a las 8:50 el sistema le avisó que su apartado venció.
     *
     * null si la reserva no es un apartado vigente.
     */
    public function holdDeadlineNotice(\App\Models\Reservation $reservation): ?string
    {
        if ($reservation->status !== \App\Enums\ReservationStatus::Pending
            || $reservation->hold_expires_at === null
            || $reservation->hold_expires_at->isPast()) {
            return null;
        }

        // Un grupo se nombra por su GRP-: con el folio de una sola cabaña el
        // huésped creyó que tenía que reactivar "el 1747" (GRP-2026-0152).
        $group = $reservation->reservation_group_id ? $reservation->group : null;

        $formal = $this->formalAddress();

        if ($group !== null) {
            return ($formal ? 'Su grupo ' : 'Tu grupo ').$group->displayCode().' queda guardado hasta '
                .$this->holdDeadlineLabel($reservation->hold_expires_at)
                .'. Si no se paga antes de esa hora, las habitaciones se liberan. '
                .($formal
                    ? 'Si después quiere retomarlo, escríbame y lo reactivo con el mismo código si siguen libres.'
                    : 'Si después quieres retomarlo, escríbeme y lo reactivo con el mismo código si siguen libres.');
        }

        return ($formal ? 'Su apartado ' : 'Tu apartado ').$reservation->displayCode().' queda guardado hasta '
            .$this->holdDeadlineLabel($reservation->hold_expires_at)
            .'. Si no se paga antes de esa hora, la habitación se libera. '
            .($formal
                ? 'Si después quiere retomarlo, escríbame y lo reactivo con el mismo código si sigue libre.'
                : 'Si después quieres retomarlo, escríbeme y lo reactivo con el mismo código si sigue libre.');
    }

    /**
     * ¿El hotel le habla de usted al huésped? Opt-in (`settings.formal_address`):
     * los avisos automáticos (recordatorio y vencimiento del apartado,
     * reenganche, fuera de horario) son plantillas y no pasan por el modelo,
     * así que salían de tú aunque el hotel pidiera usted (Hotel México
     * 2026-10-01). Apagado = los textos de siempre.
     */
    public function formalAddress(): bool
    {
        return (bool) ($this->settings()['formal_address'] ?? false);
    }

    /**
     * Fecha límite de pago total para una reserva: la tarifa manda si
     * define su propia anticipación (comportamiento de siempre); si no, el
     * default del hotel (5 días). El default solo aplica cuando queda al
     * menos 24 h en el futuro — para llegadas más próximas no tiene caso
     * abrir una fecha límite ya vencida que dispararía cancelaciones.
     */
    public function paymentDueAt(RatePlan $ratePlan, CarbonInterface $start): ?CarbonInterface
    {
        if (! $this->balanceDueEnabled()) {
            return null;
        }

        $due = $ratePlan->paymentDueAt($start);

        if ($due !== null) {
            // Mismo candado que abajo: una fecha límite que nace vencida no
            // es una fecha límite. Con la tarifa en "una semana antes", quien
            // reserva con tres días de anticipación tenía el plazo cumplido
            // antes de existir, y el barrido de saldos lo trataba como
            // moroso desde el primer minuto (caso real cabañas 2026-09-12,
            // RES-2026-1718). Sin plazo, el saldo se cobra a mano.
            return $due->gt(now()->addDay()) ? $due : null;
        }

        $value = (int) ($this->settings()['balance_due_value'] ?? 5);
        $unit = RateDurationUnit::tryFrom((string) ($this->settings()['balance_due_unit'] ?? 'day')) ?? RateDurationUnit::Day;

        if ($value < 1) {
            return null;
        }

        $due = $unit->subtractFrom($start, $value);

        return $due->gt(now()->addDay()) ? $due : null;
    }

    /**
     * Plazo para pagar en el hotel: cuánto vive el apartado cuando el
     * huésped eligió "pagar en el hotel" (efectivo). Reloj PROPIO — no
     * comparte perilla con el hold corto ni con la transferencia, porque ir
     * físicamente a pagar es otro esfuerzo (un motel querrá 3 h, un hotel
     * de destino 48). Default: 24 h.
     */
    public function cashDeadlineMinutes(): int
    {
        return $this->minutesFrom('cash_deadline_value', 'cash_deadline_unit') ?? 24 * 60;
    }

    /**
     * ¿El hotel ofrece "pagar en el hotel" (efectivo) al reservar? Doble
     * llave: la plataforma permite el método (PaymentMethodGate, con toggle
     * global y override por hotel en /admin/payments) Y el hotel lo activó
     * en /ajustes/metodos-pago. Compatibilidad: los hoteles que ya usaban el
     * modo "ambos" (payment_mode=optional) lo tienen prendido por default —
     * ese modo ERA pagar al llegar antes de existir este interruptor.
     */
    public function cashPaymentEnabled(): bool
    {
        $optIn = $this->settings()['cash_payment_enabled']
            ?? (($this->settings()['payment_mode'] ?? 'automatic') === 'optional');

        return (bool) $optIn
            && app(\App\Services\Payments\PaymentMethodGate::class)->enabledFor((string) tenant('id'), 'cash');
    }

    /**
     * Formas de cobro que acepta el MOSTRADOR (efectivo, terminal, depósito
     * recibido en recepción). Es otra cosa que PaymentMethodGate: ese rige
     * el cobro EN LÍNEA que se le ofrece al huésped en el wizard público
     * (pasarelas, transferencia con comprobante, "pago al llegar"), y un
     * hotel puede tener terminal bancaria sin ninguna pasarela, o al revés.
     *
     * Default: las tres, que es como operaba el panel antes de existir este
     * ajuste. Nunca devuelve vacío: sin ninguna marcada, el efectivo queda
     * — un mostrador que no puede cobrar de ninguna forma no es un estado
     * válido, es un candado.
     *
     * @return array<int, string>
     */
    public function counterMethods(): array
    {
        $saved = $this->settings()['counter_methods'] ?? null;

        if (! is_array($saved)) {
            return \App\Models\Payment::METHODS;
        }

        $methods = array_values(array_intersect(\App\Models\Payment::METHODS, $saved));

        return $methods ?: ['cash'];
    }

    /** ¿La recepción puede cobrar así? */
    public function counterMethodEnabled(string $method): bool
    {
        return in_array($method, $this->counterMethods(), true);
    }

    /**
     * ¿El walk-in (mostrador) se cobra al registrar la llegada? Default:
     * no — la cuenta final se cobra al registrar la salida, como siempre.
     * Con esto prendido, el modal de llegada pide el método de pago y el
     * hospedaje queda pagado desde el inicio (al salir solo consumos).
     */
    public function walkinChargeOnCheckIn(): bool
    {
        return ($this->settings()['walkin_charge'] ?? 'checkout') === 'checkin';
    }

    /**
     * Política de cancelación efectiva para una tarifa: la tarifa manda si
     * define la suya (spec-pagos F4); si no, la default del hotel cuando
     * está prendida en /ajustes/metodos-pago. null = sin política — con
     * dinero pagado nadie cancela solo y el reembolso queda a criterio.
     *
     * @return array{value: int, unit: RateDurationUnit, penalty: float}|null
     */
    public function cancellationPolicyFor(?RatePlan $plan): ?array
    {
        if ($plan && $plan->hasCancellationPolicy()) {
            return [
                'value' => (int) $plan->cancel_free_value,
                'unit' => $plan->cancel_free_unit,
                'penalty' => $plan->cancel_penalty_percent !== null ? (float) $plan->cancel_penalty_percent : 100.0,
            ];
        }

        if (! ($this->settings()['cancel_policy_enabled'] ?? false)) {
            return null;
        }

        $value = (int) ($this->settings()['cancel_free_value'] ?? 0);
        $unit = RateDurationUnit::tryFrom((string) ($this->settings()['cancel_free_unit'] ?? ''));

        if ($value < 1 || $unit === null) {
            return null;
        }

        $penalty = $this->settings()['cancel_penalty_percent'] ?? null;

        return [
            'value' => $value,
            'unit' => $unit,
            'penalty' => is_numeric($penalty) ? min(100.0, max(0.0, (float) $penalty)) : 100.0,
        ];
    }

    /** Último momento para cancelar con reembolso completo: llegada − ventana. */
    public function cancelFreeDeadlineFor(?RatePlan $plan, CarbonInterface $start): ?CarbonInterface
    {
        $policy = $this->cancellationPolicyFor($plan);

        return $policy === null ? null : $policy['unit']->subtractFrom($start, $policy['value']);
    }

    /**
     * La política efectiva en palabras, para el wizard, la consulta pública
     * y el asistente. Mismo formato que la etiqueta por tarifa.
     */
    public function cancellationPolicyLabel(?RatePlan $plan = null): ?string
    {
        $policy = $this->cancellationPolicyFor($plan);

        if ($policy === null) {
            return null;
        }

        $after = $policy['penalty'] >= 100
            ? 'después no hay reembolso'
            : 'después se retiene el '.rtrim(rtrim(number_format($policy['penalty'], 2), '0'), '.').'% de lo pagado';

        return 'Cancelación sin costo hasta '.$policy['unit']->label($policy['value'])
            .' antes de la llegada; '.$after.'.';
    }

    /** Nota libre del hotel que acompaña a la política (condiciones propias). */
    public function cancellationPolicyText(): ?string
    {
        $text = trim((string) ($this->settings()['cancel_policy_text'] ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * ¿El hotel cobra fianza (depósito en garantía) por estancia? Doble
     * condición: el interruptor prendido Y un monto mayor a cero — una
     * fianza de $0 no garantiza nada. Se cobra al registrar la llegada
     * (walk-in o check-in de reserva) y se devuelve al registrar la salida;
     * NO es ingreso del hotel, es un pasivo (ver CashCutService).
     */
    public function guaranteeEnabled(): bool
    {
        return (bool) ($this->settings()['guarantee_enabled'] ?? false)
            && $this->guaranteeAmount() > 0;
    }

    /**
     * Monto de la fianza de UNA habitación suelta (el caso normal). Para
     * una reserva que trae varias, usa guaranteeAmountFor().
     */
    public function guaranteeAmount(): float
    {
        return max(0.0, round((float) ($this->settings()['guarantee_amount'] ?? 0), 2));
    }

    /**
     * Escalones por volumen: hay hoteles que bajan la fianza POR HABITACIÓN
     * cuando el mismo grupo aparta varias (Real de la Sierra: $1,500 hasta
     * dos cabañas, $1,000 cada una de ahí en adelante). Cada escalón es
     * "desde N habitaciones, $X cada una"; sin escalones, el monto base
     * aplica siempre.
     *
     * @return array<int, array{from: int, amount: float}> ordenados por `from`
     */
    public function guaranteeTiers(): array
    {
        return collect($this->settings()['guarantee_tiers'] ?? [])
            ->map(fn ($tier) => [
                'from' => (int) ($tier['from'] ?? 0),
                'amount' => round((float) ($tier['amount'] ?? 0), 2),
            ])
            // Un escalón "desde 1" sería el monto base con otro nombre, y
            // uno negativo no significa nada: se descartan en vez de
            // competir con guarantee_amount.
            ->filter(fn (array $tier) => $tier['from'] >= 2 && $tier['amount'] >= 0)
            ->unique('from')
            ->sortBy('from')
            ->values()
            ->all();
    }

    /**
     * Fianza POR HABITACIÓN cuando el mismo grupo aparta $rooms. Gana el
     * escalón más alto que la cantidad alcanza; sin escalón aplicable, el
     * monto base. Devuelve el precio unitario, no el total: la fianza se
     * cobra una vez por estancia y cada cabaña registra su propia llegada.
     */
    public function guaranteeAmountFor(int $rooms = 1): float
    {
        $amount = $this->guaranteeAmount();

        foreach ($this->guaranteeTiers() as $tier) {
            if ($rooms >= $tier['from']) {
                $amount = $tier['amount'];
            }
        }

        return max(0.0, round($amount, 2));
    }

    /**
     * Lo que el mostrador va a cobrarle a ESTA reserva, para que los
     * modales de llegada muestren el número real y no el monto base.
     *
     * Sin escalones configurados el conteo de la partida no cambia nada, y
     * este atajo se lo ahorra: las listas de reservas serializan decenas de
     * filas y un COUNT por cada una sería un N+1 a cambio de la misma cifra.
     */
    public function guaranteeAmountForReservation(?\App\Models\Reservation $reservation): float
    {
        if (! $this->guaranteeEnabled()) {
            return 0.0;
        }

        if ($reservation === null || $this->guaranteeTiers() === []) {
            return $this->guaranteeAmount();
        }

        return $this->guaranteeAmountFor($reservation->partyRoomCount());
    }

    /**
     * La fianza en palabras para el huésped: el wizard, la consulta pública
     * y el asistente la dicen ANTES de que llegue con el dinero. Sin esto
     * el depósito era una sorpresa en el mostrador — nadie lo veía al
     * reservar, solo el personal en el modal de llegada.
     *
     * @return array{amount: float, label: string, tiers: array<int, array{from: int, amount: float}>, tiers_label: ?string}|null
     */
    public function guaranteePublic(): ?array
    {
        if (! $this->guaranteeEnabled()) {
            return null;
        }

        $money = fn (float $amount) => '$'.number_format($amount, 2);
        $tiers = $this->guaranteeTiers();

        return [
            'amount' => $this->guaranteeAmount(),
            'label' => 'Al llegar se cobra un depósito en garantía de '
                .$money($this->guaranteeAmount())
                .' por habitación, que se te devuelve al registrar tu salida. No es parte del precio de tu estancia.',
            'tiers' => $tiers,
            'tiers_label' => $tiers === [] ? null : collect($tiers)
                ->map(fn (array $tier) => 'desde '.$tier['from'].' habitaciones, '.$money($tier['amount']).' cada una')
                ->implode('; '),
        ];
    }

    /**
     * Cuántos mensajes tiene que haber escrito el huésped para que valga la
     * pena reengancharlo con el "¿sigues por ahí?". 0 = sin filtro (el
     * comportamiento de siempre).
     *
     * Medido en cabañas sobre 146 avisos reales (2026-09-12): quien escribió
     * 4 mensajes o menos contestó el 14%, quien pasó de ahí el 36%. Perseguir
     * al primer grupo es escribirle a alguien que solo preguntó el precio y
     * se fue — y son la mitad de los avisos que salen.
     */
    public function nudgeMinVisitorMessages(): int
    {
        return max(0, (int) ($this->settings()['nudge_min_messages'] ?? 0));
    }

    /**
     * Días de silencio tras los que una consulta suelta se cierra sola en la
     * bandeja. 0 = apagado (nadie cierra nada).
     *
     * La bandeja no es un almacén: en cabañas entraron 985 conversaciones en
     * 30 días y el personal alcanzó a marcar 37 como resueltas, así que
     * "abierta" dejó de querer decir nada. Se cierra a quien preguntó y se
     * fue; quien llegó a una COTIZACIÓN REAL (fechas con disponibilidad
     * confirmada, AgentBrain::REAL_QUOTE) se queda abierto aunque lleve
     * semanas callado — ese sí es alguien a quien perseguir. Cerrar no borra
     * nada: si el huésped vuelve a escribir, el webhook la reabre.
     */
    public function inboxAutoCloseDays(): int
    {
        return max(0, (int) ($this->settings()['inbox_auto_close_days'] ?? 2));
    }

    /**
     * Cuánto silencio del huésped se espera antes del reenganche. Default:
     * los 20 minutos de siempre. En cabañas el aviso llegaba mientras la
     * persona estaba consultando con su familia ("están checando las
     * cabañas" a los 25 minutos), que es justo cuando estorba.
     */
    public function nudgeSilenceMinutes(): int
    {
        return max(5, (int) ($this->settings()['nudge_silence_minutes'] ?? 20));
    }

    /**
     * ¿Se manda el aviso el día de la llegada? (segundo recordatorio,
     * horas antes de la hora de entrada; el de 24 h tiene su propio
     * interruptor arrival_reminder_enabled).
     */
    public function arrivalSoonEnabled(): bool
    {
        return (bool) ($this->settings()['arrival_soon_enabled'] ?? true);
    }

    /** Cuántas horas antes de la llegada sale ese aviso. Default: 2. */
    public function arrivalSoonHours(): int
    {
        $hours = (int) ($this->settings()['arrival_soon_hours'] ?? 2);

        return min(24, max(1, $hours ?: 2));
    }

    /**
     * ¿Se agradece al huésped al completar su estancia? (mensaje al
     * check-out, manual o automático, con el link de reseñas si existe).
     */
    public function postStayThanksEnabled(): bool
    {
        return (bool) ($this->settings()['post_stay_thanks_enabled'] ?? true);
    }

    /** URL de reseñas del hotel (Google/Tripadvisor); null si no la capturó. */
    public function reviewUrl(): ?string
    {
        $url = trim((string) ($this->settings()['review_url'] ?? ''));

        return $url === '' ? null : $url;
    }

    /**
     * ¿El agradecimiento incluye el cuestionario de experiencia? (link
     * público /encuesta/{token}, una respuesta por estancia). Solo aplica
     * cuando el agradecimiento mismo está prendido.
     */
    public function postStaySurveyEnabled(): bool
    {
        return (bool) ($this->settings()['post_stay_survey_enabled'] ?? true);
    }

    /** Valor+unidad de settings traducido a minutos; null si no está configurado. */
    protected function minutesFrom(string $valueKey, string $unitKey): ?int
    {
        $value = (int) ($this->settings()[$valueKey] ?? 0);
        $unit = RateDurationUnit::tryFrom((string) ($this->settings()[$unitKey] ?? ''));

        if ($value < 1 || $unit === null || $unit->minutes() === null) {
            return null;
        }

        return $value * $unit->minutes();
    }

    /** @return array<string, mixed> */
    protected function settings(): array
    {
        return $this->settings ??= Property::query()->first()?->settings ?? [];
    }
}
