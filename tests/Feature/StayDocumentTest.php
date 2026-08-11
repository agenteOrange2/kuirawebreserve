<?php

use App\Actions\Reservations\CreateWalkInStay;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\StayController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
});

function makeExpressStay(string $number): Stay
{
    $room = Room::factory()->create([
        'property_id' => test()->property->id,
        'room_type_id' => test()->roomType->id,
        'number' => $number,
    ]);

    return app(CreateWalkInStay::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => $room->id,
        'guest_name' => 'A Pie '.$number,
    ]);
}

it('sube la foto del documento a la colección privada y la sirve por su ruta', function () {
    $stay = makeExpressStay('601');

    $request = Request::create('/api/stays/'.$stay->id.'/id-document', 'POST', [], [], [
        'file' => UploadedFile::fake()->image('ine-frente.jpg', 800, 600),
    ]);

    $response = app(StayController::class)->storeDocument($request, $stay);
    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($payload['url'])->toContain("/estancias/{$stay->id}/documento/");

    $media = $stay->getMedia('id_document');
    expect($media)->toHaveCount(1)
        ->and($media->first()->disk)->toBe('local');

    $file = app(StayController::class)->showDocument($stay, $media->first());
    expect($file->getStatusCode())->toBe(200);
});

it('no sirve la foto de otra estancia', function () {
    $stayA = makeExpressStay('602');
    $stayB = makeExpressStay('603');

    $request = Request::create('/api/stays/'.$stayA->id.'/id-document', 'POST', [], [], [
        'file' => UploadedFile::fake()->image('ine.jpg'),
    ]);
    app(StayController::class)->storeDocument($request, $stayA);

    $media = $stayA->getMedia('id_document')->first();

    expect(fn () => app(StayController::class)->showDocument($stayB, $media))
        ->toThrow(HttpException::class);
});

it('rechaza archivos que no son imagen soportada', function () {
    $stay = makeExpressStay('604');

    $request = Request::create('/api/stays/'.$stay->id.'/id-document', 'POST', [], [], [
        'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ]);

    expect(fn () => app(StayController::class)->storeDocument($request, $stay))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});
