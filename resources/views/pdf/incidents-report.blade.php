<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de incidencias — {{ $period['label'] }}</title>
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
        .danger { color: #b91c1c; }
        .success { color: #0d9488; }
        .footer { margin-top: 24px; padding-top: 8px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $hours = fn ($h) => $h === null ? '—' : ($h >= 48 ? round($h / 24).' días' : $h.' h');
    @endphp

    <div class="header">
        <h1>Reporte de incidencias — {{ $period['label'] }}</h1>
        <div class="meta">
            {{ $property['name'] }} · Del {{ $period['from'] }} al {{ $period['to'] }} · Generado el {{ $generatedAt }}
        </div>
    </div>

    <h2>Resumen</h2>
    <table class="kpis">
        <tr>
            <td><div class="value">{{ $kpis['reported'] }}</div><div class="label">Reportadas</div></td>
            <td><div class="value">{{ $kpis['resolved'] }}</div><div class="label">Resueltas en el periodo</div></td>
            <td><div class="value">{{ $kpis['pending'] }}</div><div class="label">Siguen pendientes</div></td>
            <td><div class="value">{{ $kpis['high'] }}</div><div class="label">Alta prioridad</div></td>
            <td><div class="value">{{ $kpis['resolution_rate'] }}%</div><div class="label">Tasa de resolución</div></td>
            <td><div class="value">{{ $hours($kpis['avg_hours']) }}</div><div class="label">Tiempo promedio</div></td>
        </tr>
    </table>

    <h2>Evolución del periodo</h2>
    <table>
        <thead>
            <tr>
                <th>Periodo</th>
                <th class="right">Reportadas</th>
                <th class="right">Resueltas</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($series as $bucket)
                <tr>
                    <td>{{ $bucket['label'] }}</td>
                    <td class="right {{ $bucket['reported'] ? 'danger' : 'muted' }}">{{ $bucket['reported'] }}</td>
                    <td class="right {{ $bucket['resolved'] ? 'success' : 'muted' }}">{{ $bucket['resolved'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Por habitación</h2>
    <table>
        <thead>
            <tr>
                <th>Habitación</th>
                <th class="right">Incidencias</th>
                <th class="right">Alta prioridad</th>
                <th class="right">Resueltas</th>
                <th class="right">Tiempo promedio</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($byRoom as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="right">{{ $row['total'] }}</td>
                    <td class="right {{ $row['high'] ? 'danger' : 'muted' }}">{{ $row['high'] }}</td>
                    <td class="right">{{ $row['resolved'] }}</td>
                    <td class="right">{{ $hours($row['avg_hours']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Sin incidencias en el periodo.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Por prioridad y estado</h2>
    <table>
        <thead>
            <tr><th>Concepto</th><th class="right">Incidencias</th></tr>
        </thead>
        <tbody>
            @foreach ($byPriority as $row)
                <tr><td>Prioridad {{ strtolower($row['label']) }}</td><td class="right">{{ $row['count'] }}</td></tr>
            @endforeach
            @foreach ($byStatus as $row)
                <tr><td>{{ $row['label'] }}</td><td class="right">{{ $row['count'] }}</td></tr>
            @endforeach
            @if ($byPriority->isEmpty() && $byStatus->isEmpty())
                <tr><td colspan="2" class="muted">Sin datos.</td></tr>
            @endif
        </tbody>
    </table>

    <h2>Por tipo de falla</h2>
    <table>
        <thead>
            <tr>
                <th>Tipo</th>
                <th class="right">Incidencias</th>
                <th class="right">Alta prioridad</th>
                <th class="right">Reportó huésped</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($byCategory as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="right">{{ $row['total'] }}</td>
                    <td class="right {{ $row['high'] ? 'danger' : 'muted' }}">{{ $row['high'] }}</td>
                    <td class="right {{ $row['guest'] ? '' : 'muted' }}">{{ $row['guest'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Sin incidencias en el periodo.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        KuiraReserve · {{ $property['name'] }} · Reporte generado automáticamente.
    </div>
</body>
</html>
