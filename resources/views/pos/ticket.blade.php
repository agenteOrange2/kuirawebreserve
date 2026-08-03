@php
    $money = fn ($n) => '$' . number_format((float) $n, 2);
    $methods = [
        'cash' => 'Efectivo',
        'card' => 'Tarjeta',
        'transfer' => 'Transferencia',
        'room' => 'Cargo a habitación',
    ];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ticket {{ $order->id }}</title>
    <style>
        /* Ancho de rollo térmico de 80 mm; en pantalla se ve centrado. */
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
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .muted { color: #444; }

        h1 { font-size: 14px; margin: 0 0 2px; text-transform: uppercase; }

        hr {
            border: 0;
            border-top: 1px dashed #000;
            margin: 8px 0;
        }

        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        .qty { width: 28px; }
        .amount { width: 62px; text-align: right; white-space: nowrap; }

        .totals td { padding: 1px 0; }
        .grand { font-size: 14px; font-weight: bold; border-top: 1px solid #000; padding-top: 4px; }

        .void {
            border: 2px solid #000;
            padding: 6px;
            margin: 8px 0;
            text-align: center;
            font-weight: bold;
            text-transform: uppercase;
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
            <h1>{{ $property->name }}</h1>
            @if ($property->address)
                <div class="muted">{{ $property->address }}</div>
            @endif
            @if ($phone)
                <div class="muted">Tel. {{ $phone }}</div>
            @endif
        </div>

        <hr>

        <table>
            <tr>
                <td>Folio</td>
                <td class="right bold">#{{ $order->id }}</td>
            </tr>
            <tr>
                <td>Fecha</td>
                <td class="right">{{ $order->created_at->format('d/m/Y H:i') }}</td>
            </tr>
            @if ($order->createdBy)
                <tr>
                    <td>Le atendió</td>
                    <td class="right">{{ $order->createdBy->name }}</td>
                </tr>
            @endif
            @if ($order->stay?->room)
                <tr>
                    <td>Habitación</td>
                    <td class="right bold">{{ $order->stay->room->number }}</td>
                </tr>
            @endif
        </table>

        @if ($order->isVoid())
            <div class="void">
                Venta cancelada
                @if ($order->void_reason)
                    <div style="font-weight: normal; text-transform: none;">{{ $order->void_reason }}</div>
                @endif
            </div>
        @endif

        <hr>

        <table>
            @foreach ($order->lines as $line)
                <tr>
                    <td class="qty">{{ rtrim(rtrim(number_format((float) $line->qty, 3, '.', ''), '0'), '.') }}×</td>
                    <td>{{ $line->product?->name ?? 'Producto' }}</td>
                    <td class="amount">{{ $money($line->total) }}</td>
                </tr>
                <tr>
                    <td></td>
                    <td class="muted" colspan="2">{{ $money($line->unit_price) }} c/u</td>
                </tr>
            @endforeach
        </table>

        <hr>

        <table class="totals">
            <tr>
                <td>Subtotal</td>
                <td class="amount">{{ $money($order->subtotal) }}</td>
            </tr>
            @if ((float) $order->discount > 0)
                <tr>
                    <td>Descuento{{ $order->discount_reason ? ' · ' . $order->discount_reason : '' }}</td>
                    <td class="amount">-{{ $money($order->discount) }}</td>
                </tr>
            @endif
            @if ((float) $order->tip > 0)
                <tr>
                    <td>Propina</td>
                    <td class="amount">{{ $money($order->tip) }}</td>
                </tr>
            @endif
            <tr class="grand">
                <td>TOTAL</td>
                <td class="amount">{{ $money($order->total) }}</td>
            </tr>
        </table>

        <hr>

        <table>
            <tr>
                <td>Pago</td>
                <td class="right">{{ $methods[$order->payment_method] ?? $order->payment_method }}</td>
            </tr>
            @if ($order->payment_reference)
                <tr>
                    <td>Referencia</td>
                    <td class="right">{{ $order->payment_reference }}</td>
                </tr>
            @endif
        </table>

        @if ($order->payment_method === 'room')
            <p class="center muted">Este consumo se cobra al registrar la salida.</p>
        @endif

        <p class="center">¡Gracias por su visita!</p>
    </div>

    <div class="actions">
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>

    <script>
        // El mostrador entra a imprimir, no a leer: se abre el diálogo solo.
        window.addEventListener('load', () => window.print());
    </script>
</body>
</html>
