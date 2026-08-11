@php
    /** Comanda de cocina del menú digital: qué preparar y a dónde va.
        Sin precios grandes — la cocina necesita items y notas, no cuentas. */
    $statusLabels = [
        'pending' => 'NUEVO',
        'preparing' => 'EN PREPARACIÓN',
        'attended' => 'DESPACHADO',
        'cancelled' => 'CANCELADO',
    ];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comanda {{ $request->id }}</title>
    <style>
        /* Mismo rollo térmico de 80 mm del ticket POS. */
        @page { size: 80mm auto; margin: 3mm; }

        body {
            font-family: "Courier New", monospace;
            font-size: 12px;
            line-height: 1.45;
            color: #000;
            background: #f1f5f9;
            margin: 0;
            padding: 16px;
        }

        .ticket {
            width: 74mm;
            margin: 0 auto;
            background: #fff;
            padding: 10px 12px 18px;
        }

        .center { text-align: center; }
        .muted { color: #444; }

        h1 { font-size: 14px; margin: 0 0 2px; text-transform: uppercase; }

        .room {
            font-size: 22px;
            font-weight: bold;
            text-align: center;
            border: 2px solid #000;
            padding: 6px;
            margin: 8px 0;
        }

        hr { border: 0; border-top: 1px dashed #000; margin: 8px 0; }

        table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 0; vertical-align: top; }
        .qty { width: 34px; font-size: 15px; font-weight: bold; }
        .item { font-size: 14px; }

        .notes {
            border: 1px dashed #000;
            padding: 6px;
            margin: 8px 0;
            font-weight: bold;
        }

        .actions { text-align: center; margin: 16px auto 0; }
        .actions button {
            font: inherit;
            padding: 8px 18px;
            cursor: pointer;
            border: 1px solid #000;
            background: #fff;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .ticket { width: auto; padding: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="center">
            <h1>Comanda de cocina</h1>
            <div class="muted">{{ $property->name }}</div>
            <div class="muted">
                Pedido {{ $request->id }} · {{ $request->created_at->format('d/m/Y H:i') }}
                · {{ $statusLabels[$request->status] ?? $request->status }}
            </div>
        </div>

        <div class="room">
            {{ $request->room_label ? 'HABITACIÓN '.$request->room_label : 'SIN HABITACIÓN' }}
        </div>

        <div class="center">{{ $request->guest_name }}</div>

        <hr>

        <table>
            @foreach ($request->items ?? [] as $item)
                <tr>
                    <td class="qty">{{ $item['qty'] }}x</td>
                    <td class="item">{{ $item['name'] }}</td>
                </tr>
            @endforeach
        </table>

        @if ($request->notes)
            <div class="notes">"{{ $request->notes }}"</div>
        @endif

        <hr>

        <div class="center muted">
            {{ $request->paymentLabel() }}
            @if ($request->preparingBy)
                · Prepara: {{ $request->preparingBy->name }}
            @endif
        </div>
    </div>

    <div class="actions">
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>

    <script>
        // La cocina entra a imprimir, no a leer: diálogo directo.
        window.addEventListener('load', () => window.print());
    </script>
</body>
</html>
