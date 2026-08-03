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
            'num_people' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'vehicle_desc' => ['nullable', 'string', 'max:100'],
            // Conceptos de cargos opcionales de la habitación; el monto
            // SIEMPRE se resuelve del catálogo del cuarto, nunca del cliente.
            'extra_charges' => ['sometimes', 'array', 'max:20'],
            'extra_charges.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'string'],
            // Cobro al llegar (walkin_charge=checkin): método presencial del
            // mostrador; el monto SIEMPRE es el de la estancia, nunca del cliente.
            'payment_method' => ['nullable', \Illuminate\Validation\Rule::in(\App\Models\Payment::METHODS)],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            // Fianza (depósito en garantía): método presencial; el monto lo
            // decide el ajuste del hotel, nunca el cliente.
            'guarantee_method' => ['nullable', \Illuminate\Validation\Rule::in(['cash', 'card'])],
        ]);

        try {
            $stay = $action->handle($data, $request->user());
        } catch (NoAvailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
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
            'payment_method' => ['nullable', Rule::in(Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'force' => ['sometimes', 'boolean'],
            // Fianza cobrada a la llegada: por default se devuelve al
            // registrar la salida; desmarcada = retención por daños, con
            // motivo obligatorio que queda en el registro del pago.
            'guarantee_refund' => ['sometimes', 'boolean'],
            'guarantee_retain_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $folio = $stay->folio();

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

            $this->settleGuarantee($request, $stay, $data);

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

        foreach ($guarantees as $payment) {
            $payment->update([
                'notes' => trim(($payment->notes ? $payment->notes.' | ' : '')."Fianza retenida: {$reason}"),
            ]);
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
        $guaranteeRefundable = round($stay->payments()
            ->where('kind', Payment::KIND_GUARANTEE)
            ->get()
            ->sum(fn (Payment $payment) => $payment->refundableAmount()), 2);

        return [
            'lodging_total' => $folio['lodging_total'],
            'lodging_paid' => $folio['lodging_paid'],
            'lodging_pending' => $folio['lodging_pending'],
            'consumption_pending' => $folio['consumption_pending'],
            'grand_pending' => $folio['grand_pending'],
            'guarantee_refundable' => $guaranteeRefundable,
            'orders' => $folio['orders']->map(fn (Order $order) => [
                'id' => $order->id,
                'total' => (float) $order->total,
                'created_at' => $order->created_at->format('d/m H:i'),
                'summary' => $order->lines
                    ->map(fn ($line) => ((float) $line->qty).'× '.($line->product?->name ?? 'Producto'))
                    ->implode(', '),
            ])->values(),
        ];
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
