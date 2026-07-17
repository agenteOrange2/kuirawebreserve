<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Payments\IssuePaymentRequest;
use App\Actions\Reservations\CreateReservation;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Controller;
use App\Models\Central\PaymentGatewayLink;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use App\Services\Payments\PaymentMethodGate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API pública del wizard de reservas (spec-motor-reservas-web E0). Público
 * y stateless (throttle, sin sesión); TODA la lógica de negocio es la
 * misma que usan el panel y la Agent API — esta capa solo la expone sin
 * autenticación y con anti-abuso (honeypot, tiempo mínimo de llenado,
 * idempotencia). Los montos SIEMPRE se calculan en servidor.
 *
 * Dos modalidades, según el catálogo real del hotel (decisión E0, ver
 * spec §13.2): "night" (llegada+salida, tarifas por noche) y "block"
 * (solo llegada, tarifas por bloque/hora — el caso motel, donde el
 * catálogo entero puede no tener NINGUNA tarifa por noche).
 */
class BookingController extends Controller
{
    /**
     * Paso 1: opciones disponibles para un rango — total ya calculado por
     * el servidor, una tarjeta por tipo de habitación.
     */
    public function availability(Request $request, AvailabilityService $availability): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['night', 'block'])],
            'arrive_date' => ['required_if:mode,night', 'nullable', 'date', 'after_or_equal:today'],
            'depart_date' => ['required_if:mode,night', 'nullable', 'date', 'after:arrive_date'],
            'arrive_at' => ['required_if:mode,block', 'nullable', 'date', 'after_or_equal:now'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            // Paso "Confirmar habitación" (restructurado 2026-07-11):
            // reconsulta este mismo endpoint ya con adults/children reales,
            // acotado al tipo ya elegido — evita recalcular el catálogo
            // entero solo para refrescar el precio de una tarjeta.
            'room_type_id' => ['sometimes', 'integer', 'exists:room_types,id'],
        ]);

        [$start, $end] = $this->resolveDates($data);
        $children = $this->isAdultsOnly() ? 0 : ($data['children'] ?? 0);
        // Sin personas dadas (paso "Fechas" del wizard): 1 adulto de
        // anclaje — nunca dispara el cargo por persona extra
        // (extraChargeLines exige `people > included`), así que el precio
        // que se muestra aquí es limpiamente "desde". El número real de
        // personas se pide DESPUÉS de elegir habitación (paso "Confirmar
        // habitación"), reconsultando este mismo endpoint ya con
        // adults/children reales para ese room_type_id puntual.
        $guests = ($data['adults'] ?? 1) + $children;

        $options = RoomType::query()
            ->where('active', true)
            ->when(isset($data['room_type_id']), fn ($q) => $q->where('id', $data['room_type_id']))
            ->with(['media', 'property'])
            ->orderBy('sort_order')
            ->get()
            ->map(function (RoomType $type) use ($data, $start, $end, $availability, $guests) {
                $ratePlan = $type->ratePlans()
                    ->where('active', true)
                    ->where('type', $data['mode'])
                    ->orderBy('price')
                    ->first();

                if (! $ratePlan) {
                    return null; // el tipo no vende en esta modalidad
                }

                // Por noche, los horarios son de ESTE tipo (Catálogo →
                // Horarios) con fallback a los del hotel: cada cabaña puede
                // entregar y recibir a su propia hora.
                if ($data['mode'] === 'night') {
                    [[$inHour, $inMinute], [$outHour, $outMinute]] = $type->effectiveScheduleTimes();
                    $start = $start->copy()->setTime($inHour, $inMinute);
                    $end = $end?->copy()->setTime($outHour, $outMinute);
                }

                $planEnd = $end ?? $ratePlan->suggestedEnd($start);

                $advanceError = $ratePlan->violatesMinAdvance($start)
                    ? "Requiere reservar con al menos {$ratePlan->minAdvanceLabel()} de antelación."
                    : null;

                // Habitación representativa: cada cuarto puede tener su
                // propio price_modifier (+vista al mar, −interior), así que
                // "disponible" por sí solo no basta para cotizar — hay que
                // fijar YA cuál cuarto se ofrece, para que el total que se
                // muestra sea EXACTAMENTE el que se cobra al crear el hold
                // (se manda de vuelta como room_id y el paso 2 lo reserva).
                // Se ofrece el modificador más barato primero, filtrando
                // primero a los cuartos que de verdad admiten a `$guests`.
                $availableRooms = $advanceError
                    ? collect()
                    : $availability->availableRooms($type->id, $start, $planEnd)
                        ->filter(fn ($room) => ($room->max_occupancy ?? $type->capacity) >= $guests)
                        ->sortBy(fn ($room) => (float) ($room->price_modifier ?? 0));
                $offeredRoom = $availableRooms->first();

                // Mismas líneas que ya se sumaban al total (persona extra +
                // cargos opcionales del cuarto ofrecido) — spec-wizard-precios
                // §3: se exponen para que la tarjeta explique de qué se
                // compone el precio, no solo el número final. Puramente
                // informativo: NO participa en el cálculo de 'total' (que
                // sigue siendo la misma fórmula ya probada) para que no
                // pueda haber un centavo de diferencia entre lo que se
                // muestra desglosado y lo que en verdad se cobra.
                $extraChargeLines = $offeredRoom
                    ? $offeredRoom->extraChargeLines($guests, $ratePlan->unitsFor($start, $planEnd))
                    : [];
                $total = $offeredRoom
                    ? round($ratePlan->priceFor($start, $planEnd, $offeredRoom) + array_sum(array_column($extraChargeLines, 'amount')), 2)
                    : $ratePlan->priceFor($start, $planEnd);

                return [
                    'room_type_id' => $type->id,
                    'room_id' => $offeredRoom?->id,
                    'name' => $type->name,
                    'description' => $type->description,
                    'capacity' => $type->capacity,
                    // Techo real del cuarto ofrecido si es mayor al del
                    // catálogo (con recargo por persona extra ya reflejado
                    // en price_breakdown) — el frontend lo usa para avisar
                    // "hasta N con cargo extra" en vez de ocultar la opción.
                    'effective_capacity' => $offeredRoom ? ($offeredRoom->max_occupancy ?? $type->capacity) : $type->capacity,
                    'amenities' => $type->amenities ?? [],
                    // Galería del tipo: miniatura para la tarjeta, foto
                    // completa para el paso de confirmación.
                    'photos' => $type->photosPayload(),
                    'duration_label' => $ratePlan->durationLabel(),
                    'starts_at' => $start->toIso8601String(),
                    'ends_at' => $planEnd->toIso8601String(),
                    // Incluye el cobro por personas extra del cuarto ofrecido
                    // (holds pasa adults/children y CreateReservation lo
                    // recalcula igual: lo mostrado sigue siendo lo cobrado).
                    'total' => $total,
                    'price_breakdown' => $offeredRoom ? $ratePlan->priceBreakdown($start, $planEnd, $offeredRoom, $extraChargeLines) : [],
                    'requires_prepayment' => $this->requiresPrepaymentFor($ratePlan),
                    'available' => $availableRooms->isNotEmpty(),
                    'rooms_count' => $availableRooms->count(),
                    'advance_error' => $advanceError,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'mode' => $data['mode'],
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end?->toIso8601String(),
            'options' => $options,
            'any_available' => $options->contains('available', true),
        ]);
    }

    /**
     * Paso 2: crea el hold (reserva Pendiente, 30 min). Nunca confía en un
     * rate_plan_id ni un precio del cliente: re-resuelve la tarifa más
     * barata activa de esa modalidad para el tipo pedido, igual que
     * availability() — así el total que se cobra es siempre el que el
     * servidor recalculó, no el que mandó el navegador.
     */
    public function holds(Request $request, CreateReservation $action): JsonResponse
    {
        $this->guardAgainstBots($request);

        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key !== '') {
            $hit = DB::table('booking_idempotency_keys')->where('key', $key)->first();
            if ($hit) {
                return response()
                    ->json(json_decode($hit->response, true), $hit->status)
                    ->header('Idempotency-Replayed', 'true');
            }
        }

        $data = $request->validate([
            'mode' => ['required', Rule::in(['night', 'block'])],
            'arrive_date' => ['required_if:mode,night', 'nullable', 'date', 'after_or_equal:today'],
            'depart_date' => ['required_if:mode,night', 'nullable', 'date', 'after:arrive_date'],
            'arrive_at' => ['required_if:mode,block', 'nullable', 'date', 'after_or_equal:now'],
            'room_type_id' => ['required', 'exists:room_types,id'],
            // La habitación específica que availability() ofreció (con su
            // price_modifier ya incluido en el total mostrado). Opcional
            // por si el cliente no la manda: CreateReservation resuelve
            // "la primera libre" como respaldo, aunque eso puede diferir
            // del total ya mostrado si el cuarto tiene modificador.
            'room_id' => [
                'nullable',
                Rule::exists('rooms', 'id')->where('room_type_id', $request->integer('room_type_id')),
            ],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['required', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            // Extras del wizard (productos del POS, /ajustes/wizard):
            // CreateReservation los revalida contra active+available_in_wizard
            // igual que este endpoint nunca confía en el precio del cliente.
            'products' => ['sometimes', 'array', 'max:20'],
            'products.*.product_id' => ['required_with:products', 'integer'],
            'products.*.qty' => ['required_with:products', 'integer', 'min:1', 'max:20'],
            // Add-ons del módulo `extras` (decoración, desayuno...):
            // CreateReservation revalida contra el catálogo activo.
            'extras' => ['sometimes', 'array', 'max:20'],
            'extras.*.extra_id' => ['required_with:extras', 'integer'],
            'extras.*.qty' => ['required_with:extras', 'integer', 'min:1', 'max:20'],
            // Experiencias como plus (módulo `experiencias`): el cupo duro y
            // el precio los hace cumplir CreateReservation bajo lock.
            'experiences' => ['sometimes', 'array', 'max:5'],
            'experiences.*.session_id' => ['required_with:experiences', 'integer'],
            'experiences.*.people' => ['required_with:experiences', 'integer', 'min:1', 'max:100'],
        ], [
            'room_id.exists' => 'Esa habitación ya no está disponible; vuelve a consultar la disponibilidad.',
        ]);

        [$start, $end] = $this->resolveDates($data);

        $type = RoomType::findOrFail($data['room_type_id']);
        $ratePlan = $type->ratePlans()->where('active', true)->where('type', $data['mode'])->orderBy('price')->first();

        if (! $ratePlan) {
            return response()->json(['message' => 'Esa habitación ya no tiene tarifa disponible en esa modalidad.'], 422);
        }

        // Mismos horarios por tipo que ofreció availability() — lo mostrado
        // es lo que se reserva.
        if ($data['mode'] === 'night') {
            [[$inHour, $inMinute], [$outHour, $outMinute]] = $type->effectiveScheduleTimes();
            $start = $start->copy()->setTime($inHour, $inMinute);
            $end = $end?->copy()->setTime($outHour, $outMinute);
        }

        try {
            $reservation = $action->handle([
                'rate_plan_id' => $ratePlan->id,
                'room_id' => $data['room_id'] ?? null,
                'starts_at' => $start,
                'ends_at' => $end ?? $ratePlan->suggestedEnd($start),
                'confirmed' => false,
                'source_channel' => 'web',
                'guest_name' => $data['guest_name'],
                'guest_phone' => $data['guest_phone'],
                'guest_email' => $data['guest_email'] ?? null,
                'products' => $data['products'] ?? [],
                'extras' => $data['extras'] ?? [],
                'experiences' => $data['experiences'] ?? [],
                'adults' => $data['adults'],
                'children' => $this->isAdultsOnly() ? 0 : ($data['children'] ?? 0),
                'notes' => $data['notes'] ?? 'Creada desde el wizard web',
            ]);
        } catch (NoAvailabilityException|\InvalidArgumentException $e) {
            // El cuarto (o el cupo del tour) que se mostró ya se lo llevaron
            // entre el paso 1 y el paso 2: mejor decirlo claro que cobrar
            // otro precio en silencio. InvalidArgumentException: reglas de
            // la experiencia (mínimo/máximo de personas por reserva).
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $requiresPrepayment = $this->requiresPrepaymentFor($ratePlan);

        // Desglose para la confirmación: cuánto es la habitación, cuánto
        // los productos, add-ons y experiencias — no solo el total plano.
        $productsTotal = round(array_sum(array_column($reservation->products ?? [], 'total')), 2);
        $extrasTotal = round(array_sum(array_column($reservation->extras ?? [], 'total')), 2);
        $experiencesTotal = round(array_sum(array_column($reservation->experiences ?? [], 'total')), 2);

        $payload = [
            'code' => $reservation->displayCode(),
            'room_type' => $type->name,
            'starts_at' => $reservation->starts_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'room_total' => round((float) $reservation->total_amount - $productsTotal - $extrasTotal - $experiencesTotal, 2),
            // Mismas líneas que ya cobró CreateReservation (extra_charges es
            // exactamente lo que devolvió Room::extraChargeLines()) — igual
            // que en availability(), informativo, no recalcula nada.
            'price_breakdown' => $ratePlan->priceBreakdown($reservation->starts_at, $reservation->ends_at, $reservation->room, $reservation->extra_charges ?? []),
            'products' => $reservation->products ?? [],
            'products_total' => $productsTotal,
            'extras' => $reservation->extras ?? [],
            'extras_total' => $extrasTotal,
            'experiences' => $reservation->experiences ?? [],
            'experiences_total' => $experiencesTotal,
            'total' => (float) $reservation->total_amount,
            'requires_prepayment' => $requiresPrepayment,
            'deposit' => (float) $reservation->deposit_amount,
            'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
            'hold_minutes' => app(\App\Services\ReservationPolicy::class)->holdMinutes(),
        ];

        if ($key !== '') {
            DB::table('booking_idempotency_keys')->where('created_at', '<', now()->subDays(config('booking.idempotency_key_days', 7)))->delete();
            DB::table('booking_idempotency_keys')->insertOrIgnore([
                'key' => $key,
                'status' => 201,
                'response' => json_encode($payload),
                'created_at' => now(),
            ]);
        }

        return response()->json($payload, 201);
    }

    /**
     * Paso 3: emite el cobro (link de pasarela o transferencia) — SOLO si
     * la tarifa lo exige. Si la tarifa no tiene anticipo configurado, esto
     * se rechaza a propósito: llamarlo de todos modos pediría el TOTAL
     * (IssuePaymentRequest interpreta "sin anticipo" como "cobra todo"),
     * que no es lo que "sin prepago" significa aquí — el hotel confirma
     * directo, sin cobro en línea.
     */
    public function payment(Request $request, string $code, IssuePaymentRequest $action): JsonResponse
    {
        // Cuando hay más de un método disponible, el wizard ya se lo
        // preguntó al huésped (GET payment-options) — aquí solo se respeta.
        // Sin preferencia explícita, se prueba pasarela primero (comportamiento
        // previo, para no romper el caso de un solo método disponible).
        $preferred = $request->string('method')->toString();
        $preferred = in_array($preferred, ['gateway', 'transfer'], true) ? $preferred : null;

        // Pasarela ESPECÍFICA (spec-reservas-avanzado §1.4): un hotel puede
        // tener Stripe + PayPal + MercadoPago activos a la vez (hasta 3 en
        // Pro) — antes esto no existía y siempre se usaba "la primera por
        // id" sin importar cuál eligiera el huésped en pantalla.
        $requestedProvider = $request->string('provider')->toString();
        $requestedProvider = in_array($requestedProvider, ['stripe', 'mercadopago', 'paypal'], true) ? $requestedProvider : null;

        $reservation = Reservation::with('ratePlan')->where('code', strtoupper(trim($code)))->first();

        if (! $reservation) {
            return response()->json(['message' => 'No encontramos una reserva con ese código.'], 404);
        }

        if (! $reservation->ratePlan || ! $this->requiresPrepaymentFor($reservation->ratePlan)) {
            return response()->json([
                'message' => 'Esta tarifa no requiere pago en línea; el hotel confirmará tu reserva directamente.',
            ], 422);
        }

        $gate = app(PaymentMethodGate::class);
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

        if ($requestedProvider !== null) {
            // Se pidió una pasarela puntual: se usa ESA o se rechaza — nunca
            // se sustituye en silencio por otra que el huésped no eligió.
            $link = PaymentGatewayLink::query()
                ->where('tenant_id', (string) tenant('id'))
                ->where('active', true)
                ->where('provider', $requestedProvider)
                ->whereIn('provider', $enabledProviders)
                ->first();

            if (! $link) {
                return response()->json(['message' => 'Esa pasarela ya no está disponible; vuelve a consultar las opciones de pago.'], 422);
            }
        } else {
            $link = PaymentGatewayLink::query()
                ->where('tenant_id', (string) tenant('id'))
                ->where('active', true)
                ->whereIn('provider', $enabledProviders)
                ->orderBy('id')
                ->first();
        }

        if (! $link && $accounts->isEmpty()) {
            return response()->json([
                'message' => 'El hotel aún no tiene métodos de cobro en línea; contáctalo directamente para confirmar tu reserva.',
            ], 422);
        }

        // Transferencia pedida explícitamente pero sin cuentas activas: mejor
        // rechazar que emitir un cobro sin ningún dato bancario que mostrar.
        if ($preferred === 'transfer' && $accounts->isEmpty()) {
            return response()->json([
                'message' => 'La transferencia bancaria ya no está disponible; vuelve a consultar las opciones de pago.',
            ], 422);
        }

        // El huésped pidió transferencia explícitamente aunque haya
        // pasarela: se respeta, no se le impone la pasarela.
        if ($link && $preferred !== 'transfer') {
            try {
                $paymentRequest = $action->handle($reservation, PaymentRequest::METHOD_GATEWAY, null, $link);

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

        $paymentRequest = $action->handle($reservation, PaymentRequest::METHOD_TRANSFER);

        return response()->json([
            'method' => 'transfer',
            'amount' => (float) $paymentRequest->amount,
            'amount_label' => $paymentRequest->amountLabel(),
            'bank_accounts' => $accounts,
            'valid_hours' => (int) now()->diffInHours($paymentRequest->expires_at ?? now()),
            'return_url' => route('tenant.payment.return', $paymentRequest->uuid),
        ], 201);
    }

    /**
     * "adults_only" (caso motel, configurable en Ajustes → Wizard de
     * reservas): el wizard nunca pide/cuenta niños. Se vuelve a comprobar
     * aquí y no solo en el frontend — un cliente no debería poder colar
     * niños mandando el campo directo a la API.
     */
    protected function isAdultsOnly(): bool
    {
        $settings = Property::firstOrFail()->settings ?? [];

        return ($settings['guest_policy'] ?? 'family') === 'adults_only';
    }

    /**
     * Anti-abuso v1 (spec §9.3): honeypot + tiempo mínimo de llenado. El
     * límite de holds simultáneos por IP queda pendiente (ver spec).
     */
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
     * ¿Esta tarifa pide pago en línea al reservar? Por default (`automatic`,
     * el comportamiento de siempre) lo decide la tarifa misma
     * (`deposit_percent`). El hotel puede forzarlo en /ajustes/wizard
     * (spec-wizard-precios-y-pasos §5.2) cuando quiere control explícito en
     * vez de que dependa de cómo esté configurada cada tarifa:
     * `always` = pedir pago en línea aunque la tarifa no tenga anticipo
     * configurado (IssuePaymentRequest ya interpreta "sin anticipo" como
     * "cobra el total"); `never` = jamás pedirlo, el hotel siempre confirma
     * directo, aunque la tarifa sí tenga anticipo configurado.
     */
    protected function requiresPrepaymentFor(RatePlan $ratePlan): bool
    {
        $mode = Property::firstOrFail()->settings['payment_mode'] ?? 'automatic';

        return match ($mode) {
            'always' => true,
            'never' => false,
            default => $ratePlan->requiresPrepayment(),
        };
    }

    /**
     * Por noche: las horas de entrada/salida son las de Ajustes del hotel
     * (check_in_time/check_out_time), no un 15:00/12:00 fijo — si el hotel
     * configuró otro horario, la reserva del wizard debe respetarlo igual
     * que el panel.
     *
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
