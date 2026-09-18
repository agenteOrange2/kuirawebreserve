<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de reservas — {{ $period['label'] }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1e293b; padding: 26px 30px; }
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
        .kpis td { border: 1px solid #e2e8f0; background: #fff !important; text-align: center; padding: 8px 5px; width: 25%; }
        .kpis .value { font-size: 13px; font-weight: bold; color: #03045e; }
        .kpis .label { font-size: 8px; color: #64748b; margin-top: 2px; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .danger { color: #b91c1c; }
        .success { color: #0d9488; }
        .warning { color: #b45309; }
        .total td { background: #f1f5f9 !important; font-weight: bold; }
        .footer { margin-top: 18px; padding-top: 6px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 8px; }
        .break { page-break-before: always; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Reporte de reservas — {{ $period['label'] }}</h1>
        <div class="meta">
            {{ $property['name'] }} · Del {{ $period['from'] }} al {{ $period['to'] }}
            ({{ $period['days'] }} días) · Generado el {{ $generatedAt }}
        </div>
    </div>

    <h2>Resumen</h2>
    <div class="hint">Las reservas se cuentan por fecha de llegada; el dinero cobrado, por fecha de pago.</div>
    <table class="kpis">
        <tr>
            <td><div class="value">{{ $kpis['total'] }}</div><div class="label">Reservas que llegan ({{ $kpis['sold'] }} efectivas)</div></td>
            <td><div class="value">{{ $kpis['cancelled'] + $kpis['no_show'] }}</div><div class="label">Canceladas / no-show ({{ $kpis['cancel_rate'] }}% / {{ $kpis['no_show_rate'] }}%)</div></td>
            <td><div class="value">{{ $occupancy['percent'] }}%</div><div class="label">Uso: {{ $occupancy['occupied'] }} de {{ $occupancy['available'] }} noches</div></td>
            <td><div class="value">{{ $occupancy['uses'] }}</div><div class="label">Usos ({{ $kpis['check_ins'] }} check-ins · {{ $kpis['check_outs'] }} check-outs)</div></td>
        </tr>
    </table>
    <table class="kpis">
        <tr>
            <td><div class="value">${{ number_format($money['sold_value'], 2) }}</div><div class="label">Hospedaje vendido</div></td>
            <td><div class="value">${{ number_format($money['net'], 2) }}</div><div class="label">Ingresos cobrados (neto)</div></td>
            <td><div class="value">${{ number_format($money['reserved_pending'], 2) }}</div><div class="label">Saldo pendiente ({{ $money['with_debt'] }} reservas)</div></td>
            <td><div class="value">{{ $kpis['avg_lead_days'] }}</div><div class="label">Días de anticipación promedio</div></td>
        </tr>
    </table>

    <h2>Dinero del periodo</h2>
    <div class="hint">
        Lo vendido y lo cobrado son bases distintas a propósito: un anticipo cobrado en otro mes por una
        llegada de este rango suma al vendido de aquí y al cobrado de aquel. La fianza no es ingreso.
    </div>
    <table>
        <thead>
            <tr><th>Concepto</th><th>Base</th><th class="right">Monto</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>Hospedaje de reservas</td>
                <td class="muted">Reservas efectivas que llegan en el rango</td>
                <td class="right">${{ number_format($money['reserved'], 2) }}</td>
            </tr>
            <tr>
                <td>Walk-ins sin reserva</td>
                <td class="muted">Estancias con check-in en el rango</td>
                <td class="right">${{ number_format($money['walkin'], 2) }}</td>
            </tr>
            <tr class="total">
                <td>Hospedaje vendido</td>
                <td class="muted">Suma de los dos anteriores</td>
                <td class="right">${{ number_format($money['sold_value'], 2) }}</td>
            </tr>
            <tr>
                <td>Ya abonado de esas reservas</td>
                <td class="muted">{{ $money['reserved_paid_pct'] }}% del hospedaje de reservas</td>
                <td class="right success">${{ number_format($money['reserved_paid'], 2) }}</td>
            </tr>
            <tr>
                <td>Saldo pendiente</td>
                <td class="muted">{{ $money['with_debt'] }} reservas con adeudo</td>
                <td class="right warning">${{ number_format($money['reserved_pending'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Ingresos cobrados en el rango</h2>
    <table>
        <thead>
            <tr><th>Concepto</th><th>Qué incluye</th><th class="right">Monto</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>Hospedaje</td>
                <td class="muted">Abonos de reservas y estancias, sin fianzas</td>
                <td class="right">${{ number_format($money['lodging'], 2) }}</td>
            </tr>
            <tr>
                <td>Consumos y punto de venta</td>
                <td class="muted">Mostrador más lo cargado a habitación ya liquidado</td>
                <td class="right">${{ number_format($money['pos'], 2) }}</td>
            </tr>
            <tr>
                <td>Devoluciones</td>
                <td class="muted">Dinero que salió en el periodo</td>
                <td class="right danger">-${{ number_format($money['refunds'], 2) }}</td>
            </tr>
            <tr class="total">
                <td>Neto cobrado</td>
                <td class="muted">{{ $money['payments_count'] }} abonos registrados</td>
                <td class="right">${{ number_format($money['net'], 2) }}</td>
            </tr>
            <tr>
                <td>Fianzas en garantía</td>
                <td class="muted">No es ingreso: se devuelve al salir ({{ $money['guarantees_count'] }})</td>
                <td class="right muted">${{ number_format($money['guarantees'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Cómo se cobró</h2>
    <table>
        <thead>
            <tr><th>Método</th><th class="right">Abonos</th><th class="right">Monto</th></tr>
        </thead>
        <tbody>
            @forelse ($money['by_method'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="right">{{ $row['count'] }}</td>
                    <td class="right">${{ number_format($row['amount'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">Sin abonos en el periodo.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Porcentaje de uso por habitación</h2>
    <div class="hint">
        Noches ocupadas sobre las {{ $occupancy['days'] }} noches disponibles de cada habitación. Cuenta las
        estancias registradas y las reservas vendidas sin check-in; una habitación cuenta una vez por día
        aunque rote varias veces (la rotación se ve en los usos).
    </div>
    <table>
        <thead>
            <tr>
                <th>Habitación</th>
                <th class="right">Usos</th>
                <th class="right">Noches</th>
                <th class="right">% de uso</th>
                <th class="right">Hospedaje vendido</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($byRoom as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="right">{{ $row['uses'] }}</td>
                    <td class="right">{{ $row['nights'] }}</td>
                    <td class="right {{ $row['percent'] !== null && $row['percent'] < 25 ? 'danger' : '' }}">
                        {{ $row['percent'] === null ? '—' : $row['percent'].'%' }}
                    </td>
                    <td class="right">${{ number_format($row['revenue'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No hay habitaciones registradas.</td></tr>
            @endforelse
            <tr class="total">
                <td>Total</td>
                <td class="right">{{ collect($byRoom)->sum('uses') }}</td>
                <td class="right">{{ collect($byRoom)->sum('nights') }}</td>
                <td class="right">{{ $occupancy['percent'] }}%</td>
                <td class="right">${{ number_format(collect($byRoom)->sum('revenue'), 2) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Evolución del periodo</h2>
    <table>
        <thead>
            <tr>
                <th>Periodo</th>
                <th class="right">Reservas</th>
                <th class="right">Canceladas / No-show</th>
                <th class="right">Uso</th>
                <th class="right">Hospedaje vendido</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($series as $bucket)
                <tr>
                    <td>{{ $bucket['label'] }}</td>
                    <td class="right">{{ $bucket['reservations'] }}</td>
                    <td class="right {{ $bucket['cancelled'] ? 'danger' : 'muted' }}">{{ $bucket['cancelled'] }}</td>
                    <td class="right">{{ $bucket['occupancy'] }}%</td>
                    <td class="right">${{ number_format($bucket['sold_value'], 2) }}</td>
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
            <tr><th>Tipo</th><th class="right">Reservas</th><th class="right">Canceladas / No-show</th><th class="right">Vendido</th></tr>
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
    @php
        $channelLabels = [
            'front_desk' => 'Mostrador',
            'counter' => 'Mostrador',
            'phone' => 'Teléfono',
            'web' => 'Sitio web',
            'whatsapp' => 'WhatsApp',
            'agent' => 'Asistente',
            'walk_in' => 'Sin reserva',
        ];
    @endphp
    <table>
        <thead>
            <tr><th>Canal</th><th class="right">Reservas</th></tr>
        </thead>
        <tbody>
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

    <div class="break"></div>

    <h2>Anticipación con la que se reservó</h2>
    <div class="hint">Días completos entre el día en que se capturó la reserva y el día de llegada. Promedio: {{ $kpis['avg_lead_days'] }} días.</div>
    <table>
        <thead>
            <tr><th>Anticipación</th><th class="right">Reservas</th></tr>
        </thead>
        <tbody>
            @foreach ($lead as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="right">{{ $row['count'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Estado de pago</h2>
    <table>
        <thead>
            <tr><th>Estado</th><th class="right">Reservas</th><th class="right">Valor</th><th class="right">Falta</th></tr>
        </thead>
        <tbody>
            @foreach ($paymentStatus as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="right">{{ $row['count'] }}</td>
                    <td class="right">${{ number_format($row['value'], 2) }}</td>
                    <td class="right {{ $row['pending'] > 0 ? 'warning' : 'muted' }}">${{ number_format($row['pending'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Detalle: cuándo se reservó y si se pagó</h2>
    <div class="hint">{{ count($detail) }} reservas, primero las que deben dinero.</div>
    <table>
        <thead>
            <tr>
                <th>Folio</th>
                <th>Huésped</th>
                <th>Habitación</th>
                <th>Se reservó</th>
                <th>Antic.</th>
                <th>Estancia</th>
                <th>Estado</th>
                <th>Pago</th>
                <th class="right">Total</th>
                <th class="right">Pagado</th>
                <th class="right">Saldo</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($detail as $row)
                <tr>
                    <td>{{ $row['code'] }}</td>
                    <td>{{ $row['guest'] }}</td>
                    <td>{{ $row['room'] }}</td>
                    <td>{{ $row['created_at'] }}</td>
                    <td>{{ $row['lead_days'] === null ? '—' : $row['lead_days'].' d' }}</td>
                    <td>{{ $row['starts_at'] }} – {{ $row['ends_at'] }}</td>
                    <td>{{ $row['status_label'] }}</td>
                    <td>{{ $row['payment_label'] }}</td>
                    <td class="right">${{ number_format($row['total'], 2) }}</td>
                    <td class="right success">${{ number_format($row['paid'], 2) }}</td>
                    <td class="right {{ $row['pending'] > 0 ? 'warning' : 'muted' }}">${{ number_format($row['pending'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="muted">Sin reservas en el periodo.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        KuiraReserve · {{ $property['name'] }} · Contabilidad del reporte: la fianza no es ingreso, lo cargado
        a habitación suma una sola vez al liquidarse en el folio y las devoluciones se restan.
    </div>
</body>
</html>
