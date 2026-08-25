<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de limpieza — {{ $periodLabel }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; padding: 28px 32px; }
        .header { border-bottom: 3px solid #03045e; padding-bottom: 12px; margin-bottom: 18px; }
        .header h1 { font-size: 18px; color: #03045e; }
        .header .meta { margin-top: 4px; color: #64748b; font-size: 10px; }
        h2 { font-size: 13px; color: #03045e; margin: 18px 0 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #03045e; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; }
        td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        tr:nth-child(even) td { background: #f8fafc; }
        .kpis { width: 100%; margin-bottom: 4px; }
        .kpis td { border: 1px solid #e2e8f0; background: #fff !important; text-align: center; padding: 10px 6px; }
        .kpis .value { font-size: 16px; font-weight: bold; color: #03045e; }
        .kpis .label { font-size: 9px; color: #64748b; margin-top: 2px; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .footer { margin-top: 24px; padding-top: 8px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $minutes = fn ($m) => $m === null
            ? '—'
            : ($m < 60 ? $m.' min' : intdiv($m, 60).' h '.($m % 60).' min');
    @endphp

    <div class="header">
        <h1>Reporte de limpieza — {{ $periodLabel }}</h1>
        <div class="meta">
            {{ $property['name'] }} · Del {{ $filters['from'] }} al {{ $filters['to'] }} · Generado el {{ $generatedAt }}
        </div>
    </div>

    <h2>Resumen</h2>
    <table class="kpis">
        <tr>
            <td>
                <div class="value">{{ $kpis['rooms'] }}</div>
                <div class="label">Habitaciones limpiadas</div>
            </td>
            <td>
                <div class="value">{{ $minutes($kpis['avg_minutes']) }}</div>
                <div class="label">Tiempo promedio</div>
            </td>
            <td>
                <div class="value">{{ $kpis['total_hours'] }} h</div>
                <div class="label">Horas trabajadas</div>
            </td>
            <td>
                <div class="value">{{ $minutes($kpis['turnaround']['avg_total']) }}</div>
                <div class="label">Vuelta a vendible</div>
            </td>
        </tr>
    </table>

    <h2>Por camarista</h2>
    @if (empty($byHousekeeper))
        <p class="muted">Sin limpiezas registradas en este periodo.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Camarista</th>
                    <th class="right">Habitaciones</th>
                    <th class="right">Promedio</th>
                    <th class="right">Más rápida</th>
                    <th class="right">Más lenta</th>
                    <th class="right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($byHousekeeper as $row)
                    <tr>
                        <td>
                            {{ $row['name'] }}
                            @if (! empty($row['linens']))
                                <div class="muted" style="font-size: 9px; margin-top: 2px;">
                                    @foreach ($row['linens'] as $linen)
                                        {{ $linen['label'] }}: {{ $linen['total'] }}@if (! $loop->last) · @endif
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="right">{{ $row['rooms'] }}</td>
                        <td class="right">{{ $row['avg_minutes'] }} min</td>
                        <td class="right muted">{{ $row['fastest'] }} min</td>
                        <td class="right muted">{{ $row['slowest'] }} min</td>
                        <td class="right">{{ $minutes($row['total_minutes']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Ropa consumida</h2>
    @if (empty($linens))
        <p class="muted">No se registró consumo de ropa en el periodo.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Insumo</th>
                    <th class="right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($linens as $linen)
                    <tr>
                        <td>{{ $linen['label'] }}</td>
                        <td class="right">{{ $linen['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Tipo de limpieza</h2>
    <table>
        <thead>
            <tr>
                <th>Tipo</th>
                <th class="right">Habitaciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($byKind as $kind)
                <tr>
                    <td>{{ $kind['label'] }}</td>
                    <td class="right">{{ $kind['count'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Vuelta a vendible</h2>
    <p class="muted">
        Desde que el huésped desocupa hasta que la habitación vuelve a estar disponible.
        Incluye la espera antes de que alguien la tome y los ciclos que liberó el sistema por tiempo.
    </p>
    <table style="margin-top: 8px;">
        <tbody>
            <tr>
                <td>Espera antes de limpiar</td>
                <td class="right">{{ $minutes($kpis['turnaround']['avg_wait']) }}</td>
            </tr>
            <tr>
                <td>Ciclo completo</td>
                <td class="right">{{ $minutes($kpis['turnaround']['avg_total']) }}</td>
            </tr>
            <tr>
                <td class="muted">Ciclos medidos</td>
                <td class="right muted">{{ $kpis['turnaround']['samples'] }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        {{ $property['name'] }} · Reporte generado por KuiraWebReserve
    </div>
</body>
</html>
