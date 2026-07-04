<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de reservas — {{ $period['label'] }}</title>
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
    <div class="header">
        <h1>Reporte de reservas — {{ $period['label'] }}</h1>
        <div class="meta">
            {{ $property['name'] }} · Del {{ $period['from'] }} al {{ $period['to'] }} · Generado el {{ $generatedAt }}
        </div>
    </div>

    <h2>Resumen</h2>
    <table class="kpis">
        <tr>
            <td><div class="value">{{ $kpis['total'] }}</div><div class="label">Reservas</div></td>
            <td><div class="value">{{ $kpis['confirmed'] + $kpis['checked_in'] + $kpis['completed'] }}</div><div class="label">Efectivas</div></td>
            <td><div class="value">{{ $kpis['cancelled'] }}</div><div class="label">Canceladas ({{ $kpis['cancel_rate'] }}%)</div></td>
            <td><div class="value">{{ $kpis['no_show'] }}</div><div class="label">No-shows ({{ $kpis['no_show_rate'] }}%)</div></td>
            <td><div class="value">{{ $kpis['check_ins'] }}</div><div class="label">Check-ins</div></td>
            <td><div class="value">{{ $kpis['check_outs'] }}</div><div class="label">Check-outs</div></td>
        </tr>
    </table>
    <table class="kpis">
        <tr>
            <td><div class="value">${{ number_format($kpis['revenue_total'], 2) }}</div><div class="label">Ingresos totales</div></td>
            <td><div class="value">${{ number_format($kpis['payments_total'], 2) }}</div><div class="label">Pagos de reservas</div></td>
            <td><div class="value">${{ number_format($kpis['orders_total'], 2) }}</div><div class="label">Ventas POS</div></td>
            <td><div class="value">${{ number_format($kpis['reserved_value'], 2) }}</div><div class="label">Valor reservado</div></td>
            <td><div class="value">${{ number_format($kpis['avg_reservation'], 2) }}</div><div class="label">Reserva promedio</div></td>
        </tr>
    </table>

    <h2>Evolución del periodo</h2>
    <table>
        <thead>
            <tr>
                <th>Periodo</th>
                <th class="right">Reservas</th>
                <th class="right">Canceladas / No-show</th>
                <th class="right">Ingresos</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($series as $bucket)
                <tr>
                    <td>{{ $bucket['label'] }}</td>
                    <td class="right">{{ $bucket['reservations'] }}</td>
                    <td class="right {{ $bucket['cancelled'] ? 'danger' : 'muted' }}">{{ $bucket['cancelled'] }}</td>
                    <td class="right">${{ number_format($bucket['revenue'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Por estado</h2>
    <table>
        <thead>
            <tr><th>Estado</th><th class="right">Reservas</th><th class="right">% del total</th></tr>
        </thead>
        <tbody>
            @forelse ($byStatus as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="right">{{ $row['count'] }}</td>
                    <td class="right">{{ $kpis['total'] > 0 ? round($row['count'] / $kpis['total'] * 100, 1) : 0 }}%</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">Sin reservas en el periodo.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Por tipo de habitación</h2>
    <table>
        <thead>
            <tr><th>Tipo</th><th class="right">Reservas</th><th class="right">Canceladas / No-show</th><th class="right">Ingresos reservados</th></tr>
        </thead>
        <tbody>
            @forelse ($byRoomType as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="right">{{ $row['total'] }}</td>
                    <td class="right {{ $row['cancelled'] ? 'danger' : 'muted' }}">{{ $row['cancelled'] }}</td>
                    <td class="right">${{ number_format($row['revenue'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Sin datos.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Por canal</h2>
    <table>
        <thead>
            <tr><th>Canal</th><th class="right">Reservas</th></tr>
        </thead>
        <tbody>
            @php
                $channelLabels = [
                    'front_desk' => 'Mostrador',
                    'phone' => 'Teléfono',
                    'web' => 'Web',
                    'whatsapp' => 'WhatsApp',
                    'walk_in' => 'Walk-in',
                ];
            @endphp
            @forelse ($byChannel as $row)
                <tr>
                    <td>{{ $channelLabels[$row['channel']] ?? $row['channel'] }}</td>
                    <td class="right">{{ $row['count'] }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="muted">Sin datos.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        KuiraReserve · {{ $property['name'] }} · Reporte generado automáticamente.
    </div>
</body>
</html>
