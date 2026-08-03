<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consulta pública de reserva (/reserva): el huésped busca con su código
 * y su teléfono, y ve el resumen — estado, fechas, cuánto ha pagado y qué
 * falta, con el link de pago vigente si hay uno. El teléfono es la llave
 * anti-curiosos: el código solo no basta (viaja en mensajes y capturas),
 * y la respuesta de "no encontrado" es la misma exista o no el código.
 */
class BookingLookupController extends Controller
{
    public function page(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        // Misma apariencia que el wizard de habitaciones (/reservas/ajustes):
        // una sola configuración para todas las páginas públicas.
        $appearance = $property->wizardAppearance();

        return Inertia::render('tenant/reservar/Lookup', [
            'appearance' => $appearance,
            'property' => [
                'name' => $property->name,
                'logo_url' => $appearance['logo_url'],
                'phone' => $settings['phone'] ?? null,
                'currency' => $settings['currency'] ?? 'MXN',
            ],
        ]);
    }

    public function find(Request $request): JsonResponse
    {
        $reservation = $this->resolve($request);

        if ($reservation === null) {
            return $this->notFound();
        }

        return $this->summary($reservation);
    }

    /**
     * Cancelación autoservicio: solo cuando no hay dinero en riesgo — nada
     * pagado, o dentro de la ventana sin costo de la política de la tarifa.
     * Con dinero de por medio fuera de ventana, la decisión (retenciones,
     * reembolsos) es del hotel, no de un botón público.
     */
    public function cancel(Request $request): JsonResponse
    {
        $reservation = $this->resolve($request);

        if ($reservation === null) {
            return $this->notFound();
        }

        [$allowed, $reason] = $this->selfCancelState($reservation);

        if (! $allowed) {
            return response()->json(['message' => $reason], 422);
        }

        app(\App\Actions\Reservations\TransitionReservation::class)->cancel(
            $reservation,
            null,
            reason: 'Cancelada por el huésped desde la consulta pública.',
        );

        return $this->summary($reservation->refresh()->load(['roomType', 'ratePlan', 'guest']));
    }

