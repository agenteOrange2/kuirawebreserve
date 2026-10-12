<?php

use App\Actions\Payments\IssueGroupPayment;
use App\Actions\Reservations\CreateGroupReservation;
use App\Http\Controllers\Tenant\BookingLookupController;
use App\Models\PaymentRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->property->update(['settings' => array_replace($this->property->settings ?? [], [
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel', 'clabe' => '012345678901234567', 'active' => true]],
    ])]);
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'name' => 'Cabaña', 'capacity' => 2]);
    Room::factory()->count(2)->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
        'deposit_percent' => 20,
    ]);

    $this->group = app(CreateGroupReservation::class)->handle([
        'mode' => 'night',
        'starts_at' => now()->addDays(10)->setTime(15, 0),
        'ends_at' => now()->addDays(11)->setTime(12, 0),
        'guest_name' => 'Familia García',
        'guest_phone' => '5544332211',
        'confirmed' => false,
        'lines' => [['room_type_id' => $this->roomType->id, 'rooms' => 2, 'adults' => 2]],
    ]);
});

function groupLookup(string $action, array $params, array $files = []): \Illuminate\Http\JsonResponse
{
    $request = Request::create('/api/booking/reservation', $action === 'find' ? 'GET' : 'POST', $params, [], $files);

    return app(BookingLookupController::class)->{$action}($request);
}

it('el folio GRP- con el teléfono del grupo abre la vista del grupo con sus habitaciones', function () {
    $response = groupLookup('find', ['code' => strtolower($this->group->code), 'phone' => '55 4433 2211']);

    expect($response->getStatusCode())->toBe(200);

    $data = $response->getData(true);
    expect($data['kind'])->toBe('group')
        ->and($data['code'])->toBe($this->group->displayCode())
        ->and($data['rooms'])->toHaveCount(2)
        ->and($data['total'])->toEqual(2000)
        ->and($data['pending_balance'])->toEqual(2000)
        ->and($data['adults'])->toBe(4)
        ->and($data['proof']['can_send'])->toBeTrue();

    expect(groupLookup('find', ['code' => $this->group->code, 'phone' => '0000000000'])->getStatusCode())->toBe(404);
});

it('una habitación del grupo enseña el cobro consolidado del GRP-', function () {
    app(IssueGroupPayment::class)->handle($this->group);
    $member = $this->group->reservations()->first();

    $data = groupLookup('find', ['code' => $member->code, 'phone' => '5544332211'])->getData(true);

    expect($data['kind'])->toBe('reservation')
        ->and($data['group_code'])->toBe($this->group->code)
        ->and($data['pending_request'])->not->toBeNull()
        ->and($data['pending_request']['covers_group'])->toBeTrue()
        ->and($data['pending_request']['amount'])->toEqual(400);
});

it('el comprobante subido con el folio del grupo queda en el cobro consolidado', function () {
    Storage::fake('local');

    $response = groupLookup('proof', ['code' => $this->group->code, 'phone' => '5544332211'], [
        'file' => UploadedFile::fake()->image('comprobante.jpg', 600, 900),
    ]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['kind'])->toBe('group')
        ->and($response->getData(true)['proof']['received_at'])->not->toBeNull();

    $requests = PaymentRequest::query()
        ->where('reservation_group_id', $this->group->id)
        ->where('status', PaymentRequest::STATUS_PENDING)
        ->get();

    // Un solo cobro para todo el grupo, con el comprobante pegado.
    expect($requests)->toHaveCount(1)
        ->and($requests->first()->getFirstMedia('receipt'))->not->toBeNull();
});
