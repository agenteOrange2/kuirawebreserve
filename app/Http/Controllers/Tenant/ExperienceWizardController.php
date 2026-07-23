<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Experiences\CreateExperienceBooking;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Controller;
use App\Models\Experience;
use App\Models\ExperienceBooking;
use App\Models\ExperienceSession;
use App\Models\Property;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Wizard público de experiencias (/reservar/experiencias): mismo patrón
 * standalone que el wizard de habitaciones — anti-abuso (honeypot +
 * tiempo mínimo), montos SIEMPRE del servidor y cupo duro bajo lock. La
 * reserva nace pendiente: el hotel la confirma y cobra por sus vías; el
 * pago en línea de experiencias queda para una siguiente iteración.
 */
class ExperienceWizardController extends Controller
{
    public function page(): Response
    {
        $property = Property::firstOrFail();

        abort_unless((bool) (($property->settings['widget_experiencias_enabled'] ?? true)), 404);
        $settings = $property->settings ?? [];

        // Misma apariencia que el wizard de habitaciones (/reservas/ajustes):
        // una sola configuración para todas las páginas públicas.
        $appearance = $property->wizardAppearance();

        return Inertia::render('tenant/reservar/Experiences', [
            'appearance' => $appearance,
            'property' => [
                'name' => $property->name,
                'logo_url' => $appearance['logo_url'],
                'phone' => $settings['phone'] ?? null,
                'currency' => $settings['currency'] ?? 'MXN',
                // Doble moneda: se muestra el "aprox" en la otra divisa.
                'currency_secondary' => $settings['currency_secondary'] ?? null,
                'exchange_rate' => $settings['exchange_rate'] ?? null,
            ],
            // Accesos cruzados (misma botonera que /reservar): solo a páginas
            // que existen de verdad para este hotel — módulo activo y, en las
            // que tienen toggle de widget, con la página pública prendida.
            'hasWizard' => (bool) tenant()?->hasModule('motor-web')
                && (bool) ($settings['widget_reservas_enabled'] ?? true),
            'hasLookup' => (bool) tenant()?->hasModule('motor-web'),
            'hasGroups' => (bool) tenant()?->hasModule('grupos')
                && (bool) ($settings['widget_grupos_enabled'] ?? true),
        ]);
    }

