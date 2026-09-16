{{--
    Respaldo de las pantallas de error.

    Solo se ve cuando no se puede armar la página Inertia con el theme:
    subdominio de hotel desconocido, base de datos caída, o mantenimiento
    (que se sirve antes de que arranque la aplicación). Por eso lleva su
    propio CSS en línea y no depende de Vite ni de la sesión.

    El texto sale de config/error-pages.php, el mismo que usa la versión
    Vue, para que las dos digan lo mismo. Sin iconos: aquí no existe el
    componente Lucide del theme y el proyecto no admite SVG sueltos, así
    que el ancla visual es el propio número del error.
--}}
@php
    $e = \App\Support\ErrorPage::safePayload($status);
    $tones = [
        'primary' => '#03045e',
        'info' => '#0891b2',
        'success' => '#0d9488',
        'warning' => '#ca8a04',
        'pending' => '#c2410c',
        'danger' => '#b91c1c',
        'dark' => '#1e293b',
    ];
    $accent = $tones[$e['tone']] ?? $tones['dark'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $e['status'] }} · {{ $e['badge'] }} — {{ $e['appName'] }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1.25rem;
            background: linear-gradient(to bottom, #03045e, #0c4a6e);
            color: #334155;
            font-family: 'Public Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin-bottom: 1.75rem;
            color: #fff;
            font-size: 1.125rem;
            font-weight: 500;
        }
        .brand img { max-height: 2.75rem; max-width: 180px; object-fit: contain; }
        .card {
            width: 100%;
            max-width: 36rem;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: .6rem;
            box-shadow: 0 3px 5px #0000000b;
            overflow: hidden;
        }
        .card__main { padding: 2.25rem 1.5rem; text-align: center; }
        .code {
            width: 3.5rem; height: 3.5rem;
            margin: 0 auto;
            display: flex; align-items: center; justify-content: center;
            border-radius: 9999px;
            font-size: 1rem; font-weight: 600; letter-spacing: .02em;
        }
        .badge {
            display: inline-block;
            margin-top: 1rem;
            border: 1px solid rgba(226,232,240,.7);
            background: #f8fafc;
            border-radius: 9999px;
            padding: .25rem .75rem;
            font-size: 11px; font-weight: 500;
            letter-spacing: .04em; text-transform: uppercase;
            color: #64748b;
        }
        h1 {
            margin: .875rem 0 0;
            font-size: 1.125rem; font-weight: 500; line-height: 1.4;
            color: #334155;
        }
        .body { margin: .5rem auto 0; max-width: 28rem; font-size: 12px; color: #64748b; }
        .actions { margin-top: 1.5rem; display: flex; flex-wrap: wrap; gap: .5rem; justify-content: center; }
        .btn {
            display: inline-flex; align-items: center; height: 2.25rem;
            padding: 0 .875rem; border-radius: .5rem;
            font-size: 12px; font-weight: 500; text-decoration: none;
            transition: all .2s;
        }
        .btn--primary { background: #03045e; color: #fff; box-shadow: 0 4px 6px -1px rgba(3,4,94,.2); }
        .btn--primary:hover { opacity: .9; }
        .btn--ghost {
            border: 1px solid #e2e8f0; background: #fff; color: #64748b;
            border-radius: 9999px;
        }
        .btn--ghost:hover { border-color: rgba(3,4,94,.3); color: #03045e; }
        .hints {
            border-top: 1px solid rgba(226,232,240,.6);
            background: rgba(248,250,252,.7);
            padding: 1rem 1.25rem;
        }
        .hints__label {
            font-size: 11px; font-weight: 500; letter-spacing: .04em;
            text-transform: uppercase; color: #94a3b8;
        }
        .hints ul { margin: .5rem 0 0; padding-left: 1.1rem; }
        .hints li { font-size: 12px; color: #64748b; margin-top: .375rem; }
        .folio {
            border-top: 1px solid rgba(226,232,240,.6);
            padding: .875rem 1.25rem;
            display: flex; align-items: center; gap: .5rem;
            font-size: 12px; color: #94a3b8;
        }
        .folio code {
            background: #f1f5f9; color: #475569;
            border-radius: .375rem; padding: .125rem .5rem;
            font-size: 11px; font-weight: 500; letter-spacing: .08em;
        }
        .foot { margin-top: 1.5rem; font-size: 12px; color: rgba(255,255,255,.6); text-align: center; }
        @media (min-width: 640px) { .card__main { padding: 2.25rem; } }
    </style>
</head>
<body>
    <div class="brand">{{ $e['appName'] }}</div>

    <div class="card">
        <div class="card__main">
            <div class="code" style="border: 1px solid {{ $accent }}1a; background: {{ $accent }}1a; color: {{ $accent }};">
                {{ $e['status'] }}
            </div>

            <div class="badge">{{ $e['badge'] }}</div>
            <h1>{{ $e['title'] }}</h1>
            <p class="body">{{ $e['body'] }}</p>

            <div class="actions">
                <a class="btn btn--primary" href="{{ $e['home']['url'] }}">{{ $e['home']['label'] }}</a>
                @if ($e['status'] >= 500)
                    <a class="btn btn--ghost" href="">Reintentar</a>
                @endif
            </div>
        </div>

        @if (! empty($e['hints']))
            <div class="hints">
                <div class="hints__label">Qué puedes hacer</div>
                <ul>
                    @foreach ($e['hints'] as $hint)
                        <li>{{ $hint }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($e['folio'])
            <div class="folio">
                Folio del error <code>{{ $e['folio'] }}</code>
            </div>
        @endif
    </div>

    <p class="foot">{{ $e['appName'] }} — plataforma de reservas y atención para hoteles</p>
</body>
</html>
