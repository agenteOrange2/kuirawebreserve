<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Experience;
use App\Models\ExperienceBooking;
use App\Models\ExperienceSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel del módulo Experiencias: catálogo, sesiones próximas con su cupo
 * y las reservas por atender (pendientes primero).
 */
class ExperiencesPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $experiences = Experience::query()
            ->with(['media', 'slots'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $vehicles = \App\Models\ExperienceVehicle::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $fleet = $vehicles->where('active', true)->keyBy('id');

        // Cupo vendido por sesión en una sola pasada (evita N+1 de
        // peopleBooked por sesión). Solo los próximos 60 días: el horizonte
        // de venta es anual (se genera solo) y mandarlo completo inflaría la
        // página sin aportar — la operación diaria vive en el corto plazo.
        $sessions = ExperienceSession::query()
            ->whereBetween('starts_at', [now()->subDay(), now()->addDays(60)])
            ->orderBy('starts_at')
            ->withSum(['bookings as people_booked' => fn ($q) => $q->whereIn('status', [
                ExperienceBooking::STATUS_PENDING, ExperienceBooking::STATUS_CONFIRMED,
            ])], 'people')
            ->get()
            ->groupBy('experience_id');

        return Inertia::render('tenant/experiences/Index', [
            'experiences' => $experiences->map(fn (Experience $experience) => [
                ...ExperienceController::serialize($experience),
                'slots' => $experience->slots->map(fn (\App\Models\ExperienceSlot $slot) => [
                    ...ExperienceSlotController::serialize($slot),
                    'effective_capacity' => $slot->effectiveCapacity($fleet),
                ])->values(),
                'sessions' => ($sessions->get($experience->id) ?? collect())->map(fn (ExperienceSession $session) => [
                    'id' => $session->id,
                    'starts_at' => $session->starts_at->toIso8601String(),
                    'capacity' => $session->capacity,
                    'people_booked' => (int) ($session->people_booked ?? 0),
                    'remaining' => max(0, $session->capacity - (int) ($session->people_booked ?? 0)),
                    'status' => $session->status,
                    // Generada por la programación semanal o creada a mano.
                    'from_schedule' => $session->experience_slot_id !== null,
                ])->values(),
            ]),
            'vehicles' => $vehicles->map(fn (\App\Models\ExperienceVehicle $vehicle) => ExperienceVehicleController::serialize($vehicle)),
            'bookings' => ExperienceBooking::query()
                ->with(['session.experience', 'guest', 'reservation', 'group'])
                ->whereHas('session', fn ($q) => $q->where('starts_at', '>=', now()->subDay()))
                ->orderByRaw("field(status, 'pending') desc")
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get()
                ->map(fn (ExperienceBooking $booking) => ExperienceBookingController::serialize($booking)),
            'pricingModes' => Experience::PRICING_MODES,
            'publicUrl' => "https://{$request->getHost()}/reservar/experiencias",
            'canManage' => $request->user()->can('properties.manage'),
        ]);
    }
}
