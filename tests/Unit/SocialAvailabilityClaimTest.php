<?php

use App\Services\Social\SocialCommentClassifier;

// Caso real cabañas 2026-09-14 (comentario 642): a un comentario de Facebook
// se le contestó "varias cabañas disponibles este fin de semana" con el hotel
// lleno. Este servicio no tiene herramientas: NO puede consultar el
// calendario, así que cualquier disponibilidad que afirme es inventada.

function sinInventos(string $texto): string
{
    $clasificador = (new ReflectionClass(SocialCommentClassifier::class))->newInstanceWithoutConstructor();

    return (fn () => $this->withoutAvailabilityClaims($texto))->call($clasificador);
}

it('quita la disponibilidad inventada y deja la pregunta que sí corresponde', function (string $texto) {
    $salida = sinInventos($texto);

    expect($salida)->toContain('¿Para qué fechas')
        ->and($salida)->not->toContain('disponibles este')
        ->and($salida)->not->toContain('Sí hay lugar');
})->with([
    'Hola, tenemos varias cabañas disponibles este fin de semana. Te esperamos.',
    'Sí hay lugar para esas fechas. Escríbenos por inbox.',
    'Aún hay cabañas libres para el sábado.',
]);

it('respeta lo que no afirma disponibilidad', function (string $texto) {
    expect(sinInventos($texto))->toBe($texto);
})->with([
    'La Cabaña Real cuesta $4,500 por noche e incluye 6 personas.',
    'Gracias por escribirnos, con gusto te ayudamos por inbox.',
    // Preguntar no es afirmar.
    '¿Para qué fechas buscas? Con gusto reviso si tenemos lugar.',
]);
