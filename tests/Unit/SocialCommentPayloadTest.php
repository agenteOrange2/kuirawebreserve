<?php

use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\SocialPost;
use App\Services\Agent\AgentBrain;
use App\Services\Social\SocialCommentClassifier;

/**
 * Piezas puras del módulo de redes: el normalizador del webhook y el parseo
 * de la salida del clasificador. Ninguna toca base de datos ni red.
 */
function socialClassifier(): SocialCommentClassifier
{
    return new SocialCommentClassifier(
        (new ReflectionClass(AgentBrain::class))->newInstanceWithoutConstructor(),
        (new ReflectionClass(\App\Http\Controllers\Agent\AgentToolsController::class))->newInstanceWithoutConstructor(),
        (new ReflectionClass(\App\Services\Agent\PlatformAgentGate::class))->newInstanceWithoutConstructor(),
    );
}

it('normaliza un comentario de Facebook (field feed)', function () {
    $payload = MetaWebhookController::commentChangeToPayload(
        ['id' => 'PAGE123'],
        ['field' => 'feed', 'value' => [
            'item' => 'comment',
            'verb' => 'add',
            'comment_id' => 'PAGE123_c1',
            'post_id' => 'PAGE123_p1',
            'from' => ['id' => 'USER9', 'name' => 'Ana Ruiz'],
            'message' => '¿Cuánto cuesta la habitación con jacuzzi?',
            'created_time' => 1755700000,
        ]],
    );

    expect($payload)->not->toBeNull()
        ->and($payload['network'])->toBe(SocialPost::NETWORK_FACEBOOK)
        ->and($payload['comment_id'])->toBe('PAGE123_c1')
        ->and($payload['post_id'])->toBe('PAGE123_p1')
        ->and($payload['author_name'])->toBe('Ana Ruiz')
        ->and($payload['commented_at'])->not->toBeNull();
});

it('normaliza un comentario de Instagram (field comments)', function () {
    $payload = MetaWebhookController::commentChangeToPayload(
        ['id' => 'IG_CUENTA'],
        ['field' => 'comments', 'value' => [
            'id' => 'ig_c1',
            'text' => 'Precios porfa',
            'from' => ['id' => 'IGSID7', 'username' => 'anaruiz'],
            'media' => ['id' => 'ig_media_1'],
        ]],
    );

    expect($payload['network'])->toBe(SocialPost::NETWORK_INSTAGRAM)
        ->and($payload['comment_id'])->toBe('ig_c1')
        ->and($payload['post_id'])->toBe('ig_media_1')
        ->and($payload['author_name'])->toBe('anaruiz');
});

it('descarta lo que no es comentario y los ecos de la propia página', function () {
    $like = MetaWebhookController::commentChangeToPayload(
        ['id' => 'PAGE123'],
        ['field' => 'feed', 'value' => ['item' => 'reaction', 'verb' => 'add']],
    );

    // La respuesta que nosotros publicamos vuelve como evento: si no se
    // filtrara, el bot se contestaría a sí mismo en bucle.
    $eco = MetaWebhookController::commentChangeToPayload(
        ['id' => 'PAGE123'],
        ['field' => 'feed', 'value' => [
            'item' => 'comment', 'verb' => 'add', 'comment_id' => 'c2',
            'from' => ['id' => 'PAGE123', 'name' => 'Hotel'],
        ]],
    );

    $dm = MetaWebhookController::commentChangeToPayload(
        ['id' => 'PAGE123'],
        ['field' => 'messages', 'value' => ['sender' => ['id' => 'X']]],
    );

    expect($like)->toBeNull()->and($eco)->toBeNull()->and($dm)->toBeNull();
});

it('conserva el verbo para distinguir alta, edición y borrado', function () {
    $borrado = MetaWebhookController::commentChangeToPayload(
        ['id' => 'PAGE123'],
        ['field' => 'feed', 'value' => [
            'item' => 'comment', 'verb' => 'remove', 'comment_id' => 'c3',
            'from' => ['id' => 'USER9'],
        ]],
    );

    expect($borrado['verb'])->toBe('remove');
});

it('extrae el JSON del clasificador aunque venga envuelto en texto o cercas', function () {
    $parsed = socialClassifier()->parse(
        "Claro, aquí tienes:\n```json\n{\"clasificacion\":\"compra\","
        .'"respuesta_publica":"Con gusto te mandamos la info por privado.",'
        ."\"mensaje_privado\":\"Hola, vimos tu comentario. Te ayudo con tarifas.\"}\n```"
    );

    expect($parsed['clasificacion'])->toBe('compra')
        ->and($parsed['respuesta_publica'])->toBe('Con gusto te mandamos la info por privado.')
        ->and($parsed['mensaje_privado'])->toContain('tarifas');
});

it('sanea la salida del modelo: sin emojis, markdown ni caracteres de otro alfabeto', function () {
    $parsed = socialClassifier()->parse(
        '{"clasificacion":"elogio","respuesta_publica":"**Gracias** por tu comentario 😊",'
        .'"mensaje_privado":"Te esperamos pronto告诉我"}'
    );

    expect($parsed['respuesta_publica'])->toBe('Gracias por tu comentario')
        ->and($parsed['mensaje_privado'])->toBe('Te esperamos pronto');
});

it('devuelve null si la categoría no existe o no hay JSON: nunca adivina', function () {
    expect(socialClassifier()->parse('{"clasificacion":"otra_cosa"}'))->toBeNull()
        ->and(socialClassifier()->parse('No sé qué responder'))->toBeNull()
        ->and(socialClassifier()->parse(''))->toBeNull();
});
