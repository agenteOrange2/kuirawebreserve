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
        $supportHours = app(\App\Services\SupportHours::class);

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
                // Servicios de TODO el complejo: lo que comparten todas las
                // habitaciones (alberca, fogata, estacionamiento). Así el bot
                // contesta "¿tienen alberca?" sin que le nombren una cabaña.
                'services' => $this->sharedAmenities(),
                // Horario de atención del personal (NO el check-in): el bot
                // no debe prometer "en un momento te atienden" de madrugada.
                'support_hours' => $supportHours->enabled() ? $supportHours->label() : null,
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
            // Plazos de pago del hotel (/ajustes/metodos-pago/plazos-y-saldo):
            // hasta cuándo vale un apartado por transferencia o efectivo y
            // con cuánta antelación hay que liquidar. El bot los daba solo si
            // el huésped preguntaba y coincidía con una FAQ.
            'payment_terms' => array_filter([
                'transfer_window' => $this->minutesLabel(app(\App\Services\ReservationPolicy::class)->transferMinutes()),
                // Contrato / aviso legal que el huésped debe leer y confirmar
                // antes de apartar (opcional por hotel).
                'legal_notice_url' => $this->legalNoticeUrl(),
                'balance_due' => app(\App\Services\ReservationPolicy::class)->balanceDueLabel(),
                'notice' => implode(' ', $this->paymentNoticeLines()),
            ]),
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

                    return [
                        'name' => $type->name,
                        'description' => $type->description,
                        // INVENTARIO REAL del tipo: cuántas habitaciones
                        // existen. Sin este dato el bot suponía que podía
                        // repetir un tipo cuantas veces quisiera y ofrecía
                        // "2 Cabañas Reales" donde solo hay UNA (caso real
                        // cabañas 2026-08-30, Messenger).
                        'units' => $rooms->count(),
                        'occupancy' => $this->occupancyOf($type),
                        // La misma capacidad en una frase lista para mandar:
                        // el bot cotizaba sin decir cuántos entran ni cuánto
                        // cuesta el extra aunque el dato ya iba aquí.
                        'occupancy_notice' => $this->occupancyNotice($type),
                        // Cargos opcionales del cuarto (mascota, decoración…)
                        // que recepción aplica al llegar.
                        'optional_charges' => $rooms
                            ->flatMap(fn ($room) => $room->optional_charges ?? [])
                            ->unique('concept')
                            ->map(fn (array $charge) => [
                                'concept' => $charge['concept'] ?? '',
                                'amount_label' => isset($charge['amount']) ? '$'.number_format((float) $charge['amount'], 2) : null,
                            ])->values(),
                        // Amenidades del tipo (alberca, asador, fogata,
                        // minisplit...). Sin esto el bot no sabía que las
                        // cabañas tienen alberca y transfería a recepción una
                        // pregunta que el catálogo ya contestaba (caso real
                        // cabañas 2026-09-07, Messenger).
                        'amenities' => array_values(array_filter(
                            (array) ($type->amenities ?? []),
                            fn ($amenity) => is_string($amenity) && trim($amenity) !== '',
                        )),
                        // Página del sitio web con fotos: el bot la comparte
                        // cuando piden fotos de la habitación.
                        'photos_url' => $type->photos_url,
                    ];
                })->values(),
            ...$this->experiencesBlock(),
        ]);
    }

    /**
     * Minutos en palabras redondas: 1440 minutos se dice "24 horas".
     */
    protected function minutesLabel(int $minutes): string
    {
        return $minutes % 60 === 0 && $minutes >= 60
            ? \App\Enums\RateDurationUnit::Hour->label(intdiv($minutes, 60))
            : \App\Enums\RateDurationUnit::Minute->label($minutes);
    }

    /**
     * Ocupación REAL de un tipo: personas incluidas en la tarifa, tope y
     * costo por persona extra. Una sola fuente para las tres superficies
     * que la exponen (policies, panorama y cotización) — antes estaba
     * copiada y era cuestión de tiempo que una se quedara atrás.
     *
     * @return array{included_guests: int, max_guests: int, extra_guest_fee: float|null, extra_guest_fee_label: string|null}
     */
    protected function occupancyOf(RoomType $type): array
    {
        $rooms = $type->relationLoaded('rooms') ? $type->rooms : $type->rooms()->get();
        $included = $rooms->whereNotNull('included_occupancy')->min('included_occupancy');
        $extraFee = $rooms->whereNotNull('extra_guest_fee')->max('extra_guest_fee');

        return [
            'included_guests' => $included !== null ? (int) $included : (int) $type->capacity,
            'max_guests' => max((int) ($rooms->max('max_occupancy') ?: $type->capacity), (int) $type->capacity),
            'extra_guest_fee' => $extraFee !== null ? (float) $extraFee : null,
            'extra_guest_fee_label' => $extraFee !== null
                ? '$'.number_format((float) $extraFee, 2).' por persona extra por noche/periodo'
                : null,
        ];
    }

    /**
     * Capacidad y persona extra en una frase lista para mandar. El dato ya
     * viajaba en el JSON, pero el bot lo omitía al cotizar y el huésped se
     * enteraba del cargo al llegar: con la frase hecha solo tiene que
     * copiarla (con MiniMax, lo que no es determinista no se cumple).
     */
    protected function occupancyNotice(RoomType $type, ?RatePlan $ratePlan = null): string
    {
        $occupancy = $this->occupancyOf($type);

        $notice = "Incluye {$occupancy['included_guests']} ".($occupancy['included_guests'] === 1 ? 'persona' : 'personas');

        if ($occupancy['max_guests'] > $occupancy['included_guests']) {
            $notice .= " (máximo {$occupancy['max_guests']})";
        }

        $notice .= '.';

        if ($occupancy['extra_guest_fee'] !== null) {
            // "por noche" o "por periodo" según cómo venda ESTE tipo: decir
            // solo "$250" deja creer que es un cobro único por la estancia.
            $unit = ($ratePlan?->type->value ?? $type->ratePlans()->where('active', true)->value('type')) === 'block'
                ? 'por periodo'
                : 'por noche';

            $notice .= ' Cada persona extra cuesta $'.number_format($occupancy['extra_guest_fee'], 2)." {$unit}.";
        }

        return $notice;
    }

    /**
     * Aviso de pago para una cotización: anticipo para apartar, hasta cuándo
     * hay que liquidar (/ajustes/metodos-pago/plazos-y-saldo) y a dónde
     * llamar para dudas. Va como texto hecho porque son las cosas que el bot
     * se saltaba al cotizar — el plazo vivía solo en una FAQ y la gente se
     * enteraba tarde de que el saldo vence ANTES de llegar.
     *
     * El anticipo se calcula del MISMO campo que después cobra el link
     * (deposit_percent/deposit_amount de la tarifa), para que no pueda haber
     * diferencia entre lo que el bot promete y lo que el sistema pide. Si el
     * hotel cuenta otra cifra en sus instrucciones, el que está mal es ese
     * texto: la cuenta la manda el catálogo.
     *
     * @return array<int, string>
     */
    protected function paymentNoticeLines(?RatePlan $ratePlan = null, ?\Carbon\CarbonInterface $start = null, ?float $total = null): array
    {
        $policy = app(\App\Services\ReservationPolicy::class);
        $lines = [];

        $deposit = $ratePlan !== null && $total !== null
            ? (float) ($ratePlan->depositAmountFor($total) ?? 0)
            : 0.0;

        if ($deposit > 0) {
            $share = $ratePlan?->deposit_percent !== null && (float) $ratePlan->deposit_percent > 0
                ? ' ('.$ratePlan->depositLabel().' del total)'
                : '';

            $lines[] = 'Para apartar se pide un anticipo de $'.number_format($deposit, 2).$share.'.';
        }

        if ($ratePlan !== null && $start !== null) {
            $balance = $policy->balanceDueNotice($ratePlan, $start);

            if ($balance !== null) {
                $lines[] = $balance;
            }
        } elseif (($label = $policy->balanceDueLabel()) !== null) {
            $lines[] = "El pago total debe quedar liquidado {$label}.";
        }

        $phone = Property::query()->first()?->settings['phone'] ?? null;

        if (filled($phone)) {
            $lines[] = "Para cualquier duda o aclaración, comunícate al {$phone}.";
        }

        return $lines;
    }

    /**
     * Amenidades presentes en TODOS los tipos de habitación: eso es un
     * servicio del complejo, no de una cabaña en particular.
     *
     * @return array<int, string>
     */
    protected function sharedAmenities(): array
    {
        $lists = RoomType::query()->where('active', true)->get()
            ->map(fn (RoomType $type) => array_values(array_filter(
                (array) ($type->amenities ?? []),
                fn ($amenity) => is_string($amenity) && trim($amenity) !== '',
            )))
            ->filter(fn (array $amenities) => $amenities !== [])
            ->values();

        if ($lists->isEmpty()) {
            return [];
        }

        return array_values(array_intersect(...$lists->all()));
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
        $dateNotice = $this->forwardPastDates($request);

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

        // Lo que el bot debe decir SIEMPRE junto al total y se le olvidaba:
        // cuántas personas entran y qué cuesta la extra, el anticipo, hasta
        // cuándo hay que liquidar y a dónde llamar. Va como frase hecha para
        // que solo tenga que copiarla.
        // Primera línea: QUÉ cabaña y si está libre, pegada a SU precio.
        // Caso real cabañas 2026-09-11 (Karely): el bot dijo "¡Hay
        // disponibilidad!" para la Luxury —ocupada— con el precio de la Real.
        $typeName = $ratePlan->roomType?->name ?? 'La habitación';
        $range = $start->locale('es')->isoFormat('dddd D [de] MMMM').' al '.$end->locale('es')->isoFormat('dddd D [de] MMMM');
        $headline = $rooms->isNotEmpty()
            ? "{$typeName}, {$range}: disponible, total $".number_format($total, 2).'.'
            : "{$typeName}, {$range}: NO está disponible. No la ofrezcas ni la cotices.";

        $noticeLines = array_values(array_filter([
            $headline,
            $rooms->isNotEmpty() && $ratePlan->roomType ? $this->occupancyNotice($ratePlan->roomType, $ratePlan) : null,
            ...($rooms->isNotEmpty() ? $this->paymentNoticeLines($ratePlan, $start, $total) : []),
        ]));

        return response()->json([
            'room_type' => $ratePlan->roomType?->name,
            'available' => $rooms->isNotEmpty(),
            'rooms_count' => $rooms->count(),
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
            'units' => $ratePlan->unitsFor($start, $end),
            'duration_label' => $ratePlan->durationLabel(),
            'total' => $total,
            'total_label' => '$'.number_format($total, 2),
            'occupancy' => $ratePlan->roomType ? $this->occupancyOf($ratePlan->roomType) : null,
            'deposit_label' => $ratePlan->depositLabel(),
            'deposit_amount' => $ratePlan->depositAmountFor($total),
            // OBLIGATORIO al cotizar: renglones textuales que el bot repite
            // tal cual después del precio.
            'quote_notice' => $noticeLines,
            'advance_error' => $ratePlan->violatesMinAdvance($start)
                ? "Esta tarifa requiere reservar con al menos {$ratePlan->minAdvanceLabel()} de antelación."
                : null,
            'date_notice' => $dateNotice,
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
        $dateNotice = $this->forwardPastDates($request);

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:200'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        // Lo que ESTE huésped ya tiene apartado para esas fechas. El motor
        // de disponibilidad cuenta sus propios apartados como ocupados —es
        // correcto para todos los demás— y el bot lo leía como "ya no hay
        // lugar" y se lo decía a quien acababa de transferir (cabañas
        // 2026-09-14, GRP-2026-0149: cuatro cabañas suyas, "solo queda 1").
        $propias = $this->ownLiveReservations(
            ! empty($data['conversation_id'])
                ? \App\Models\Conversation::query()->find($data['conversation_id'])
                : null,
        );

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
            $occupancy = $this->occupancyOf($type);
            $includedGuests = $occupancy['included_guests'];
            $maxGuests = $occupancy['max_guests'];
            $total = $plan->priceFor($start, $end);

            // De este tipo y estas fechas, ¿cuántas ya son de este huésped?
            $suyas = $propias
                ->where('room_type_id', $type->id)
                ->filter(fn (Reservation $r) => $r->starts_at < $end && $r->ends_at > $start)
                ->count();

            $options[] = [
                'room_type' => $type->name,
                // Lo pide crear_apartado_grupo para armar sus líneas.
                'room_type_id' => $type->id,
                // Ya apartadas por ESTE huésped: no están libres para nadie
                // más, pero para él no son un "no hay".
                'yours_already' => $suyas,
                'rate_plan_id' => $plan->id,
                'rate_plan' => $plan->name,
                // units = cuántas existen; units_available = cuántas quedan
                // libres en ESTE rango. Nunca ofrecer más de units_available.
                'units' => $rooms->count(),
                'units_available' => $free->count(),
                'available' => $free->isNotEmpty(),
                'included_guests' => $includedGuests,
                'max_guests' => $maxGuests,
                'extra_guest_fee' => $occupancy['extra_guest_fee'],
                'extra_guest_fee_label' => $occupancy['extra_guest_fee_label'],
                // Capacidad y persona extra en una frase: al listar varias
                // opciones el bot las daba "peladas", con el precio y nada más.
                'occupancy_notice' => $this->occupancyNotice($type),
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

        // La misma gente en MENOS habitaciones usando la persona extra: para
        // 10 personas el panorama proponía 3 cabañas de 4 ($9,000) cuando
        // caben en 2 de máximo 5 con dos personas extra ($6,500). El huésped
        // tuvo que pelearlo (cabañas 2026-09-14: "no, dos sencillas").
        $withExtras = [];
        $extrasTotal = 0.0;
        $remaining = $guests ?? 0;

        if ($guests !== null) {
            // Primero las que más gente admiten y, a igualdad, la más barata.
            $pool = $availableOptions->sortBy(fn (array $option) => [-$option['max_guests'], $option['total']])->values();

            foreach ($pool as $option) {
                if ($remaining <= 0) {
                    break;
                }

                $fee = (float) ($option['extra_guest_fee'] ?? 0);
                $nights = max(1, (int) $option['nights']);
                $units = 0;
                $subtotal = 0.0;
                $extraGuests = 0;

                while ($units < $option['units_available'] && $remaining > 0) {
                    $inRoom = min($option['max_guests'], $remaining);
                    $extra = max(0, $inRoom - $option['included_guests']);

                    $subtotal += (float) $option['total'] + $extra * $fee * $nights;
                    $extraGuests += $extra;
                    $remaining -= $inRoom;
                    $units++;
                }

                if ($units < 1) {
                    continue;
                }

                $withExtras[] = [
                    'room_type' => $option['room_type'],
                    'room_type_id' => $option['room_type_id'],
                    'rate_plan_id' => $option['rate_plan_id'],
                    'units' => $units,
                    'extra_guests' => $extraGuests,
                    'subtotal' => round($subtotal, 2),
                    'subtotal_label' => '$'.number_format($subtotal, 2),
                ];

                $extrasTotal += $subtotal;
            }
        }

        $extrasTotal = round($extrasTotal, 2);
        $extrasCovered = $guests !== null ? $guests - max(0, $remaining) : 0;
        $standardRooms = (int) array_sum(array_column($combination, 'units'));
        $extrasRooms = (int) array_sum(array_column($withExtras, 'units'));

        // Solo vale la pena ofrecerla si cubre al grupo y mejora a la
        // combinación normal: menos habitaciones, más barata, o la normal ni
        // siquiera alcanzaba.
        $offerExtras = $guests !== null
            && $withExtras !== []
            && $extrasCovered >= $guests
            && ($covered < $guests || $extrasRooms < $standardRooms || $extrasTotal + 0.01 < $combinationTotal);

        $notes = ['Ofrece SOLO tipos con units_available mayor a 0, y nunca más unidades de las que dice units_available (units es cuántas existen en total).'];

        if ($offerExtras) {
            $detail = collect($withExtras)
                ->map(fn (array $line) => $line['units'].' '.$line['room_type'].($line['extra_guests'] > 0 ? ' (+'.$line['extra_guests'].' persona extra)' : ''))
                ->implode(' + ');

            $notes[] = "Cabe en menos habitaciones con persona extra: {$detail} = $".number_format($extrasTotal, 2)
                .' (combination_with_extras). Ofrécele las dos opciones con su total y deja que elija; al apartar manda el total de personas en el campo personas.';
        }

        // Lo primero que tiene que leer el modelo: parte de lo "ocupado" es
        // de este mismo huésped. Sin esta línea le dice "ya no hay lugar" a
        // quien tiene las habitaciones apartadas y pagadas.
        if ($propias->isNotEmpty()) {
            $suyasEnRango = $propias->filter(
                fn (Reservation $r) => $r->starts_at < ($requestedEnd ?? $requestedStart->copy()->addDay())
                    && $r->ends_at > $requestedStart,
            );

            if ($suyasEnRango->isNotEmpty()) {
                $folio = $suyasEnRango->first()->group?->displayCode()
                    ?? $suyasEnRango->first()->displayCode();

                array_unshift(
                    $notes,
                    'ATENCIÓN: este huésped YA TIENE '.$suyasEnRango->count().' habitación(es) apartadas para estas fechas bajo el folio '
                    .$folio.' (campo yours_already por tipo). Esas habitaciones son SUYAS: aparecen como ocupadas porque él las apartó. '
                    .'NUNCA le digas que no hay disponibilidad, que su reserva no existe o que la perdió. Confírmale su folio; '
                    .'si algo no cuadra con su pago, usa transferir_a_humano.',
                );
            }
        }

        if ($unitsAvailable === 0) {
            $notes[] = 'No queda ninguna habitación libre en ese rango: dilo con claridad. Si alternative_dates trae fechas, ofrécelas TAL CUAL (son fechas verificadas con lugar); si viene vacío, di que esas semanas están llenas y pide otra fecha. No inventes alternativas.';
            $notes[] = 'De las fechas alternativas solo sabes CUÁNTAS habitaciones quedan y para cuánta gente, NO cuáles: no las nombres. Si el huésped elige una, vuelve a llamar consultar_disponibilidad_general con esa fecha para decirle qué habitaciones son.';
        }

        if ($guests !== null && $unitsAvailable > 0 && $covered < $guests && ! $offerExtras) {
            $notes[] = "La capacidad libre no alcanza para {$guests} personas: dilo tal cual, ofrece otras fechas o usa transferir_a_humano. No completes el grupo con habitaciones que no están libres.";
        }

        // Cuando no alcanza, un recepcionista no cuelga: ofrece la fecha
        // más cercana que SÍ tiene lugar. Aquí se calcula igual de duro que
        // la disponibilidad del día pedido, para que el bot no invente
        // "puede ser el otro fin de semana" sin saberlo.
        $alternatives = [];

        if ($unitsAvailable === 0 || ($guests !== null && $covered < $guests && ! $offerExtras)) {
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
            // La misma gente en menos habitaciones, con persona extra.
            'combination_with_extras' => $offerExtras ? $withExtras : [],
            'combination_with_extras_rooms' => $offerExtras ? $extrasRooms : null,
            'combination_with_extras_total' => $offerExtras ? $extrasTotal : null,
            'combination_with_extras_total_label' => $offerExtras ? '$'.number_format($extrasTotal, 2) : null,
            'alternative_dates' => $alternatives,
            // Plazo de liquidación y teléfono del hotel: van también aquí
            // porque muchas conversaciones cotizan por el panorama y nunca
            // pasan por consultar_disponibilidad. El anticipo NO, que cambia
            // por tarifa: ese sale al cotizar una habitación concreta.
            'payment_notice' => $this->paymentNoticeLines(),
            'note' => implode(' ', $notes),
            'date_notice' => $dateNotice,
        ]);
    }

    /**
     * Las reservas VIVAS de esta conversación (y las de su mismo grupo): las
     * que el motor de disponibilidad cuenta como ocupadas y que, para este
     * huésped, no son un "no hay" sino lo que ya apartó.
     *
     * @return \Illuminate\Support\Collection<int, Reservation>
     */
    protected function ownLiveReservations(?\App\Models\Conversation $conversation): \Illuminate\Support\Collection
    {
        $reservation = $conversation?->reservation;

        if (! $reservation) {
            return collect();
        }

        return Reservation::query()
            ->with('group:id,code,created_at')
            ->where(function ($query) use ($reservation) {
                $reservation->reservation_group_id
                    ? $query->where('reservation_group_id', $reservation->reservation_group_id)
                    : $query->whereKey($reservation->id);
            })
            ->where(function ($query) {
                $query->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
                    ->orWhere(fn ($pending) => $pending
                        ->where('status', ReservationStatus::Pending)
                        ->where('hold_expires_at', '>', now()));
            })
            ->get();
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

    /**
     * La fianza de ESTA reserva ya calculada: cuántas habitaciones, cuánto
     * cada una (con el escalón que le toque) y el total. Con solo el
     * genérico "$1,500 por habitación" el modelo tenía que hacer la cuenta
     * él solo, y a un grupo le decía el monto base (Real de la Sierra: una
     * cabaña $1,500, dos o más $1,000 cada una). Null si el hotel no cobra
     * fianza.
     *
     * @return array<string, mixed>|null
     */
    protected function guaranteeForBooking(int $rooms): ?array
    {
        $policy = app(\App\Services\ReservationPolicy::class);

        if ($policy->guaranteePublic() === null) {
            return null;
        }

        $rooms = max(1, $rooms);
        $perRoom = $policy->guaranteeAmountFor($rooms);
        $total = round($perRoom * $rooms, 2);
        $money = fn (float $amount) => '$'.number_format($amount, 2);

        return [
            'rooms' => $rooms,
            'per_room' => $perRoom,
            'per_room_label' => $money($perRoom),
            'total' => $total,
            'total_label' => $money($total),
            'label' => $rooms === 1
                ? 'Al llegar se cobra un depósito en garantía de '.$money($perRoom).', que se te devuelve al registrar tu salida. No es parte del precio de tu estancia.'
                : 'Por esta reserva ('.$rooms.' habitaciones) al llegar se cobra un depósito en garantía de '.$money($perRoom).' por habitación ('.$money($total).' en total), que se te devuelve al registrar tu salida. No es parte del precio de tu estancia.',
        ];
    }

    /**
     * Nadie reserva el pasado. El modelo, cuando el huésped no dice el año,
     * a veces pone uno que ya pasó ("3 de octubre" → 2025 estando en
     * sep-2026): ese rango sale todo libre porque no hay reservas en el
     * pasado, y el bot llegó a ofrecer "octubre 2025 u octubre 2026" (caso
     * real cabañas 2026-09-10). Aquí la llegada se corre al próximo año en
     * que ese día y mes todavía no pasan; la salida se corre igual si
     * también estaba en el pasado (así se conserva la duración). Devuelve
     * la frase que el bot debe obedecer, o null si no hubo nada que mover.
     */
    protected function forwardPastDates(Request $request): ?string
    {
        $rawStart = $request->input('starts_at');

        if (! is_string($rawStart) || trim($rawStart) === '') {
            return null;
        }

        try {
            $start = Carbon::parse($rawStart);
        } catch (\Throwable) {
            return null; // la validación explica el formato
        }

        $today = now()->startOfDay();

        if ($start->copy()->startOfDay()->gte($today)) {
            return null;
        }

        $years = max(1, $today->year - $start->year);
        if ($start->copy()->addYearsNoOverflow($years)->startOfDay()->lt($today)) {
            $years++;
        }

        $format = fn (string $raw) => preg_match('/\d{1,2}:\d{2}/', $raw) ? 'Y-m-d H:i' : 'Y-m-d';
        $moved = $start->copy()->addYearsNoOverflow($years);
        $merge = ['starts_at' => $moved->format($format($rawStart))];

        $rawEnd = $request->input('ends_at');
        if (is_string($rawEnd) && trim($rawEnd) !== '') {
            try {
                $end = Carbon::parse($rawEnd);
                if ($end->copy()->startOfDay()->lt($today)) {
                    $merge['ends_at'] = $end->copy()->addYearsNoOverflow($years)->format($format($rawEnd));
                }
            } catch (\Throwable) {
                // la validación lo rechaza
            }
        }

        $request->merge($merge);

        return "La fecha que mandaste ({$start->toDateString()}) ya pasó y NO se puede cotizar ni apartar en el pasado: se usó el {$this->dateLabel($moved)}. "
            .'Habla SOLO de esta fecha y de este año; nunca menciones ni ofrezcas años anteriores ni pongas al huésped a escoger entre años.';
    }

    /** Fecha en español para que el bot la copie sin traducirla mal. */
    protected function dateLabel(Carbon $date): string
    {
        return $date->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');
    }

    /**
     * get_reservation: estado de una reserva por su código (RES-AAAA-XXXX)
     * o de un grupo completo por el suyo (GRP-AAAA-XXXX).
     *
     * Los folios de grupo son los que el bot REPARTE cuando aparta varias
     * habitaciones, así que son los que el huésped tiene anotados y los que
     * teclea de vuelta. Buscar solo en `reservations.code` devolvía 404 y el
     * bot lo decía como "su reserva no aparece registrada" — se lo dijo a
     * una señora que acababa de transferir $6,750 y de mandar su comprobante
     * (cabañas 2026-09-14, GRP-2026-0149). El hotel le devolvió el dinero.
     */
    public function showReservation(string $code): JsonResponse
    {
        $code = strtoupper(trim($code));

        if (str_starts_with($code, 'GRP-')) {
            return $this->showGroup($code);
        }

        $reservation = Reservation::query()
            ->with(['room:id,number', 'ratePlan:id,name'])
            ->where('code', $code)
            ->first();

        if (! $reservation) {
            return response()->json([
                'message' => 'No encontramos ninguna reserva ni grupo con ese código. '
                    .'OJO: esto NO significa que el huésped no tenga reserva — puede haberlo tecleado mal o venir de otro lado. '
                    .'Si dice que ya pagó o que ya mandó comprobante, NUNCA le digas que su reserva no existe ni que no está registrada: usa transferir_a_humano para que el personal lo revise.',
            ], 404);
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
     * El grupo completo bajo su folio GRP-: cuántas habitaciones, qué total,
     * cuánto se ha pagado y hasta cuándo se sostiene. Un grupo es TODO O
     * NADA, así que se responde como una sola cosa y no como N reservas.
     */
    protected function showGroup(string $code): JsonResponse
    {
        $group = \App\Models\ReservationGroup::query()
            ->with(['reservations.room:id,number', 'reservations.roomType:id,name'])
            ->where('code', $code)
            ->first();

        if (! $group) {
            return response()->json([
                'message' => 'No encontramos ningún grupo con ese folio. Si el huésped dice que ya pagó o que ya mandó comprobante, '
                    .'NUNCA le digas que su reserva no existe: usa transferir_a_humano.',
            ], 404);
        }

        $reservations = $group->reservations;
        $vivas = $reservations->whereIn('status', [
            ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn,
        ]);
        $total = $group->totalAmount();
        $pagado = round((float) \App\Models\Payment::query()
            ->whereIn('reservation_id', $reservations->pluck('id'))
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', \App\Models\Payment::KIND_GUARANTEE))
            ->sum('amount'), 2);
        $primera = $reservations->sortBy('starts_at')->first();
        // El grupo se sostiene mientras siga vivo el apartado más largo: es
        // todo o nada, no vence habitación por habitación.
        $hold = $vivas->filter(fn (Reservation $r) => $r->hold_expires_at !== null)->max('hold_expires_at');

        return response()->json([
            'kind' => 'group',
            'code' => $group->displayCode(),
            'rooms_count' => $reservations->count(),
            'rooms_alive' => $vivas->count(),
            'status' => $vivas->isNotEmpty()
                ? ($vivas->contains(fn (Reservation $r) => $r->status === ReservationStatus::Pending) ? 'pending' : 'confirmed')
                : 'cancelled',
            'status_label' => $vivas->isEmpty()
                ? 'El grupo ya no está vigente'
                : ($vivas->contains(fn (Reservation $r) => $r->status === ReservationStatus::Pending)
                    ? 'Apartado, esperando que el hotel confirme el pago'
                    : 'Confirmado'),
            'guest_first_name' => str($group->guest_name ?? '')->before(' ')->toString() ?: null,
            'rooms' => $reservations->map(fn (Reservation $r) => [
                'code' => $r->displayCode(),
                'room' => $r->room?->number,
                'room_type' => $r->roomType?->name,
                'status_label' => $r->status->label(),
            ])->values(),
            'starts_at' => $primera?->starts_at?->toIso8601String(),
            'ends_at' => $primera?->ends_at?->toIso8601String(),
            'total' => $total,
            'total_label' => '$'.number_format($total, 2),
            'paid_label' => '$'.number_format($pagado, 2),
            'pending_amount' => round(max(0, $total - $pagado), 2),
            'pending_label' => '$'.number_format(max(0, $total - $pagado), 2),
            'hold_expires_at' => $hold?->toIso8601String(),
            'instructions' => $vivas->isEmpty()
                ? 'El grupo ya no está vigente. Si el huésped dice que pagó o mandó comprobante, NO le digas que no existe ni que venció: usa transferir_a_humano para que el personal revise su depósito.'
                : 'El grupo SIGUE VIGENTE con '.$vivas->count().' habitación(es). Díselo con su folio; nunca le digas que no hay disponibilidad para esas fechas: esas habitaciones ya son suyas.',
        ]);
    }

    /**
     * reopen_hold: reactiva con el MISMO código un apartado que venció sin
     * pago (regla del hotel de cabañas, 2026-09-11: "si se tardó en depositar
     * y se venció, volver a reservar y darle su código").
     *
     * Solo apartados vencidos por plazo y solo el de ESTA conversación (o de
     * su mismo huésped): el código se reenvía y se dice en voz alta. Lo que
     * canceló el hotel o un "no llegó" lo reabre el personal desde el panel.
     */
    public function reopenHold(Request $request, \App\Actions\Reservations\TransitionReservation $action): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        $code = strtoupper(trim($data['code']));
        $conversation = ! empty($data['conversation_id'])
            ? \App\Models\Conversation::query()->find($data['conversation_id'])
            : null;

        // Folio de grupo: el huésped teclea el que le dieron, y el que le
        // dieron fue el GRP-. Reactivar solo una de sus cuatro cabañas sería
        // peor que no reactivar ninguna.
        if (str_starts_with($code, 'GRP-')) {
            return $this->reopenGroupHold($code, $conversation, $request->user(), $action);
        }

        $reservation = Reservation::query()->where('code', $code)->first();

        if (! $reservation) {
            return response()->json([
                'message' => 'No encontramos ninguna reserva ni grupo con ese código. Si el huésped dice que ya pagó o mandó comprobante, '
                    .'NUNCA le digas que su reserva no existe: usa transferir_a_humano.',
            ], 404);
        }

        $sameGuest = $reservation->guest_id !== null && $conversation?->guest_id === $reservation->guest_id;

        if ($conversation && $conversation->reservation_id !== $reservation->id && ! $sameGuest) {
            return response()->json(['message' => 'Ese código no corresponde a esta conversación. Pide al huésped que lo verifique o usa transferir_a_humano.'], 403);
        }

        if (in_array($reservation->status, [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)) {
            return response()->json(['message' => "La reserva sigue vigente ({$reservation->status->label()}): no hace falta reactivarla. Revisa su estado con consultar_reserva."], 422);
        }

        if (! $reservation->isExpiredHold()) {
            return response()->json(['message' => 'Esta reserva no venció por plazo: la canceló el hotel o se registró que no llegó. Solo el personal puede reabrirla; usa transferir_a_humano.'], 422);
        }

        // Si ya mandó su comprobante por este chat, el apartado revivido se
        // sostiene lo que dura la verificación, no los minutos de un
        // apartado nuevo (si no, vuelve a vencer antes de que lo revisen).
        $policy = app(\App\Services\ReservationPolicy::class);
        $proofSent = $conversation !== null && $conversation->messages()
            ->where('direction', 'in')
            ->where('created_at', '>=', $reservation->created_at)
            ->whereHas('media')
            ->exists();

        try {
            $reservation = $action->reopen($reservation, $request->user(), [
                'hold_minutes' => $proofSent ? $policy->proofReviewMinutes() : $policy->holdMinutes(),
            ]);
        } catch (NoAvailabilityException $e) {
            return response()->json(['message' => 'La habitación de ese apartado ya no está libre: '.$e->getMessage().' Ofrece otras fechas u otra habitación con consultar_disponibilidad.'], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $reservation->load(['room:id,number', 'ratePlan']);

        return response()->json([
            'code' => $reservation->displayCode(),
            'status' => $reservation->status->value,
            'room' => $reservation->room?->number,
            'starts_at' => $reservation->starts_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'total_label' => '$'.number_format((float) $reservation->total_amount, 2),
            'deposit_label' => '$'.number_format((float) $reservation->deposit_amount, 2),
            'requires_prepayment' => (bool) $reservation->ratePlan?->requiresPrepayment(),
            'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
            'proof_received' => $proofSent,
            'payment_options' => $this->paymentOptionsSummary(),
            'message' => $proofSent
                ? 'Apartado reactivado con el mismo código y sostenido mientras el hotel verifica el comprobante que ya mandó. Dale su código y dile eso; NO digas que el pago fue recibido ni le pidas que pague otra vez.'
                : 'Apartado reactivado con el mismo código. Dale su código y hasta cuándo queda apartado (hold_expires_at); si requiere prepago, ofrécele el pago con solicitar_pago.',
        ]);
    }

    /**
     * Reactiva un GRUPO vencido con su mismo folio: todo o nada, igual que
     * cuando se creó. Si alguna habitación ya la ganó otro huésped, no se
     * revive media reserva — se le dice al personal.
     */
    protected function reopenGroupHold(
        string $code,
        ?\App\Models\Conversation $conversation,
        ?\App\Models\User $user,
        \App\Actions\Reservations\TransitionReservation $action,
    ): JsonResponse {
        $group = \App\Models\ReservationGroup::query()->with('reservations')->where('code', $code)->first();

        if (! $group) {
            return response()->json([
                'message' => 'No encontramos ningún grupo con ese folio. Si el huésped dice que ya pagó, usa transferir_a_humano en vez de decirle que no existe.',
            ], 404);
        }

        $vivas = $group->reservations->whereIn('status', [
            ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn,
        ]);

        if ($vivas->isNotEmpty()) {
            return response()->json([
                'message' => "El grupo {$group->displayCode()} sigue vigente con {$vivas->count()} habitación(es): no hace falta reactivarlo. Revísalo con consultar_reserva y díselo al huésped.",
            ], 422);
        }

        $vencidas = $group->reservations->filter(fn (Reservation $r) => $r->isExpiredHold());

        if ($vencidas->isEmpty()) {
            return response()->json([
                'message' => 'Ese grupo no venció por plazo: lo canceló el hotel o se registró que no llegó. Solo el personal puede reabrirlo; usa transferir_a_humano.',
            ], 422);
        }

        $policy = app(\App\Services\ReservationPolicy::class);
        $proofSent = $conversation !== null && $conversation->messages()
            ->where('direction', 'in')
            ->where('created_at', '>=', $vencidas->min('created_at'))
            ->whereHas('media')
            ->exists();

        $minutes = $proofSent ? $policy->proofReviewMinutes() : $policy->holdMinutes();
        $reabiertas = [];

        foreach ($vencidas as $hold) {
            try {
                $reabiertas[] = $action->reopen($hold, $user, ['hold_minutes' => $minutes]);
            } catch (NoAvailabilityException|\InvalidArgumentException $e) {
                // Todo o nada: lo que ya se revivió se deja como está y lo
                // resuelve una persona, pero NO se le dice al huésped que
                // "no existe" ni que no hay nada.
                return response()->json([
                    'message' => "Una de las habitaciones del grupo {$group->displayCode()} ya no está libre, así que el grupo no se puede reactivar completo. "
                        .'NO le digas que su reserva no existe ni que perdió su dinero: usa transferir_a_humano para que el personal lo resuelva.',
                ], 422);
            }
        }

        $primera = collect($reabiertas)->sortBy('starts_at')->first();

        return response()->json([
            'kind' => 'group',
            'code' => $group->displayCode(),
            'rooms_count' => count($reabiertas),
            'status' => ReservationStatus::Pending->value,
            'starts_at' => $primera?->starts_at?->toIso8601String(),
            'ends_at' => $primera?->ends_at?->toIso8601String(),
            'total_label' => '$'.number_format($group->totalAmount(), 2),
            'hold_expires_at' => $primera?->hold_expires_at?->toIso8601String(),
            'proof_received' => $proofSent,
            'message' => $proofSent
                ? 'Grupo reactivado completo con el MISMO folio y sostenido mientras el hotel verifica el comprobante que ya mandó. Dale su folio y dile eso; NO des el pago por recibido ni le pidas que pague otra vez.'
                : 'Grupo reactivado completo con el MISMO folio. Dale el folio y hasta cuándo queda apartado.',
        ]);
    }

    /**
     * validate_coupon: el huésped trae un código de descuento. Mismas reglas
     * que el wizard (BookingCouponController) y que el apartado
     * (CreateReservation): vigencia, usos, noches mínimas, tipo de cabaña,
     * cliente frecuente y cumpleaños. El descuento se calcula aquí sobre la
     * tarifa real; el apartado lo vuelve a validar y lo congela.
     */
    public function checkCoupon(Request $request): JsonResponse
    {
        $dateNotice = $request->filled('starts_at') ? $this->forwardPastDates($request) : null;

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'rate_plan_id' => ['nullable', 'exists:rate_plans,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        if (! $this->couponsAllowed()) {
            return response()->json(['message' => 'Este hotel no maneja cupones de descuento. No ofrezcas ningún descuento.'], 422);
        }

        $coupon = $this->findCoupon($data['code']);

        if (! $coupon || ! $coupon->isRedeemable()) {
            return response()->json(['message' => 'Ese código no es válido o ya no está disponible. Díselo tal cual y no ofrezcas otro descuento.'], 422);
        }

        $plan = ! empty($data['rate_plan_id']) ? RatePlan::query()->with('roomType:id,name')->find($data['rate_plan_id']) : null;
        $start = ! empty($data['starts_at']) ? Carbon::parse($data['starts_at']) : null;
        $end = ! empty($data['ends_at'])
            ? Carbon::parse($data['ends_at'])
            : ($plan && $start ? $plan->suggestedEnd($start) : null);

        if ($plan && $start && $end) {
            [$start, $end] = $this->normalizeNightTimes($plan, $start, $end);
        }

        $nights = $start && $end
            ? max(1, (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()))
            : null;

        // Cliente frecuente y cumpleaños se revisan contra la ficha del
        // huésped de ESTA conversación.
        $guest = ! empty($data['conversation_id'])
            ? \App\Models\Conversation::query()->find($data['conversation_id'])?->guest
            : null;

        $reason = $coupon->rejectionReason($guest, $start, $nights, $plan?->room_type_id, $end);

        if ($reason !== null) {
            return response()->json(['message' => $reason.' Díselo tal cual; si quiere, se puede apartar sin el cupón.'], 422);
        }

        $payload = [
            'code' => $coupon->code,
            'valid' => true,
            'discount_label' => $coupon->kindLabel(),
            'message' => 'Cupón válido. Al apartar, pásalo en crear_apartado con cupon = '.$coupon->code.': el descuento queda aplicado en el apartado.',
        ];

        if ($plan && $start && $end) {
            $subtotal = $plan->priceFor($start, $end);
            $discount = $coupon->discountFor($subtotal);

            $payload['subtotal_label'] = '$'.number_format($subtotal, 2);
            $payload['discount_amount_label'] = '$'.number_format($discount, 2);
            $payload['total_label'] = '$'.number_format($subtotal - $discount, 2);
            $payload['quote_notice'] = [
                ($plan->roomType?->name ?? 'La habitación').' con el cupón '.$coupon->code.' ('.$coupon->kindLabel().' de descuento): $'.number_format($subtotal, 2).' menos $'.number_format($discount, 2).', total $'.number_format($subtotal - $discount, 2).'.',
            ];
        }

        return response()->json($payload + ['date_notice' => $dateNotice]);
    }

    /** Todo lo que el huésped ha escrito en la conversación, en un texto. */
    protected function guestSaid(\App\Models\Conversation $conversation): string
    {
        return $conversation->messages()
            ->where('direction', 'in')
            ->latest('id')
            ->limit(200)
            ->pluck('body')
            ->implode(' ');
    }

    /** Contrato / aviso legal del hotel, si lo tiene configurado. */
    protected function legalNoticeUrl(): ?string
    {
        $settings = Property::firstOrFail()->settings ?? [];
        $url = trim((string) ($settings['legal_notice_url'] ?? ''));

        return $url !== '' ? $url : null;
    }

    /** ¿El bot debe ver la herramienta de cupones? Módulo y algún cupón activo. */
    public function couponsPublic(): bool
    {
        return $this->couponsAllowed() && \App\Models\Coupon::query()->where('active', true)->exists();
    }

    /**
     * El número real del huésped cuando la conversación es de WhatsApp. En
     * Messenger, Instagram, Telegram, TikTok y webchat contact_phone guarda
     * el id del hilo, no un teléfono: ahí no se inventa nada.
     */
    protected function conversationPhone(?\App\Models\Conversation $conversation): ?string
    {
        if ($conversation === null || ! $conversation->phoneIsIdentity()) {
            return null;
        }

        $phone = trim((string) $conversation->contact_phone);

        return strlen(preg_replace('/\D+/', '', $phone)) >= 10 ? $phone : null;
    }

    /**
     * Apuntar al huésped en la lista de espera cuando no hay lugar.
     *
     * En 2.5 días de septiembre, más de 40 conversaciones de cabañas
     * chocaron con "no hay disponibilidad" para el 18-19 y el 25-26 y ahí se
     * acabaron: el módulo existía, la pantalla existía, pero el bot no tenía
     * con qué apuntarlos y esa gente se fue sin dejar rastro.
     */
    public function joinWaitlist(Request $request): JsonResponse
    {
        if (! $this->waitlistPublic()) {
            return response()->json(['error' => 'Este hotel no maneja lista de espera.'], 422);
        }

        $conversation = \App\Models\Conversation::find($request->input('conversation_id'));

        // El teléfono del chat manda sobre lo que el huésped teclee: es el
        // que de verdad recibe el aviso.
        $phone = $this->conversationPhone($conversation) ?: trim((string) $request->input('guest_phone'));

        $request->merge(['guest_phone' => $phone ?: null]);

        $data = $request->validate([
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'starts_at' => ['required', 'date', 'after_or_equal:today'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'room_type_id' => ['nullable', 'integer', 'exists:room_types,id'],
        ]);

        if (blank($data['guest_phone'] ?? null) && blank($data['guest_email'] ?? null)) {
            return response()->json([
                'error' => 'Necesito un teléfono o un correo para poder avisarle. Pídeselo.',
            ], 422);
        }

        // Mismo contacto y mismas fechas no se apunta dos veces (el huésped
        // insiste, el modelo reintenta).
        $existing = \App\Models\WaitlistEntry::query()
            ->waiting()
            ->whereDate('starts_at', $data['starts_at'])
            ->whereDate('ends_at', $data['ends_at'])
            ->where(fn ($q) => $q
                ->when($data['guest_phone'] ?? null, fn ($qq, $value) => $qq->orWhere('guest_phone', $value))
                ->when($data['guest_email'] ?? null, fn ($qq, $value) => $qq->orWhere('guest_email', $value)))
            ->first();

        $entry = $existing ?? \App\Models\WaitlistEntry::create([
            ...$data,
            'conversation_id' => $conversation?->id,
            'status' => \App\Models\WaitlistEntry::STATUS_WAITING,
        ]);

        $roomType = $entry->room_type_id ? RoomType::find($entry->room_type_id)?->name : null;

        return response()->json([
            'ok' => true,
            'id' => $entry->id,
            'ya_estaba' => $existing !== null,
            'habitacion' => $roomType,
            'message' => 'Quedó apuntado en la lista de espera para esas fechas'
                .($roomType ? " ({$roomType})" : '')
                .'. Si se libera lugar, el hotel le avisa por este medio. No promete lugar: es un aviso si se desocupa.',
        ]);
    }

    /** ¿El hotel tiene el módulo de lista de espera encendido? */
    public function waitlistPublic(): bool
    {
        $tenant = tenant();

        return ! $tenant instanceof \App\Models\Tenant || $tenant->hasModule('lista-espera');
    }

    protected function couponsAllowed(): bool
    {
        return app(\App\Services\CouponService::class)->enabled();
    }

    /**
     * Llave de comparación de un código de cupón: sin NINGÚN tipo de
     * espacio (también el no separable que se cuela al copiar desde el
     * celular o una hoja de cálculo) y en mayúsculas.
     */
    protected function couponKey(?string $code): string
    {
        return \App\Models\Coupon::keyOf($code);
    }

    /**
     * El cupón tal cual está guardado aunque el huésped lo escriba sin
     * espacios, con espacios de más o en minúsculas: "verano25" encuentra
     * "VERANO 25" (el código real de cabañas lleva un espacio).
     */
    protected function findCoupon(?string $code): ?\App\Models\Coupon
    {
        return app(\App\Services\CouponService::class)->find($code);
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

        // Horario de transferencias del hotel (cabañas: 9 a 5). Fuera de él
        // la transferencia ni se ofrece: solo el pago en línea.
        $policy = app(\App\Services\ReservationPolicy::class);
        $transferOpen = $policy->transferOpenNow();

        return array_filter([
            'pasarelas' => $gateways,
            'transferencia' => $hasAccounts && $transferOpen,
            'transferencia_nota' => $hasAccounts && ! $transferOpen
                ? 'Las transferencias solo se reciben '.$policy->transferHoursLabel().'. Ahora NO ofrezcas transferencia: solo el pago en línea (pasarela).'
                : null,
            'efectivo' => $policy->cashPaymentEnabled(),
        ], fn ($value) => $value !== null);
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
        $accounts = (! $enabled['transfer'] || ! app(\App\Services\ReservationPolicy::class)->transferOpenNow()) ? collect() : collect($settings['bank_accounts'] ?? [])
            ->filter(fn (array $account) => ! empty($account['active']))
            ->map(fn (array $account) => [
                'banco' => $account['bank'] ?? '',
                'titular' => $account['holder'] ?? '',
                'cuenta' => $account['clabe'] ?? '',
                // Qué ES el número (tarjeta, CLABE o cuenta) y el bloque tal
                // cual se le pega al huésped: el modelo lo copia, no lo redacta.
                'tipo' => \App\Support\BankAccountNumber::label($account['clabe'] ?? ''),
                'bloque' => implode("\n", \App\Support\BankAccountNumber::blockLines($account)),
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

        // Horario de transferencias del hotel: fuera de él solo en línea.
        if ($metodo === 'transferencia' && ! app(\App\Services\ReservationPolicy::class)->transferOpenNow()) {
            // El apartado NO se sostiene hasta que reabra el horario (decisión
            // del hotel de cabañas, 2026-09-13): el bot no puede ofrecer "te
            // paso los datos mañana". Caso real RES-2026-1727 — vencía a las
            // 8:50 PM y el bot le dijo al huésped que esperara a mañana.
            $notice = app(\App\Services\ReservationPolicy::class)->holdDeadlineNotice($reservation);

            return response()->json([
                'message' => 'Las transferencias solo se reciben '.app(\App\Services\ReservationPolicy::class)->transferHoursLabel().'. Ahora solo se puede pagar en línea: ofrece el link de pago (metodo pasarela).'
                    .($notice === null ? '' : ' NO le prometas que puede transferir mañana ni que le pasas los datos después: su apartado no se sostiene hasta entonces. Dile esto tal cual: '.$notice),
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
                    // Emitir el cobro estira el apartado sobre otra copia
                    // bloqueada: sin refresh saldría la hora de antes.
                    'hold_deadline_notice' => app(\App\Services\ReservationPolicy::class)->holdDeadlineNotice($reservation->refresh()),
                    'instructions' => 'Comparte el link tal cual: el huésped paga en la página segura del proveedor y la confirmación llega sola al sistema. NUNCA afirmes que el pago fue recibido; el sistema avisará. No pidas datos de tarjeta por el chat. Dile hasta cuándo queda apartada copiando hold_deadline_notice tal cual, y nunca le prometas pagar después de esa hora.',
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
            // Hacia arriba: con una ventana de 60 min, medida milisegundos
            // después, el truncado daba valid_hours 0 (cabañas 2026-09-13).
            'valid_hours' => (int) ceil(now()->diffInMinutes($paymentRequest->expires_at ?? now()) / 60),
            'bank_accounts' => $accounts,
            'hold_deadline_notice' => app(\App\Services\ReservationPolicy::class)->holdDeadlineNotice($reservation->refresh()),
            'instructions' => 'Pide al huésped que realice la transferencia por el monto exacto y envíe por este chat su comprobante (foto o captura). El equipo del hotel lo verificará; NUNCA afirmes que el pago fue recibido. Dile hasta cuándo queda apartada copiando hold_deadline_notice tal cual, y nunca le prometas pagar después de esa hora.',
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
        $accounts = (! $enabled['transfer'] || ! app(\App\Services\ReservationPolicy::class)->transferOpenNow()) ? collect() : collect($settings['bank_accounts'] ?? [])
            ->filter(fn (array $account) => ! empty($account['active']))
            ->map(fn (array $account) => [
                'banco' => $account['bank'] ?? '',
                'titular' => $account['holder'] ?? '',
                'cuenta' => $account['clabe'] ?? '',
                'tipo' => \App\Support\BankAccountNumber::label($account['clabe'] ?? ''),
                'bloque' => implode("\n", \App\Support\BankAccountNumber::blockLines($account)),
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

        $dateNotice = $this->forwardPastDates($request);

        $data = $request->validate([
            'rate_plan_id' => ['required', 'exists:rate_plans,id'],
            'starts_at' => ['required', 'date', 'after_or_equal:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            // El correo ya lo guardaba CreateReservation (ficha del huésped y
            // envío de la confirmación), pero el asistente no tenía por dónde
            // pasarlo: lo pedía en el chat y se perdía.
            'guest_email' => ['nullable', 'email', 'max:255'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
            // Cupón que trajo el huésped (módulo cupones). CreateReservation
            // lo revalida y congela el descuento en el apartado.
            'coupon_code' => ['nullable', 'string', 'max:40'],
            // Cómo eligió pagar el huésped: el hotel puede exigirlo ANTES
            // de apartar (ajuste agent_require_payment_choice).
            'metodo_pago' => ['nullable', 'string', Rule::in(['pasarela', 'transferencia', 'efectivo'])],
        ]);

        // El código como está guardado aunque el huésped lo escriba sin
        // espacios o en minúsculas ("verano25" → "VERANO 25").
        if (! empty($data['coupon_code'])) {
            $data['coupon_code'] = $this->findCoupon($data['coupon_code'])?->code ?? $data['coupon_code'];
        }

        // Mismas horas normalizadas que ofreció get_availability: lo
        // cotizado es lo que se aparta.
        $holdPlan = RatePlan::findOrFail($data['rate_plan_id']);
        $holdStart = Carbon::parse($data['starts_at']);
        $holdEnd = ! empty($data['ends_at']) ? Carbon::parse($data['ends_at']) : $holdPlan->suggestedEnd($holdStart);
        [$holdStart, $holdEnd] = $this->normalizeNightTimes($holdPlan, $holdStart, $holdEnd);

        // Un apartado por cabaña y fechas en cada conversación. Caso real
        // cabañas 2026-09-11 (Marcus Fenix): al cambiar de método de pago el
        // bot volvía a llamar crear_apartado, su PROPIO apartado le ganaba la
        // cabaña y le decía al huésped "ya no está disponible"; terminó con
        // dos apartados y dos respuestas falsas. Si ya lo tiene, es el mismo.
        $conversation = $request->filled('conversation_id')
            ? \App\Models\Conversation::query()->find($request->integer('conversation_id'))
            : null;
        $previous = $conversation?->reservation;
        $previousLive = $previous !== null
            && $previous->status === ReservationStatus::Pending
            && $previous->hold_expires_at?->isFuture()
            && $previous->reservation_group_id === null;

        if ($previousLive
            && $previous->room_type_id === $holdPlan->room_type_id
            && $previous->starts_at->equalTo($holdStart)
            && $previous->ends_at->equalTo($holdEnd)) {
            $previous->loadMissing(['room:id,number', 'roomType:id,name', 'ratePlan']);

            return response()->json([
                'code' => $previous->displayCode(),
                'status' => ReservationStatus::Pending->value,
                'already_held' => true,
                'room' => $previous->room?->number,
                'room_type' => $previous->roomType?->name,
                'starts_at' => $previous->starts_at->toIso8601String(),
                'ends_at' => $previous->ends_at->toIso8601String(),
                'total' => (float) $previous->total_amount,
                'total_label' => '$'.number_format((float) $previous->total_amount, 2),
                'deposit_label' => '$'.number_format((float) $previous->deposit_amount, 2),
                'requires_prepayment' => (bool) $previous->ratePlan?->requiresPrepayment(),
                'hold_expires_at' => $previous->hold_expires_at?->toIso8601String(),
                'payment_options' => $this->paymentOptionsSummary(),
                'message' => 'El huésped YA tiene este apartado (mismo código): no se creó otro y la cabaña sigue apartada para él. Para cobrarlo o cambiar la forma de pago llama solicitar_pago con este código. No le digas que no hay disponibilidad.',
            ]);
        }

        // Antes de apartar, el hotel puede exigir dos cosas (ajustes del
        // tenant). Caso real cabañas 2026-09-11: el bot apartaba la cabaña
        // en cuanto el huésped daba su nombre, sin correo, sin el aviso
        // legal y sin que dijera cómo iba a pagar.
        $settings = Property::firstOrFail()->settings ?? [];
        $legalUrl = $this->legalNoticeUrl();
        $legalLine = $legalUrl !== null
            ? ' Comparte el aviso legal (el contrato) '.$legalUrl.' con esta frase: "Es importante que lea y confirme el contrato; confírmeme de leído, por favor".'
            : '';

        if (! empty($settings['agent_require_email']) && empty($data['guest_email'])) {
            return response()->json([
                'message' => 'Este hotel pide el CORREO del huésped antes de apartar. Pídeselo junto con su nombre completo.'.$legalLine.' Con eso, vuelve a llamar crear_apartado.',
            ], 422);
        }

        $paymentChoice = $request->string('metodo_pago')->toString();
        $paymentOptions = $this->paymentOptionsSummary();
        $hasPaymentOptions = $paymentOptions['pasarelas'] !== [] || ($paymentOptions['transferencia'] ?? false) || ($paymentOptions['efectivo'] ?? false);

        if (! empty($settings['agent_require_payment_choice'])
            && $hasPaymentOptions
            && $holdPlan->requiresPrepayment()
            && ! in_array($paymentChoice, ['pasarela', 'transferencia', 'efectivo'], true)) {
            return response()->json([
                'message' => 'Todavía NO se aparta la cabaña: primero pregúntale cómo va a pagar el anticipo, con las opciones reales (payment_options).'.$legalLine.' Cuando elija, vuelve a llamar crear_apartado con metodo_pago = pasarela, transferencia o efectivo; ahí sí se aparta.',
                'payment_options' => $paymentOptions,
                'legal_notice_url' => $legalUrl,
            ], 422);
        }

        // El teléfono del huésped es el WhatsApp desde el que escribe: el bot
        // casi nunca lo pasa (ya está hablando con él) y la ficha quedaba sin
        // número. Sin él, la consulta pública /reserva no lo encuentra aunque
        // el aviso de confirmación le pide entrar "con el teléfono con el que
        // reservaste" (caso real cabañas 2026-09-13, RES-2026-1724: las 8
        // reservas vivas sin teléfono eran todas del bot).
        $data['guest_phone'] = ($data['guest_phone'] ?? null) ?: $this->conversationPhone($conversation);

        // El cupón de una colaboración se aplica porque el huésped lo
        // mencionó en el chat, aunque el bot olvide pasarlo (caso real
        // cabañas 2026-09-11, "vengo del video del pache pache").
        $mentionedCoupon = null;

        if (empty($data['coupon_code']) && $conversation !== null) {
            $mentionedCoupon = \App\Models\Coupon::mentionedIn($this->guestSaid($conversation));

            if ($mentionedCoupon !== null) {
                $data['coupon_code'] = $mentionedCoupon->code;
            }
        }

        $couponNote = null;

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
        } catch (\InvalidArgumentException $e) {
            // El cupón que pidió el bot no aplica: el motivo exacto.
            if ($mentionedCoupon === null) {
                return response()->json([
                    'message' => $e->getMessage().' Díselo tal cual; si quiere, se puede apartar sin el cupón.',
                ], 422);
            }

            // El que solo MENCIONÓ en el chat no puede costarle el apartado:
            // se aparta sin él y el bot le explica por qué no se aplicó.
            $couponNote = 'El cupón '.$mentionedCoupon->code.' no se aplicó: '.$e->getMessage().' Díselo tal cual.';
            unset($data['coupon_code']);

            try {
                $reservation = $action->handle([
                    ...$data,
                    'starts_at' => $holdStart,
                    'ends_at' => $holdEnd,
                    'confirmed' => false,
                    'source_channel' => 'agent',
                    'notes' => $data['notes'] ?? 'Creada por asistente IA',
                ], $request->user());
            } catch (NoAvailabilityException|\InvalidArgumentException $retry) {
                return response()->json(['message' => $retry->getMessage()], 422);
            }
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
            // Con cuántas personas quedó: el total ya trae la persona extra.
            'people' => $reservation->num_people,
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
            'guarantee_for_this_booking' => $this->guaranteeForBooking(1),
            // Los métodos REALES del hotel: el bot pregunta "¿cómo prefieres
            // pagar?" solo con opciones que existen, y llama solicitar_pago
            // con la elección (metodo/proveedor).
            'payment_options' => $this->paymentOptionsSummary(),
            // Hasta cuándo hay que liquidar y a dónde llamar: al confirmar es
            // cuando de verdad importa que el huésped lo sepa.
            'payment_notice' => $this->paymentNoticeLines($reservation->ratePlan, $reservation->starts_at, (float) $reservation->total_amount),
            'message' => $this->holdMessage($requiresPrepayment),
            'date_notice' => $dateNotice,
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

        // Cambió de cabaña para la MISMA estancia: se libera el apartado
        // anterior. Con reservas de grupo disponibles, dos cabañas a la vez
        // van por crear_apartado_grupo, así que dos apartados sueltos
        // encimados en la misma conversación son un cambio de opinión.
        if ($previousLive
            && $previous->id !== $reservation->id
            && $previous->starts_at < $reservation->ends_at
            && $previous->ends_at > $reservation->starts_at) {
            if ($this->groupsAllowed()) {
                try {
                    app(\App\Actions\Reservations\TransitionReservation::class)->cancel(
                        $previous,
                        $request->user(),
                        ReservationStatus::Cancelled,
                        'Reemplazado por '.$reservation->displayCode().': el huésped cambió de cabaña',
                    );
                    $payload['replaced_hold'] = $previous->displayCode();
                    $payload['message'] .= ' Se liberó su apartado anterior '.$previous->displayCode().' ('.($previous->roomType?->name ?? 'otra cabaña').'): su apartado ahora es este código.';
                } catch (\Throwable $e) {
                    report($e);
                }
            } else {
                $payload['message'] .= ' Ojo: esta conversación tiene además el apartado '.$previous->displayCode().' ('.($previous->roomType?->name ?? 'otra cabaña').', mismas fechas). Si el huésped cambió de cabaña, avisa al personal para liberarlo.';
            }
        }

        if ($reservation->coupon_code !== null) {
            $payload['coupon_applied'] = $reservation->coupon_code;
            $payload['message'] .= ' Se aplicó el cupón '.$reservation->coupon_code.': el total ya trae el descuento (-$'.number_format((float) $reservation->discount_amount, 2).'). Díselo.';
        } elseif ($couponNote !== null) {
            $payload['message'] .= ' '.$couponNote;
        }

        if ($legalUrl !== null) {
            $payload['legal_notice_url'] = $legalUrl;
            $payload['legal_notice'] = 'Manda el aviso legal (contrato) '.$legalUrl.' y pídele que lo lea y te confirme de leído, con esta frase: "Es importante que lea y confirme el contrato; confírmeme de leído, por favor".';
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

        $dateNotice = $this->forwardPastDates($request);

        $data = $request->validate([
            'starts_at' => ['required', 'date', 'after_or_equal:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'lines' => ['required', 'array', 'min:1', 'max:10'],
            'lines.*.room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'lines.*.rooms' => ['required', 'integer', 'min:1', 'max:30'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:300'],
        ]);

        // Cuántas personas van en cada cabaña. Sin esto el grupo nacía con 1
        // persona por habitación y nunca cobraba la persona extra: a Kevin
        // se le cotizaron $6,250 por 9 personas y el grupo quedó en $6,000
        // (cabañas 2026-09-14, GRP-2026-0152).
        $spread = $this->spreadGuests($data['lines'], isset($data['guests']) ? (int) $data['guests'] : null);

        if (isset($spread['error'])) {
            return response()->json(['message' => $spread['error']], 422);
        }

        $data['lines'] = $spread['lines'];
        unset($data['guests']);

        // Mismo criterio que crear_apartado: sin teléfono explícito, el del
        // WhatsApp de la conversación.
        $data['guest_phone'] = ($data['guest_phone'] ?? null) ?: $this->conversationPhone(
            $request->filled('conversation_id')
                ? \App\Models\Conversation::query()->find($request->integer('conversation_id'))
                : null,
        );

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
            'guests' => (int) $reservations->sum('num_people'),
            'rooms' => $reservations->map(fn (Reservation $reservation) => [
                'code' => $reservation->displayCode(),
                'room_type' => $reservation->roomType?->name,
                'room' => $reservation->room?->number,
                'people' => $reservation->num_people,
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
            'guarantee_for_this_booking' => $this->guaranteeForBooking($reservations->count()),
            'payment_options' => $this->paymentOptionsSummary(),
            'message' => 'Grupo apartado con UN solo folio. Da el código del grupo (no el de cada habitación, salvo que lo pidan) y di cuántas habitaciones quedaron. '
                .'El total ya incluye las personas extra de cada habitación (campo people): da total_label tal cual, no lo recalcules. '
                .($this->paymentMethodsPublic()
                    ? 'El cobro del grupo es UNO consolidado: llama solicitar_pago con este mismo folio GRP-.'
                    : $this->noPaymentMethodsNote()),
            'date_notice' => $dateNotice,
        ];

        if ($this->hasActiveExperiences()) {
            $payload['experiences_hint'] = 'Después del código del grupo, menciona en UNA sola línea que el hotel tiene recorridos por si les interesan. Una vez, sin insistir.';
        }

        return response()->json($payload, 201);
    }

    /**
     * Reparte las personas del grupo entre sus habitaciones como lo haría
     * recepción: primero las incluidas de cada cabaña y después las extra,
     * hasta el máximo de cada una. Devuelve una línea por habitación con sus
     * adultos (CreateReservation cobra la persona extra de cada una), o el
     * error con cuántas caben. Sin personas, cada habitación lleva sus
     * incluidas: nunca más "1 persona" por cabaña.
     *
     * @param  array<int, array{room_type_id: int, rooms: int}>  $lines
     * @return array{lines?: array<int, array{room_type_id: int, rooms: int, adults: int}>, error?: string}
     */
    protected function spreadGuests(array $lines, ?int $guests): array
    {
        $slots = [];

        foreach ($lines as $line) {
            $type = RoomType::query()->with('rooms')->find($line['room_type_id']);

            if (! $type) {
                continue;
            }

            $occupancy = $this->occupancyOf($type);

            for ($i = 0; $i < (int) $line['rooms']; $i++) {
                $slots[] = [
                    'room_type_id' => $type->id,
                    'included' => max(1, $occupancy['included_guests']),
                    'max' => max(1, $occupancy['max_guests'], $occupancy['included_guests']),
                    'adults' => 0,
                ];
            }
        }

        if ($guests === null) {
            $slots = array_map(fn (array $slot) => ['adults' => $slot['included']] + $slot, $slots);
        } else {
            $maxTotal = array_sum(array_column($slots, 'max'));

            if ($guests > $maxTotal) {
                return ['error' => "No caben {$guests} personas en esas habitaciones: caben hasta {$maxTotal} contando personas extra ("
                    .array_sum(array_column($slots, 'included')).' incluidas). Agrega otra habitación (consulta consultar_disponibilidad_general con las personas) o ajusta el grupo con el huésped.'];
            }

            $left = $guests;

            foreach ($slots as $i => $slot) {
                $take = min($slot['included'], $left);
                $slots[$i]['adults'] = $take;
                $left -= $take;
            }

            foreach ($slots as $i => $slot) {
                if ($left <= 0) {
                    break;
                }

                $take = min($slot['max'] - $slot['adults'], $left);
                $slots[$i]['adults'] += $take;
                $left -= $take;
            }
        }

        return ['lines' => array_map(fn (array $slot) => [
            'room_type_id' => $slot['room_type_id'],
            'rooms' => 1,
            'adults' => max(1, $slot['adults']),
        ], $slots)];
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
            ? 'Apartado creado; se confirma al recibir el pago. Ofrece al huésped elegir entre las opciones de payment_options y llama solicitar_pago con el metodo que elija. NO le digas todavía cuánto tiempo tiene para pagar (hold_minutes es solo el apartado previo a elegir método): el plazo real lo devuelve solicitar_pago en hold_deadline_notice.'
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
