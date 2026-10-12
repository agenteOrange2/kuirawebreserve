<?php

namespace App\Http\Controllers\Tenant;

use App\Models\Property;
use App\Models\Stay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Huéspedes alojados ahora (/reservas/alojados): la lista completa de
 * estancias activas, con buscador y paginación. En /reservas solo se
 * asoman las 20 más próximas a salir; un hotel grande tiene tantas
 * estancias vivas como habitaciones y esa página ya carga mucho.
 *
 * La salida se registra desde /reservas/operacion (el botón manda ahí con
 * la estancia enfocada), donde vive el folio con sus consumos, su saldo y
 * la fianza. Aquí se ve quién está, cuándo sale y cuánto debe.
 */
class InHouseStaysPageController extends ReservationsPageController
{
    /** Diez por página: el tablero manda aquí a revisar, no a leer un archivo. */
    protected const PER_PAGE = 10;

    /** Los filtros de salida que entiende la pantalla (los chips y la dona). */
    protected const DEPARTURE_FILTERS = \App\Services\Reservations\BoardMetrics::DEPARTURES;

    public function __invoke(Request $request): Response
    {
        $property = Property::firstOrFail();
        $search = trim($request->string('q')->toString());
        $departure = $request->string('salida')->toString();
        if (! in_array($departure, self::DEPARTURE_FILTERS, true)) {
            $departure = '';
        }
        $withBalance = $request->boolean('saldo');

        $paginator = Stay::query()
            ->active()
            ->withBalance()
            ->with(['room:id,number', 'ratePlan:id,name', 'guest:id,first_name,last_name,phone'])
            ->when($search !== '', fn ($query) => $query->where(function ($q) use ($search) {
                $q->where('guest_name', 'like', "%{$search}%")
                    ->orWhere('vehicle_plate', 'like', "%{$search}%")
                    ->orWhereHas('room', fn ($r) => $r->where('number', 'like', "%{$search}%"))
                    // La estancia que nació de una reserva puede no traer el
                    // nombre copiado: también se busca en la ficha del CRM.
                    ->orWhereHas('guest', fn ($g) => $g->search($search));
            }))
            ->when($departure !== '', fn ($query) => $this->whereDeparture($query, $departure))
            ->when($withBalance, fn ($query) => $query->whereRaw($this->balanceSql().' > 0.009'))
            // Primero quien está por salir: es el trabajo del día.
            ->orderBy('planned_end_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $paginator->through(fn (Stay $stay) => $this->serializeStay($stay));

        return Inertia::render('tenant/reservations/InHouse', [
            'property' => $property->only(['id', 'name']),
            'stays' => $paginator,
            // Las cifras son de la casa entera, no de la página ni de la
            // búsqueda: es el estado del hotel en este momento.
            'summary' => $this->summary(),
            'filters' => ['q' => $search, 'salida' => $departure, 'saldo' => $withBalance],
            'canManage' => $request->user()->can('reservations.manage'),
        ]);
    }

    protected function balanceSql(): string
    {
        return app(\App\Services\Reservations\BoardMetrics::class)->stayBalanceSql();
    }

    protected function whereDeparture(Builder $query, string $when): Builder
    {
        return app(\App\Services\Reservations\BoardMetrics::class)->whereDeparture($query, $when);
    }

    /**
     * El estado de la casa (BoardMetrics::inHouse). Con $charts trae
     * también lo que grafica /reservas/alojados/reporte.
     *
     * @return array<string, mixed>
     */
    protected function summary(bool $charts = false): array
    {
        return app(\App\Services\Reservations\BoardMetrics::class)->inHouse($charts);
    }
}
