<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Reservations\CreateWalkInStay;
use App\Actions\Reservations\SettleStay;
use App\Actions\Reservations\TransitionReservation;
use App\Exceptions\NoAvailabilityException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Stay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $stay = $action->handle($data, $request->user());
        } catch (NoAvailabilityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($stay->load(['room:id,number', 'ratePlan:id,name,type'])), 201);
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

            $action->checkOut($stay, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($stay->refresh()->load(['room:id,number', 'ratePlan:id,name,type'])));
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeFolio(Stay $stay): array
    {
        $folio = $stay->folio();

        return [
            'lodging_total' => $folio['lodging_total'],
            'lodging_paid' => $folio['lodging_paid'],
            'lodging_pending' => $folio['lodging_pending'],
            'consumption_pending' => $folio['consumption_pending'],
            'grand_pending' => $folio['grand_pending'],
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
            'channel' => $stay->channel,
            'reservation_id' => $stay->reservation_id,
        ];
    }
}
