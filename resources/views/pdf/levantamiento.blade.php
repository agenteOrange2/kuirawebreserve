<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        @page { margin: 90px 42px 60px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1e293b; line-height: 1.5; }

        /* Cabecera y pie fijos en todas las páginas */
        header {
            position: fixed; top: -60px; left: 0; right: 0; height: 42px;
            border-bottom: 2px solid #03045e; padding-bottom: 6px;
        }
        header .marca { font-size: 12px; font-weight: bold; color: #03045e; }
        header .hotel { float: right; font-size: 10px; color: #64748b; padding-top: 3px; }
        footer {
            position: fixed; bottom: -40px; left: 0; right: 0; height: 30px;
            border-top: 1px solid #e2e8f0; padding-top: 6px;
            color: #94a3b8; font-size: 8.5px;
        }
        footer .pagina:after { content: counter(page); }

        /* Portada */
        /* Sin page-break-after: el primer h2 ya rompe página y saldría
           una hoja en blanco entre la portada y la sección 1. */
        .portada { padding-top: 90px; }
        .portada h1 { font-size: 30px; color: #03045e; line-height: 1.15; }
        .portada .bajada { margin-top: 10px; font-size: 13px; color: #475569; }
        .portada .para {
            margin-top: 34px; padding: 16px 18px;
            border: 1px solid #e2e8f0; border-left: 4px solid #03045e; border-radius: 4px;
        }
        .portada .para .etiqueta { font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.06em; }
        .portada .para .nombre { font-size: 18px; font-weight: bold; color: #03045e; margin-top: 3px; }
        .portada .pie { position: absolute; bottom: 40px; font-size: 9.5px; color: #94a3b8; }

        h1 { font-size: 17px; color: #03045e; }
        h2 {
            font-size: 14px; color: #03045e; margin: 0 0 10px;
            padding-bottom: 5px; border-bottom: 2px solid #03045e;
            page-break-before: always; page-break-after: avoid;
        }
        h3 {
            font-size: 11.5px; color: #334155; margin: 16px 0 6px;
            page-break-after: avoid;
        }
        p { margin: 0 0 9px; }
        ul { margin: 0 0 10px 16px; }
        li { margin-bottom: 3px; }
        strong { color: #0f172a; }
        code { font-size: 9.5px; background: #f1f5f9; padding: 1px 3px; border-radius: 2px; }

        table {
            width: 100%; border-collapse: collapse; margin: 6px 0 12px;
            page-break-inside: auto;
        }
        tr { page-break-inside: avoid; }
        th {
            background: #03045e; color: #fff; text-align: left;
            padding: 6px 7px; font-size: 9px; font-weight: bold;
        }
        td {
            padding: 7px; border: 1px solid #cbd5e1; height: 28px;
            vertical-align: top; font-size: 10px;
        }
        /* La primera columna es la pregunta; la segunda, el espacio para escribir */
        td:first-child { background: #f8fafc; width: 46%; }
        table.libre td:first-child { background: #fff; width: auto; }

        blockquote {
            margin: 10px 0; padding: 9px 12px;
            background: #fffbeb; border-left: 3px solid #f59e0b;
            font-size: 9.5px; color: #78350f;
        }
        blockquote p { margin: 0; }

        hr { display: none; }
    </style>
</head>
<body>
    <header>
        <span class="marca">{{ $marca }}</span>
        <span class="hotel">{{ $hotel }}</span>
    </header>

    <footer>
        {{ $marca }} · Levantamiento de información · Página <span class="pagina"></span>
    </footer>

    <div class="portada">
        <h1>Levantamiento<br>de información</h1>
        <div class="bajada">
            Lo que necesitamos de tu hotel o motel para dejarlo operando.
        </div>

        <div class="para">
            <div class="etiqueta">Preparado para</div>
            <div class="nombre">{{ $hotel }}</div>
        </div>

        <p style="margin-top: 34px; font-size: 11px; color: #475569;">
            Este formato se llena una sola vez. Con él configuramos el sistema completo:
            habitaciones, tarifas, cobros, políticas y el asistente que atiende a tus
            clientes por chat.
        </p>
        <p style="font-size: 11px; color: #475569;">
            Contesta lo que aplique a tu negocio. Si algo no aplica, escribe
            <strong>No aplica</strong>: es una respuesta válida y nos ahorra la ida y vuelta.
            Lo marcado como <strong>(requerido)</strong> es lo mínimo para arrancar.
        </p>

        <div class="pie">Generado el {{ $fecha }}</div>
    </div>

    {!! $contenido !!}
</body>
</html>
