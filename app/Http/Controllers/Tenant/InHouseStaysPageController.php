<?php

namespace App\Http\Controllers\Tenant;

use App\Models\Property;
use App\Models\Stay;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Huéspedes alojados ahora (/reservas/alojados): la lista completa de
 * estancias activas, con buscador y paginación. En /reservas solo se
 * asoman las 20 más próximas a salir; un hotel grande tiene tantas
 * estancias vivas como habitaciones y esa página ya carga mucho.
 *
 * Solo consulta: la salida se registra desde /reservas (el botón manda
 * ahí con la estancia enfocada), donde vive el folio con sus consumos,
 * su saldo y la fianza.
 */
class InHouseStaysPageController extends ReservationsPageController
{
    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();
        $search = trim($request->string('q')->toString());

        $paginator = Stay::query()
            ->active()
            ->with(['room:id,number', 'ratePlan:id,name'])
            ->when($search !== '', fn ($query) => $query->where(function ($q) use ($search) {
                $q->where('guest_name', 'like', "%{$search}%")
                    ->orWhere('vehicle_plate', 'like', "%{$search}%")
                    ->orWhereHas('room', fn ($r) => $r->where('number', 'like', "%{$search}%"));
            }))
            // Primero quien está por salir: es el trabajo del día.
            ->orderBy('planned_end_at')
            ->paginate(25)
            ->withQueryString();

        $paginator->through(fn (Stay $stay) => $this->serializeStay($stay));

        return Inertia::render('tenant/reservations/InHouse', [
            'property' => $property->only(['id', 'name']),
            'stays' => $paginator,
            'filters' => ['q' => $search],
            'canManage' => $request->user()->can('reservations.manage'),
        ]);
    }
}
