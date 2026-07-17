<?php

namespace App\Http\Controllers\Agent;

use App\Actions\Reservations\CreateReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Herramientas (tools) que consumen los agentes IA vía tool-calling
 * (spec-pendientes §4.1). Contratos JSON estables: montos siempre en crudo
 * + etiqueta formateada para minimizar alucinación de cifras. Reutiliza las
 * mismas actions/servicios que el panel — un solo camino de negocio.
 */
class AgentToolsController extends Controller
{
    /**
     * get_policies: identidad, horarios, contacto y políticas del hotel.
     */
    public function policies(): JsonResponse
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return response()->json([
            'hotel' => [
                'name' => $property->name,
                'address' => $property->address,
                'timezone' => $property->timezone,
                'phone' => $settings['phone'] ?? null,
                'email' => $settings['email'] ?? null,
            ],
            'check_in_time' => $settings['check_in_time'] ?? null,
            'check_out_time' => $settings['check_out_time'] ?? null,
            'currency' => $settings['currency'] ?? 'MXN',
            // Fuente única de verdad: si no está aquí, el agente no lo sabe.
            'policies' => $settings['policies'] ?? null,
            'faqs' => \App\Models\Faq::query()->active()->ordered()
                ->get()
                ->map(fn (\App\Models\Faq $faq) => [
                    'q' => $faq->question,
                    'a' => $faq->answer,
                ])->values(),
            'room_types' => RoomType::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (RoomType $type) => [
                    'name' => $type->name,
                    'description' => $type->description,
                    'capacity' => $type->capacity,
                ])->values(),
        ]);
    }

    /**
     * get_rate_plans: tarifas activas con las que se puede cotizar.
     */
    public function ratePlans(): JsonResponse
    {
        return response()->json([
            'rate_plans' => RatePlan::query()
                ->where('active', true)
                ->with('roomType:id,name,capacity')
                ->orderBy('price')
                ->get()
                ->map(fn (RatePlan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'room_type' => $plan->roomType?->name,
                    'capacity' => $plan->roomType?->capacity,
                    'billing' => $plan->type->value, // night | block
                    'duration_label' => $plan->durationLabel(),
                    'price' => (float) $plan->price,
                    'price_label' => '$'.number_format((float) $plan->price, 2),
                    'deposit_percent' => $plan->deposit_percent !== null ? (float) $plan->deposit_percent : null,
                    'min_advance' => $plan->minAdvanceLabel(),
                ])->values(),
        ]);
    }

    /**
     * check_availability: habitaciones libres y total para tarifa + rango.
     */
    public function availability(Request $request, AvailabilityService $availability): JsonResponse
    {
        $data = $request->validate([
            'rate_plan_id' => ['required', 'exists:rate_plans,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $ratePlan = RatePlan::findOrFail($data['rate_plan_id']);
        $start = Carbon::parse($data['starts_at']);
        $end = ! empty($data['ends_at']) ? Carbon::parse($data['ends_at']) : $ratePlan->suggestedEnd($start);

        // Por noche, el LLM manda fechas peladas que Carbon deja a las
        // 00:00: sin normalizar a los horarios reales (tipo ?? hotel ??
        // 15/12), el bot choca con la noche anterior en días de rotación
        // (diría "no hay" cuando la cabaña se libera a las 11 y entra a
        // las 14) — mismo fix que ya lleva el wizard.
        [$start, $end] = $this->normalizeNightTimes($ratePlan, $start, $end);

        $rooms = $availability->availableRooms($ratePlan->room_type_id, $start, $end);
        $total = $ratePlan->priceFor($start, $end);

        return response()->json([
            'available' => $rooms->isNotEmpty(),
            'rooms_count' => $rooms->count(),
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
            'units' => $ratePlan->unitsFor($start, $end),
            'duration_label' => $ratePlan->durationLabel(),
            'total' => $total,
            'total_label' => '$'.number_format($total, 2),
            'advance_error' => $ratePlan->violatesMinAdvance($start)
                ? "Esta tarifa requiere reservar con al menos {$ratePlan->minAdvanceLabel()} de antelación."
                : null,
        ]);
    }

    /**
     * get_reservation: estado de una reserva por su código (RES-AAAA-XXXX).
     */
    public function showReservation(string $code): JsonResponse
    {
        $reservation = Reservation::query()
            ->with(['room:id,number', 'ratePlan:id,name'])
            ->where('code', strtoupper(trim($code)))
            ->first();

        if (! $reservation) {
            return response()->json(['message' => 'No encontramos una reserva con ese código.'], 404);
        }

        $activeRequest = $reservation->paymentRequests()->active()->latest('id')->first();

        // Privacidad: el agente solo confirma datos no sensibles.
        return response()->json([
            'code' => $reservation->displayCode(),
            'status' => $reservation->status->value,
            'status_label' => $reservation->status->label(),
            'guest_first_name' => str($reservation->guest_name ?? '')->before(' ')->toString() ?: null,
            'room' => $reservation->room?->number,
            'rate_plan' => $reservation->ratePlan?->name,
            'starts_at' => $reservation->starts_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'total' => (float) $reservation->total_amount,
            'total_label' => '$'.number_format((float) $reservation->total_amount, 2),
            'payment_status' => $reservation->payment_status->value,
            'payment_status_label' => $reservation->payment_status->label(),
            'pending_amount' => $reservation->pendingBalance(),
            'pending_label' => '$'.number_format($reservation->pendingBalance(), 2),
            // Cobro en curso: el bot informa el estado, JAMÁS lo da por pagado.
            'payment_request' => $activeRequest ? [
                'concept' => $activeRequest->conceptLabel(),
                'amount_label' => $activeRequest->amountLabel(),
                'status' => 'en verificación o pendiente de pago',
                'expires_at' => $activeRequest->expires_at?->toIso8601String(),
            ] : null,
            'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
        ]);
    }

    /**
     * request_payment: emite la solicitud de cobro de lo que toque (anticipo
     * o saldo) y entrega las instrucciones de pago del hotel. El monto lo
     * calcula el servidor; el bot solo pasa el código. Marcarla pagada es
     * asunto del staff (verificación) o del webhook (F1) — nunca del bot.
     */
    public function requestPayment(Request $request, \App\Actions\Payments\IssuePaymentRequest $action): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
        ]);

        $reservation = Reservation::query()
            ->where('code', strtoupper(trim($data['code'])))
            ->first();

        if (! $reservation) {
            return response()->json(['message' => 'No encontramos una reserva con ese código.'], 404);
        }

        // Métodos habilitados por plataforma/hotel (admin manda): un método
        // apagado no se ofrece aunque haya cuentas o pasarela conectada.
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

        // Con pasarela activa el cobro sale como LINK (se confirma solo por
        // webhook); la transferencia queda de respaldo (spec-pagos §7.1/7.4).
        $enabledProviders = array_keys(array_filter([
            'stripe' => $enabled['stripe'],
            'mercadopago' => $enabled['mercadopago'],
            'paypal' => $enabled['paypal'],
        ]));
        $link = \App\Models\Central\PaymentGatewayLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('active', true)
            ->whereIn('provider', $enabledProviders)
            ->orderBy('id')
            ->first();

        if (! $link && $accounts->isEmpty()) {
            return response()->json([
                'message' => 'El hotel aún no tiene métodos de cobro configurados; informa que recepción confirmará su apartado directamente.',
            ], 422);
        }

        if ($link) {
            try {
                $paymentRequest = $action->handle($reservation, \App\Models\PaymentRequest::METHOD_GATEWAY, $request->user(), $link);

                return response()->json([
                    'code' => $reservation->displayCode(),
                    'method' => 'link_de_pago',
                    'provider' => $link->providerLabel(),
                    'concept' => $paymentRequest->conceptLabel(),
                    'amount' => (float) $paymentRequest->amount,
                    'amount_label' => $paymentRequest->amountLabel(),
                    'payment_link' => $paymentRequest->checkout_url,
                    'expires_at' => $paymentRequest->expires_at?->toIso8601String(),
                    'instructions' => 'Comparte el link tal cual: el huésped paga en la página segura del proveedor y la confirmación llega sola al sistema. NUNCA afirmes que el pago fue recibido; el sistema avisará. No pidas datos de tarjeta por el chat.',
                ], 201);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            } catch (\RuntimeException $e) {
                if ($accounts->isEmpty()) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
                // La pasarela falló pero hay cuentas: cae a transferencia.
            }
        }

        try {
            $paymentRequest = $action->handle(
                $reservation,
                \App\Models\PaymentRequest::METHOD_TRANSFER,
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'code' => $reservation->displayCode(),
            'method' => 'transferencia',
            'concept' => $paymentRequest->conceptLabel(),
            'amount' => (float) $paymentRequest->amount,
            'amount_label' => $paymentRequest->amountLabel(),
            'expires_at' => $paymentRequest->expires_at?->toIso8601String(),
            'valid_hours' => (int) now()->diffInHours($paymentRequest->expires_at ?? now()),
            'bank_accounts' => $accounts,
            'instructions' => 'Pide al huésped que realice la transferencia por el monto exacto y envíe por este chat su comprobante (foto o captura). El equipo del hotel lo verificará; NUNCA afirmes que el pago fue recibido.',
        ], 201);
    }

    /**
     * create_hold: aparta habitación como reserva pendiente (NUNCA confirma
     * ni cobra). Idempotente vía header Idempotency-Key: el mismo intento
     * reintentado devuelve la respuesta original.
     */
    public function storeHold(Request $request, CreateReservation $action): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key !== '') {
            $hit = DB::table('agent_idempotency_keys')->where('key', $key)->first();
            if ($hit) {
                return response()
                    ->json(json_decode($hit->response, true), $hit->status)
                    ->header('Idempotency-Replayed', 'true');
            }
        }

        $data = $request->validate([
            'rate_plan_id' => ['required', 'exists:rate_plans,id'],
            'starts_at' => ['required', 'date', 'after_or_equal:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // Mismas horas normalizadas que ofreció get_availability: lo
        // cotizado es lo que se aparta.
        $holdPlan = RatePlan::findOrFail($data['rate_plan_id']);
        $holdStart = Carbon::parse($data['starts_at']);
        $holdEnd = ! empty($data['ends_at']) ? Carbon::parse($data['ends_at']) : $holdPlan->suggestedEnd($holdStart);
        [$holdStart, $holdEnd] = $this->normalizeNightTimes($holdPlan, $holdStart, $holdEnd);

        try {
            $reservation = $action->handle([
                ...$data,
                'starts_at' => $holdStart,
                'ends_at' => $holdEnd,
                'confirmed' => false, // hold: lo confirma un humano en el panel
                'source_channel' => 'agent',
                'notes' => $data['notes'] ?? 'Creada por asistente IA',
            ], $request->user());
        } catch (NoAvailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Con prepago, la confirmación depende del pago, no del hotel: el bot
        // debe ofrecer las instrucciones de cobro (request_payment) enseguida.
        $requiresPrepayment = (bool) $reservation->ratePlan?->requiresPrepayment();

        // Desglose (spec-wizard-precios-y-pasos §3/P2): mismo formato que ya
        // usa el wizard público — el bot explica de qué se compone el total
        // en vez de darlo como número plano (reduce alucinación de cifras,
        // spec-pendientes-y-agentes §6).
        $priceBreakdown = $reservation->ratePlan
            ? $reservation->ratePlan->priceBreakdown($reservation->starts_at, $reservation->ends_at, $reservation->room, $reservation->extra_charges ?? [])
            : [];

        $payload = [
            'code' => $reservation->displayCode(),
            'status' => ReservationStatus::Pending->value,
            'room' => $reservation->room?->number,
            'starts_at' => $reservation->starts_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'total' => (float) $reservation->total_amount,
            'total_label' => '$'.number_format((float) $reservation->total_amount, 2),
            'price_breakdown' => collect($priceBreakdown)->map(fn (array $line) => [
                'concept' => $line['concept'],
                'amount' => $line['amount'],
                'amount_label' => '$'.number_format($line['amount'], 2),
            ])->values(),
            'deposit' => (float) $reservation->deposit_amount,
            'deposit_label' => '$'.number_format((float) $reservation->deposit_amount, 2),
            'requires_prepayment' => $requiresPrepayment,
            'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
            'hold_minutes' => app(\App\Services\ReservationPolicy::class)->holdMinutes(),
            'message' => $requiresPrepayment
                ? 'Apartado creado; se confirma al recibir el pago. Usa solicitar_pago para dar las instrucciones de pago al huésped.'
                : 'Apartado creado; el hotel lo confirmará. Si no se confirma, expira solo.',
        ];

        if ($key !== '') {
            // Limpieza perezosa de llaves viejas + registro tolerante a carreras.
            DB::table('agent_idempotency_keys')->where('created_at', '<', now()->subDays(7))->delete();
            DB::table('agent_idempotency_keys')->insertOrIgnore([
                'key' => $key,
                'status' => 201,
                'response' => json_encode($payload),
                'created_at' => now(),
            ]);
        }

        return response()->json($payload, 201);
    }

    /**
     * Tarifas por noche: aplica los horarios reales de entrada/salida
     * (los del tipo, o los del hotel, o 15:00/12:00) a fechas que el LLM
     * manda peladas. Las tarifas por bloque conservan la hora pedida.
     *
     * @return array{0: \Carbon\Carbon|\Carbon\CarbonInterface, 1: \Carbon\Carbon|\Carbon\CarbonInterface}
     */
    protected function normalizeNightTimes(RatePlan $ratePlan, $start, $end): array
    {
        if ($ratePlan->type->value !== 'night' || ! $ratePlan->roomType) {
            return [$start, $end];
        }

        [[$inHour, $inMinute], [$outHour, $outMinute]] = $ratePlan->roomType->effectiveScheduleTimes();

        return [
            $start->copy()->setTime($inHour, $inMinute),
            $end->copy()->setTime($outHour, $outMinute),
        ];
    }
}
