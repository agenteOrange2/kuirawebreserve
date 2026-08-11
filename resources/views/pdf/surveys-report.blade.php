<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de satisfacción — {{ $period['label'] }}</title>
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
        .kpis { width: 100%; margin-bottom: 4px; }
        .kpis td { border: 1px solid #e2e8f0; background: #fff !important; text-align: center; padding: 10px 6px; }
        .kpis .value { font-size: 16px; font-weight: bold; color: #03045e; }
        .kpis .label { font-size: 9px; color: #64748b; margin-top: 2px; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .danger { color: #b91c1c; }
        .footer { margin-top: 24px; padding-top: 8px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 9px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Reporte de satisfacción · {{ $period['label'] }}</h1>
        <div class="meta">
            {{ $property['name'] }}
            @if ($period['from'])
                · {{ $period['from'] }} → {{ $period['to'] }}
            @endif
            · Generado el {{ $generatedAt }}
        </div>
    </div>

    <h2>Resumen</h2>
    <table class="kpis">
        <tr>
            <td><div class="value">{{ $kpis['sent'] }}</div><div class="label">Encuestas enviadas</div></td>
            <td><div class="value">{{ $kpis['answered'] }}</div><div class="label">Respondidas</div></td>
            <td><div class="value">{{ $kpis['response_rate'] }}%</div><div class="label">Tasa de respuesta</div></td>
            <td><div class="value">{{ $kpis['avg_rating'] !== null ? $kpis['avg_rating'].' / 5' : '—' }}</div><div class="label">Calificación general</div></td>
            <td><div class="value {{ $kpis['low'] > 0 ? 'danger' : '' }}">{{ $kpis['low'] }}</div><div class="label">Evaluaciones bajas (1-2)</div></td>
        </tr>
    </table>

    <h2>Promedio por aspecto</h2>
    <table>
        <thead>
            <tr><th>Aspecto</th><th class="right">Promedio</th></tr>
        </thead>
        <tbody>
            @forelse ($aspectAverages as $aspect)
                <tr>
                    <td>{{ $aspect['label'] }}</td>
                    <td class="right">{{ $aspect['average'] !== null ? $aspect['average'].' / 5' : 'Sin respuestas' }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="muted">Sin aspectos configurados.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Distribución de calificaciones</h2>
    <table>
        <thead>
            <tr><th>Estrellas</th><th class="right">Respuestas</th></tr>
        </thead>
        <tbody>
            @foreach ($distribution as $row)
                <tr>
                    <td>{{ $row['stars'] }} estrella(s)</td>
                    <td class="right">{{ $row['count'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Comentarios del periodo</h2>
    <table>
        <thead>
            <tr><th>Fecha</th><th>Huésped</th><th>Habitación</th><th class="right">General</th><th>Comentario</th></tr>
        </thead>
        <tbody>
            @forelse (collect($responses)->filter(fn ($r) => filled($r['comment']))->take(30) as $r)
                <tr>
                    <td class="muted">{{ $r['submitted_at'] }}</td>
                    <td>{{ $r['guest'] ?? 'Anónimo' }}</td>
                    <td>{{ $r['room'] ?? '—' }}</td>
                    <td class="right {{ ($r['rating'] ?? 5) <= 2 ? 'danger' : '' }}">{{ $r['rating'] }} / 5</td>
                    <td>{{ $r['comment'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Sin comentarios en el periodo.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        KuiraReserve · {{ $property['name'] }} · Reporte de satisfacción del huésped.
    </div>
</body>
</html>
