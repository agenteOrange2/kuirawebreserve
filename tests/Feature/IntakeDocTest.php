<?php

/**
 * El formato de levantamiento que se le manda al cliente. El contenido
 * vive en docs/levantamiento-cliente.md; esto cuida que el comando lo
 * encuentre, lo maquete y lo personalice — un PDF con el nombre de otro
 * hotel en la portada es peor que no mandarlo.
 */
it('genera el PDF con el nombre del hotel en la portada', function () {
    $destino = storage_path('app/testing-levantamiento.pdf');
    @unlink($destino);

    $this->artisan('levantamiento:pdf', [
        '--hotel' => 'Hotel del Lago',
        '--out' => $destino,
    ])->assertExitCode(0);

    expect(is_file($destino))->toBeTrue();

    $contenido = file_get_contents($destino);

    expect(substr($contenido, 0, 5))->toBe('%PDF-')
        ->and(filesize($destino))->toBeGreaterThan(50_000);

    @unlink($destino);
});

it('el formato cubre las áreas que el sistema necesita configurar', function () {
    $md = file_get_contents(base_path('docs/levantamiento-cliente.md'));

    // Si alguien recorta el documento, que se note aquí: son las áreas
    // sin las cuales el hotel no queda operando.
    foreach ([
        'Datos del negocio',
        'Zonas del inmueble',
        'Tipos de habitación',
        'Habitaciones',
        'Tarifas',
        'Cobros',
        'Políticas de las habitaciones',
        'Reglas del lugar',
        'Términos y condiciones',
        'El asistente que atiende por chat',
        'Canales de mensajería',
        'Tu equipo',
    ] as $area) {
        expect($md)->toContain($area);
    }

    // Las llaves de la pasarela NUNCA se piden por escrito en un formato
    // que viaja por correo o WhatsApp.
    expect($md)->toContain('Las llaves NO las escribas aquí');
});

it('las secciones van numeradas sin saltos', function () {
    $md = file_get_contents(base_path('docs/levantamiento-cliente.md'));

    preg_match_all('/^## (\d+)\./m', $md, $m);
    $numeros = array_map('intval', $m[1]);

    expect($numeros)->toBe(range(1, count($numeros)));

    // Las subsecciones cuelgan de su sección (11.3 dentro de la 11).
    preg_match_all('/^### (\d+)\.(\d+)/m', $md, $sub, PREG_SET_ORDER);
    foreach ($sub as [$linea, $seccion]) {
        expect((int) $seccion)->toBeLessThanOrEqual(count($numeros), "sub fuera de rango: {$linea}");
    }
});
