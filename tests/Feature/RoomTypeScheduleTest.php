<?php

use App\Http\Controllers\Tenant\BookingController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
});

function scheduleAvailability(): array
{
    $request = Request::create('/api/booking/availability', 'GET', [
        'mode' => 'night',
        'arrive_date' => now()->addDays(10)->toDateString(),
        'depart_date' => now()->addDays(11)->toDateString(),
    ]);

    return app(BookingController::class)->availability($request, app(\App\Services\AvailabilityService::class))->getData(true);
}

it('por noche usa los horarios del TIPO cuando los define (caso cabañas 14:00/11:00)', function () {
    $type = RoomType::factory()->create([
        'property_id' => $this->property->id,
        'check_in_time' => '14:00',
        'check_out_time' => '11:00',
    ]);
    Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $type->id]);
    RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $type->id, 'price' => 3500]);

    $option = scheduleAvailability()['options'][0];

    expect($option['starts_at'])->toContain('T14:00:00')
        ->and($option['ends_at'])->toContain('T11:00:00');
});

it('sin horarios del tipo caen los del hotel, y sin nada el 15:00/12:00 de siempre', function () {
    $this->property->update(['settings' => ['check_in_time' => '13:00', 'check_out_time' => '10:30']]);

    $type = RoomType::factory()->create(['property_id' => $this->property->id]);
    Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $type->id]);
    RatePlan::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $type->id, 'price' => 1000]);

    $option = scheduleAvailability()['options'][0];

    expect($option['starts_at'])->toContain('T13:00:00')
        ->and($option['ends_at'])->toContain('T10:30:00');

    $this->property->update(['settings' => []]);

    $option = scheduleAvailability()['options'][0];

    expect($option['starts_at'])->toContain('T15:00:00')
        ->and($option['ends_at'])->toContain('T12:00:00');
});
