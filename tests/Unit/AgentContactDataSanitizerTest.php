<?php

use App\Services\Agent\AgentBrain;

/**
 * El bot no puede escribir un teléfono, correo o liga que no le hayamos dado.
 *
 * Nació del caso de la cuenta bancaria inventada (cabañas, conv. 635,
 * 2026-09-13: un huésped transfirió $1,700 a un número que el modelo se sacó
 * de la nada). La lista permitida sale de los datos reales de cabañas en el
 * VPS; el saneador es puro y la lista se inyecta.
 */
function contactSanitizer(): AgentBrain
{
    return (new ReflectionClass(AgentBrain::class))->newInstanceWithoutConstructor();
}

function permitidosCabanas(): array
{
    return [
        'urls' => [
            'https://cabanasrealdelasierra.com',
            'https://maps.app.goo.gl/4x1fDq9H4Pygogu79',
            'https://cabanasrealdelasierra.com/cabana/cabana-real/',
            'https://cabanasrealdelasierra.com/aviso-legal/',
        ],
        'hosts' => ['cabanasrealdelasierra.tureservaenlinea.com'],
        'emails' => ['crealdelasierra@gmail.com', 'ramoslopezchema1234@gmail.com'],
        'phones' => ['+526568508818', '6147117604'],
        'bank_numbers' => ['4152314577952941'],
        'main_phone' => '+526568508818',
        'main_email' => 'crealdelasierra@gmail.com',
    ];
}

function limpiarContacto(string $texto): string
{
    return contactSanitizer()->sanitizeContactData($texto, null, permitidosCabanas());
}

it('un teléfono inventado se cambia por el del hotel', function () {
    $limpio = limpiarContacto('Para dudas llama al 656 123 4567 y con gusto te atienden.');

    expect($limpio)->not->toContain('656 123 4567')
        ->and($limpio)->toContain('+526568508818');
});

it('el teléfono del hotel y el del huésped pasan tal cual', function () {
    $texto = "Escríbenos al WhatsApp +52 656 850 8818.\nRecepción te manda las fotos al 6147117604.";

    expect(limpiarContacto($texto))->toBe($texto);
});

it('un correo inventado se cambia por el del hotel, el del huésped pasa', function () {
    $limpio = limpiarContacto("Manda tu comprobante a pagos@cabanasrealdelasierra.com.\nTu correo registrado: ramoslopezchema1234@gmail.com");

    expect($limpio)->not->toContain('pagos@cabanasrealdelasierra.com')
        ->and($limpio)->toContain('crealdelasierra@gmail.com')
        ->and($limpio)->toContain('ramoslopezchema1234@gmail.com');
});

it('una liga inventada se quita, aunque sea del dominio real del hotel', function () {
    $limpio = limpiarContacto('Mira las fotos aquí: https://cabanasrealdelasierra.com/cabana/cabana-fantasma/');

    expect($limpio)->not->toContain('cabana-fantasma');
});

it('las ligas reales, las del sistema y el WhatsApp del hotel pasan', function () {
    $texto = "Fotos: https://cabanasrealdelasierra.com/cabana/cabana-real/\n"
        ."Cómo llegar: https://maps.app.goo.gl/4x1fDq9H4Pygogu79\n"
        ."Paga aquí: https://cabanasrealdelasierra.tureservaenlinea.com/pago/ac949f02-0155-4560-a3e2-d238f591d23a\n"
        .'Escríbenos: https://wa.me/526568508818';

    expect(limpiarContacto($texto))->toBe($texto);
});

it('no confunde cuentas, folios, fechas, horas ni montos con teléfonos', function () {
    $texto = "- Cuenta: 4152314577952941\n- Cuenta: 4152 3145 7795 2941\n"
        ."Folio GRP-2026-0149, llegada 2026-09-20 14:00, entrega de 2:00 a 4:00 PM.\n"
        .'Total: $13,500.00 por 4 cabañas para 16 personas.';

    expect(limpiarContacto($texto))->toBe($texto);
});

it('un mensaje sin datos de contacto no se toca', function () {
    $texto = 'La alberca está disponible de 9:00 AM a 10:30 PM y está a temperatura ambiente.';

    expect(limpiarContacto($texto))->toBe($texto);
});
