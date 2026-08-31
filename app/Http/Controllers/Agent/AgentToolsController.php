<?php

namespace App\Http\Controllers\Agent;

use App\Actions\Reservations\CreateReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Controller;
use App\Models\Experience;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
                // Ligas del hotel: sin esto el bot no podía mandar ni el
                // sitio ni el mapa, y con "comparte fotos" se quedaba corto.
                'website' => $settings['website'] ?? null,
                'maps_url' => $settings['maps_url'] ?? null,
                // Enlaces útiles que el hotel captura en /ajustes/general
                // (recorridos, galería, cómo llegar...).
                'links' => collect($settings['links'] ?? [])
                    ->filter(fn ($link) => ! empty($link['url']))
                    ->map(fn (array $link) => [
                        'label' => $link['label'] ?? '',
                        'url' => $link['url'],
                    ])->values(),
            ],
            'check_in_time' => $settings['check_in_time'] ?? null,
            'check_out_time' => $settings['check_out_time'] ?? null,
            'currency' => $settings['currency'] ?? 'MXN',
            // Fuente única de verdad: si no está aquí, el agente no lo sabe.
            'policies' => $settings['policies'] ?? null,
            // Política de cancelación default del hotel (una tarifa puede
            // definir la suya; el bot responde con la general).
            'cancellation_policy' => app(\App\Services\ReservationPolicy::class)->cancellationPolicyLabel(),
            'cancellation_policy_notes' => app(\App\Services\ReservationPolicy::class)->cancellationPolicyText(),
            // Fianza: el bot la menciona al cotizar para que nadie llegue
            // sin ese dinero. Es aparte del precio; `tiers_label` trae los
            // escalones por volumen cuando el hotel los configuró.
            'guarantee' => app(\App\Services\ReservationPolicy::class)->guaranteePublic(),
            'faqs' => \App\Models\Faq::query()->active()->ordered()
                ->get()
                ->map(fn (\App\Models\Faq $faq) => [
                    'q' => $faq->question,
                    'a' => $faq->answer,
                ])->values(),
            'room_types' => RoomType::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->with('rooms')
                ->get()
                ->map(function (RoomType $type) {
                    // Ocupación REAL desde las habitaciones del tipo: personas
                    // incluidas en la tarifa, máximo permitido y costo por
                    // persona extra — sin esto el bot inventaba "no hay cobro
                    // extra" (bug real 2026-08-18, Telegram motellacupula).
                    $rooms = $type->rooms;
                    $maxOccupancy = (int) ($rooms->max('max_occupancy') ?: $type->capacity);
                    $included = $rooms->whereNotNull('included_occupancy')->min('included_occupancy');
                    $extraFee = $rooms->whereNotNull('extra_guest_fee')->max('extra_guest_fee');

                    return [
                        'name' => $type->name,
                        'description' => $type->description,
                        // INVENTARIO REAL del tipo: cuántas habitaciones
                        // existen. Sin este dato el bot suponía que podía
                        // repetir un tipo cuantas veces quisiera y ofrecía
                        // "2 Cabañas Reales" donde solo hay UNA (caso real
                        // cabañas 2026-08-30, Messenger).
                        'units' => $rooms->count(),
                        'occupancy' => [
                            'included_guests' => $included !== null ? (int) $included : (int) $type->capacity,
                            'max_guests' => max($maxOccupancy, (int) $type->capacity),
                            'extra_guest_fee' => $extraFee !== null ? (float) $extraFee : null,
                            'extra_guest_fee_label' => $extraFee !== null
                                ? '$'.number_format((float) $extraFee, 2).' por persona extra por noche/periodo'
                                : null,
                        ],
                        // Cargos opcionales del cuarto (mascota, decoración…)
                        // que recepción aplica al llegar.
                        'optional_charges' => $rooms
                            ->flatMap(fn ($room) => $room->optional_charges ?? [])
                            ->unique('concept')
                            ->map(fn (array $charge) => [
                                'concept' => $charge['concept'] ?? '',
                                'amount_label' => isset($charge['amount']) ? '$'.number_format((float) $charge['amount'], 2) : null,
                            ])->values(),
                        // Página del sitio web con fotos: el bot la comparte
                        // cuando piden fotos de la habitación.
                        'photos_url' => $type->photos_url,
                    ];
                })->values(),
            ...$this->experiencesBlock(),
        ]);
    }

    /**
     * Recorridos/tours que el hotel ya vende (módulo `experiencias`). Sin
     * esto el bot no sabía que existían: en cabañas había 3 experiencias
     * activas con sesiones y reservas hechas, y a "¿qué se puede hacer por
     * allá?" contestaba que no tenía esa información.
     *
     * Va en el prompt (no en una herramienta) a propósito: son pocas líneas
     * y una tool-call cuesta reenviar el prompt completo otra vez.
     *
     * @return array<string, mixed>
     */
    protected function experiencesBlock(): array
    {
        if (! $this->hasActiveExperiences()) {
            return [];
        }

        $experiences = Experience::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'experiences' => $experiences->map(fn (Experience $experience) => array_filter([
                'name' => $experience->name,
                // Recortada: la ficha completa vive en su página, aquí solo
                // lo que el bot necesita para engancharlo.
                'description' => $experience->description
                    ? \Illuminate\Support\Str::limit($experience->description, 160)
                    : null,
                'duration_label' => $experience->durationLabel(),
                'price_label' => $experience->priceLabel(),
                'min_people' => $experience->min_people,
                'max_people' => $experience->max_people,
                'url' => $experience->url,
            ], fn ($value) => $value !== null && $value !== ''))->values(),
            'experiences_booking_url' => $this->publicTenantUrl(route('tenant.booking.experiences', [], false)),
            'experiences_note' => 'Recorridos que SÍ vende el hotel; para apartarlos, experiences_booking_url.',
        ];
    }

    /**
     * ¿Este hotel puede cobrar con pasarela? El módulo `cobros` es lo que
     * permite conectar una (routes/tenant.php), pero el bot leía las ligas
     * conectadas sin preguntar por el módulo: a un hotel al que se lo
     * quitaran, con Stripe ya conectado, el bot le habría seguido emitiendo
     * links. La transferencia y el efectivo NO dependen del módulo: van en
     * todos los planes.
     */
    protected function gatewaysAllowed(): bool
    {
        $tenant = tenant();

        return ! $tenant instanceof \App\Models\Tenant || $tenant->hasModule('cobros');
    }

    /** ¿El hotel tiene recorridos vivos que ofrecer? */
    protected function hasActiveExperiences(): bool
    {
        $tenant = tenant();

        if ($tenant instanceof \App\Models\Tenant && ! $tenant->hasModule('experiencias')) {
            return false;
        }

        return Experience::query()->where('active', true)->exists();
    }

    /**
     * URL pública SIEMPRE en el dominio del hotel: el bot contesta desde
     * webhooks que entran por el dominio central, donde route() a secas
     * hereda el host equivocado (mismo criterio que
     * PaymentRequest::publicReturnUrl).
     */
    protected function publicTenantUrl(string $relative): string
    {
        $domain = tenant()?->domains()->value('domain');

        if (! $domain) {
            return url($relative);
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$domain}{$relative}";
    }

    /**
     * get_rate_plans: tarifas activas con las que se puede cotizar.
     */
    public function ratePlans(): JsonResponse
    {
        return response()->json([
            'rate_plans' => RatePlan::query()
                ->where('active', true)
                ->with(['roomType:id,name,capacity', 'seasons' => fn ($q) => $q->where('active', true)])
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
                    // Con temporadas activas, este precio es solo el de
                    // referencia: el de unas fechas concretas sale de
                    // consultar_disponibilidad. Sin esta bandera el bot
                    // afirmaba "precio fijo todo el año" por su cuenta.
                    'seasonal' => $plan->seasons->isNotEmpty(),
                    'seasonal_note' => $plan->seasons->isNotEmpty()
                        ? 'El precio cambia por temporada: cotiza con fechas (consultar_disponibilidad) y nunca digas que es fijo todo el año.'
                        : null,
                    'deposit_percent' => $plan->deposit_percent !== null ? (float) $plan->deposit_percent : null,
                    'deposit_amount' => $plan->deposit_amount !== null ? (float) $plan->deposit_amount : null,
                    'deposit_label' => $plan->depositLabel(),
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
     * check_availability_overview: panorama de TODO el inventario para un
     * rango — cuántas unidades hay de cada tipo, cuántas quedan LIBRES, el
     * precio por unidad y el total del rango. Con `guests` arma además la
     * combinación de habitaciones que sí están libres para ese grupo.
     *
     * Existe porque el bot improvisaba justo aquí: ante un grupo ofrecía
     * varias unidades de un tipo que solo tiene una, y tras un "no hay
     * disponibilidad" listaba alternativas sin verificar NINGUNA (caso real
     * cabañas 2026-08-30, conversaciones 19 y 21). Las cuentas —cuántas
     * caben, cuántas quedan, cuánto suma— las hace el servidor.
     */
    public function availabilityOverview(Request $request, AvailabilityService $availability): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $requestedStart = Carbon::parse($data['starts_at']);
        $requestedEnd = ! empty($data['ends_at']) ? Carbon::parse($data['ends_at']) : null;
        $guests = isset($data['guests']) ? (int) $data['guests'] : null;

        $options = [];

        $types = RoomType::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->with('rooms')
            ->get();

        foreach ($types as $type) {
            // Una tarifa por tipo para cotizar el rango: por noche primero
            // (es lo que pide quien da fechas) y la más barata a igualdad.
            $plan = RatePlan::query()
                ->where('active', true)
                ->where('room_type_id', $type->id)
                ->get()
                ->sortBy(fn (RatePlan $candidate) => [$candidate->type->value === 'night' ? 0 : 1, (float) $candidate->price])
                ->first();

            if (! $plan) {
                continue;
            }

            $start = $requestedStart->copy();
            $end = $requestedEnd?->copy() ?? $plan->suggestedEnd($start);
            [$start, $end] = $this->normalizeNightTimes($plan, $start, $end);

            $rooms = $type->rooms;
            $free = $availability->availableRooms($type->id, $start, $end);
            $included = $rooms->whereNotNull('included_occupancy')->min('included_occupancy');
            $includedGuests = $included !== null ? (int) $included : (int) $type->capacity;
            $maxGuests = max((int) ($rooms->max('max_occupancy') ?: $type->capacity), (int) $type->capacity);
            $extraFee = $rooms->whereNotNull('extra_guest_fee')->max('extra_guest_fee');
            $total = $plan->priceFor($start, $end);

            $options[] = [
                'room_type' => $type->name,
                // Lo pide crear_apartado_grupo para armar sus líneas.
                'room_type_id' => $type->id,
                'rate_plan_id' => $plan->id,
                'rate_plan' => $plan->name,
                // units = cuántas existen; units_available = cuántas quedan
                // libres en ESTE rango. Nunca ofrecer más de units_available.
                'units' => $rooms->count(),
                'units_available' => $free->count(),
                'available' => $free->isNotEmpty(),
                'included_guests' => $includedGuests,
                'max_guests' => $maxGuests,
                'extra_guest_fee_label' => $extraFee !== null
                    ? '$'.number_format((float) $extraFee, 2).' por persona extra por noche/periodo'
                    : null,
                'price_label' => '$'.number_format((float) $plan->price, 2),
                'duration_label' => $plan->durationLabel(),
                'nights' => $plan->unitsFor($start, $end),
                'total' => $total,
                'total_label' => '$'.number_format($total, 2),
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
            ];
        }

        $availableOptions = collect($options)->where('units_available', '>', 0)->values();

        $unitsAvailable = (int) $availableOptions->sum('units_available');
        $capacityAvailable = (int) $availableOptions->sum(fn (array $option) => $option['units_available'] * $option['included_guests']);
        $maxCapacityAvailable = (int) $availableOptions->sum(fn (array $option) => $option['units_available'] * $option['max_guests']);

        $combination = [];
        $covered = 0;
        $combinationTotal = 0.0;

        if ($guests !== null) {
            // La combinación que armaría recepción: primero las de mayor
            // capacidad (menos habitaciones que coordinar) y a igual
            // capacidad la más barata. Solo con unidades realmente libres.
            $pool = $availableOptions->sortBy(fn (array $option) => [-$option['included_guests'], $option['total']])->values();

            foreach ($pool as $option) {
                if ($covered >= $guests) {
                    break;
                }

                $needed = (int) ceil(($guests - $covered) / max(1, $option['included_guests']));
                $take = min($needed, $option['units_available']);

                if ($take < 1) {
                    continue;
                }

                $subtotal = $take * $option['total'];
                $covered += $take * $option['included_guests'];
                $combinationTotal += $subtotal;

                $combination[] = [
                    'room_type' => $option['room_type'],
                    'room_type_id' => $option['room_type_id'],
                    'rate_plan_id' => $option['rate_plan_id'],
                    'units' => $take,
                    'guests_covered' => $take * $option['included_guests'],
                    'total_each_label' => $option['total_label'],
                    'subtotal' => $subtotal,
                    'subtotal_label' => '$'.number_format($subtotal, 2),
                ];
            }
        }

        $notes = ['Ofrece SOLO tipos con units_available mayor a 0, y nunca más unidades de las que dice units_available (units es cuántas existen en total).'];

        if ($unitsAvailable === 0) {
            $notes[] = 'No queda ninguna habitación libre en ese rango: dilo con claridad. Si alternative_dates trae fechas, ofrécelas TAL CUAL (son fechas verificadas con lugar); si viene vacío, di que esas semanas están llenas y pide otra fecha. No inventes alternativas.';
            $notes[] = 'De las fechas alternativas solo sabes CUÁNTAS habitaciones quedan y para cuánta gente, NO cuáles: no las nombres. Si el huésped elige una, vuelve a llamar consultar_disponibilidad_general con esa fecha para decirle qué habitaciones son.';
        }

        if ($guests !== null && $unitsAvailable > 0 && $covered < $guests) {
            $notes[] = "La capacidad libre no alcanza para {$guests} personas: dilo tal cual, ofrece otras fechas o usa transferir_a_humano. No completes el grupo con habitaciones que no están libres.";
        }

        // Cuando no alcanza, un recepcionista no cuelga: ofrece la fecha
        // más cercana que SÍ tiene lugar. Aquí se calcula igual de duro que
        // la disponibilidad del día pedido, para que el bot no invente
        // "puede ser el otro fin de semana" sin saberlo.
        $alternatives = [];

        if ($unitsAvailable === 0 || ($guests !== null && $covered < $guests)) {
            $alternatives = $this->nearbyDatesWithRoom(
                $types,
                $requestedStart,
                $requestedEnd,
                $guests,
                $availability,
            );
        }

        return response()->json([
            'starts_at' => $requestedStart->toDateString(),
            'ends_at' => $requestedEnd?->toDateString(),
            'starts_label' => $this->dateLabel($requestedStart),
            'ends_label' => $requestedEnd ? $this->dateLabel($requestedEnd) : null,
            'guests' => $guests,
            'units_available' => $unitsAvailable,
            'capacity_available' => $capacityAvailable,
            'max_capacity_available' => $maxCapacityAvailable,
            'options' => $options,
            'suggested_combination' => $combination,
            'combination_covers_guests' => $guests !== null ? $covered >= $guests : null,
            'combination_guests_covered' => $guests !== null ? $covered : null,
            'combination_total' => $combination ? $combinationTotal : null,
            'combination_total_label' => $combination ? '$'.number_format($combinationTotal, 2) : null,
            'alternative_dates' => $alternatives,
            'note' => implode(' ', $notes),
        ]);
    }

    /**
     * Primeras fechas cercanas (hasta 21 días adelante, misma duración) con
     * lugar de sobra para el grupo. Se corta en cuanto junta 3 opciones y
     * cada día sale del MISMO motor de disponibilidad, no de una suposición.
     *
     * @param  \Illuminate\Support\Collection<int, RoomType>  $types
     * @return array<int, array<string, mixed>>
     */
    protected function nearbyDatesWithRoom(
        $types,
        Carbon $start,
        ?Carbon $end,
        ?int $guests,
        AvailabilityService $availability,
    ): array {
        $nights = max(1, $end ? (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) : 1);
        $needed = max(1, $guests ?? 1);
        $found = [];

        $cursor = $start->copy()->startOfDay();
        $today = now()->startOfDay();

        // El mismo día de la semana primero (quien pide un sábado quiere el
        // sábado siguiente, no el martes), y después los días contiguos.
        $offsets = array_values(array_unique([7, 14, 21, ...range(1, 21)]));

        foreach ($offsets as $day) {
            if (count($found) >= 3) {
                break;
            }

            $candidate = $cursor->copy()->addDays($day);

            if ($candidate->lt($today)) {
                continue;
            }

            [$units, $capacity] = $this->rangeCapacity(
                $types,
                $candidate,
                $candidate->copy()->addDays($nights),
                $needed,
                $availability,
            );

            if ($units > 0 && $capacity >= $needed) {
                $checkout = $candidate->copy()->addDays($nights);

                $found[] = [
                    'starts_at' => $candidate->toDateString(),
                    'ends_at' => $checkout->toDateString(),
                    'starts_label' => $this->dateLabel($candidate),
                    'ends_label' => $this->dateLabel($checkout),
                    'nights' => $nights,
                    'units_available' => $units,
                    'capacity_available' => $capacity,
                ];
            }
        }

        return $found;
    }

    /**
     * Unidades libres y capacidad incluida de TODO el hotel en un rango.
     * Corta en cuanto junta la capacidad que hace falta: en un hotel con
     * lugar de sobra son una o dos consultas, no una por tipo.
     *
     * @param  \Illuminate\Support\Collection<int, RoomType>  $types
     * @return array{0: int, 1: int}
     */
    protected function rangeCapacity($types, Carbon $start, Carbon $end, int $needed, AvailabilityService $availability): array
    {
        $units = 0;
        $capacity = 0;

        foreach ($types as $type) {
            $plan = RatePlan::query()
                ->where('active', true)
                ->where('room_type_id', $type->id)
                ->get()
                ->sortBy(fn (RatePlan $candidate) => [$candidate->type->value === 'night' ? 0 : 1, (float) $candidate->price])
                ->first();

            if (! $plan) {
                continue;
            }

            [$from, $to] = $this->normalizeNightTimes($plan, $start->copy(), $end->copy());

            $free = $availability->availableRooms($type->id, $from, $to)->count();

            if ($free === 0) {
                continue;
            }

            $included = $type->rooms->whereNotNull('included_occupancy')->min('included_occupancy');
            $units += $free;
            $capacity += $free * ($included !== null ? (int) $included : (int) $type->capacity);

            if ($capacity >= $needed) {
                break;
            }
        }

        return [$units, $capacity];
    }

    /** Fecha en español para que el bot la copie sin traducirla mal. */
    protected function dateLabel(Carbon $date): string
    {
        return $date->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');
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
    /**
     * Métodos de cobro que este hotel puede ofrecer DE VERDAD, para que el
     * bot pregunte "¿cómo prefieres pagar?" solo con opciones reales:
     * pasarelas conectadas y activas, transferencia (cuentas activas) y
     * efectivo al llegar (doble llave de ReservationPolicy).
     *
     * @return array{pasarelas: array<int, array{provider: string, label: string}>, transferencia: bool, efectivo: bool}
     */
    protected function paymentOptionsSummary(): array
    {
        $enabled = app(\App\Services\Payments\PaymentMethodGate::class)->methodsFor((string) tenant('id'));

        $settings = Property::firstOrFail()->settings ?? [];
        $hasAccounts = $enabled['transfer'] && collect($settings['bank_accounts'] ?? [])
            ->filter(fn (array $account) => ! empty($account['active']))
            ->isNotEmpty();

        $enabledProviders = ! $this->gatewaysAllowed() ? [] : array_keys(array_filter([
            'stripe' => $enabled['stripe'],
            'mercadopago' => $enabled['mercadopago'],
            'paypal' => $enabled['paypal'],
        ]));
        $gateways = $enabledProviders === [] ? [] : \App\Models\Central\PaymentGatewayLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('active', true)
            ->whereIn('provider', $enabledProviders)
            ->orderBy('id')
            ->get()
            ->map(fn (\App\Models\Central\PaymentGatewayLink $link) => [
                'provider' => $link->provider,
                'label' => $link->providerLabel(),
            ])
            ->values()
            ->all();

        return [
            'pasarelas' => $gateways,
            'transferencia' => $hasAccounts,
            'efectivo' => app(\App\Services\ReservationPolicy::class)->cashPaymentEnabled(),
        ];
    }

    public function requestPayment(Request $request, \App\Actions\Payments\IssuePaymentRequest $action): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            // Elección del huésped (spec-reservas-avanzado §1.4 aplicado al
            // bot): sin metodo, el sistema decide como siempre (pasarela
            // primero, transferencia de respaldo).
            'metodo' => ['nullable', 'string', Rule::in(['pasarela', 'transferencia', 'efectivo'])],
            'proveedor' => ['nullable', 'string', Rule::in(['stripe', 'mercadopago', 'paypal'])],
        ]);

        $metodo = $data['metodo'] ?? null;
        $code = strtoupper(trim($data['code']));

        // Folio de grupo: UN cobro consolidado por todas las habitaciones,
        // no uno por cuarto (spec-pagos §6.4). Se resuelve aparte porque el
        // reparto por reserva lo hace IssueGroupPayment.
        if (str_starts_with($code, 'GRP')) {
            return $this->requestGroupPayment($code, $metodo, $data['proveedor'] ?? null, $request);
        }

        $reservation = Reservation::query()
            ->where('code', $code)
            ->first();

        if (! $reservation) {
            return response()->json(['message' => 'No encontramos una reserva con ese código.'], 404);
        }

        // Efectivo: no se emite ningún cobro — el apartado se extiende al
        // plazo de efectivo del hotel y recepción cobra en el check-in.
        if ($metodo === 'efectivo') {
            if (! app(\App\Services\ReservationPolicy::class)->cashPaymentEnabled()) {
                return response()->json(['message' => 'El hotel no ofrece pagar en efectivo al llegar; ofrece las otras opciones de pago.'], 422);
            }

            $deadline = app(\App\Actions\Payments\ChooseCashPayment::class)->handle($reservation);

            return response()->json([
                'code' => $reservation->displayCode(),
                'method' => 'efectivo',
                'hold_expires_at' => $deadline?->toIso8601String(),
                'instructions' => 'El huésped pagará al llegar al hotel. Dile hasta cuándo queda apartada su habitación (hold_expires_at) y que recepción cobra en el check-in. NUNCA lo des por pagado ni por confirmado.',
            ], 201);
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
        // Si el huésped eligió transferencia, se respeta: nunca se le impone
        // la pasarela (mismo principio que el wizard, §1.4).
        $enabledProviders = ! $this->gatewaysAllowed() ? [] : array_keys(array_filter([
            'stripe' => $enabled['stripe'],
            'mercadopago' => $enabled['mercadopago'],
            'paypal' => $enabled['paypal'],
        ]));
        $link = ($metodo === 'transferencia' || $enabledProviders === []) ? null : \App\Models\Central\PaymentGatewayLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('active', true)
            ->whereIn('provider', $enabledProviders)
            ->when(! empty($data['proveedor']), fn ($q) => $q->where('provider', $data['proveedor']))
            ->orderBy('id')
            ->first();

        if ($metodo === 'pasarela' && ! $link) {
            return response()->json([
                'message' => ! empty($data['proveedor'])
                    ? 'Esa pasarela no está disponible en este hotel; ofrece las opciones que sí existen.'
                    : 'El hotel no tiene pasarela de pago conectada; ofrece transferencia o efectivo si están disponibles.',
            ], 422);
        }

        if ($metodo === 'transferencia' && $accounts->isEmpty()) {
            return response()->json(['message' => 'El hotel no tiene cuentas bancarias activas para transferencia; ofrece las otras opciones de pago.'], 422);
        }

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
                    // Link CORTO del hotel (/pago/{uuid}), nunca el checkout
                    // crudo: el de Stripe mide ~470 caracteres con un
                    // #fragmento obligatorio que el modelo a veces recorta al
                    // escribirlo → "link no válido" (bug real 2026-08-12,
                    // bandeja motellacupula). La página corta trae el botón
                    // de pago con el URL completo intacto.
                    'payment_link' => $paymentRequest->publicReturnUrl(),
                    'expires_at' => $paymentRequest->expires_at?->toIso8601String(),
                    'instructions' => 'Comparte el link tal cual: el huésped paga en la página segura del proveedor y la confirmación llega sola al sistema. NUNCA afirmes que el pago fue recibido; el sistema avisará. No pidas datos de tarjeta por el chat.',
                ], 201);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            } catch (\RuntimeException $e) {
                // Con elección explícita de pasarela no se sustituye en
                // silencio por transferencia: se informa y el huésped decide.
                if ($accounts->isEmpty() || $metodo === 'pasarela') {
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
     * Cobro de un grupo: mismo criterio que el panel (link de pasarela o
     * transferencia; el efectivo no aplica a un folio consolidado). El bot
     * comparte lo que salga y NUNCA da el pago por recibido: la pasarela se
     * confirma sola por webhook y la transferencia la verifica el personal.
     */
    protected function requestGroupPayment(string $code, ?string $metodo, ?string $proveedor, Request $request): JsonResponse
    {
        $group = \App\Models\ReservationGroup::query()->where('code', $code)->first();

        if (! $group) {
            return response()->json(['message' => 'No encontramos un grupo con ese folio.'], 404);
        }

        if ($metodo === 'efectivo') {
            return response()->json([
                'message' => 'Un grupo no se aparta con pago en efectivo al llegar: ofrece link de pago o transferencia.',
            ], 422);
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

        $enabledProviders = ! $this->gatewaysAllowed() ? [] : array_keys(array_filter([
            'stripe' => $enabled['stripe'],
            'mercadopago' => $enabled['mercadopago'],
            'paypal' => $enabled['paypal'],
        ]));

        $link = ($metodo === 'transferencia' || $enabledProviders === []) ? null : \App\Models\Central\PaymentGatewayLink::query()
            ->where('tenant_id', (string) tenant('id'))
            ->where('active', true)
            ->whereIn('provider', $enabledProviders)
            ->when($proveedor, fn ($q) => $q->where('provider', $proveedor))
            ->orderBy('id')
            ->first();

        if ($metodo === 'pasarela' && ! $link) {
            return response()->json([
                'message' => $proveedor
                    ? 'Esa pasarela no está disponible en este hotel; ofrece las opciones que sí existen.'
                    : 'El hotel no tiene pasarela de pago conectada; ofrece transferencia si está disponible.',
            ], 422);
        }

        if (! $link && $accounts->isEmpty()) {
            return response()->json([
                'message' => 'El hotel aún no tiene métodos de cobro configurados; informa que recepción confirmará el grupo directamente.',
            ], 422);
        }

        try {
            $paymentRequest = app(\App\Actions\Payments\IssueGroupPayment::class)->handle(
                $group,
                $link ? \App\Models\PaymentRequest::METHOD_GATEWAY : \App\Models\PaymentRequest::METHOD_TRANSFER,
                $request->user(),
                $link,
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($link) {
            return response()->json([
                'code' => $group->displayCode(),
                'method' => 'link_de_pago',
                'provider' => $link->providerLabel(),
                'amount' => (float) $paymentRequest->amount,
                'amount_label' => $paymentRequest->amountLabel(),
                'payment_link' => $paymentRequest->publicReturnUrl(),
                'expires_at' => $paymentRequest->expires_at?->toIso8601String(),
                'instructions' => 'Un solo link por todo el grupo. Compártelo tal cual: pagan en la página segura del proveedor y la confirmación llega sola. NUNCA afirmes que el pago fue recibido.',
            ], 201);
        }

        return response()->json([
            'code' => $group->displayCode(),
            'method' => 'transferencia',
            'amount' => (float) $paymentRequest->amount,
            'amount_label' => $paymentRequest->amountLabel(),
            'expires_at' => $paymentRequest->expires_at?->toIso8601String(),
            'bank_accounts' => $accounts,
            'instructions' => 'Una sola transferencia por todo el grupo, por el monto exacto, y que envíen el comprobante por este chat. El equipo del hotel lo verifica; NUNCA afirmes que el pago fue recibido.',
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
            // Fianza en el MISMO resultado que confirma el apartado: el bot
            // la avisa aquí sin depender de que haya llamado get_policies
            // antes (caso real cabañas 2026-08-28: confirmó sin mencionarla).
            'guarantee' => app(\App\Services\ReservationPolicy::class)->guaranteePublic(),
            // Los métodos REALES del hotel: el bot pregunta "¿cómo prefieres
            // pagar?" solo con opciones que existen, y llama solicitar_pago
            // con la elección (metodo/proveedor).
            'payment_options' => $this->paymentOptionsSummary(),
            'message' => $this->holdMessage($requiresPrepayment),
        ];

        // Venta cruzada en el único momento que no se siente spam: ya
        // apartó. Va como DATO del resultado (no solo como regla) y solo si
        // el hotel tiene recorridos activos, para que el bot no invente que
        // hay tours donde no los hay.
        if ($this->hasActiveExperiences()) {
            $payload['experiences_hint'] = 'Después del código del apartado, menciona en UNA sola línea que el hotel tiene recorridos (los de experiences) por si les interesan. Una vez, sin insistir y sin repetir la lista completa.';
        }

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
     * create_group_hold: aparta VARIAS habitaciones bajo un folio GRP-,
     * TODO O NADA (módulo `grupos`). Es el cierre que le faltaba al bot:
     * ya sabía proponer la combinación para 15 personas, pero para
     * apartarla tenía que hacer apartados sueltos — y si el tercero se
     * quedaba sin cuarto, el grupo quedaba partido.
     *
     * Reutiliza CreateGroupReservation, o sea los mismos locks, precios de
     * servidor y política de anticipos que el panel.
     */
    public function storeGroupHold(Request $request, \App\Actions\Reservations\CreateGroupReservation $action): JsonResponse
    {
        if (! $this->groupsAllowed()) {
            return response()->json(['message' => 'Este hotel no tiene reservas de grupo; aparta las habitaciones una por una con crear_apartado.'], 403);
        }

        $data = $request->validate([
            'starts_at' => ['required', 'date', 'after_or_equal:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'lines' => ['required', 'array', 'min:1', 'max:10'],
            'lines.*.room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'lines.*.rooms' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        // Modalidad: por noche cuando el tipo tiene tarifa de noche (el caso
        // de quien da fechas); si el hotel solo cobra por bloque, se respeta.
        $firstType = RoomType::query()->find($data['lines'][0]['room_type_id']);
        $mode = $firstType?->ratePlans()->where('active', true)->where('type', 'night')->exists()
            ? 'night'
            : 'block';

        try {
            $group = $action->handle([
                ...$data,
                'mode' => $mode,
                'confirmed' => false, // igual que un apartado: lo confirma el hotel
                'source_channel' => 'agent',
                'notes' => 'Creada por asistente IA',
            ], $request->user());
        } catch (NoAvailabilityException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $group = $group->fresh()->load('reservations.roomType', 'reservations.room');
        $reservations = $group->reservations;
        $total = (float) $reservations->sum('total_amount');
        $deposit = (float) $reservations->sum('deposit_amount');

        $payload = [
            'code' => $group->displayCode(),
            'status' => ReservationStatus::Pending->value,
            'rooms_count' => $reservations->count(),
            'rooms' => $reservations->map(fn (Reservation $reservation) => [
                'code' => $reservation->displayCode(),
                'room_type' => $reservation->roomType?->name,
                'room' => $reservation->room?->number,
            ])->values(),
            'starts_at' => $reservations->min('starts_at')?->toIso8601String(),
            'ends_at' => $reservations->max('ends_at')?->toIso8601String(),
            'total' => $total,
            'total_label' => '$'.number_format($total, 2),
            'deposit' => $deposit,
            'deposit_label' => '$'.number_format($deposit, 2),
            'requires_prepayment' => $reservations->contains(fn (Reservation $r) => (bool) $r->ratePlan?->requiresPrepayment()),
            'hold_expires_at' => $reservations->min('hold_expires_at')?->toIso8601String(),
            // Fianza: en grupo es donde más se nota (los escalones por
            // volumen viven en tiers_label).
            'guarantee' => app(\App\Services\ReservationPolicy::class)->guaranteePublic(),
            'payment_options' => $this->paymentOptionsSummary(),
            'message' => 'Grupo apartado con UN solo folio. Da el código del grupo (no el de cada habitación, salvo que lo pidan) y di cuántas habitaciones quedaron. '
                .($this->paymentMethodsPublic()
                    ? 'El cobro del grupo es UNO consolidado: llama solicitar_pago con este mismo folio GRP-.'
                    : $this->noPaymentMethodsNote()),
        ];

        if ($this->hasActiveExperiences()) {
            $payload['experiences_hint'] = 'Después del código del grupo, menciona en UNA sola línea que el hotel tiene recorridos por si les interesan. Una vez, sin insistir.';
        }

        return response()->json($payload, 201);
    }

    /**
     * Qué hacer después de apartar. Sin métodos de cobro configurados el bot
     * NO tiene la herramienta solicitar_pago, y ese vacío se lo inventaba
     * ("se paga al llegar en recepción" en un hotel que no aceptaba
     * efectivo): aquí se le dice explícitamente qué decir.
     */
    protected function holdMessage(bool $requiresPrepayment): string
    {
        if (! $this->paymentMethodsPublic()) {
            return 'Apartado creado. '.$this->noPaymentMethodsNote();
        }

        return $requiresPrepayment
            ? 'Apartado creado; se confirma al recibir el pago. Ofrece al huésped elegir entre las opciones de payment_options y llama solicitar_pago con el metodo que elija.'
            : 'Apartado creado; el hotel lo confirmará. Si no se confirma, expira solo.';
    }

    protected function noPaymentMethodsNote(): string
    {
        return 'El hotel NO tiene cobros configurados: di que recepción se comunica para confirmar y cerrar el pago. NO prometas ninguna forma de pago (ni efectivo al llegar, ni transferencia, ni link): no sabes cuál acepta.';
    }

    /** ¿Este hotel vende reservas de grupo? */
    protected function groupsAllowed(): bool
    {
        $tenant = tenant();

        return ! $tenant instanceof \App\Models\Tenant || $tenant->hasModule('grupos');
    }

    /** Lo mismo, para que el cerebro decida si registra la herramienta. */
    public function groupsPublic(): bool
    {
        return $this->groupsAllowed();
    }

    /**
     * ¿El hotel tiene ALGÚN método de cobro? Sin pasarela, sin cuentas de
     * transferencia y sin efectivo, ofrecer "solicitar_pago" solo lleva al
     * bot a prometer un cobro que revienta.
     */
    public function paymentMethodsPublic(): bool
    {
        $options = $this->paymentOptionsSummary();

        return $options['pasarelas'] !== [] || $options['transferencia'] || $options['efectivo'];
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
