<?php

use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Services\Meta\MetaApi;
use Illuminate\Support\Facades\Http;

it('normaliza un DM de Instagram que trae solo una imagen (comprobante)', function () {
    $entry = ['id' => '17841448419479242'];
    $change = ['field' => 'messages', 'value' => [
        'sender' => ['id' => 'IGSID_HUESPED'],
        'message' => ['mid' => 'mid.foto', 'attachments' => [
            ['type' => 'image', 'payload' => ['url' => 'https://cdn.fb/foto.jpg']],
        ]],
    ]];

    $normalized = MetaWebhookController::instagramChangeToMessage($entry, $change);

    expect($normalized)->not->toBeNull()
        ->and($normalized['body'])->toBe('[Imagen]')
        ->and($normalized['media']['url'])->toBe('https://cdn.fb/foto.jpg')
        ->and($normalized['media']['kind'])->toBe('image');
});

it('audio y video siguen sin soporte: el evento sin texto se descarta', function () {
    $entry = ['id' => '17841448419479242'];
    $change = ['field' => 'messages', 'value' => [
        'sender' => ['id' => 'IGSID_HUESPED'],
        'message' => ['mid' => 'mid.audio', 'attachments' => [
            ['type' => 'audio', 'payload' => ['url' => 'https://cdn.fb/nota.mp4']],
        ]],
    ]];

    expect(MetaWebhookController::instagramChangeToMessage($entry, $change))->toBeNull();
});

it('elige el primer adjunto descargable (imagen o documento)', function () {
    $media = MetaWebhookController::firstDownloadableAttachment([
        ['type' => 'video', 'payload' => ['url' => 'https://cdn.fb/v.mp4']],
        ['type' => 'file', 'payload' => ['url' => 'https://cdn.fb/comprobante.pdf']],
    ]);

    expect($media['kind'])->toBe('file')
        ->and($media['url'])->toBe('https://cdn.fb/comprobante.pdf');
});

it('descarga un adjunto por URL directa del CDN', function () {
    Http::fake(['cdn.fb/*' => Http::response('BYTES', 200, ['Content-Type' => 'image/jpeg; charset=binary'])]);

    $binary = app(MetaApi::class)->downloadMediaUrl('https://cdn.fb/foto.jpg');

    expect($binary['contents'])->toBe('BYTES')
        ->and($binary['mime'])->toBe('image/jpeg');
});

it('descarga fallida del CDN devuelve null sin tronar', function () {
    Http::fake(['cdn.fb/*' => Http::response('', 404)]);

    expect(app(MetaApi::class)->downloadMediaUrl('https://cdn.fb/rota.jpg'))->toBeNull();
});

it('el telefono solo es identidad del hilo en canales WhatsApp', function () {
    $porCanal = function (string $type): bool {
        $conversation = new Conversation;
        $conversation->setRelation('channel', new Channel(['type' => $type]));

        return $conversation->phoneIsIdentity();
    };

    expect($porCanal('whatsapp'))->toBeTrue()
        ->and($porCanal('whatsapp_evo'))->toBeTrue()
        ->and($porCanal('instagram'))->toBeFalse()
        ->and($porCanal('messenger'))->toBeFalse()
        ->and($porCanal('webchat'))->toBeFalse();
});
