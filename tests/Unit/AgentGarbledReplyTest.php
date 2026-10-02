<?php

use App\Services\Agent\AgentBrain;

// 14 mensajes con basura del modelo en 2.5 días de cabañas (13 al 15 de
// septiembre). El huésped no tiene por qué ver marcadores sin llenar,
// palabras pegadas ni idiomas que este hotel no habla.

function esBasura(string $texto, bool $enEspanol = true): bool
{
    $brain = (new ReflectionClass(AgentBrain::class))->newInstanceWithoutConstructor();

    return $brain->garbledReply($texto, $enEspanol);
}

it('detecta la basura que sí salió al huésped', function (string $texto) {
    expect(esBasura($texto))->toBeTrue();
})->with([
    // Conv. 648: dos idiomas pegados a media pregunta.
    '¿Qué díasWould you like to stay?',
    // Conv. 660: inglés a quien escribía en español.
    "Hello! I'd be happy to help you with information about our cabins. What would you like to know?",
    // Conv. 614 y 745: marcadores que el modelo nunca llenó.
    '[Asistente Virtual] Buenas tardes, ¿en qué puedo ayudarle?',
    'Quedo atento, [nombre del asistente]',
    // Fugas de otros idiomas.
    'Então, para essas datas temos disponibilidade',
    'Mi sarebbe utile saber cuántas personas son',
    'Con gusto podemos accueillir a su grupo',
]);

it('no marca como basura una respuesta normal', function (string $texto) {
    expect(esBasura($texto))->toBeFalse();
})->with([
    'Con gusto. ¿Para qué fechas busca? Así verifico qué cabañas están disponibles.',
    'La Cabaña Real cuesta $4,500 por noche e incluye 6 personas; la persona extra son $250.',
    // Marcas con mayúscula en medio: correctas.
    'Puede mandarnos el comprobante por WhatsApp o pagar con PayPal.',
    'Lamento informarle que para el sábado 26 de septiembre no hay disponibilidad.',
]);

it('respeta el inglés cuando el huésped escribe en inglés', function () {
    $respuesta = 'Hello! We have availability for those dates. Would you like me to check the price for you?';

    expect(esBasura($respuesta, enEspanol: false))->toBeFalse()
        ->and(esBasura($respuesta, enEspanol: true))->toBeTrue();
});

it('una liga no vuelve basura un mensaje bueno', function () {
    // Hotel México 2026-10-01 (conv. 3): el id del Google Doc del contrato
    // parecía dos palabras pegadas y el huésped recibió "repítame su mensaje".
    $respuesta = "Su apartado está creado con el código **RES-2026-0002**.\n\n"
        ."Es importante que lea y confirme el contrato; confírmeme de leído, por favor:\n"
        .'https://docs.google.com/document/d/1qiEc4XtcluOOeDrbrIsuiAZwD-Px3z5myhLopgzeqzg/edit?usp=sharing';

    expect(esBasura($respuesta))->toBeFalse()
        ->and(esBasura('¿Qué díasWould you like? https://example.com/pago'))->toBeTrue();
});
