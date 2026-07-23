<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Payments\IssueGroupPayment;
use App\Actions\Reservations\CreateGroupReservation;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\ReservationGroup;
use App\Models\RoomType;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Wizard público de GRUPOS (/reservar/grupos): varias habitaciones de un
 * jalón, todo-o-nada, con cobro consolidado — un solo link por el grupo.
 * Mismo patrón que el wizard de habitaciones: standalone, anti-bots,
 * montos SIEMPRE del servidor. La disponibilidad la sirve el mismo
 * endpoint del wizard normal (rooms_count por tipo incluido).
 */
class GroupWizardController extends Controller
{
    public function page(): Response
    {
        $property = Property::firstOrFail();

        abort_unless((bool) (($property->settings['widget_grupos_enabled'] ?? true)), 404);
        $settings = $property->settings ?? [];

        $activeRatePlans = RatePlan::query()
            ->where('active', true)
            ->whereHas('roomType', fn ($q) => $q->where('active', true));

        // Misma apariencia que el wizard de habitaciones (/reservas/ajustes):
        // una sola configuración para todas las páginas públicas.
        $appearance = $property->wizardAppearance();

        return Inertia::render('tenant/reservar/Groups', [
            'appearance' => $appearance,
            'property' => [
                'name' => $property->name,
                'logo_url' => $appearance['logo_url'],
                'phone' => $settings['phone'] ?? null,
                'currency' => $settings['currency'] ?? 'MXN',
                // Doble moneda: se muestra el "aprox" en la otra divisa.
                'currency_secondary' => $settings['currency_secondary'] ?? null,
                'exchange_rate' => $settings['exchange_rate'] ?? null,
                'guest_policy' => $settings['guest_policy'] ?? 'family',
                'block_mode_label' => $settings['block_mode_label'] ?? 'Por rato/periodo',
            ],
            'hasNightRates' => (clone $activeRatePlans)->where('type', 'night')->exists(),
            'hasBlockRates' => (clone $activeRatePlans)->where('type', 'block')->exists(),
            'holdMinutes' => app(\App\Services\ReservationPolicy::class)->holdMinutes(),
            // Accesos cruzados (misma botonera que /reservar): solo a páginas
            // que existen de verdad para este hotel — módulo activo y, en las
            // que tienen toggle de widget, con la página pública prendida.
            'hasWizard' => (bool) tenant()?->hasModule('motor-web')
                && (bool) ($settings['widget_reservas_enabled'] ?? true),
            'hasLookup' => (bool) tenant()?->hasModule('motor-web'),
            'hasExperiences' => (bool) tenant()?->hasModule('experiencias')
                && (bool) ($settings['widget_experiencias_enabled'] ?? true)
                && \App\Models\Experience::query()->where('active', true)->exists(),
        ]);
    }

