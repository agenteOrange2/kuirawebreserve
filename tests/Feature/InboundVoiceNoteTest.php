<?php

use App\Http\Controllers\Webhooks\EvolutionWebhookController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Http\Controllers\Webhooks\TelegramWebhookController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Property;
use App\Services\Agent\VoiceTranscriber;
use App\Services\Channels\InboundVoiceService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->property = Property::factory()->create();

    config([
        'services.transcription.enabled' => true,
        'services.transcription.url' => 'https://api.openai.com/v1',
        'services.transcription.api_key' => 'sk-prueba',
        'services.transcription.model' => 'gpt-4o-mini-transcribe',
    ]);
});

function voiceConversation(): Conversation
{
    $channel = Channel::firstOrCreate(
        ['property_id' => test()->property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );

    return Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216141234567',
        'status' => Conversation::STATUS_OPEN,
        'bot_enabled' => true,
        'lead_status' => Conversation::LEAD_QUOTING,
        'last_message_at' => now()->subMinutes(40),
    ]);
}

it('la nota de voz de Evolution llega con su clase y su duración', function () {
    $messages = EvolutionWebhookController::extractMessages([
        'event' => 'messages.upsert',
        'data' => [
            'key' => ['remoteJid' => '5216141234567@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-PTT-1'],
            'pushName' => 'Ana',
            'messageType' => 'audioMessage',
            'message' => [
                'audioMessage' => ['mimetype' => 'audio/ogg; codecs=opus', 'seconds' => 12],
                'base64' => base64_encode('OGG-BYTES'),
            ],
        ],
    ]);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['media']['kind'])->toBe(InboundVoiceService::KIND_AUDIO)
        ->and($messages[0]['media']['seconds'])->toBe(12)
        ->and($messages[0]['body'])->toBe('[Nota de voz]');
});

it('la nota de voz de Messenger ya no se descarta en silencio', function () {
    // Antes, un adjunto de audio no pasaba el filtro y el evento moría sin
    // dejar mensaje: el huésped que contestaba hablando quedaba mudo.
    $audio = MetaWebhookController::firstDownloadableAttachment([
        ['type' => 'audio', 'payload' => ['url' => 'https://cdn.meta.test/audio.mp4']],
    ]);

    expect($audio)->not->toBeNull()
        ->and($audio['kind'])->toBe(InboundVoiceService::KIND_AUDIO);

    expect(MetaWebhookController::placeholderFor(InboundVoiceService::KIND_AUDIO))->toBe('[Nota de voz]')
        ->and(MetaWebhookController::placeholderFor('image'))->toBe('[Imagen]')
        ->and(MetaWebhookController::placeholderFor('file'))->toBe('[Documento]');
});

it('el voice de Telegram entra como audio con su duración', function () {
    $normalized = TelegramWebhookController::extractMessage([
        'message' => [
            'message_id' => 42,
            'chat' => ['id' => 9911, 'type' => 'private'],
            'from' => ['first_name' => 'Ana'],
            'voice' => ['file_id' => 'FILE-VOICE-1', 'mime_type' => 'audio/ogg', 'duration' => 8],
        ],
    ]);

    expect($normalized['media']['kind'])->toBe(InboundVoiceService::KIND_AUDIO)
        ->and($normalized['media']['seconds'])->toBe(8)
        ->and($normalized['body'])->toBe('[Nota de voz]');
});

it('transcribe la nota de voz y el texto entra al flujo como si lo hubiera escrito', function () {
    Http::fake([
        'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Sí, el domingo 6 está bien, ¿cuánto sale?']),
    ]);

    $resultado = app(InboundVoiceService::class)->interpret(
        InboundVoiceService::KIND_AUDIO,
        fn () => ['contents' => 'OGG-BYTES', 'mime' => 'audio/ogg; codecs=opus'],
        12,
    );

    expect($resultado['transcribed'])->toBeTrue()
        ->and($resultado['body'])->toBe('Sí, el domingo 6 está bien, ¿cuánto sale?')
        ->and($resultado['meta']['voice_note'])->toBeTrue();
});

it('sin key de transcripción no se baja el audio: se pide texto', function () {
    config(['services.transcription.api_key' => null, 'services.transcription.url' => 'https://api.groq.com/openai/v1']);
    Http::fake();

    $bajadas = 0;
    $resultado = app(InboundVoiceService::class)->interpret(
        InboundVoiceService::KIND_AUDIO,
        function () use (&$bajadas) {
            $bajadas++;

            return ['contents' => 'OGG-BYTES', 'mime' => 'audio/ogg'];
        },
    );

    expect($resultado['transcribed'])->toBeFalse()
        ->and($resultado['body'])->toBe('[Nota de voz]')
        ->and($resultado['meta']['unsupported_media'])->toBe(InboundVoiceService::KIND_AUDIO)
        ->and($bajadas)->toBe(0);
});

it('un audio larguísimo no se manda a transcribir', function () {
    config(['services.transcription.max_seconds' => 180]);
    Http::fake();

    expect(app(VoiceTranscriber::class)->transcribe('OGG-BYTES', 'audio/ogg', 900))->toBeNull();
    Http::assertNothingSent();
});

it('el aviso de solo texto deja la conversación esperando a un humano', function () {
    $conversation = voiceConversation();

    app(InboundVoiceService::class)->askForText($conversation, InboundVoiceService::KIND_AUDIO);

    $aviso = $conversation->messages()->latest('id')->first();

    expect($conversation->refresh()->status)->toBe(Conversation::STATUS_PENDING)
        ->and($aviso->direction)->toBe('out')
        ->and($aviso->body)->toContain('solo puedo leer mensajes escritos')
        ->and($aviso->meta['unsupported_media_notice'])->toBe(InboundVoiceService::KIND_AUDIO);
});

it('el seguimiento no persigue con "¿sigues por ahí?" a quien acaba de mandar audio', function () {
    $conversation = voiceConversation();

    // El huésped contestó (con voz) y nosotros le pedimos texto: la pelota
    // es nuestra, no suya. Antes el nudge salía y se leía como ignorarlo.
    $conversation->messages()->create([
        'direction' => 'in',
        'sender_type' => 'visitor',
        'body' => '[Nota de voz]',
        'meta' => ['unsupported_media' => InboundVoiceService::KIND_AUDIO],
        'created_at' => now()->subMinutes(41),
    ]);
    app(InboundVoiceService::class)->askForText($conversation, InboundVoiceService::KIND_AUDIO);

    $conversation->update(['status' => Conversation::STATUS_OPEN, 'last_message_at' => now()->subMinutes(40)]);
    $antes = $conversation->messages()->count();

    $this->artisan('conversations:follow-up');

    expect($conversation->messages()->count())->toBe($antes);
});

it('un video con pie de foto sigue siendo texto para el bot', function () {
    // El caption es lo que el huésped escribió: si se pisa con "[Video]"
    // el bot pierde la pregunta y contesta el aviso de "solo texto".
    $messages = EvolutionWebhookController::extractMessages([
        'event' => 'messages.upsert',
        'data' => [
            'key' => ['remoteJid' => '5216141234567@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-VID-1'],
            'messageType' => 'videoMessage',
            'message' => [
                'videoMessage' => ['caption' => '¿Se ve así la cabaña?', 'mimetype' => 'video/mp4'],
            ],
        ],
    ]);

    expect($messages[0]['body'])->toBe('¿Se ve así la cabaña?')
        ->and($messages[0]['media']['kind'])->toBe(InboundVoiceService::KIND_VIDEO);
});
