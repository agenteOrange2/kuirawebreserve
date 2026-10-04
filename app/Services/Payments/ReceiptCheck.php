<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * Coteja lo que se leyó en un comprobante contra lo que el hotel espera:
 * monto del cobro, cuentas propias, fecha y clave de rastreo no repetida.
 *
 * No aprueba nada: el veredicto solo decide cuánto confía el sistema en la
 * imagen mientras el personal verifica.
 * - match: todo cuadra.
 * - review: parece comprobante pero algo no cuadra; sostiene el apartado y
 *   el personal ve el motivo.
 * - duplicate: la clave de rastreo ya se usó en otro cobro; NO sostiene nada.
 * - not_receipt: no es comprobante.
 */
class ReceiptCheck
{
    public const MATCH = 'match';

    public const REVIEW = 'review';

    public const DUPLICATE = 'duplicate';

    public const NOT_RECEIPT = 'not_receipt';

    /**
     * @param  array<string, mixed>  $reading  Salida de ReceiptReader::read().
     * @return array{verdict: string, warnings: array<int, string>, summary: string}
     */
    public function evaluate(array $reading, ?PaymentRequest $request = null): array
    {
        if (($reading['kind'] ?? null) === ReceiptReader::KIND_NOT_RECEIPT) {
            return [
                'verdict' => self::NOT_RECEIPT,
                'warnings' => [],
                'summary' => 'No es un comprobante'.(! empty($reading['description']) ? ': '.$reading['description'] : ''),
            ];
        }

        $warnings = [];

        if (($reading['kind'] ?? null) === ReceiptReader::KIND_OTHER_PAYMENT) {
            $warnings[] = 'No parece una transferencia bancaria sino otro tipo de comprobante.';
        }

        $amount = $reading['amount'] ?? null;

        if ($amount === null) {
            $warnings[] = 'No se alcanzó a leer el monto.';
        } elseif ($request !== null && abs((float) $amount - (float) $request->amount) > 1) {
            $warnings[] = (float) $amount < (float) $request->amount
                ? 'El monto del comprobante ($'.number_format((float) $amount, 2).') es MENOR al cobro ($'.number_format((float) $request->amount, 2).').'
                : 'El monto del comprobante ($'.number_format((float) $amount, 2).') es mayor al cobro ($'.number_format((float) $request->amount, 2).').';
        }

        $last4 = $reading['destination_account_last4'] ?? null;
        $ours = $this->accountEndings();

        if ($last4 !== null && $ours !== [] && ! in_array($last4, $ours, true)) {
            $warnings[] = "La cuenta destino (terminación {$last4}) no coincide con las cuentas del hotel.";
        }

        if (! empty($reading['date'])) {
            try {
                $date = CarbonImmutable::parse($reading['date'])->startOfDay();
                $floor = ($request?->created_at ?? now())->copy()->subDays(3)->startOfDay();

                if ($date->lt($floor)) {
                    $warnings[] = 'La fecha del comprobante ('.$date->format('d/m/Y').') es anterior al cobro.';
                } elseif ($date->gt(now()->addDay()->startOfDay())) {
                    $warnings[] = 'La fecha del comprobante ('.$date->format('d/m/Y').') está en el futuro.';
                }
            } catch (\Throwable) {
                // Fecha ilegible: no suma ni resta.
            }
        }

        if (! empty($reading['status']) && preg_match('/(pendiente|proceso|programad|rechazad|cancelad|devuelt|fallid)/iu', (string) $reading['status'])) {
            $warnings[] = 'El comprobante dice "'.$reading['status'].'": no es una operación terminada.';
        }

        $duplicateOf = $this->duplicateOf($reading['tracking_key'] ?? null, $request);

        if ($duplicateOf !== null) {
            array_unshift($warnings, "Esta clave de rastreo ya se usó en {$duplicateOf}.");
        }

        return [
            'verdict' => match (true) {
                $duplicateOf !== null => self::DUPLICATE,
                $warnings === [] => self::MATCH,
                default => self::REVIEW,
            },
            'warnings' => $warnings,
            'summary' => $this->summary($reading),
        ];
    }

