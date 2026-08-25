<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cuenta de la habitación {{ $room }} — {{ $guest }}</title>
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
        .right { text-align: right; }
        .muted { color: #64748b; }
        .danger { color: #b91c1c; }
        .totals td { border: none; padding: 4px 8px; }
        .totals .grand { font-size: 14px; font-weight: bold; color: #03045e; border-top: 2px solid #03045e; padding-top: 8px; }
        .footer { margin-top: 28px; padding-top: 8px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 9px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Cuenta · Habitación {{ $room }}</h1>
        <div class="meta">
            {{ $property }} · {{ $guest }}
            · Entrada {{ $checkIn ?? '—' }}
            @if ($checkOut) · Salida {{ $checkOut }} @endif
            · Generada el {{ $generatedAt }}
        </div>
    </div>

    <h2>Hospedaje</h2>
    <table>
        <tbody>
            <tr>
                <td>{{ $ratePlan ?? 'Estancia' }}</td>
                <td class="right">${{ number_format((float) $folio['lodging_total'], 2) }}</td>
            </tr>
            <tr>
                <td class="muted">Pagado</td>
                <td class="right muted">-${{ number_format((float) $folio['lodging_paid'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    @if (count($folio['consumption']))
        <h2>Consumos</h2>
        <table>
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th>Cuándo</th>
                    <th>Cómo se cobró</th>
                    <th class="right">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($folio['consumption'] as $order)
                    <tr>
                        <td>{{ $order['summary'] }}</td>
                        <td class="muted">{{ $order['created_at'] }}</td>
                        <td class="muted">{{ $order['method_label'] }}{{ $order['settled'] ? '' : ' (pendiente)' }}</td>
                        <td class="right">${{ number_format((float) $order['total'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (count($folio['payments']))
        <h2>Pagos recibidos</h2>
        <table>
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th>Método</th>
                    <th>Cuándo</th>
                    <th class="right">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($folio['payments'] as $payment)
                    <tr>
                        <td>{{ $payment['kind_label'] }}</td>
                        <td class="muted">{{ $payment['method_label'] }}{{ $payment['reference'] ? ' · '.$payment['reference'] : '' }}</td>
                        <td class="muted">{{ $payment['paid_at'] }}</td>
                        <td class="right">${{ number_format((float) $payment['amount'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals" style="margin-top: 18px;">
        <tr>
            <td class="muted">Hospedaje por cobrar</td>
            <td class="right">${{ number_format((float) $folio['lodging_pending'], 2) }}</td>
        </tr>
        <tr>
            <td class="muted">Consumos por cobrar</td>
            <td class="right">${{ number_format((float) $folio['consumption_pending'], 2) }}</td>
        </tr>
        <tr>
            <td class="grand">Total por cobrar</td>
            <td class="right grand {{ $folio['grand_pending'] > 0 ? 'danger' : '' }}">
                ${{ number_format((float) $folio['grand_pending'], 2) }}
            </td>
        </tr>
    </table>

    @if ((float) $folio['guarantee_refundable'] > 0)
        <p class="muted" style="margin-top: 10px;">
            Fianza en garantía por devolver: ${{ number_format((float) $folio['guarantee_refundable'], 2) }}
        </p>
    @endif

    <div class="footer">
        {{ $property }} · Documento informativo de la cuenta, no es un comprobante fiscal.
    </div>
</body>
</html>
