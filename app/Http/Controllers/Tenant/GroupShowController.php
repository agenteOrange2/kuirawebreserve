<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\ReservationGroup;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Detalle de una reserva grupal (/grupos/{id}): todas sus habitaciones y
 * recorridos con acciones de edición real (agregar/cancelar líneas,
 * personas), el dinero del grupo (total, pagado, pendiente, cobros) y los
 * datos del responsable. El listado de /grupos queda para la vista rápida.
 */
class GroupShowController extends Controller
{
    public function __invoke(Request $request, ReservationGroup $group): Response
    {
        $group->load([
            'guest',
            'reservations.roomType',
            'reservations.room',
            'reservations.ratePlan',
            'experienceBookings.session.experience',
        ]);

        $reservationIds = $group->reservations->pluck('id');
        $experienceIds = $group->experienceBookings->pluck('id');

        $paid = round(
            (float) Payment::query()->whereIn('reservation_id', $reservationIds)->sum('amount')
                + (float) Payment::query()->whereIn('experience_booking_id', $experienceIds)->sum('amount'),
            2,
        );
        $total = $group->totalAmount();

        return Inertia::render('tenant/groups/Show', [
            'group' => [
                ...GroupReservationController::serialize($group),
                'guest_phone' => $group->guest?->phone,
                'guest_email' => $group->guest?->email,
                'mode' => $group->reservations->first()?->ratePlan?->type->value ?? 'night',
                'paid_total' => $paid,
                'pending_balance' => max(0, round($total - $paid, 2)),
                'reservations_detail' => $group->reservations->map(fn ($r) => [
                    'id' => $r->id,
                    'code' => $r->displayCode(),
                    'room_type' => $r->roomType?->name,
                    'room_type_id' => $r->room_type_id,
                    'rate_plan_id' => $r->rate_plan_id,
                    'room' => $r->room?->number,
                    'adults' => (int) $r->adults,
                    'children' => (int) $r->children,
                    'starts_at' => $r->starts_at->toIso8601String(),
                    'ends_at' => $r->ends_at?->toIso8601String(),
                    'total' => (float) $r->total_amount,
                    'status' => $r->status->value,
                    'status_label' => $r->status->label(),
                ])->values(),
                'payment_requests' => $group->paymentRequests()
                    ->latest('id')
                    ->limit(10)
                    ->get()
                    ->map(fn (PaymentRequest $pr) => [
                        'id' => $pr->id,
                        'method' => $pr->method,
                        'amount' => (float) $pr->amount,
                        'amount_label' => $pr->amountLabel(),
                        'status' => $pr->status,
                        'checkout_url' => $pr->checkout_url,
                        'expires_at' => $pr->expires_at?->toIso8601String(),
                        'created_at' => $pr->created_at->toIso8601String(),
                    ])->values(),
            ],
            // Para "Agregar habitación": tipos activos con cuartos físicos.
            'roomTypes' => RoomType::query()
                ->where('active', true)
                ->withCount('rooms')
                ->orderBy('name')
                ->get()
                ->map(fn (RoomType $type) => [
                    'id' => $type->id,
                    'name' => $type->name,
                    'capacity' => $type->capacity,
                    'rooms_count' => $type->rooms_count,
                ]),
            'hasExperiencesModule' => (bool) tenant()?->hasModule('experiencias'),
            'canManage' => $request->user()->can('reservations.manage'),
        ]);
    }
}