    /**
     * Crea el grupo (hold pendiente, todo-o-nada). Las líneas nunca traen
     * precio: CreateGroupReservation resuelve tarifa y total en servidor.
     */
    public function hold(Request $request, CreateGroupReservation $action): JsonResponse
    {
        $this->guardAgainstBots($request);

        $data = $request->validate([
            'mode' => ['required', Rule::in(['night', 'block'])],
            'arrive_date' => ['required_if:mode,night', 'nullable', 'date', 'after_or_equal:today'],
            'depart_date' => ['required_if:mode,night', 'nullable', 'date', 'after:arrive_date'],
            'arrive_at' => ['required_if:mode,block', 'nullable', 'date', 'after_or_equal:now'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['required', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:10'],
            'lines.*.room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'lines.*.rooms' => ['required', 'integer', 'min:1', 'max:30'],
            'lines.*.adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'lines.*.children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            // Experiencias como plus del grupo (módulo `experiencias`): el
            // cupo duro y el precio los hace cumplir el servidor bajo lock.
            'experiences' => ['sometimes', 'array', 'max:5'],
            'experiences.*.session_id' => ['required_with:experiences', 'integer', 'exists:experience_sessions,id'],
            'experiences.*.people' => ['required_with:experiences', 'integer', 'min:1', 'max:100'],
        ]);

        [$start, $end] = $this->resolveDates($data);

        try {
            $group = $action->handle([
                'mode' => $data['mode'],
                'starts_at' => $start,
                'ends_at' => $end,
                'guest_name' => $data['guest_name'],
                'guest_phone' => $data['guest_phone'],
                'guest_email' => $data['guest_email'] ?? null,
                'notes' => $data['notes'] ?? 'Creada desde el wizard web de grupos',
                'confirmed' => false,
                'source_channel' => 'web',
                'lines' => $data['lines'],
                'experiences' => $data['experiences'] ?? [],
            ]);
        } catch (NoAvailabilityException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $group->load('reservations.roomType', 'experienceBookings.session.experience');
        $reservations = $group->reservations;
        $experienceBookings = $group->experienceBookings;

        return response()->json([
            'code' => $group->displayCode(),
            'rooms' => $reservations->groupBy(fn ($r) => $r->roomType?->name ?? 'Habitación')
                ->map(fn ($rows, $name) => [
                    'room_type' => $name,
                    'rooms' => $rows->count(),
                    'total' => round((float) $rows->sum('total_amount'), 2),
                ])->values(),
            'experiences' => $experienceBookings->map(fn ($booking) => [
                'name' => $booking->session?->experience?->name,
                'starts_at' => $booking->session?->starts_at?->toIso8601String(),
                'people' => $booking->people,
                'total' => (float) $booking->total,
            ])->values(),
            'experiences_total' => round((float) $experienceBookings->sum('total'), 2),
            'starts_at' => $reservations->min('starts_at')?->toIso8601String(),
            'ends_at' => $reservations->max('ends_at')?->toIso8601String(),
            'total' => round((float) $reservations->sum('total_amount') + (float) $experienceBookings->sum('total'), 2),
            // Lo mínimo para apartar (anticipos de los cuartos + tours
            // completos) — el huésped puede elegir pagar esto o el total.
            'deposit' => round(
                $reservations->sum(function ($r) {
                    $deposit = (float) $r->deposit_amount;
                    $total = (float) $r->total_amount;

                    return $deposit > 0 && $deposit < $total ? $deposit : $total;
                }) + (float) $experienceBookings->sum('total'),
                2,
            ),
            'requires_prepayment' => $this->groupRequiresPrepayment($data['mode'], $data['lines']),
            'payment_optional' => app(\App\Services\ReservationPolicy::class)->cashPaymentEnabled(),
            'hold_expires_at' => $reservations->min('hold_expires_at')?->toIso8601String(),
            'hold_minutes' => app(\App\Services\ReservationPolicy::class)->holdMinutes(),
        ], 201);
    }

    /** Mismos métodos de cobro que el resto de los wizards. */
    public function paymentOptions(): JsonResponse
    {
        return app(ExperienceWizardController::class)->paymentOptions();
    }

    /**
     * Igual que el wizard de habitaciones (BookingController::payLater):
     * elegir "pagar en el hotel" extiende el hold de TODO el grupo al plazo
     * de efectivo, cada reserva con tope en su propia llegada. Solo
     * extiende, nunca recorta; libera el scheduler de siempre.
     */
    public function payLater(string $code): JsonResponse
    {
        $policy = app(\App\Services\ReservationPolicy::class);

        if (! $policy->cashPaymentEnabled()) {
            return response()->json(['message' => 'El hotel no ofrece pagar en el hotel; elige un método de pago en línea.'], 422);
        }

        $group = ReservationGroup::query()->where('code', strtoupper(trim($code)))->first();

        if (! $group) {
            return response()->json(['message' => 'No encontramos un grupo con ese folio.'], 404);
        }

        $deadline = now()->addMinutes($policy->cashDeadlineMinutes());

        $group->reservations()
            ->where('status', \App\Enums\ReservationStatus::Pending)
            ->whereNotNull('hold_expires_at')
            ->get()
            ->each(function ($reservation) use ($deadline) {
                $target = $reservation->starts_at !== null && $reservation->starts_at->lt($deadline)
                    ? $reservation->starts_at
                    : $deadline;

                if ($reservation->hold_expires_at->lt($target)) {
                    $reservation->update(['hold_expires_at' => $target]);
                }
            });

        $min = $group->reservations()
            ->where('status', \App\Enums\ReservationStatus::Pending)
            ->min('hold_expires_at');

        return response()->json([
            'hold_expires_at' => $min ? Carbon::parse($min)->toIso8601String() : null,
        ]);
    }

    /** Cobro consolidado: un solo link por todo el grupo. */
    public function payment(Request $request, string $code, IssueGroupPayment $action): JsonResponse
    {
        $preferred = $request->string('method')->toString();
        $preferred = in_array($preferred, ['gateway', 'transfer'], true) ? $preferred : null;

        // Anticipos (default) o liquidar todo de una vez — elección del huésped.
        $preferFull = $request->string('pay')->toString() === 'full';

        $requestedProvider = $request->string('provider')->toString();
        $requestedProvider = in_array($requestedProvider, ['stripe', 'mercadopago', 'paypal'], true) ? $requestedProvider : null;

        $group = ReservationGroup::query()->where('code', strtoupper(trim($code)))->first();

        if (! $group) {
            return response()->json(['message' => 'No encontramos un grupo con ese folio.'], 404);
        }

        $gate = app(\App\Services\Payments\PaymentMethodGate::class);
        $enabled = $gate->methodsFor((string) tenant('id'));

        $settings = Property::firstOrFail()->settings ?? [];
        $accounts = ! $enabled['transfer'] ? collect() : collect($settings['bank_accounts'] ?? [])
            ->filter(fn (array $account) => ! empty($account['active']))
            ->map(fn (array $account) => [
                'banco' => $account['bank'] ?? '',
                'titular' => $account['holder'] ?? '',
                'cuenta' => $account['clabe'] ?? '',
            ])
            ->values();

        $enabledProviders = array_keys(array_filter([
            'stripe' => $enabled['stripe'],
            'mercadopago' => $enabled['mercadopago'],
            'paypal' => $enabled['paypal'],
        ]));

        $linkQuery = \App\Models\Central\PaymentGatewayLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('active', true)
            ->whereIn('provider', $enabledProviders)
            ->orderBy('id');

        $link = $requestedProvider !== null
            ? (clone $linkQuery)->where('provider', $requestedProvider)->first()
            : $linkQuery->first();

        if ($requestedProvider !== null && ! $link) {
            return response()->json(['message' => 'Esa pasarela ya no está disponible; vuelve a consultar las opciones de pago.'], 422);
        }

        if (! $link && $accounts->isEmpty()) {
            return response()->json([
                'message' => 'El hotel aún no tiene métodos de cobro en línea; te contactará para coordinar el pago.',
            ], 422);
        }

        if ($preferred === 'transfer' && $accounts->isEmpty()) {
            return response()->json(['message' => 'La transferencia bancaria ya no está disponible; vuelve a consultar las opciones de pago.'], 422);
        }

        try {
            if ($link && $preferred !== 'transfer') {
                try {
                    $paymentRequest = $action->handle($group, PaymentRequest::METHOD_GATEWAY, null, $link, $preferFull);

                    return response()->json([
                        'method' => 'gateway',
                        'provider' => $link->providerLabel(),
                        'amount' => (float) $paymentRequest->amount,
                        'amount_label' => $paymentRequest->amountLabel(),
                        'checkout_url' => $paymentRequest->checkout_url,
                        'return_url' => route('tenant.payment.return', $paymentRequest->uuid),
                    ], 201);
                } catch (\RuntimeException $e) {
                    if ($accounts->isEmpty()) {
                        return response()->json(['message' => $e->getMessage()], 422);
                    }
                }
            }

            $paymentRequest = $action->handle($group, PaymentRequest::METHOD_TRANSFER, preferFull: $preferFull);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'method' => 'transfer',
            'amount' => (float) $paymentRequest->amount,
            'amount_label' => $paymentRequest->amountLabel(),
            'bank_accounts' => $accounts,
            'whatsapps' => app(\App\Services\ReservationPolicy::class)->transferWhatsapps(),
            'valid_hours' => (int) now()->diffInHours($paymentRequest->expires_at ?? now()),
            'return_url' => route('tenant.payment.return', $paymentRequest->uuid),
        ], 201);
    }

    /**
     * ¿El grupo pide pago en línea? Mismo criterio que el wizard normal
     * (payment_mode del hotel; en automático decide la tarifa de cada
     * línea — con que UNA pida anticipo, el grupo cobra).
     *
     * @param  array<int, array{room_type_id: int}>  $lines
     */
    protected function groupRequiresPrepayment(string $mode, array $lines): bool
    {
        $paymentMode = Property::firstOrFail()->settings['payment_mode'] ?? 'automatic';

        if ($paymentMode !== 'automatic') {
            // 'optional' ("ambos") también muestra el paso de pago; el
            // responsable puede elegir pagar al llegar.
            return in_array($paymentMode, ['always', 'optional'], true);
        }

        foreach ($lines as $line) {
            $ratePlan = RoomType::find($line['room_type_id'])?->ratePlans()
                ->where('active', true)
                ->where('type', $mode)
                ->orderBy('price')
                ->first();

            if ($ratePlan?->requiresPrepayment()) {
                return true;
            }
        }

        return false;
    }

    protected function guardAgainstBots(Request $request): void
    {
        $genericError = ['guest_name' => ['No se pudo procesar la solicitud.']];

        if ($request->filled('website')) {
            throw ValidationException::withMessages($genericError);
        }

        $renderedAt = $request->input('rendered_at');
        $minSeconds = (int) config('booking.min_fill_seconds', 3);

        if (! $renderedAt || Carbon::parse($renderedAt)->diffInSeconds(now(), false) < $minSeconds) {
            throw ValidationException::withMessages($genericError);
        }
    }

    /**
     * @param  array{mode: string, arrive_date?: ?string, depart_date?: ?string, arrive_at?: ?string}  $data
     * @return array{0: Carbon, 1: ?Carbon}
     */
    protected function resolveDates(array $data): array
    {
        if ($data['mode'] === 'night') {
            $settings = Property::firstOrFail()->settings ?? [];
            [$inHour, $inMinute] = array_map('intval', explode(':', $settings['check_in_time'] ?? '15:00'));
            [$outHour, $outMinute] = array_map('intval', explode(':', $settings['check_out_time'] ?? '12:00'));

            return [
                Carbon::parse($data['arrive_date'])->setTime($inHour, $inMinute),
                Carbon::parse($data['depart_date'])->setTime($outHour, $outMinute),
            ];
        }

        return [Carbon::parse($data['arrive_at']), null];
    }
}
