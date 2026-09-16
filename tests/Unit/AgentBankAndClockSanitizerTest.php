<?php

use App\Services\Agent\AgentBrain;

/**
 * Datos bancarios: el bot JAMÁS pone datos propios.
 *
 * Caso real cabañas 2026-09-13 18:01 (conv. 635): con las transferencias ya
 * cerradas, el modelo se inventó "Cuenta: 0119870255" y "Beneficiario:
 * Cabañas Real de la Sierra". El filtro anterior lo dejaba pasar porque solo
 * desconfiaba de renglones SIN dígitos — y esta misma prueba exigía conservar
 * el número inventado. Estos saneadores son puros: se instancian sin
 * constructor y todos sus datos se inyectan.
 */
function bankSanitizer(): AgentBrain
{
    return (new ReflectionClass(AgentBrain::class))->newInstanceWithoutConstructor();
}

$cuentas = [[
    'bank' => 'BBVA Bancomer',
    'holder' => 'Jatziry Sofía Salazar Salazar',
    'clabe' => '4152314577952941',
    'active' => true,
]];

$telefonos = ['526568508818', '6568508818'];

function limpiarBanco(string $texto, array $cuentas, bool $abierto = true, array $telefonos = ['526568508818', '6568508818']): string
{
    return bankSanitizer()->sanitizeBankBlocks($texto, $cuentas, $abierto, $telefonos, 'de 9:00 AM a 5:00 PM');
}

$incidente = <<<'TXT'
Para transferencia bancaria, los datos son:

- Banco: BBVA
- Cuenta: 0119870255
- Clabe: (pide al personal los datos de la cuenta)
- Beneficiario: Cabañas Real de la Sierra

Después de hacer la transferencia, envíame el comprobante por este chat.
TXT;

it('el número de cuenta inventado no sale, y en su lugar van los datos reales', function () use ($cuentas, $incidente) {
    $limpio = limpiarBanco($incidente, $cuentas);

    expect($limpio)->not->toContain('0119870255')
        ->and($limpio)->not->toContain('Beneficiario: Cabañas Real de la Sierra')
        ->and($limpio)->not->toContain('pide al personal')
        ->and($limpio)->toContain('- Banco: BBVA Bancomer')
        ->and($limpio)->toContain('- Titular: Jatziry Sofía Salazar Salazar')
        ->and($limpio)->toContain('- Cuenta: 4152314577952941')
        // Lo que no es dato bancario se respeta.
        ->and($limpio)->toContain('envíame el comprobante por este chat')
        // Y en su lugar: los datos van ANTES de "Después de hacer la
        // transferencia", no pegados al final del mensaje.
        ->and(strpos($limpio, '4152314577952941'))->toBeLessThan(strpos($limpio, 'Después de hacer la transferencia'))
        ->and($limpio)->not->toContain("\n\n\n");
});

it('fuera del horario de transferencias no se da ninguna cuenta, ni real ni inventada', function () use ($cuentas, $incidente) {
    $limpio = limpiarBanco($incidente, $cuentas, abierto: false);

    expect($limpio)->not->toContain('0119870255')
        ->and($limpio)->not->toContain('4152314577952941')
        ->and($limpio)->toContain('Las transferencias se reciben de 9:00 AM a 5:00 PM')
        // Sin datos que dar, el "los datos son:" no se queda colgando.
        ->and($limpio)->not->toContain('los datos son:');
});

it('un bloque con los datos reales no se toca', function () use ($cuentas) {
    $bueno = "Datos para transferencia:\n- Banco: BBVA Bancomer\n- Titular: Jatziry Sofia Salazar Salazar\n- Cuenta: 4152 3145 7795 2941\n\nMonto: $6,750.00 MXN";

    expect(limpiarBanco($bueno, $cuentas))->toBe($bueno);
});

it('la cuenta correcta con un titular inventado también se corrige', function () use ($cuentas) {
    $limpio = limpiarBanco("- Cuenta: 4152314577952941\n- Beneficiario: Cabañas Real de la Sierra", $cuentas);

    expect($limpio)->not->toContain('Cabañas Real de la Sierra')
        ->and($limpio)->toContain('- Titular: Jatziry Sofía Salazar Salazar');
});

it('un número inventado en prosa se quita, pero el teléfono del hotel no', function () use ($cuentas) {
    $limpio = limpiarBanco(
        "Deposita a la cuenta 0119870255.\nManda el comprobante de la transferencia al 656 850 8818.",
        $cuentas,
    );

    expect($limpio)->not->toContain('0119870255')
        ->and($limpio)->toContain('Manda el comprobante de la transferencia al 656 850 8818.')
        ->and($limpio)->toContain('- Cuenta: 4152314577952941');
});

it('no confunde folios, fechas ni ligas con cuentas', function () use ($cuentas) {
    $texto = 'Haz tu transferencia con concepto RES-2026-1719 antes del 2026-09-20: https://x.test/pago/ac949f02-0155-4560-a3e2-d238f591d23a';

    expect(limpiarBanco($texto, $cuentas))->toBe($texto);
});

it('quita el "Banco: Por confirmar" que deja al huésped sin datos', function () use ($cuentas) {
    $limpio = limpiarBanco(
        "Datos para transferencia:\n- Banco: Por confirmar (el hotel te los enviará una vez que confirmes)",
        $cuentas,
    );

    expect($limpio)->not->toContain('Por confirmar')
        ->and($limpio)->toContain('- Banco: BBVA Bancomer')
        ->and($limpio)->toContain('- Cuenta: 4152314577952941');
});

it('sin cuentas configuradas solo quita el relleno, no inventa nada', function () {
    expect(limpiarBanco("Te paso los datos:\n- Clabe: (pídelos al personal)", []))->toBe('Te paso los datos:');
});

it('corrige la hora que el modelo se invento', function () {
    $limpio = bankSanitizer()->sanitizeClockClaims(
        'Son las 16:11, tienes hasta las 16:41 para realizar el pago.',
        '16:51',
    );

    // La hora afirmada se corrige; el plazo que calculó la herramienta no se toca.
    expect($limpio)->toBe('son las 16:51, tienes hasta las 16:41 para realizar el pago.');
});

it('deja en paz las horas que no son una afirmación del reloj', function () {
    $texto = 'La alberca está disponible de 9:00 AM a 10:30 PM y tu llegada es a las 14:00.';

    expect(bankSanitizer()->sanitizeClockClaims($texto, '16:51'))->toBe($texto);
});
