<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Loader de widgets incrustables: un script que cualquier sitio (WP con
 * el plugin kuira-reservas, o un <script> pegado a mano) carga desde el
 * dominio del hotel. Busca <div data-kuira-widget="reservas|experiencias
 * |grupos"> e inyecta el wizard como iframe con alto autoajustable.
 * Los precios SIEMPRE son en vivo: el iframe habla directo con el motor.
 */
class WidgetScriptController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $origin = 'https://'.$request->getHost();

        $js = <<<JS
(function () {
    'use strict';
    var ORIGIN = '{$origin}';
    var PATHS = { reservas: '/reservar', experiencias: '/reservar/experiencias', grupos: '/reservar/grupos' };

    function mount() {
        var nodes = document.querySelectorAll('[data-kuira-widget]');
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            if (el.getAttribute('data-kuira-loaded')) continue;
            el.setAttribute('data-kuira-loaded', '1');
            var kind = el.getAttribute('data-kuira-widget');
            var iframe = document.createElement('iframe');
            iframe.src = ORIGIN + (PATHS[kind] || PATHS.reservas) + '?embed=1';
            iframe.title = 'Reservas en linea';
            iframe.loading = 'lazy';
            iframe.setAttribute('allow', 'payment');
            iframe.style.cssText = 'width:100%;border:0;display:block;min-height:760px;';
            el.appendChild(iframe);
        }
    }

    window.addEventListener('message', function (e) {
        if (e.origin !== ORIGIN || !e.data || e.data.type !== 'kuira:height') return;
        var frames = document.querySelectorAll('[data-kuira-widget] iframe');
        for (var i = 0; i < frames.length; i++) {
            if (frames[i].contentWindow === e.source) {
                frames[i].style.minHeight = Math.max(480, e.data.height) + 'px';
            }
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();
JS;

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
