<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\Stay;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuestsPageController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim($request->string('q')->toString());

        $guests = Guest::query()
            ->when($search !== '', fn ($q) => $q->search($search))
            ->when($request->boolean('blacklisted'), fn ($q) => $q->where('is_blacklisted', true))
            ->withCount(['stays as visits' => fn ($q) => $q->where('status', 'completed')])
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Guest $guest) => [
                'id' => $guest->id,
                'full_name' => $guest->full_name ?? 'Sin nombre',
                'phone' => $guest->phone,
                'email' => $guest->email,
                'visits' => $guest->visits,
                'is_blacklisted' => $guest->is_blacklisted,
                'created_at' => $guest->created_at->format('d/m/Y'),
            ]);

        return Inertia::render('tenant/guests/Index', [
            'guests' => $guests,
            'filters' => ['q' => $search, 'blacklisted' => $request->boolean('blacklisted')],
            'canManage' => $request->user()->can('guests.manage'),
            'canViewDocuments' => $request->user()->can('guests.view-documents'),
            'documentTypes' => Guest::DOCUMENT_TYPES,
        ]);
    }

    public function show(Request $request, Guest $guest): Response
    {
        $canViewDocuments = $request->user()->can('guests.view-documents');

        $stays = $guest->stays()
            ->with(['room:id,number', 'ratePlan:id,name'])
            ->orderByDesc('check_in_at')
            ->take(30)
            ->get();

        $consumosPorStay = Order::whereIn('stay_id', $stays->pluck('id'))
            ->where('status', Order::STATUS_COMPLETED)
            ->selectRaw('stay_id, SUM(total) AS total')
            ->groupBy('stay_id')
            ->pluck('total', 'stay_id');

        return Inertia::render('tenant/guests/Show', [
            'guest' => [
                'id' => $guest->id,
                'first_name' => $guest->first_name,
                'last_name' => $guest->last_name,
                'full_name' => $guest->full_name ?? 'Sin nombre',
                'phone' => $guest->phone,
                'email' => $guest->email,
                'birth_date' => $guest->birth_date?->format('Y-m-d'),
                'nationality' => $guest->nationality,
                'address' => $guest->address,
                'city' => $guest->city,
                'state' => $guest->state,
                'zip' => $guest->zip,
                'id_document_type' => $guest->id_document_type,
                'id_document_number' => $canViewDocuments ? $guest->id_document_number : null,
                'notes' => $guest->notes,
                'is_blacklisted' => $guest->is_blacklisted,
                'blacklist_reason' => $guest->blacklist_reason,
                'marketing_consent' => $guest->marketing_consent,
                'created_at' => $guest->created_at->format('d/m/Y'),
            ],
            'metrics' => $guest->metrics(),
            'documents' => $canViewDocuments ? GuestController::documents($guest) : [],
            'vehicle' => $guest->vehicle() ?: null,
            'vehiclePhotos' => $canViewDocuments ? GuestController::media($guest, 'vehicle') : [],
            'stays' => $stays->map(fn (Stay $stay) => [
                'id' => $stay->id,
                'room' => $stay->room?->number,
                'rate_plan' => $stay->ratePlan?->name,
                'check_in_at' => $stay->check_in_at->format('d/m/Y H:i'),
                'check_out_at' => $stay->check_out_at?->format('d/m/Y H:i'),
                'status' => $stay->status,
                'amount' => $stay->amount,
                'consumos' => (float) ($consumosPorStay[$stay->id] ?? 0),
            ]),
            'reservations' => $guest->reservations()
                ->with('room:id,number')
                ->orderByDesc('starts_at')
                ->take(30)
                ->get()
                ->map(fn (Reservation $r) => [
                    'id' => $r->id,
                    'room' => $r->room?->number,
                    'starts_at' => $r->starts_at->format('d/m/Y'),
                    'ends_at' => $r->ends_at->format('d/m/Y'),
                    'status' => $r->status->value,
                    'status_label' => $r->status->label(),
                    'total_amount' => $r->total_amount,
                    'is_upcoming' => in_array($r->status, [ReservationStatus::Pending, ReservationStatus::Confirmed], true),
                ]),
            'canManage' => $request->user()->can('guests.manage'),
            'canReserve' => $request->user()->can('reservations.manage'),
            'canViewDocuments' => $canViewDocuments,
            'documentTypes' => Guest::DOCUMENT_TYPES,
        ]);
    }
}
