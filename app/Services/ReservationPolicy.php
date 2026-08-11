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
            return $due;
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

    /** Monto fijo de la fianza por estancia. */
    public function guaranteeAmount(): float
    {
        return max(0.0, round((float) ($this->settings()['guarantee_amount'] ?? 0), 2));
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