    /** "Transferencia por $3,000.00 · BANORTE ·1234 · 14/09 18:10 · rastreo MBAN…" */
    public function summary(array $reading): string
    {
        $parts = [
            ($reading['kind'] ?? null) === ReceiptReader::KIND_OTHER_PAYMENT ? 'Comprobante de pago' : 'Transferencia',
        ];

        if (($reading['amount'] ?? null) !== null) {
            $parts[0] .= ' por $'.number_format((float) $reading['amount'], 2);
        }

        $bank = trim(($reading['destination_bank'] ?? '').(($reading['destination_account_last4'] ?? null) ? ' ·'.$reading['destination_account_last4'] : ''));

        if ($bank !== '') {
            $parts[] = $bank;
        }

        if (! empty($reading['date'])) {
            try {
                $parts[] = CarbonImmutable::parse($reading['date'])->format('d/m').(! empty($reading['time']) ? ' '.$reading['time'] : '');
            } catch (\Throwable) {
            }
        }

        if (! empty($reading['tracking_key'])) {
            $parts[] = 'rastreo '.$reading['tracking_key'];
        }

        return implode(' · ', $parts);
    }

    /**
     * Terminaciones de las cuentas activas del hotel (CLABE, cuenta o
     * tarjeta capturadas en Métodos de pago).
     *
     * @return array<int, string>
     */
    protected function accountEndings(): array
    {
        // Los TRES números de cada cuenta: el huésped pudo transferir a la
        // CLABE, a la tarjeta o al número de cuenta. Mirando solo uno, un
        // depósito legítimo salía marcado como "la cuenta no coincide".
        return collect(Property::query()->first()?->settings['bank_accounts'] ?? [])
            ->filter(fn ($account) => is_array($account) && ($account['active'] ?? true))
            ->flatMap(fn (array $account) => \App\Support\BankAccountNumber::normalize($account)['digits'])
            ->map(fn (string $number) => substr($number, -4))
            ->filter(fn (string $ending) => strlen($ending) === 4)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Folio del otro cobro o pago que ya trae esta clave de rastreo. Un
     * comprobante reciclado es la trampa más barata: la misma captura para
     * dos apartados.
     */
    protected function duplicateOf(?string $tracking, ?PaymentRequest $request): ?string
    {
        if ($tracking === null || strlen($tracking) < 8) {
            return null;
        }

        $other = PaymentRequest::query()
            ->when($request, fn ($query) => $query->whereKeyNot($request->id))
            // Otro cobro del MISMO grupo no es reciclaje: es el mismo dinero
            // que se re-emitió consolidado.
            ->when($request?->reservation_group_id, fn ($query, $group) => $query->where(fn ($q) => $q
                ->whereNull('reservation_group_id')->orWhere('reservation_group_id', '!=', $group)))
            ->when($request?->reservation_id, fn ($query, $reservation) => $query->where(fn ($q) => $q
                ->whereNull('reservation_id')->orWhere('reservation_id', '!=', $reservation)))
            ->where('meta', 'like', '%"tracking_key":"'.$tracking.'"%')
            ->with(['reservation', 'group', 'experienceBooking'])
            ->latest('id')
            ->first();

        if ($other !== null) {
            return 'el cobro de '.$other->subjectCode();
        }

        // El folio vive en `reference` (capturado o verificado) o en
        // `gateway_ref` (pago de pasarela). Caso real cabañas 2026-09-27,
        // reserva 1789: el comprobante del pago de Mercado Pago se aprobó
        // otra vez como saldo porque solo se miraba `reference`.
        $payment = Payment::query()
            ->where(fn ($query) => $query->where('reference', $tracking)->orWhere('gateway_ref', $tracking))
            ->with('reservation')
            ->latest('id')
            ->first();

        return $payment !== null
            ? 'un pago ya registrado'.($payment->reservation ? ' de '.$payment->reservation->displayCode() : '')
            : null;
    }
}
