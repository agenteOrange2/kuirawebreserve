<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Reservations\ApplyReservationCoupon;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\RegisterReservationPayment;
use App\Actions\Reservations\TransitionReservation;
use App\Actions\Reservations\UpdateReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateReservationRequest;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reservations = Reservation::query()
            ->with(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type'])
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->date('from'), fn ($q, $from) => $q->where('ends_at', '>=', $from))
            ->when($request->date('to'), fn ($q, $to) => $q->where('starts_at', '<=', $to))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Reservation $r) => $this->serialize($r));

        return response()->json($reservations);
    }

    public function store(Request $request, CreateReservation $action): JsonResponse
    {
        $data = $request->validate([
            'rate_plan_id' => ['required', 'exists:rate_plans,id'],
            'room_id' => ['nullable', 'exists:rooms,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'guest_id' => ['nullable', 'exists:guests,id'],
            'guest_name' => ['nullable', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'num_people' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'vehicle_desc' => ['nullable', 'string', 'max:100'],
            // Ficha del vehículo: la reserva solo guarda placa y descripción,
            // pero lo estructurado vive en `vehicles` (App\Services\
            // VehicleRegistry). Sin esto, marca, modelo y color capturados al
            // reservar se perdían y el check-in creaba la ficha en blanco.
            'vehicle_brand' => ['nullable', 'string', 'max:40'],
            'vehicle_model' => ['nullable', 'string', 'max:40'],
            'vehicle_color' => ['nullable', 'string', 'max:30'],
            'eta' => ['nullable', 'date_format:H:i'],
            'confirmed' => ['sometimes', 'boolean'],
            'source_channel' => ['sometimes', Rule::in(['front_desk', 'phone', 'web', 'whatsapp', 'walk_in'])],
            'deposit_amount' => ['sometimes', 'numeric', 'min:0'],
            // Recepción también puede aplicar cupones (módulo cupones): la
            // validación y el congelado viven en CreateReservation, igual
            // que en el wizard público.
            'coupon_code' => ['nullable', 'string', 'max:40'],
            // Conceptos de cargos opcionales de la habitación; el monto
            // SIEMPRE se resuelve del catálogo del cuarto, nunca del cliente.
            'extra_charges' => ['sometimes', 'array', 'max:20'],
            'extra_charges.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'guest_notes' => ['nullable', 'string'],
        ]);

        try {
            $reservation = $action->handle($data, $request->user());
        } catch (NoAvailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\InvalidArgumentException $e) {
            // Cupón inválido o que no cumple sus condiciones: el motivo
            // exacto llega a recepción, igual que al huésped del wizard.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($reservation->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type'])), 201);
    }

    public function update(UpdateReservationRequest $request, Reservation $reservation, UpdateReservation $action): JsonResponse
    {
        try {
            $reservation = $action->handle($reservation, $request->validated(), $request->user());
        } catch (NoAvailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($reservation->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type'])));
    }

    /**
     * Aplica un cupón a una reserva YA creada (módulo cupones).
     *
     * El descuento solo entraba al reservar: si el huésped no escribió el
     * código, o lo escribió mal, recepción no tenía forma de dárselo salvo
     * cancelar y volver a reservar. El total se rearma desde el precio sin
     * descuento, así que aplicar dos veces no encoge la cuenta.
     */
    public function applyCoupon(Request $request, Reservation $reservation, ApplyReservationCoupon $action): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
        ]);

        return $this->withCoupon($reservation, $data['code'], $request->user(), $action);
    }

    /** Quita el cupón de la reserva y devuelve el total al precio de lista. */
    public function removeCoupon(Request $request, Reservation $reservation, ApplyReservationCoupon $action): JsonResponse
    {
        return $this->withCoupon($reservation, null, $request->user(), $action);
    }

    protected function withCoupon(
        Reservation $reservation,
        ?string $code,
        ?\App\Models\User $user,
        ApplyReservationCoupon $action,
    ): JsonResponse {
        try {
            $reservation = $action->handle($reservation, $code, $user);
        } catch (InvalidArgumentException $e) {
            // El motivo exacto (vencido, no aplica en esos días, ya cerrada)
            // llega a recepción tal cual, para que sepa qué decirle al huésped.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            $this->serialize($reservation->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type'])),
        );
    }

    public function confirm(Request $request, Reservation $reservation, TransitionReservation $action): JsonResponse
    {
        return $this->transition(fn () => $action->confirm($reservation, $request->user()), $reservation);
    }

    public function cancel(Request $request, Reservation $reservation, TransitionReservation $action): JsonResponse
    {
        $data = $request->validate([
            'no_show' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $to = $request->boolean('no_show') ? ReservationStatus::NoShow : ReservationStatus::Cancelled;

        return $this->transition(
            fn () => $action->cancel($reservation, $request->user(), $to, $data['reason'] ?? null),
            $reservation,
        );
    }

    /**
     * Reabrir (y reagendar) una reserva cancelada o de "no llegó", con su
     * mismo código. Sin fechas, vuelve en las suyas; con fechas, se reagenda
     * y se recalcula como en la edición.
     */
    public function reopen(Request $request, Reservation $reservation, TransitionReservation $action): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'confirmed' => ['sometimes', 'boolean'],
        ]);

        return $this->transition(
            fn () => $action->reopen($reservation, $request->user(), [
                ...$data,
                'confirmed' => $request->boolean('confirmed'),
            ]),
            $reservation,
        );
    }

    public function checkIn(Request $request, Reservation $reservation, TransitionReservation $action): JsonResponse
    {
        // Fianza (depósito en garantía): método presencial con el que el
        // staff la cobró al registrar la llegada. El monto default sale del
        // ajuste del hotel (ReservationPolicy, con el escalón de la
        // partida); el mostrador puede ajustarlo, pero ChargeGuarantee le
        // exige motivo — este endpoint es del panel, no público.
        $data = $request->validate([
            // Lo que acepte el mostrador de ESTE hotel: la lista cableada
            // que había aquí ignoraba /ajustes/metodos-pago por completo, así
            // que un hotel sin terminal podía registrar fianzas con tarjeta y
            // uno que cobra por transferencia no podía registrarlas.
            'guarantee_method' => ['nullable', Rule::in(app(\App\Services\ReservationPolicy::class)->counterMethods())],
            'guarantee_amount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'guarantee_reason' => ['nullable', 'string', 'max:255'],
            'guarantee_reference' => ['nullable', 'string', 'max:100'],
            // Llegada anticipada: el panel la manda solo cuando quien atiende
            // confirmó que el huésped ya está aquí, días antes de su fecha.
            'early' => ['sometimes', 'boolean'],
        ]);

        return $this->transition(
            fn () => $action->checkIn(
                $reservation,
                $request->user(),
                [],
                $data['guarantee_method'] ?? null,
                isset($data['guarantee_amount']) ? (float) $data['guarantee_amount'] : null,
                $data['guarantee_reason'] ?? null,
                $request->boolean('early'),
                $data['guarantee_reference'] ?? null,
            ),
            $reservation,
        );
    }

    /**
     * Borrado en masa desde el Historial. Solo acepta estados terminales
     * (completada, cancelada, no-show): una reserva viva no se elimina,
     * primero se cancela. Pagos y solicitudes de cobro caen en cascada;
     * estancias y conversaciones solo pierden la referencia.
     */
    public function destroyBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $reservations = Reservation::query()
            ->whereIn('id', $data['ids'])
            ->whereIn('status', [
                ReservationStatus::Completed,
                ReservationStatus::Cancelled,
                ReservationStatus::NoShow,
            ])
            ->get();

        if ($reservations->count() !== count($data['ids'])) {
            return response()->json([
                'message' => 'Solo se pueden eliminar reservas del historial (completadas, canceladas o no-show).',
            ], 422);
        }

        DB::transaction(fn () => $reservations->each->delete());

        return response()->json(['deleted' => $reservations->count()]);
    }

    /**
     * Registra un abono (anticipo o liquidación) — spec §7.5.
     */
    public function registerPayment(Request $request, Reservation $reservation, RegisterReservationPayment $action): JsonResponse
    {
        // Métodos presenciales aceptados por la recepción, dentro de los dos
        // que este endpoint admite...
        $counterMethods = array_values(array_intersect(
            ['cash', 'card'],
            app(\App\Services\ReservationPolicy::class)->counterMethods(),
        ));

        // ...más la transferencia YA VERIFICADA, con su folio. Caso real
        // cabañas 2026-09-11: el huésped depositó, su cobro por transferencia
        // había vencido a los 20 minutos y no había dónde registrar ese
        // dinero — ni en Pagos (sin cobro vivo) ni aquí (solo efectivo o
        // tarjeta). El folio es obligatorio: es lo que se concilia con el banco.
        $counterMethods[] = 'transfer';

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            // Solo métodos presenciales verificables al momento: la
            // transferencia NO se registra directo — se genera la solicitud
            // (cobro en línea) y se confirma con su comprobante en /pagos.
            // El check-out (SettleStay) y la verificación usan otras rutas.
            // ...y de esos dos, los que la recepción acepta de verdad
            // (/ajustes/metodos-pago → Políticas): un hotel sin terminal no
            // debe poder registrar un cobro con tarjeta ni por descuido.
            'method' => ['required', Rule::in($counterMethods)],
            'reference' => ['nullable', 'required_if:method,transfer', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
            // Por defecto el huésped recibe su comprobante por el mismo hilo
            // (o WhatsApp/correo directo). Se apaga para un cobro que no le
            // interesa ver, p. ej. un ajuste interno.
            'notify_guest' => ['sometimes', 'boolean'],
        ], [
            'method.in' => 'Ese método de cobro no está habilitado en la recepción; revísalo en Ajustes, Métodos de pago.',
            'reference.required_if' => 'Anota el folio o la referencia de la transferencia: es lo que se concilia con el banco.',
        ]);

        try {
            $payment = $action->handle($reservation, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($request->boolean('notify_guest', true)) {
            try {
                app(\App\Services\Payments\PaymentGuestNotifier::class)
                    ->manualPaymentReceived($reservation, (float) $payment->amount, $payment->method);
            } catch (\Throwable $e) {
                // El dinero ya quedó registrado: un aviso fallido no lo deshace.
                report($e);
            }
        }

        return response()->json($this->serialize(
            $reservation->refresh()->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type']),
        ));
    }

    protected function transition(callable $fn, Reservation $reservation): JsonResponse
    {
        try {
            $fn();
        } catch (NoAvailabilityException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize(
            $reservation->refresh()->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type']),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(Reservation $r): array
    {
        return [
            'id' => $r->id,
            'code' => $r->displayCode(),
            'guest_id' => $r->guest_id,
            'guest_name' => $r->guest_name,
            'guest_email' => $r->guest?->email,
            'num_people' => $r->num_people,
            'adults' => $r->adults,
            'children' => $r->children,
            'vehicle_plate' => $r->vehicle_plate,
            'vehicle_desc' => $r->vehicle_desc,
            'eta' => $r->eta ? substr($r->eta, 0, 5) : null,
            'guest_notes' => $r->guest_notes,
            'cancellation_reason' => $r->cancellation_reason,
            'room' => $r->room?->number,
            'room_id' => $r->room_id,
            'room_type' => $r->roomType?->name,
            'rate_plan' => $r->ratePlan?->name,
            'starts_at' => $r->starts_at->format('d/m/Y H:i'),
            'ends_at' => $r->ends_at->format('d/m/Y H:i'),
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'hold_expires_at' => $r->hold_expires_at?->format('d/m/Y H:i'),
            'source_channel' => $r->source_channel,
            'total_amount' => $r->total_amount,
            'extra_charges' => $r->extra_charges ?? [],
            'deposit_amount' => $r->deposit_amount,
            // Cupón aplicado en el wizard (módulo cupones): el descuento ya
            // está dentro de total_amount; aquí solo se muestra la línea.
            'coupon_code' => $r->coupon_code,
            'discount_amount' => (float) ($r->discount_amount ?? 0),
            'payment_status' => $r->payment_status->value,
            'payment_status_label' => $r->payment_status->label(),
            'payment_due_at' => $r->payment_due_at?->format('d/m/Y H:i'),
            'payment_overdue' => $r->isPaymentOverdue(),
            'paid_total' => $r->paidTotal(),
            'pending_balance' => $r->pendingBalance(),
            'payments' => $r->payments()->latest('paid_at')->get()->map(fn (Payment $p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'method' => Payment::methodLabel($p->method),
                'reference' => $p->reference,
                'paid_at' => $p->paid_at->format('d/m/Y H:i'),
                'received_by' => $p->receivedBy?->name,
                // F4: cuánto puede devolverse de este pago todavía.
                'refunded' => $p->refundedTotal(),
                'refundable' => $p->refundableAmount(),
                'via_gateway' => $p->gateway !== null,
            ]),
            'refunded_total' => $r->refundedTotal(),
            // Sugerencia por política de cancelación (la de la tarifa, o la
            // default del hotel): "si se cancela ahora, correspondería X".
            'refund_suggestion' => ($suggestion = $r->suggestedRefund()) !== null ? [
                'amount' => $suggestion,
                'amount_label' => '$'.number_format($suggestion, 2),
                'policy_label' => app(\App\Services\ReservationPolicy::class)
                    ->cancellationPolicyLabel($r->ratePlan),
            ] : null,
            'stay_id' => $r->stay?->id,
            // Cobro en curso (spec-pagos §7.5): link vivo para copiar/enviar.
            'payment_request' => ($pr = $r->paymentRequests()->active()->latest('id')->first()) ? [
                'id' => $pr->id,
                'concept' => $pr->conceptLabel(),
                'amount_label' => $pr->amountLabel(),
                'method' => $pr->method,
                'provider_label' => $pr->provider ? (\App\Models\Central\PaymentGatewayLink::PROVIDERS[$pr->provider] ?? $pr->provider) : null,
                'checkout_url' => $pr->checkout_url,
                'public_url' => route('tenant.payment.return', $pr->uuid),
                'status_label' => $pr->statusLabel(),
                'expires_label' => $pr->expires_at?->diffForHumans(),
            ] : null,
        ];
    }

    /**
     * Genera un cobro para la reserva desde el panel (spec-pagos §7.5):
     * link de pasarela si hay una activa, o transferencia con las cuentas
     * del hotel. Reutiliza el mismo IssuePaymentRequest que el bot.
     */
    public function issuePayment(Request $request, Reservation $reservation, \App\Actions\Payments\IssuePaymentRequest $action): JsonResponse
    {
        // Puerta única de métodos (PaymentMethodGate): una pasarela
        // conectada NO basta — plataforma o el propio hotel pueden tener
        // ese método apagado en /ajustes/metodos-pago. Preguntando aquí
        // por la fila cruda, el panel emitía links de Stripe a hoteles que
        // solo cobran por transferencia y mostrador (bug 2026-08-28). Sin
        // pasarela habilitada, $link queda en null y el cobro sale como
        // transferencia, que es lo que ese hotel sí puede cobrar.
        $link = app(\App\Services\Payments\PaymentMethodGate::class)
            ->activeGatewayLink((string) tenant('id'));

        try {
            $paymentRequest = $action->handle($reservation, \App\Models\PaymentRequest::METHOD_TRANSFER, $request->user(), $link);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            // La pasarela falló: cae a transferencia (spec-pagos §7.1).
            try {
                $paymentRequest = $action->handle($reservation, \App\Models\PaymentRequest::METHOD_TRANSFER, $request->user());
            } catch (InvalidArgumentException $inner) {
                return response()->json(['message' => $inner->getMessage()], 422);
            }
        }

        // Fuera de la transacción: el link/instrucciones viajan solos al
        // huésped por sus canales de contacto. Avisar es cortesía — el
        // cobro ya existe y el panel lo muestra; un transporte caído no
        // debe convertirlo en error.
        try {
            app(\App\Services\Payments\PaymentGuestNotifier::class)->paymentRequestIssued($paymentRequest);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($this->serialize(
            $reservation->refresh()->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type']),
        ));
    }

    /**
     * Reembolsa un pago, total o parcial (spec-pagos F4). Pasarela = via API
     * del proveedor; manual = solo registro (efectivo o hecho en el dashboard).
     */
    public function refundPayment(Request $request, Reservation $reservation, Payment $payment, \App\Actions\Payments\RefundPayment $action): JsonResponse
    {
        abort_unless($payment->reservation_id === $reservation->id, 404);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'manual' => ['sometimes', 'boolean'],
        ]);

        try {
            $refund = $action->handle(
                $payment,
                (float) $data['amount'],
                $data['reason'] ?? null,
                $request->user(),
                (bool) ($data['manual'] ?? false),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        app(\App\Services\Payments\PaymentGuestNotifier::class)->refundIssued($refund);

        return response()->json($this->serialize(
            $reservation->refresh()->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type']),
        ));
    }

    /** Cancela el cobro pendiente de la reserva (spec-pagos §7.5). */
    public function cancelPayment(Reservation $reservation, \App\Models\PaymentRequest $paymentRequest): JsonResponse
    {
        abort_unless($paymentRequest->reservation_id === $reservation->id, 404);

        if ($paymentRequest->status === \App\Models\PaymentRequest::STATUS_PENDING) {
            $paymentRequest->update(['status' => \App\Models\PaymentRequest::STATUS_CANCELED]);
        }

        return response()->json($this->serialize(
            $reservation->refresh()->load(['room:id,number', 'roomType:id,name', 'ratePlan:id,name,type']),
        ));
    }
}
