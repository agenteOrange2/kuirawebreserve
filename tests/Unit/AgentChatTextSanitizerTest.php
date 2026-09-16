<?php

use App\Services\Agent\AgentBrain;

// sanitizeChatText es puro (no toca tools/gate ni BD): se instancia sin
// constructor para no armar toda la cadena de dependencias del cerebro.
function chatSanitizer(): AgentBrain
{
    return (new ReflectionClass(AgentBrain::class))->newInstanceWithoutConstructor();
}

it('convierte tablas markdown en renglones de texto plano (bug real MiniMax 2026-08-20)', function () {
    $malo = <<<'TXT'
    Tenemos las siguientes habitaciones:

    | Habitación | Precio |
    |------------|--------|
    | **Habitación Sencilla** | $1,300 |
    | **Habitaciones Jacuzzi VIP** | $2,000 |

    Todas incluyen cochera privada.
    TXT;

    $limpio = chatSanitizer()->sanitizeChatText($malo);

    expect($limpio)->not->toContain('|')
        ->and($limpio)->not->toContain('**')
        ->and($limpio)->toContain('- Habitación Sencilla — $1,300')
        ->and($limpio)->toContain('- Habitaciones Jacuzzi VIP — $2,000')
        ->and($limpio)->toContain('Todas incluyen cochera privada.');
});

it('elimina caracteres CJK fugados a media frase', function () {
    $limpio = chatSanitizer()->sanitizeChatText('Si告诉我 qué fecha planeas llegar, verifico disponibilidad。');

    expect($limpio)->toBe('Si qué fecha planeas llegar, verifico disponibilidad')
        ->and($limpio)->not->toMatch('/[\x{4E00}-\x{9FFF}]/u');
});

it('quita una palabra en ruso fugada a media frase (bug real 2026-08-20)', function () {
    // Primera respuesta automática a un comentario real: MiniMax metió
    // "классик" en medio de una frase en español.
    $limpio = chatSanitizer()->sanitizeChatText(
        'Contamos con habitaciones классик, remodeladas y con jacuzzi.'
    );

    expect($limpio)->toBe('Contamos con habitaciones , remodeladas y con jacuzzi.')
        ->and($limpio)->not->toMatch('/[\x{0400}-\x{04FF}]/u');
});

it('detecta una respuesta entera en otro alfabeto para volver a redactarla (caso real ruso 2026-09-10)', function () {
    // Cabañas, conv. 69: a un huésped que escribía en español, MiniMax le
    // contestó entero en ruso. El hotel solo admite español e inglés.
    $brain = chatSanitizer();

    expect($brain->needsTranslation('He передал ваш запрос. Скоро с вами свяжутся по телефону для подтверждения оплаты.'))->toBeTrue()
        ->and($brain->needsTranslation('您好，我们有带按摩浴缸的房间。'))->toBeTrue()
        // Una fuga suelta en un texto latino no se traduce: se recorta.
        ->and($brain->needsTranslation('Contamos con habitaciones классик, remodeladas y con jacuzzi.'))->toBeFalse()
        ->and($brain->needsTranslation('Hello! We have rooms available for Friday.'))->toBeFalse()
        ->and($brain->needsTranslation('Tiene 20 minutos para hacer la transferencia.'))->toBeFalse();
});

it('nunca deja pasar otro alfabeto, aunque el mensaje venga entero en él', function () {
    // Antes se respetaba un mensaje entero en ruso o chino; desde
    // 2026-09-11 solo español o inglés, así que el saneador recorta siempre.
    expect(chatSanitizer()->sanitizeChatText('Здравствуйте! У нас есть номера с джакузи.'))->not->toMatch('/[\x{0400}-\x{04FF}]/u')
        ->and(chatSanitizer()->sanitizeChatText('您好，我们有带按摩浴缸的房间。'))->not->toMatch('/[\x{4E00}-\x{9FFF}]/u');
});

it('quita emojis, títulos y viñetas markdown sin tocar el texto normal', function () {
    $limpio = chatSanitizer()->sanitizeChatText("¡Bienvenido al Motel la Cupula! 🏨\n\n### Opciones\n* Una\n* Dos");

    expect($limpio)->toBe("¡Bienvenido al Motel la Cupula!\n\nOpciones\n- Una\n- Dos");
});

it('deja intactos mensajes ya limpios (montos, guiones y acentos incluidos)', function () {
    $texto = "- Habitación Sencilla: \$1,300\n- Jacuzzi VIP: \$2,000\n¿Te interesa alguna? El total es \$650.00 por persona extra.";

    expect(chatSanitizer()->sanitizeChatText($texto))->toBe($texto);
});

it('corrige la cuenta bancaria que el bot copió mal (caso real conv. 69, 2026-09-10)', function () {
    $brain = chatSanitizer();
    $real = '4152314577952941';

    $malo = "Datos para la transferencia:\n- Banco: BBVA Bancomer\n- Cuenta: 4152313477952941\n- Monto: $2,250.00 MXN";

    expect($brain->sanitizeBankNumbers($malo, [$real]))->toContain('Cuenta: 4152314577952941')
        ->and($brain->sanitizeBankNumbers($malo, [$real]))->not->toContain('4152313477952941')
        // Con espacios también se detecta y se deja la real.
        ->and($brain->sanitizeBankNumbers('Cuenta: 4152 3134 7795 2941', [$real]))->toBe('Cuenta: 4152314577952941')
        // La correcta pasa tal cual.
        ->and($brain->sanitizeBankNumbers("Cuenta: {$real}", [$real]))->toBe("Cuenta: {$real}")
        // Un número inventado que no se parece a ninguna cuenta se quita.
        ->and($brain->sanitizeBankNumbers('Cuenta: 9999888877776666', [$real]))->not->toContain('9999888877776666')
        // Los teléfonos no se tocan.
        ->and($brain->sanitizeBankNumbers('WhatsApp 526568508818 o 656 850 8818', [$real]))->toBe('WhatsApp 526568508818 o 656 850 8818');
});
