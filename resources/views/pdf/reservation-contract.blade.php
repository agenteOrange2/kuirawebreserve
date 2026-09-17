<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Contrato de hospedaje {{ $reservation->displayCode() }} — {{ $hotel['name'] }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; padding: 28px 32px; line-height: 1.45; }
        .header { border-bottom: 3px solid #03045e; padding-bottom: 12px; margin-bottom: 18px; }
        .header h1 { font-size: 18px; color: #03045e; }
        .header .meta { margin-top: 4px; color: #64748b; font-size: 10px; }
        h2 { font-size: 13px; color: #03045e; margin: 18px 0 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #03045e; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; }
        td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        td.label { width: 34%; color: #64748b; }
        .right { text-align: right; }
        .muted { color: #64748b; }
        .totals td { border: none; padding: 4px 8px; }
        .totals .grand { font-size: 13px; font-weight: bold; color: #03045e; border-top: 2px solid #03045e; padding-top: 8px; }
        .rules p { margin: 6px 0; }
        .rules ul { margin: 4px 0 10px 16px; }
        .rules li { margin: 3px 0; }
        .sign { margin-top: 26px; padding-top: 10px; border-top: 1px dashed #cbd5e1; }
        .footer { margin-top: 22px; padding-top: 8px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 9px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Contrato de hospedaje · {{ $reservation->displayCode() }}</h1>
        <div class="meta">
            {{ $hotel['name'] }}
            @if ($hotel['address']) · {{ $hotel['address'] }} @endif
            @if ($hotel['phone']) · Tel. {{ $hotel['phone'] }} @endif
            @if ($hotel['email']) · {{ $hotel['email'] }} @endif
        </div>
    </div>

    <h2>Datos de la reserva</h2>
    <table>
        <tbody>
            <tr>
                <td class="label">Huésped</td>
                <td>
                    {{ $guest['name'] ?: 'Sin nombre' }}
                    @if ($guest['phone']) <span class="muted">· {{ $guest['phone'] }}</span> @endif
                    @if ($guest['email']) <span class="muted">· {{ $guest['email'] }}</span> @endif
                </td>
            </tr>
            <tr>
                <td class="label">Alojamiento</td>
                <td>
                    {{ $reservation->roomType?->name ?? 'Habitación' }}
                    @if ($reservation->room?->number) <span class="muted">· Hab. {{ $reservation->room->number }}</span> @endif
                </td>
            </tr>
            <tr>
                <td class="label">Llegada</td>
                <td>{{ $reservation->starts_at->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY, HH:mm') }}</td>
            </tr>
            <tr>
                <td class="label">Salida</td>
                <td>{{ $reservation->ends_at->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY, HH:mm') }}</td>
            </tr>
            <tr>
                <td class="label">Personas</td>
                <td>{{ $reservation->num_people }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Importes</h2>
    <table class="totals">
        <tbody>
            <tr>
                <td>Total de la estancia</td>
                <td class="right">${{ number_format($money['total'], 2) }}</td>
            </tr>
            @if ($money['deposit'] > 0)
                <tr>
                    <td>Anticipo para apartar</td>
                    <td class="right">${{ number_format($money['deposit'], 2) }}</td>
                </tr>
            @endif
            <tr>
                <td>Pagado a la fecha</td>
                <td class="right">${{ number_format($money['paid'], 2) }}</td>
            </tr>
            <tr class="grand">
                <td>Saldo pendiente</td>
                <td class="right">${{ number_format($money['pending'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Condiciones</h2>
    <div class="rules">
        @foreach ($blocks as $block)
            @if ($block['type'] === 'heading')
                <h2>{{ $block['text'] }}</h2>
            @elseif ($block['type'] === 'list')
                <ul>
                    @foreach ($block['items'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            @else
                <p>{{ $block['text'] }}</p>
            @endif
        @endforeach
    </div>

    <div class="sign">
        <p><strong>Aceptación.</strong> Este contrato se genera al confirmarse la reserva
            {{ $reservation->displayCode() }} y se envía al huésped junto con su comprobante.
            Al presentarse al alojamiento, el huésped acepta las condiciones aquí descritas.</p>
        <p class="muted">Nombre de quien recibe el alojamiento: ____________________________________________
            &nbsp;&nbsp; Firma: ____________________</p>
    </div>

    <div class="footer">
        Documento generado el {{ $generatedAt }} · {{ $hotel['name'] }} · {{ $reservation->displayCode() }}
    </div>
</body>
</html>
