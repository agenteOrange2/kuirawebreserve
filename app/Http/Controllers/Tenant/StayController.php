<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Reservations\ChangeStayRoom;
use App\Actions\Reservations\CreateWalkInStay;
use App\Actions\Reservations\ExtendStay;
use App\Actions\Reservations\SettleStay;
use App\Actions\Reservations\TransitionReservation;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Stay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class StayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $stays = Stay::query()
            ->with(['room:id,number', 'ratePlan:id,name,type'])
            ->when(
                $request->string('status')->toString(),
                fn ($q, $status) => $q->where('status', $status),
                fn ($q) => $q->active(),
            )
            ->orderBy('planned_end_at')
            ->get()
            ->map(fn (Stay $stay) => $this->serialize($stay));

        return response()->json($stays);
    }

    /**
     * Formas de cobro que acepta la recepción, opcionalmente acotadas al
     * menú de un flujo (la fianza solo admite efectivo o terminal). Nunca
     * queda vacía para el caso general: ReservationPolicy deja el efectivo.
     *
     * @param  array<int, string>|null  $allowed
     * @return array<int, string>
     */
    private function counterMethods(?array $allowed = null): array
    {
        $methods = app(\App\Services\ReservationPolicy::class)->counterMethods();

        return $allowed === null
            ? $methods
            : array_values(array_intersect($allowed, $methods));
    }

    /**
     * Walk-in: ocupación inmediata (flujo motel / mostrador).
     */
    public function store(Request $request, CreateWalkInStay $action): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'exists:rooms,id'],
            'rate_plan_id' => ['required', 'exists:rate_plans,id'],
            'planned_end_at' => ['nullable', 'date', 'after:now'],
            'guest_id' => ['nullable', 'exists:guests,id'],
            'guest_name' => ['nullable', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'num_people' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'vehicle_desc' => ['nullable', 'string', 'max:100'],
            // Ficha del vehículo (caseta): lo estructurado vive en `vehicles`,
            // no en la estancia — ver App\Services\VehicleRegistry.
            'vehicle_brand' => ['nullable', 'string', 'max:40'],
            'vehicle_model' => ['nullable', 'string', 'max:40'],
            'vehicle_color' => ['nullable', 'string', 'max:30'],
            // Identificación del huésped a pie (registro exprés, spec-modo-
            // motel): tipo del catálogo del CRM + número (se guarda cifrado).
            'id_document_type' => ['nullable', Rule::in(\App\Models\Guest::DOCUMENT_TYPES)],
            'id_document_number' => ['nullable', 'string', 'max:60'],
            // Conceptos de cargos opcionales de la habitación; el monto
            // SIEMPRE se resuelve del catálogo del cuarto, nunca del cliente.
            'extra_charges' => ['sometimes', 'array', 'max:20'],
            'extra_charges.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'string'],
            // Cobro al llegar (walkin_charge=checkin): método presencial del
            // mostrador; el monto SIEMPRE es el de la estancia, nunca del
            // cliente. La lista sale de lo que la recepción acepta de verdad
            // (/ajustes/metodos-pago → Políticas), no de las tres de siempre.
            'payment_method' => ['nullable', \Illuminate\Validation\Rule::in($this->counterMethods())],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            // Caseta de motel: la llegada nace sin sellar y sin cobro, para
            // completarla cuando el encargado regrese con el papel.
            'arrival_pending' => ['sometimes', 'boolean'],
            // En carro o a pie: lo elige la caseta y ya no se vuelve a pedir.
            'arrival_mode' => ['nullable', Rule::in(['vehicle', 'foot'])],
            // Fianza (depósito en garantía): la recibe el mostrador con
            // cualquiera de las formas que el hotel acepta en /ajustes/
            // metodos-pago → Políticas. Antes iba cableada a efectivo o
            // terminal "porque se recibe en la mano", y eso dejaba fuera al
            // hotel que sí cobra depósitos por transferencia. El monto
            // default lo pone el ajuste; ajustarlo exige motivo, y la
            // transferencia exige referencia (ver ChargeGuarantee).
            'guarantee_method' => ['nullable', \Illuminate\Validation\Rule::in($this->counterMethods())],
            'guarantee_amount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'guarantee_reason' => ['nullable', 'string', 'max:255'],
            'guarantee_reference' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $stay = $action->handle($data, $request->user());
        } catch (NoAvailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // La tarifa elegida es de otro tipo de habitación (el select del
            // modal mezcla tipos): sin esto el firstOrFail truena en 404 crudo.
            return response()->json([
                'message' => 'La tarifa elegida no corresponde al tipo de esta habitación; elige una tarifa de su tipo.',
            ], 422);
        }

        return response()->json($this->serialize($stay->load(['room:id,number', 'ratePlan:id,name,type'])), 201);
    }

    /**
     * "Una noche más": mueve la salida prevista y recalcula el hospedaje.
     * La diferencia queda como saldo pendiente en el folio.
     */
    public function extend(Request $request, Stay $stay, ExtendStay $action): JsonResponse
    {
        $data = $request->validate([
            'planned_end_at' => ['required', 'date', 'after:now'],
        ]);

        try {
            $stay = $action->handle($stay, Carbon::parse($data['planned_end_at']), $request->user());
        } catch (NoAvailabilityException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($stay->load(['room:id,number', 'ratePlan:id,name,type'])));
    }

    /**
     * Cambio de habitación con el huésped adentro: la que deja pasa a sucia.
     */
    public function changeRoom(Request $request, Stay $stay, ChangeStayRoom $action): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'exists:rooms,id'],
            // Por defecto NO se recobra: mover suele ser cortesía del hotel.
            'recalculate' => ['sometimes', 'boolean'],
        ]);

        try {
            $stay = $action->handle(
                $stay,
                \App\Models\Room::findOrFail($data['room_id']),
                $request->user(),
                $request->boolean('recalculate'),
            );
        } catch (NoAvailabilityException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($stay->load(['room:id,number', 'ratePlan:id,name,type'])));
    }

    /**
     * Cuenta final de la estancia: hospedaje pendiente + consumos por liquidar.
     */
    public function folio(Stay $stay): JsonResponse
    {
        return response()->json($this->serializeFolio($stay));
    }

    /**
     * Check-out con cuenta final: si hay saldo, exige cobrarlo (payment_method)
     * o forzar la salida con saldo explícitamente (force).
     */
    public function checkOut(Request $request, Stay $stay, TransitionReservation $action, SettleStay $settle): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['nullable', Rule::in($this->counterMethods())],
            'reference' => ['nullable', 'string', 'max:100'],
            'force' => ['sometimes', 'boolean'],
            // Fianza cobrada a la llegada: por default se devuelve al
            // registrar la salida; desmarcada = retención por daños, con
            // motivo obligatorio que queda en el registro del pago.
            'guarantee_refund' => ['sometimes', 'boolean'],
            'guarantee_retain_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            // La fianza va PRIMERO: si se aplica a la cuenta, baja el saldo y
            // lo que se cobra en mostrador es solo el excedente. Al revés se
            // le cobraba al huésped la cuenta completa y además se le
            // retenía el depósito — la copia decía "puedes cubrirlo con
            // ella" y el código nunca lo hacía.
            $this->settleGuarantee($request, $stay, $data);

            $folio = $stay->fresh()->folio();

            if ($folio['grand_pending'] > 0) {
                if (! empty($data['payment_method'])) {
                    $settle->handle($stay, [
                        'method' => $data['payment_method'],
                        'reference' => $data['reference'] ?? null,
                    ], $request->user());
                } elseif (! $request->boolean('force')) {
                    return response()->json([
                        'message' => 'La estancia tiene saldo pendiente; cóbralo o confirma la salida con saldo.',
                        'folio' => $this->serializeFolio($stay),
                    ], 422);
                }
            }

            $action->checkOut($stay, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($stay->refresh()->load(['room:id,number', 'ratePlan:id,name,type'])));
    }

    /**
     * Fianza al registrar la salida: devolverla (default, Refund manual —
     * el dinero regresa en mostrador) o retenerla por daños con motivo. La
     * ausencia de guarantee_refund cuenta como devolver: la fianza es un
     * pasivo y quedársela requiere decisión explícita, nunca un olvido.
     *
     * @param  array<string, mixed>  $data
     */
    protected function settleGuarantee(Request $request, Stay $stay, array $data): void
    {
        $guarantees = $stay->payments()
            ->where('kind', Payment::KIND_GUARANTEE)
            ->get()
            ->filter(fn (Payment $payment) => $payment->refundableAmount() > 0);

        if ($guarantees->isEmpty()) {
            return;
        }

        $refund = array_key_exists('guarantee_refund', $data)
            ? (bool) $data['guarantee_refund']
            : true;

        if ($refund) {
            foreach ($guarantees as $payment) {
                app(\App\Actions\Payments\RefundPayment::class)->handle(
                    $payment,
                    $payment->refundableAmount(),
                    'Devolución de fianza al registrar la salida',
                    $request->user(),
                    manual: true,
                );
            }

            return;
        }

        // Retención: exige el porqué — queda en el registro del pago.
        $reason = trim((string) ($data['guarantee_retain_reason'] ?? ''));

        if ($reason === '') {
            throw new InvalidArgumentException('Para retener la fianza indica el motivo (daños, faltantes...).');
        }

        // Lo que la cuenta puede absorber. El resto se queda retenido a secas
        // (penalización), que es lo que hacía antes con TODO el depósito.
        $pending = $stay->folio()['grand_pending'];

        foreach ($guarantees as $payment) {
            $payment->update([
                'notes' => trim(($payment->notes ? $payment->notes.' | ' : '')."Fianza retenida: {$reason}"),
            ]);

            $aplicar = round(min($payment->refundableAmount(), $pending), 2);

            if ($aplicar <= 0) {
                continue;
            }

            // Aplicar = el depósito deja de ser pasivo y paga la cuenta. Se
            // registran las dos patas —devolución del depósito y cobro por el
            // mismo monto y método— porque es exactamente lo que pasa: el
            // dinero ya estaba en el cajón y ahora es venta. Así el arqueo no
            // lo cuenta dos veces (la devolución compensa la fianza en
            // efectivo) y el corte lo ve como el ingreso que es.
            app(\App\Actions\Payments\RefundPayment::class)->handle(
                $payment,
                $aplicar,
                "Fianza aplicada a la cuenta: {$reason}",
                $request->user(),
                manual: true,
            );

            $stay->payments()->create([
                'amount' => $aplicar,
                'method' => $payment->method,
                'kind' => Payment::KIND_LODGING,
                // Con reserva, el hospedaje lo manda ELLA: folio() lee
                // reservation.paidTotal(), que suma por reservation_id. Sin
                // esta línea el saldo no bajaría y el cobro se pediría dos
                // veces (mismo motivo por el que la fianza va sin ella).
                'reservation_id' => $stay->reservation_id,
                'notes' => "Cubierto con la fianza: {$reason}",
                'received_by' => $request->user()?->id,
                'paid_at' => now(),
                'created_at' => now(),
            ]);

            $stay->reservation?->syncPaymentStatus();

            $pending = round($pending - $aplicar, 2);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeFolio(Stay $stay): array
    {
        $folio = $stay->folio();

        // Fianza viva de la estancia (cobrada y aún no devuelta): el modal
        // de salida ofrece devolverla. Fuera del folio a propósito — no es
        // parte de la cuenta, es un pasivo que regresa.
        $guarantees = $stay->payments()
            ->where('kind', Payment::KIND_GUARANTEE)
            ->get();

        $guaranteeRefundable = round(
            $guarantees->sum(fn (Payment $payment) => $payment->refundableAmount()),
            2,
        );

        // Por dónde entró el depósito: el efectivo sale del cajón, pero una
        // transferencia hay que regresarla a una cuenta, y sin decirlo aquí
        // quien registra la salida no tiene cómo saberlo.
        $guaranteeLive = $guarantees->first(fn (Payment $payment) => $payment->refundableAmount() > 0);

        return [
            // Quién y dónde: lo necesitan el PDF de la cuenta y el mensaje que
            // se le manda al huésped por WhatsApp.
            'stay' => [
                'id' => $stay->id,
                'room' => $stay->room?->number,
                'guest_name' => $stay->guest?->full_name ?? $stay->guest_name ?? 'Anónimo',
                // El contacto vive en el huésped, no en la estancia.
                'guest_phone' => $stay->guest?->phone,
                'rate_plan' => $stay->ratePlan?->name,
                'check_in_at' => $stay->check_in_at?->format('d/m/Y H:i'),
                'check_out_at' => $stay->check_out_at?->format('d/m/Y H:i'),
            ],
            'lodging_total' => $folio['lodging_total'],
            'lodging_paid' => $folio['lodging_paid'],
            'lodging_pending' => $folio['lodging_pending'],
            'consumption_pending' => $folio['consumption_pending'],
            'grand_pending' => $folio['grand_pending'],
            // Daños capturados en esta salida: el mostrador tiene que ver
            // qué sumó y poder quitarlo antes de cobrar. Antes solo existía
            // un contador ("2 daños cargados") y no había manera de deshacer.
            'damages' => $folio['damages'],
            'damages_total' => $folio['damages_total'],
            'guarantee_refundable' => $guaranteeRefundable,
            'guarantee_method_label' => $guaranteeLive
                ? Payment::methodLabel((string) $guaranteeLive->method)
                : null,
            'guarantee_reference' => $guaranteeLive?->reference,
            'orders' => $folio['orders']->map(fn (Order $order) => [
                'id' => $order->id,
                'total' => (float) $order->total,
                'created_at' => $order->created_at->format('d/m H:i'),
                'summary' => $order->lines
                    ->map(fn ($line) => ((float) $line->qty).'× '.($line->product?->name ?? 'Producto'))
                    ->implode(', '),
            ])->values(),
            // TODO lo consumido en la estancia, no solo lo que falta cobrar:
            // el folio filtra cargos a habitación sin liquidar, así que un
            // refresco ya cobrado al momento no aparecía en ningún lado. El
            // panel del plano lo necesita para que el cajero vea qué entregó.
            'consumption' => $stay->orders()
                ->with('lines.product:id,name')
                ->where('status', Order::STATUS_COMPLETED)
                ->latest()
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'total' => (float) $order->total,
                    'created_at' => $order->created_at->format('d/m H:i'),
                    'method' => $order->payment_method,
                    'method_label' => $order->payment_method === 'room'
                        ? 'A la cuenta'
                        : Payment::methodLabel((string) $order->payment_method),
                    // Cobrado ya: método real, o cargo a habitación liquidado
                    // en el check-out. Mismo criterio que la ficha del vehículo.
                    'settled' => $order->settled_at !== null || $order->payment_method !== 'room',
                    'can_void' => $order->settled_at === null,
                    'summary' => $order->lines
                        ->map(fn ($line) => ((float) $line->qty).'× '.($line->product?->name ?? 'Producto'))
                        ->implode(', '),
                ])->values(),
            // Lo que el huésped YA pagó: anticipos, abonos, consumos cobrados
            // y la fianza. El saldo solo dice lo que falta; esto dice por qué.
            'payments' => $stay->payments()
                ->latest('paid_at')
                ->get()
                ->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'kind' => $payment->kind,
                    'kind_label' => match ($payment->kind) {
                        Payment::KIND_LODGING => 'Hospedaje',
                        Payment::KIND_CONSUMPTION => 'Consumos',
                        Payment::KIND_GUARANTEE => 'Fianza',
                        default => 'Pago',
                    },
                    'method_label' => Payment::methodLabel((string) $payment->method),
                    'reference' => $payment->reference,
                    'paid_at' => $payment->paid_at?->format('d/m H:i'),
                ])->values(),
        ];
    }

    /**
     * Segundo momento de la caseta de motel: el encargado regresó con el papel
     * y aquí se termina de capturar la llegada — placa, marca, modelo y color
     * (o la identificación si llegaron a pie) — y se marca el cobro que hizo
     * en la habitación.
     *
     * Se puede sellar SIN datos: el cliente que no quiso darlos existe, y
     * dejar el aviso de "falta capturar" encendido para siempre es peor que
     * registrar que no hubo datos.
     */
    public function completeArrival(Request $request, Stay $stay): JsonResponse
    {
        if ($stay->status !== Stay::STATUS_ACTIVE) {
            return response()->json([
                'message' => 'Esa estancia ya no está activa.',
            ], 422);
        }

        $data = $request->validate([
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'vehicle_desc' => ['nullable', 'string', 'max:100'],
            'vehicle_brand' => ['nullable', 'string', 'max:40'],
            'vehicle_model' => ['nullable', 'string', 'max:40'],
            'vehicle_color' => ['nullable', 'string', 'max:30'],
            'id_document_type' => ['nullable', Rule::in(\App\Models\Guest::DOCUMENT_TYPES)],
            'id_document_number' => ['nullable', 'string', 'max:60'],
            // Se corrige solo si la realidad no coincidió con lo que anotó la
            // caseta (llegaron en carro y resultó que venían a pie).
            'arrival_mode' => ['nullable', Rule::in(['vehicle', 'foot'])],
            // Cobro que hizo el encargado en la habitación. El monto SIEMPRE
            // es lo que falta de hospedaje, nunca lo que mande el cliente.
            'payment_method' => ['nullable', Rule::in($this->counterMethods())],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($data, $stay, $request) {
            $plate = filled($data['vehicle_plate'] ?? null)
                ? mb_strtoupper(trim($data['vehicle_plate']))
                : null;

            // Mismo escritor de siempre: la ficha de la placa la resuelve
            // VehicleRegistry, que normaliza y no duplica.
            $vehicle = app(\App\Services\VehicleRegistry::class)->resolve(
                $data,
                $stay->guest,
            );

            $stay->fill(array_filter([
                'vehicle_plate' => $plate,
                'vehicle_desc' => $data['vehicle_desc'] ?? null,
                'vehicle_id' => $vehicle?->id,
                'id_document_type' => $data['id_document_type'] ?? null,
                'id_document_number' => $data['id_document_number'] ?? null,
                'arrival_mode' => $data['arrival_mode'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], fn ($value) => $value !== null));

            $stay->arrival_completed_at = now();
            $stay->save();

            // El cobro entra al corte de quien lo captura, no del que abrió el
            // acceso: es quien tiene el dinero en la mano.
            if (! empty($data['payment_method'])) {
                $pending = $stay->folio()['lodging_pending'];

                if ($pending > 0) {
                    $stay->payments()->create([
                        'amount' => $pending,
                        'method' => $data['payment_method'],
                        'kind' => Payment::KIND_LODGING,
                        'reference' => $data['payment_reference'] ?? null,
                        'notes' => 'Hospedaje cobrado en la habitación',
                        'received_by' => $request->user()?->id,
                        'paid_at' => now(),
                        'created_at' => now(),
                    ]);
                }
            }

            return response()->json($this->serializeFolio($stay->fresh()));
        });
    }

    /**
     * Cargo extra sobre una estancia en curso: hoy, los daños que se
     * encuentran al revisar la habitación antes de dejar salir al cliente.
     *
     * Se suma a `extra_charges` y sube el monto de la estancia, así que el
     * saldo crece y el cobro de la salida ya lo incluye — el check-out se
     * niega con saldo pendiente salvo que se fuerce, que es justo lo que hace
     * que nadie salga sin pagar el daño.
     */
    public function addCharge(Request $request, Stay $stay): JsonResponse
    {
        if ($stay->status !== Stay::STATUS_ACTIVE) {
            return response()->json([
                'message' => 'Esa estancia ya no está activa; el cargo va en su cuenta antes de registrar la salida.',
            ], 422);
        }

        $data = $request->validate([
            'concept' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'kind' => ['sometimes', 'string', 'max:20'],
        ]);

        $line = [
            // Id propio para poder quitarlo después: por posición se borraba
            // el equivocado en cuanto alguien agregaba otro en paralelo.
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'concept' => trim($data['concept']),
            'amount' => round((float) $data['amount'], 2),
            'kind' => $data['kind'] ?? 'damage',
        ];

        $this->applyCharge($stay, $line, 1);

        return response()->json($this->serializeFolio($stay->fresh()));
    }

    /**
     * Quitar un cargo capturado por error antes de registrar la salida.
     *
     * Sin esto, un daño mal tecleado se quedaba en la cuenta para siempre:
     * el botón "Agregar" era de ida y el mostrador tenía que cobrarlo o
     * forzar la salida con saldo.
     */
    public function destroyCharge(Stay $stay, string $charge): JsonResponse
    {
        if ($stay->status !== Stay::STATUS_ACTIVE) {
            return response()->json([
                'message' => 'Esa estancia ya no está activa; su cuenta se corrige en Cuentas por cerrar.',
            ], 422);
        }

        $line = collect($stay->extra_charges ?? [])
            ->first(fn ($item, $i) => (string) ($item['id'] ?? $i) === $charge);

        if ($line === null) {
            return response()->json(['message' => 'Ese cargo ya no está en la cuenta.'], 404);
        }

        $this->applyCharge($stay, $line, -1, $charge);

        return response()->json($this->serializeFolio($stay->fresh()));
    }

    /**
     * Suma (o resta, con -1) un cargo a la cuenta de la estancia.
     *
     * Con reserva, el hospedaje lo manda ELLA: Stay::folio() lee
     * reservation.total_amount y no stay.amount, así que tocar solo la
     * estancia dejaba el daño invisible y sin cobrar. Ese era el bug de
     * "agrego el daño y no aparece en la cuenta".
     *
     * @param  array<string, mixed>  $line
     * @param  string|null  $id  Con qué identificador se encontró la línea.
     *                           Los cargos viejos no traen `id` y se ubican
     *                           por posición: derivarlo de la línea daba ''
     *                           y el cargo se restaba de la cuenta sin
     *                           borrarse de la lista.
     */
    protected function applyCharge(Stay $stay, array $line, int $sign, ?string $id = null): void
    {
        $delta = round($sign * (float) $line['amount'], 2);
        $id ??= (string) ($line['id'] ?? '');

        $lines = collect($stay->extra_charges ?? []);
        $stay->extra_charges = ($sign > 0
            ? $lines->push($line)
            : $lines->reject(fn ($item, $i) => (string) ($item['id'] ?? $i) === $id)
        )->values()->all();

        $stay->amount = max(0, round((float) $stay->amount + $delta, 2));
        $stay->save();

        if (! $stay->reservation) {
            return;
        }

        $reservationLines = collect($stay->reservation->extra_charges ?? []);
        $stay->reservation->update([
            'total_amount' => max(0, round((float) $stay->reservation->total_amount + $delta, 2)),
            'extra_charges' => ($sign > 0
                ? $reservationLines->push($line)
                : $reservationLines->reject(fn ($item, $i) => (string) ($item['id'] ?? $i) === $id)
            )->values()->all(),
        ]);
        $stay->reservation->syncPaymentStatus();
    }

    /**
     * La cuenta en PDF, para imprimirla o mandarla. Es informativa: el
     * comprobante fiscal es otra cosa y este documento lo dice.
     */
    public function folioPdf(Stay $stay): \Symfony\Component\HttpFoundation\Response
    {
        $stay->load(['room:id,number', 'guest:id,first_name,last_name,phone', 'ratePlan:id,name']);
        $folio = $this->serializeFolio($stay);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.stay-folio', [
            'folio' => $folio,
            'room' => $folio['stay']['room'] ?? '—',
            'guest' => $folio['stay']['guest_name'],
            'ratePlan' => $folio['stay']['rate_plan'],
            'checkIn' => $folio['stay']['check_in_at'],
            'checkOut' => $folio['stay']['check_out_at'],
            'property' => \App\Models\Property::query()->value('name') ?? '',
            'generatedAt' => now()->format('d/m/Y H:i'),
        ])->setPaper('letter');

        return $pdf->download('cuenta-hab-'.($folio['stay']['room'] ?? $stay->id).'-'.now()->format('Y-m-d-Hi').'.pdf');
    }

    /**
     * Foto del documento del huésped a pie (registro exprés, spec-modo-motel):
     * se sube DESPUÉS de crear la estancia con su id — el POST de stays es
     * JSON transaccional y no carga con multipart.
     */
    public function storeDocument(Request $request, Stay $stay): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:8192'],
        ], [
            'file.max' => 'La foto debe pesar máximo 8 MB.',
            'file.mimes' => 'Usa una imagen JPG, PNG o WebP.',
        ]);

        $media = $stay->addMedia($request->file('file'))->toMediaCollection('id_document');

        return response()->json([
            'id' => $media->id,
            'url' => route('tenant.stays.document.show', [$stay, $media], false),
        ], 201);
    }

    /**
     * Sirve la foto del documento con el MISMO permiso que las INE del CRM
     * (guests.view-documents); disco privado, nunca URL pública.
     */
    public function showDocument(Stay $stay, \Spatie\MediaLibrary\MediaCollections\Models\Media $media): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless(
            $media->model_type === $stay->getMorphClass()
            && (int) $media->model_id === $stay->id
            && $media->collection_name === 'id_document',
            404,
        );

        return response()->file($media->getPath());
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(Stay $stay): array
    {
        return [
            'id' => $stay->id,
            'room' => $stay->room?->number,
            'guest_name' => $stay->guest_name,
            'num_people' => $stay->num_people,
            'vehicle_plate' => $stay->vehicle_plate,
            'vehicle_desc' => $stay->vehicle_desc,
            'rate_plan' => $stay->ratePlan?->name,
            'check_in_at' => $stay->check_in_at->format('d/m/Y H:i'),
            'planned_end_at' => $stay->planned_end_at->format('d/m/Y H:i'),
            'check_out_at' => $stay->check_out_at?->format('d/m/Y H:i'),
            'status' => $stay->status,
            'amount' => $stay->amount,
            'extra_charges' => $stay->extra_charges ?? [],
            'channel' => $stay->channel,
            'reservation_id' => $stay->reservation_id,
        ];
    }
}
