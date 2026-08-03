<?php

function guestCrudSource(string $file): string
{
    $source = file_get_contents(
        dirname(__DIR__, 2).'/resources/js/pages/tenant/guests/'.$file,
    );

    expect($source)->toBeString();

    return $source;
}

it('mantiene el directorio de huéspedes ordenado y fácil de filtrar', function () {
    $index = guestCrudSource('Index.vue');

    expect($index)
        ->toContain('Directorio de huéspedes')
        ->toContain('Encuentra un huésped')
        ->toContain('Nombre, teléfono o correo')
        ->toContain('Resultados del directorio')
        ->toContain('Ver ficha')
        ->toContain('Archivar o eliminar')
        ->toContain('Eliminar definitivamente')
        ->toContain('Restaurar huésped')
        ->toContain('class="h-7 w-7"')
        ->toContain('size="lg"')
        ->not->toContain('>Acciones</Table.Th');
});

it('agrupa la alta y edición en secciones claras con lada internacional', function () {
    $modal = guestCrudSource('GuestFormModal.vue');

    expect($modal)
        ->toContain('Contacto principal')
        ->toContain('Información opcional de residencia')
        ->toContain('Documento y fotografías de respaldo')
        ->toContain('Datos para reconocerlo al ingresar')
        ->toContain('Notas y seguimiento')
        ->toContain('+52 · México')
        ->toContain('+1 · EU / Canadá')
        ->toContain('Otra lada')
        ->toContain('Se guardará como')
        ->toContain('Identificación y dirección')
        ->toContain('Vehículo y notas')
        ->toContain("activeSection === 'contact'")
        ->toContain('stepDone')
        ->toContain('Siguiente')
        ->toContain('Anterior')
        ->toContain('sm:w-[94vw] lg:w-[960px]');
});

it('distribuye la ficha del huésped entre información e historial', function () {
    $show = guestCrudSource('Show.vue');

    expect($show)
        ->toContain('Información personal')
        ->toContain('Historial de estancias')
        ->toContain('Historial de reservas')
        ->toContain('Datos para reconocerlo en el acceso')
        ->toContain("status === 'no_show' ? 'No llegó'")
        ->toContain('class="h-7 w-7');
});
