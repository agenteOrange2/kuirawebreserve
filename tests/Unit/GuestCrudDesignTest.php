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
        // El buscador y la lista se unieron en una caja: el encabezado de
        // los resultados nombra la lista (o los archivados) con el total al
        // lado, en vez de "Resultados del directorio".
        ->toContain("archived ? 'Huéspedes archivados' : 'Huéspedes'")
        ->toContain('{{ guests.total }}')
        // Las cifras de arriba son filtros y la lista se puede ordenar y
        // bajar en CSV: el directorio también sirve para trabajarlo, no
        // solo para consultarlo.
        ->toContain('applyCard(')
        ->toContain('Exportar CSV')
        ->toContain('Más han dejado')
        ->toContain('Con llegada')
        ->toContain('Ver ficha')
        ->toContain('Archivar o eliminar')
        ->toContain('Eliminar definitivamente')
        ->toContain('Restaurar huésped')
        // El directorio dejó de ser tabla con botones de icono (de ahí el
        // viejo h-7 w-7): hoy es una lista de tarjetas con las acciones
        // escritas, que es lo que estas mismas expectativas comprueban
        // arriba. Lo que se conserva es que no haya una columna "Acciones"
        // muda y que los diálogos sean grandes.
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
        ->toContain('Datos para reconocerlo en el acceso')
        // UN solo historial: la reserva y su estancia son la misma noche,
        // no dos tablas donde la visita normal salía repetida.
        ->toContain('Historial del huésped')
        ->toContain('Próximas')
        ->toContain('group.year')
        ->not->toContain('Historial de estancias')
        ->not->toContain('Historial de reservas');
});
