<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\WaitlistEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página del módulo Lista de espera (/lista-espera): quiénes esperan un
 * hueco, a quién ya se le avisó y qué terminó en reserva. El alta la hace
 * el wizard público; aquí el staff da seguimiento (convertida / eliminar).
 */
class WaitlistPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('tenant/waitlist/Index', [
            'entries' => WaitlistEntry::query()
                ->with('roomType:id,name')
                ->latest('id')
                ->limit(200)
                ->get()
                ->map(fn (WaitlistEntry $entry) => [
                    'id' => $entry->id,
                    'guest_name' => $entry->guest_name,
                    'guest_phone' => $entry->guest_phone,
                    'guest_email' => $entry->guest_email,
                    'room_type' => $entry->roomType?->name,
                    'starts_at' => $entry->starts_at->format('d/m/Y'),
                    'ends_at' => $entry->ends_at->format('d/m/Y'),
                    'status' => $entry->status,
                    'status_label' => $entry->statusLabel(),
                    'notified_at' => $entry->notified_at?->format('d/m/Y H:i'),
                    'created_at' => $entry->created_at->format('d/m/Y H:i'),
                ]),
            'canManage' => $request->user()->can('reservations.manage'),
        ]);
    }
}