    /**
     * Experiencias activas con sus FECHAS reservables (todo el año, igual
     * que las habitaciones). Ya no manda las sesiones completas — con un
     * horizonte anual serían miles de renglones; el huésped elige fecha y
     * `sessions()` responde los horarios de ESE día.
     */
    public function list(): JsonResponse
    {
        $dates = ExperienceSession::query()
            ->where('status', ExperienceSession::STATUS_SCHEDULED)
            ->where('starts_at', '>', now())
            ->withSum(['bookings as people_booked' => fn ($q) => $q->whereIn('status', [
                ExperienceBooking::STATUS_PENDING, ExperienceBooking::STATUS_CONFIRMED,
            ])], 'people')
            ->get()
            ->filter(fn (ExperienceSession $session) => $session->capacity - (int) ($session->people_booked ?? 0) > 0)
            ->groupBy('experience_id')
            ->map(fn ($own) => $own->map(fn (ExperienceSession $s) => $s->starts_at->format('Y-m-d'))->unique()->sort()->values());

        $experiences = Experience::query()
            ->where('active', true)
            ->with('media')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Experience $experience) => [
                'id' => $experience->id,
                'name' => $experience->name,
                'description' => $experience->description,
                'includes' => $experience->includes ?? [],
                'duration_label' => $experience->durationLabel(),
                'pricing_mode' => $experience->pricing_mode,
                'price' => (float) $experience->price,
                'price_label' => $experience->priceLabel(),
                'min_people' => $experience->min_people,
                'max_people' => $experience->max_people,
                'photos' => $experience->photosPayload(),
                'available_dates' => $dates->get($experience->id) ?? collect(),
            ])
            // Sin fechas reservables no hay nada que vender: fuera de la lista.
            ->filter(fn (array $experience) => count($experience['available_dates']) > 0)
            ->values();

        return response()->json(['experiences' => $experiences]);
    }

    /** Horarios con cupo de UNA experiencia en UNA fecha — el paso 2 del flujo. */
    public function sessions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'experience_id' => ['required', 'integer', 'exists:experiences,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $day = Carbon::parse($data['date']);

        $sessions = ExperienceSession::query()
            ->where('experience_id', $data['experience_id'])
            ->where('status', ExperienceSession::STATUS_SCHEDULED)
            ->whereBetween('starts_at', [$day->startOfDay(), $day->copy()->endOfDay()])
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->withSum(['bookings as people_booked' => fn ($q) => $q->whereIn('status', [
                ExperienceBooking::STATUS_PENDING, ExperienceBooking::STATUS_CONFIRMED,
            ])], 'people')
            ->get()
            ->map(fn (ExperienceSession $session) => [
                'id' => $session->id,
                'starts_at' => $session->starts_at->toIso8601String(),
                'remaining' => max(0, $session->capacity - (int) ($session->people_booked ?? 0)),
            ])
            ->filter(fn (array $session) => $session['remaining'] > 0)
            ->values();

        return response()->json(['sessions' => $sessions]);
    }

    public function book(Request $request, CreateExperienceBooking $action): JsonResponse
    {
        $this->guardAgainstBots($request);

        $data = $request->validate([
            'experience_session_id' => ['required', 'integer', 'exists:experience_sessions,id'],
            'people' => ['required', 'integer', 'min:1', 'max:100'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['required', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $booking = $action->handle($data);
        } catch (NoAvailabilityException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $booking->load('session.experience');

        return response()->json([
            'code' => $booking->displayCode(),
            'experience' => $booking->session->experience->name,
            'starts_at' => $booking->session->starts_at->toIso8601String(),
            'people' => $booking->people,
            'total' => (float) $booking->total,
        ], 201);
    }

    /**
     * Métodos de cobro disponibles para experiencias — misma verdad que el
     * wizard de habitaciones (gate de plataforma/hotel + pasarelas activas
     * + cuentas de transferencia).
     */
    public function paymentOptions(): JsonResponse
    {
        $gate = app(\App\Services\Payments\PaymentMethodGate::class);
        $enabled = $gate->methodsFor((string) tenant('id'));

        $settings = Property::firstOrFail()->settings ?? [];
        $accountsCount = ! $enabled['transfer'] ? 0 : collect($settings['bank_accounts'] ?? [])
            ->filter(fn (array $a) => ! empty($a['active']))
            ->count();

        $enabledProviders = array_keys(array_filter([
            'stripe' => $enabled['stripe'],
            'mercadopago' => $enabled['mercadopago'],
            'paypal' => $enabled['paypal'],
        ]));
        $links = \App\Models\Central\PaymentGatewayLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('active', true)
            ->whereIn('provider', $enabledProviders)
            ->orderBy('id')
            ->get();

        return response()->json([
            'gateways' => $links->map(fn (\App\Models\Central\PaymentGatewayLink $link) => [
                'provider' => $link->provider,
                'label' => $link->providerLabel(),
            ])->values(),
            'transfer' => [
                'available' => $accountsCount > 0,
                'accounts_count' => $accountsCount,
            ],
        ]);
    }

    /**
     * Emite el cobro de la reserva de experiencia — espejo del paso de pago
     * del wizard de habitaciones: pasarela elegida o transferencia, siempre
     * por el total, montos del servidor.
     */
    public function payment(Request $request, string $code, \App\Actions\Experiences\IssueExperiencePayment $action): JsonResponse
    {
        $preferred = $request->string('method')->toString();
        $preferred = in_array($preferred, ['gateway', 'transfer'], true) ? $preferred : null;

        $requestedProvider = $request->string('provider')->toString();
        $requestedProvider = in_array($requestedProvider, ['stripe', 'mercadopago', 'paypal'], true) ? $requestedProvider : null;

        $booking = ExperienceBooking::query()->where('code', strtoupper(trim($code)))->first();

        if (! $booking) {
            return response()->json(['message' => 'No encontramos una reserva con ese folio.'], 404);
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
                    $paymentRequest = $action->handle($booking, \App\Models\PaymentRequest::METHOD_GATEWAY, null, $link);

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
                    // La pasarela falló pero hay cuentas: cae a transferencia.
                }
            }

            $paymentRequest = $action->handle($booking, \App\Models\PaymentRequest::METHOD_TRANSFER);
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

    /** Mismo anti-abuso v1 que el wizard de habitaciones (spec §9.3). */
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
}
