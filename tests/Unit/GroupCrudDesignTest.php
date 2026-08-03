<?php

function groupCrudSource(string $file): string
{
    $source = file_get_contents(
        dirname(__DIR__, 2).'/resources/js/pages/tenant/groups/'.$file,
    );

    expect($source)->toBeString();

    return $source;
}

it('mantiene el listado de grupos operativo y fácil de filtrar', function () {
    $index = groupCrudSource('Index.vue');

    expect($index)
        ->toContain('Grupos activos')
        ->toContain('Por confirmar')
        ->toContain('Habitaciones en grupos')
        ->toContain('Encuentra un grupo')
        ->toContain('Responsable, folio o habitación')
        ->toContain('Reservas de grupo')
        ->toContain('Ver grupo')
        ->toContain('Editar responsable')
        ->toContain('Cancelar grupo completo')
        ->toContain('EllipsisVertical')
        ->not->toContain('>Acciones<');
});

it('guía el alta de grupo en tres pasos responsivos y conserva la lada internacional', function () {
    $index = groupCrudSource('Index.vue');

    expect($index)
        ->toContain('Nueva reserva grupal')
        ->toContain("type FormStep = 'stay' | 'rooms' | 'contact'")
        ->toContain("activeFormStep === 'stay'")
        ->toContain("activeFormStep === 'rooms'")
        ->toContain('watch(activeFormStep')
        ->toContain('ref="formScrollContainer"')
        ->toContain('h-[calc(100dvh-4.5rem)]')
        ->toContain('touch-pan-y overflow-y-auto overscroll-contain')
        ->toContain('[-webkit-overflow-scrolling:touch]')
        ->toContain('grid shrink-0 grid-cols-3')
        ->toContain("'grid-cols-2'")
        ->toContain('Paso {{ activeFormStepIndex + 1 }} de')
        ->toContain('Siguiente')
        ->toContain('Anterior')
        ->toContain('+52 · México')
        ->toContain('+1 · Estados Unidos / Canadá')
        ->toContain('Otro país · escribir lada')
        ->toContain('Se guardará como')
        ->toContain('sm:w-[94vw] lg:w-[980px]')
        ->toContain('class="h-7 w-7"');
});

it('distribuye la ficha del grupo y usa acciones con texto legible', function () {
    $show = groupCrudSource('Show.vue');

    expect($show)
        ->toContain('Habitaciones activas')
        ->toContain('Personas registradas')
        ->toContain('Saldo pendiente')
        ->toContain('Habitaciones del grupo')
        ->toContain('Editar ocupación')
        ->toContain('Cancelar esta habitación')
        ->toContain('Cobro del grupo')
        ->toContain('Copiar link de pago')
        ->toContain('Agregar habitaciones al grupo')
        ->toContain('Agregar experiencia')
        ->toContain('¿Cancelar esta experiencia?')
        ->toContain('size="lg"')
        ->toContain('class="h-7 w-7"')
        ->not->toContain('title="Editar personas"')
        ->not->toContain('icon="AlertTriangle"');
});
