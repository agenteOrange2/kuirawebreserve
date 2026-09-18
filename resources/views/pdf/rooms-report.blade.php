<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de habitaciones — {{ $filters['label'] }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1e293b; padding: 24px 28px; }
        .header { border-bottom: 3px solid #03045e; padding-bottom: 10px; margin-bottom: 14px; }
        .header h1 { font-size: 16px; color: #03045e; }
        .header .meta { margin-top: 4px; color: #64748b; font-size: 9px; }
        h2 { font-size: 12px; color: #03045e; margin: 16px 0 6px; }
        .hint { color: #64748b; font-size: 9px; margin-bottom: 6px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #03045e; color: #fff; text-align: left; padding: 5px 7px; font-size: 9px; }
        td { padding: 5px 7px; border-bottom: 1px solid #e2e8f0; }
        tr:nth-child(even) td { background: #f8fafc; }
        .kpis { width: 100%; margin-bottom: 4px; }
        .kpis td { border: 1px solid #e2e8f0; background: #fff !important; text-align: center; padding: 8px 5px; width: 16.6%; }
        .kpis .value { font-size: 13px; font-weight: bold; color: #03045e; }
        .kpis .label { font-size: 8px; color: #64748b; margin-top: 2px; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .danger { color: #b91c1c; }
        .success { color: #0d9488; }
        .warning { color: #b45309; }
        .total td { background: #f1f5f9 !important; font-weight: bold; }
        .footer { margin-top: 18px; padding-top: 6px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Reporte de habitaciones — {{ $filters['label'] }}</h1>
        <div class="meta">
            {{ $property['name'] }} · Del {{ $filters['from'] }} al {{ $filters['to'] }}
            ({{ $summary['days'] }} días · {{ $summary['rooms'] }} habitaciones) ·
            Generado el {{ $generatedAt }}
        </div>
    </div>

    <h2>Resumen del periodo</h2>
    <div class="hint">
        El dinero es el hospedaje VENDIDO de las rentas que entraron en el periodo,
        no lo cobrado: quién pagó y cuándo vive en los cortes de caja.
    </div>
    <table class="kpis">
        <tr>
            <td><div class="value">{{ $summary['uses'] }}</div><div class="label">Rentas registradas</div></td>
            <td><div class="value">{{ $summary['percent'] }}%</div><div class="label">De uso ({{ $summary['nights'] }} de {{ $summary['available'] }} noches)</div></td>
            <td><div class="value">{{ $summary['revenue_label'] }}</div><div class="label">Hospedaje vendido</div></td>
            <td><div class="value">{{ $summary['adr_label'] }}</div><div class="label">Tarifa promedio por noche</div></td>
            <td><div class="value">{{ $summary['avg_stay_label'] }}</div><div class="label">Dura cada renta</div></td>
            <td><div class="value">{{ $summary['idle_rooms'] }}</div><div class="label">Habitaciones sin rentarse</div></td>
        </tr>
    </table>

    <h2>Habitación por habitación</h2>
    <table>
        <thead>
            <tr>
                <th>Habitación</th>
                <th>Tipo</th>
                <th class="right">Rentas</th>
                <th class="right">Noches</th>
                <th class="right">Uso</th>
                <th class="right">Hospedaje</th>
                <th class="right">Consumos</th>
                <th class="right">Tarifa</th>
                <th>Dura</th>
                <th class="right">Incidencias</th>
                <th class="right">Días fuera</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rooms as $room)
                <tr>
                    <td>{{ $room['name'] }}</td>
                    <td class="muted">{{ $room['type'] }}{{ $room['zone'] ? ' · '.$room['zone'] : '' }}</td>
                    <td class="right">{{ $room['uses'] }}</td>
                    <td class="right">{{ $room['nights'] }}</td>
                    <td class="right">{{ $room['percent'] }}%</td>
                    <td class="right">${{ number_format($room['revenue'], 2) }}</td>
                    <td class="right muted">${{ number_format($room['consumos'], 2) }}</td>
                    <td class="right">${{ number_format($room['adr'], 2) }}</td>
                    <td class="muted">{{ $room['avg_stay_label'] }}</td>
                    <td class="right {{ $room['incidents_open'] ? 'danger' : '' }}">{{ $room['incidents'] }}</td>
                    <td class="right {{ $room['out_of_service_days'] ? 'warning' : '' }}">{{ $room['out_of_service_days'] }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="2">Total</td>
                <td class="right">{{ $summary['uses'] }}</td>
                <td class="right">{{ $summary['nights'] }}</td>
                <td class="right">{{ $summary['percent'] }}%</td>
                <td class="right">{{ $summary['revenue_label'] }}</td>
                <td class="right">{{ $summary['consumos_label'] }}</td>
                <td class="right">{{ $summary['adr_label'] }}</td>
                <td>{{ $summary['avg_stay_label'] }}</td>
                <td class="right">{{ $summary['incidents'] }}</td>
                <td class="right">{{ $summary['out_of_service_days'] }}</td>
            </tr>
        </tbody>
    </table>

    @if (count($idle))
        <h2>Dinero dormido</h2>
        <div class="hint">Habitaciones que no se rentaron ni una vez en el periodo.</div>
        <table>
            <thead>
                <tr>
                    <th>Habitación</th>
                    <th>Tipo</th>
                    <th>Estado hoy</th>
                    <th class="right">Días fuera de servicio</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($idle as $room)
                    <tr>
                        <td>{{ $room['name'] }}</td>
                        <td class="muted">{{ $room['type'] }}</td>
                        <td class="muted">{{ $room['status_label'] }}</td>
                        <td class="right">{{ $room['out_of_service_days'] ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Por tipo de habitación</h2>
    <table>
        <thead>
            <tr>
                <th>Tipo</th>
                <th class="right">Habitaciones</th>
                <th class="right">Rentas</th>
                <th class="right">Noches</th>
                <th class="right">Uso promedio</th>
                <th class="right">Hospedaje vendido</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($types as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="right">{{ $row['rooms'] }}</td>
                    <td class="right">{{ $row['uses'] }}</td>
                    <td class="right">{{ $row['nights'] }}</td>
                    <td class="right">{{ $row['percent'] }}%</td>
                    <td class="right">{{ $row['revenue_label'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Mantenimiento y limpieza</h2>
    <div class="hint">
        {{ $summary['incidents'] }} incidencias ({{ $summary['incidents_open'] }} sin resolver) ·
        {{ $summary['incident_cost_label'] }} en reparaciones ·
        {{ $maintenance['avg_resolution_label'] }} en resolverse ·
        {{ $summary['cleanings'] }} limpiezas ({{ $summary['avg_cleaning_label'] }} cada una)
    </div>
    @if (count($maintenance['categories']))
        <table>
            <thead>
                <tr>
                    <th>Tipo de falla</th>
                    <th class="right">Veces</th>
                    <th class="right">Costo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($maintenance['categories'] as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="right">{{ $row['count'] }}</td>
                        <td class="right">{{ $row['cost_label'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="hint">No se levantó ninguna incidencia de estas habitaciones en el periodo.</div>
    @endif

    <div class="footer">
        {{ $property['name'] }} · Reporte de habitaciones generado el {{ $generatedAt }} desde el panel.
    </div>
</body>
</html>
