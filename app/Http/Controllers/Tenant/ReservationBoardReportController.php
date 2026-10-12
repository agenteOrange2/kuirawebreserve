<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\Reservations\BoardMetrics;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los reportes de las pantallas de trabajo de reservas
 * (/reservas/<pantalla>/reporte). Las gráficas vivían dentro de la lista y
 * el dueño pidió sacarlas: la pantalla de trabajo es la tabla, y la
 * analítica tiene su propia página. Cada rebanada o barra del reporte
 * regresa a la lista ya filtrada.
 *
 * Las cifras salen de BoardMetrics, el mismo corte que la franja de cada
 * lista: si la lista dice "3 por vencer", la dona dice 3.
 */
class ReservationBoardReportController extends Controller
{
    public function __construct(protected BoardMetrics $metrics) {}

    public function operation(): Response
    {
        return Inertia::render('tenant/reservations/reports/OperationReport', [
            'property' => $this->property(),
            'day' => $this->metrics->dayProgress(),
            'roomStatuses' => $this->metrics->roomStatuses(),
            'inHouse' => $this->metrics->inHouse(),
            'upcoming' => $this->metrics->upcoming(),
            'pending' => $this->metrics->pending(),
        ]);
    }

    public function upcoming(): Response
    {
        return Inertia::render('tenant/reservations/reports/UpcomingReport', [
            'property' => $this->property(),
            'summary' => $this->metrics->upcoming(charts: true),
        ]);
    }

    public function inHouse(): Response
    {
        return Inertia::render('tenant/reservations/reports/InHouseReport', [
            'property' => $this->property(),
            'summary' => $this->metrics->inHouse(charts: true),
        ]);
    }

    public function pending(): Response
    {
        return Inertia::render('tenant/reservations/reports/PendingReport', [
            'property' => $this->property(),
            'summary' => $this->metrics->pending(charts: true),
            'holdMinutes' => app(\App\Services\ReservationPolicy::class)->holdMinutes(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function property(): array
    {
        return Property::firstOrFail()->only(['id', 'name']);
    }
}