    /**
     * Pre-registro en línea: el huésped completa sus datos antes de llegar
     * (nombre, correo, vehículo, hora estimada y notas) con las mismas
     * llaves código + teléfono. Solo tiene sentido antes del check-in
     * (Pendiente/Confirmada). El correo y el nombre se agregan a la ficha
     * del Guest únicamente si el CRM no los tenía — mismo criterio
     * fillMissing de CreateReservation: lo capturado por staff no se pisa.
     */
    public function preRegister(Request $request): JsonResponse
    {
        $reservation = $this->resolve($request);

        if ($reservation === null) {
            return $this->notFound();
        }

        if (! in_array($reservation->status, [\App\Enums\ReservationStatus::Pending, \App\Enums\ReservationStatus::Confirmed], true)) {
            return response()->json([
                'message' => 'Esta reserva ya no admite pre-registro; si necesitas actualizar tus datos, contacta al hotel.',
            ], 422);
        }

        $data = $request->validate([
            'guest_name' => ['nullable', 'string', 'max:120'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'vehicle_desc' => ['nullable', 'string', 'max:120'],
            'eta' => ['nullable', 'date_format:H:i'],
            'guest_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Vehículo, hora estimada y notas son del huésped: lo que mande es
        // la verdad (vacío = lo quitó). El nombre nunca se blanquea.
        $updates = [];
        foreach (['vehicle_plate', 'vehicle_desc', 'eta', 'guest_notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) ($data[$field] ?? ''));
                $updates[$field] = $value === '' ? null : $value;
            }
        }

        $name = trim((string) ($data['guest_name'] ?? ''));
        if ($name !== '') {
            $updates['guest_name'] = $name;
        }

        $reservation->update($updates);

        if ($guest = $reservation->guest) {
            $fill = [];
            $email = trim((string) ($data['guest_email'] ?? ''));

            if ($email !== '' && ! $guest->email) {
                $fill['email'] = $email;
            }
            if ($name !== '' && blank($guest->first_name) && blank($guest->last_name)) {
                $fill['first_name'] = $name;
            }
            if ($fill !== []) {
                $guest->update($fill);
            }
        }

        return $this->summary($reservation->refresh()->load(['roomType', 'ratePlan', 'guest']));
    }

    protected function resolve(Request $request): ?Reservation
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        // Se tolera el código sin el prefijo RES- (el huésped a veces solo
        // copia los números).
        $code = strtoupper(trim($data['code']));

        $reservation = Reservation::query()
            ->with(['roomType', 'ratePlan', 'guest'])
            ->whereIn('code', array_unique([$code, 'RES-'.ltrim($code, 'RES-')]))
            ->first();

        if (! $reservation || ! $this->phoneMatches($reservation, $data['phone'])) {
            return null;
        }

        return $reservation;
    }

    protected function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'No encontramos una reserva con ese código y teléfono. Revisa ambos datos e intenta de nuevo.',
        ], 404);
    }

    protected function summary(Reservation $reservation): JsonResponse
    {
        $pending = $reservation->paymentRequests()->active()->latest('id')->first();

        $settings = Property::firstOrFail()->settings ?? [];
        $accounts = $pending?->method === PaymentRequest::METHOD_TRANSFER
            ? collect($settings['bank_accounts'] ?? [])
                ->filter(fn (array $a) => ! empty($a['active']))
                ->map(fn (array $a) => [
                    'banco' => $a['bank'] ?? '',
                    'titular' => $a['holder'] ?? '',
                    'cuenta' => $a['clabe'] ?? '',
                ])
                ->values()
            : collect();

        return response()->json([
            'code' => $reservation->displayCode(),
            'status' => $reservation->status->value,
            'status_label' => $reservation->status->label(),
            'room_type' => $reservation->roomType?->name,
            'starts_at' => $reservation->starts_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'adults' => (int) $reservation->adults,
            'children' => (int) $reservation->children,
            'total' => (float) $reservation->total_amount,
            'paid' => $reservation->paidTotal(),
            'pending_balance' => $reservation->pendingBalance(),
            'payment_status_label' => $reservation->payment_status->label(),
            'payment_due_at' => $reservation->payment_due_at?->toIso8601String(),
            'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
            // Cobro vigente: con pasarela el huésped puede pagar desde aquí
            // mismo; con transferencia se le repiten las cuentas.
            'pending_request' => $pending ? [
                'method' => $pending->method,
                'amount' => (float) $pending->amount,
                'amount_label' => $pending->amountLabel(),
                'checkout_url' => $pending->checkout_url,
                'expires_at' => $pending->expires_at?->toIso8601String(),
                'bank_accounts' => $accounts,
            ] : null,
            // Pre-registro: lo que ya sabemos, para precargar el formulario
            // público (solo editable en Pendiente/Confirmada).
            'pre_registration' => [
                'guest_name' => $reservation->guest_name ?? $reservation->guest?->full_name,
                'guest_email' => $reservation->guest?->email,
                'has_email' => (bool) $reservation->guest?->email,
                'vehicle_plate' => $reservation->vehicle_plate,
                'vehicle_desc' => $reservation->vehicle_desc,
                'eta' => $reservation->eta ? substr((string) $reservation->eta, 0, 5) : null,
                'guest_notes' => $reservation->guest_notes,
            ],
            'can_cancel' => $this->selfCancelState($reservation)[0],
            'cancellation_policy' => app(\App\Services\ReservationPolicy::class)
                ->cancellationPolicyLabel($reservation->ratePlan),
            'cancellation_policy_text' => app(\App\Services\ReservationPolicy::class)
                ->cancellationPolicyText(),
            // Estimación honesta para el huésped: "si cancelas ahora, según
            // la política te corresponden $X". El reembolso en sí siempre lo
            // ejecuta el hotel, nunca este botón.
            'cancel_refund_estimate' => in_array($reservation->status, [\App\Enums\ReservationStatus::Pending, \App\Enums\ReservationStatus::Confirmed], true)
                ? $reservation->suggestedRefund()
                : null,
        ]);
    }

    /**
     * ¿Puede cancelar el propio huésped? y si no, por qué.
     *
     * @return array{0: bool, 1: string}
     */
    protected function selfCancelState(Reservation $reservation): array
    {
        if (! in_array($reservation->status, [\App\Enums\ReservationStatus::Pending, \App\Enums\ReservationStatus::Confirmed], true)) {
            return [false, 'Esta reserva ya no se puede cancelar desde aquí; contacta al hotel.'];
        }

        // Sin dinero pagado no hay nada en riesgo: se cancela directo.
        if ($reservation->paidTotal() <= 0) {
            return [true, ''];
        }

        // Ventana efectiva: la de la tarifa o la default del hotel.
        $deadline = app(\App\Services\ReservationPolicy::class)
            ->cancelFreeDeadlineFor($reservation->ratePlan, $reservation->starts_at);

        if ($deadline !== null && now()->lte($deadline)) {
            return [true, ''];
        }

        return [false, 'Tu reserva tiene pagos registrados y ya no está en la ventana de cancelación sin costo; contacta al hotel para revisar tu caso.'];
    }

    /**
     * El teléfono capturado debe coincidir con el de la reserva: se
     * comparan los últimos 8 dígitos (tolera lada de país presente o
     * ausente en cualquiera de los dos lados), mínimo 4 capturados.
     */
    protected function phoneMatches(Reservation $reservation, string $input): bool
    {
        // El contacto vive en el Guest ligado, no en la reserva.
        $stored = preg_replace('/\D+/', '', (string) $reservation->guest?->phone);
        $given = preg_replace('/\D+/', '', $input);

        if ($stored === '' || strlen($given) < 4) {
            return false;
        }

        $length = min(8, strlen($stored), strlen($given));

        return substr($stored, -$length) === substr($given, -$length);
    }
}
