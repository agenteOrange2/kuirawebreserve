<?php

use App\Models\Central\EvolutionChannelLink;
use App\Models\Central\MetaChannelLink;
use App\Services\Evolution\EvolutionApi;
use App\Services\Meta\MetaApi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Archivo real en disco: los transportes leen del path, no del upload. */
function fakeAttachment(string $name = 'comprobante.jpg'): string
{
    Storage::fake('local');
    $file = UploadedFile::fake()->image($name, 400, 400);
    $path = Storage::disk('local')->path($name);
    file_put_contents($path, file_get_contents($file->getRealPath()));

    return $path;
}

function metaWhatsappLink(): MetaChannelLink
{
    return MetaChannelLink::create([
        'tenant_id' => 'demo',
        'type' => 'whatsapp',
        'external_id' => 'PN123',
        'waba_id' => 'WABA9',
        'access_token' => 'EAAG-token',
        'active' => true,
    ]);
}

it('WhatsApp de Meta sube el archivo y luego lo manda por su id', function () {
    Http::fake([
        'graph.facebook.com/*/PN123/media' => Http::response(['id' => 'MEDIA-1']),
        'graph.facebook.com/*/PN123/messages' => Http::response(['messages' => [['id' => 'wamid.x']]]),
    ]);

    $ok = app(MetaApi::class)->sendMedia(
        metaWhatsappLink(),
        '5216561234567',
        fakeAttachment(),
        'image/jpeg',
        'comprobante.jpg',
        'Aquí está su comprobante',
    );

    expect($ok)->toBeTrue();

    // Se sube primero: nunca se expone el archivo en una URL pública para
    // que Meta lo baje, porque los adjuntos son privados.
    Http::assertSent(fn ($req) => str_contains($req->url(), '/PN123/media'));

    Http::assertSent(fn ($req) => str_contains($req->url(), '/PN123/messages')
        && $req['type'] === 'image'
        && $req['image']['id'] === 'MEDIA-1'
        && $req['image']['caption'] === 'Aquí está su comprobante');
});

it('un PDF va como documento y conserva su nombre', function () {
    Http::fake([
        'graph.facebook.com/*/PN123/media' => Http::response(['id' => 'MEDIA-2']),
        'graph.facebook.com/*/PN123/messages' => Http::response(['messages' => [['id' => 'wamid.y']]]),
    ]);

    app(MetaApi::class)->sendMedia(
        metaWhatsappLink(),
        '5216561234567',
        fakeAttachment('politicas.pdf'),
        'application/pdf',
        'politicas.pdf',
    );

    Http::assertSent(fn ($req) => str_contains($req->url(), '/PN123/messages')
        && $req['type'] === 'document'
        && $req['document']['filename'] === 'politicas.pdf');
});

it('si la subida a Meta falla no se manda el mensaje', function () {
    Http::fake([
        'graph.facebook.com/*/PN123/media' => Http::response(['error' => ['message' => 'nel']], 400),
        'graph.facebook.com/*/PN123/messages' => Http::response([]),
    ]);

    $ok = app(MetaApi::class)->sendMedia(
        metaWhatsappLink(),
        '5216561234567',
        fakeAttachment(),
        'image/jpeg',
        'comprobante.jpg',
    );

    expect($ok)->toBeFalse();
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/PN123/messages'));
});

it('Messenger e Instagram todavía no mandan adjuntos y lo dicen', function () {
    $link = MetaChannelLink::create([
        'tenant_id' => 'demo',
        'type' => 'messenger',
        'external_id' => 'PAGE1',
        'access_token' => 'EAAG-token',
        'active' => true,
    ]);

    $ok = app(MetaApi::class)->sendMedia(
        $link,
        'PSID1',
        fakeAttachment(),
        'image/jpeg',
        'foto.jpg',
    );

    expect($ok)->toBeFalse();
});

it('Evolution manda el archivo en base64, sin exponerlo en una URL', function () {
    Http::fake(['*/message/sendMedia/*' => Http::response(['key' => ['id' => 'x']])]);

    $link = EvolutionChannelLink::create([
        'tenant_id' => 'demo',
        'name' => 'Recepción',
        'base_url' => 'https://evo.local',
        'instance' => 'hotel',
        'api_key' => 'k',
        'webhook_token' => 'tok',
        'active' => true,
    ]);

    $ok = app(EvolutionApi::class)->sendMedia(
        $link,
        '5216561234567',
        fakeAttachment(),
        'image/jpeg',
        'comprobante.jpg',
        'Su comprobante',
    );

    expect($ok)->toBeTrue();
    Http::assertSent(fn ($req) => str_contains($req->url(), '/message/sendMedia/hotel')
        && $req['mediatype'] === 'image'
        && $req['fileName'] === 'comprobante.jpg'
        && $req['caption'] === 'Su comprobante'
        && strlen((string) $req['media']) > 100);
});
