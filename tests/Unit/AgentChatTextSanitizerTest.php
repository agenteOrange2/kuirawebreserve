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

it('NO destroza una respuesta escrita de verdad en otro alfabeto', function () {
    // El bot debe contestar en el idioma del huésped: si el mensaje entero
    // va en ruso o en chino, limpiarlo lo dejaría vacío.
    $ruso = 'Здравствуйте! У нас есть номера с джакузи.';
    $chino = '您好，我们有带按摩浴缸的房间。';

    expect(chatSanitizer()->sanitizeChatText($ruso))->toBe($ruso)
        ->and(chatSanitizer()->sanitizeChatText($chino))->toBe($chino);
});

it('quita emojis, títulos y viñetas markdown sin tocar el texto normal', function () {
    $limpio = chatSanitizer()->sanitizeChatText("¡Bienvenido al Motel la Cupula! 🏨\n\n### Opciones\n* Una\n* Dos");

    expect($limpio)->toBe("¡Bienvenido al Motel la Cupula!\n\nOpciones\n- Una\n- Dos");
});

it('deja intactos mensajes ya limpios (montos, guiones y acentos incluidos)', function () {
    $texto = "- Habitación Sencilla: \$1,300\n- Jacuzzi VIP: \$2,000\n¿Te interesa alguna? El total es \$650.00 por persona extra.";

    expect(chatSanitizer()->sanitizeChatText($texto))->toBe($texto);
});
