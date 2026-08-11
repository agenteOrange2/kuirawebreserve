<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Corte de {{ strtolower($cut->scopeLabel()) }} #{{ $cut->id }} — {{ $cut->user?->name }}</title>
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
        .success { color: #0d9488; }
        .info-box { border: 1px solid #e2e8f0; background: #f8fafc; padding: 10px 12px; margin-top: 6px; font-size: 10px; color: #475569; }
        .signatures { width: 100%; margin-top: 48px; }
        .signatures td { border: none; width: 50%; text-align: center; padding: 0 24px; }
        .signatures .line { border-top: 1px solid #1e293b; padding-top: 6px; font-size: 10px; }
        .signatures .role { color: #64748b; font-size: 9px; margin-top: 2px; }
        .footer { margin-top: 24px; padding-top: 8px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 9px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Corte de caja · {{ $cut->scopeLabel() }} #{{ $cut->id }} — {{ $cut->user?->name ?? 'Sin encargado' }}</h1>
        <div class="meta">
            {{ $property['name'] }}
            @if ($cut->shift_id)
                · Turno #{{ $cut->shift_id }}
            @endif
            · Periodo: {{ $cut->opened_at->format('d/m/Y H:i') }} → {{ $cut->closed_at->format('d/m/Y H:i') }}
            · Generado el {{ $generatedAt }}
        </div>
    </div>

    <h2>Resumen del periodo</h2>
    <table class="kpis">
        <tr>
            <td><div class="value">${{ number_format((float) $cut->grand_total, 2) }}</div><div class="label">Total cobrado</div></td>
            @if ($cut->scope !== 'rooms')
                <td><div class="value">${{ number_format((float) $cut->orders_total, 2) }}</div><div class="label">Ventas POS ({{ $cut->orders_count }})</div></td>
            @endif
            @if ($cut->scope !== 'pos')
                <td><div class="value">${{ number_format((float) $cut->payments_total, 2) }}</div><div class="label">Cobros de reservas ({{ $cut->payments_count }})</div></td>
            @endif
        </tr>
    </table>

    <h2>Desglose por método de pago</h2>
    <table>
        <thead>
            <tr><th>Método</th><th class="right">Total</th></tr>
        </thead>
        <tbody>
            <tr><td>Efectivo</td><td class="right">${{ number_format((float) $cut->cash_total, 2) }}</td></tr>
            <tr><td>Tarjeta</td><td class="right">${{ number_format((float) $cut->card_total, 2) }}</td></tr>
            <tr><td>Transferencia</td><td class="right">${{ number_format((float) $cut->transfer_total, 2) }}</td></tr>
            <tr>
                <td style="font-weight: bold;">Total</td>
                <td class="right" style="font-weight: bold;">${{ number_format((float) $cut->grand_total, 2) }}</td>
            </tr>
        </tbody>
    </table>

    @if (count($movements))
        <h2>Movimientos del periodo</h2>
        <table>
            <thead>
                <tr><th>Hora</th><th>Concepto</th><th>Método</th><th class="right">Monto</th></tr>
            </thead>
            <tbody>
                @foreach ($movements as $m)
                    <tr>
                        <td class="muted">{{ $m['at'] }}</td>
                        <td>
                            {{ $m['concept'] }}
                            @if (!empty($m['detail']))
                                <span class="muted">— {{ $m['detail'] }}</span>
                            @endif
                        </td>
                        <td>{{ $m['method'] }}</td>
                        <td class="right {{ $m['amount'] < 0 ? 'danger' : '' }}">
                            {{ $m['amount'] < 0 ? '−' : '' }}${{ number_format(abs((float) $m['amount']), 2) }}
                            @if (!($m['collected'] ?? true) && $m['amount'] >= 0)
                                <span class="muted">*</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="info-box">* No entró a esta caja (fianza en garantía o cargo a habitación que se cobra en el check-out). La lista se reconstruye de los registros al generar el PDF.</div>
    @endif

    @if ($cut->pending_count > 0)
        <h2>Pagos pendientes al momento del corte</h2>
        <table>
            <thead>
                <tr><th>Pendiente</th><th>Detalle</th><th class="right">Saldo</th></tr>
            </thead>
            <tbody>
                @foreach ($cut->pending_items ?? [] as $p)
                    <tr>
                        <td>{{ $p['label'] ?? '' }}</td>
                        <td class="muted">{{ $p['detail'] ?? '' }}</td>
                        <td class="right">${{ number_format((float) ($p['amount'] ?? 0), 2) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td style="font-weight: bold;" colspan="2">Total pendiente ({{ $cut->pending_count }})</td>
                    <td class="right" style="font-weight: bold;">${{ number_format((float) $cut->pending_total, 2) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <h2>Arqueo de efectivo</h2>
    <table>
        <thead>
            <tr><th>Concepto</th><th class="right">Monto</th></tr>
        </thead>
        <tbody>
            @if ((float) $cut->opening_cash > 0)
                <tr><td>Fondo de caja inicial del turno</td><td class="right">${{ number_format((float) $cut->opening_cash, 2) }}</td></tr>
            @endif
            <tr><td>Efectivo esperado en cajón</td><td class="right">${{ number_format((float) $cut->expected_cash, 2) }}</td></tr>
            @if ($cut->counted_cash !== null)
                <tr><td>Efectivo contado</td><td class="right">${{ number_format((float) $cut->counted_cash, 2) }}</td></tr>
                <tr>
                    <td style="font-weight: bold;">Diferencia</td>
                    <td class="right {{ (float) $cut->difference < 0 ? 'danger' : ((float) $cut->difference > 0 ? '' : 'success') }}" style="font-weight: bold;">
                        @if ((float) $cut->difference === 0.0)
                            Cuadra
                        @elseif ((float) $cut->difference > 0)
                            Sobra ${{ number_format((float) $cut->difference, 2) }}
                        @else
                            Falta ${{ number_format(abs((float) $cut->difference), 2) }}
                        @endif
                    </td>
                </tr>
            @else
                <tr><td class="muted" colspan="2">Corte registrado sin arqueo (no se contó el efectivo).</td></tr>
            @endif
        </tbody>
    </table>

    @if ($cut->notes)
        <h2>Notas</h2>
        <div class="info-box">{{ $cut->notes }}</div>
    @endif

    <table class="signatures">
        <tr>
            <td>
                <div class="line">{{ $cut->user?->name ?? '' }}</div>
                <div class="role">Entregó (encargado del periodo)</div>
            </td>
            <td>
                <div class="line">{{ $cut->createdBy?->name ?? '' }}</div>
                <div class="role">Recibió / registró el corte</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        KuiraReserve · {{ $property['name'] }} · Corte #{{ $cut->id }} registrado el {{ $cut->created_at->format('d/m/Y H:i') }}.
    </div>
</body>
</html>
