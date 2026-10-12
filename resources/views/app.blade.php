<!DOCTYPE html>
{{-- Las pantallas de acceso van siempre en claro: el panel blanco y los
     logos de los hoteles (casi todos en tinta oscura) no tienen versión
     oscura, y mezclarlas dejaba textos y campos oscuros sobre fondo blanco. --}}
@php($__lightOnly = in_array($page['component'] ?? null, ['auth/Login', 'auth/ForgotPassword', 'auth/ResetPassword'], true))
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ! $__lightOnly && ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';
                const lightOnly = {{ $__lightOnly ? 'true' : 'false' }};

                if (appearance === 'system' && ! lightOnly) {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #f1f5f9;
            }

            html.dark {
                background-color: #0f172a;
            }
        </style>

        @php($__brandName = \App\Models\Central\PlatformSetting::get('app_name', config('app.name', 'KuiraReserve')))
        @php($__favicon = \App\Models\Central\PlatformSetting::get('favicon_path'))
        <title inertia>{{ $__brandName }}</title>

        @if ($__favicon)
        <link rel="icon" href="/storage/{{ $__favicon }}">
        @else
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @endif
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @routes
        @vite(['resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
