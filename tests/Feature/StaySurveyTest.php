<?php

use App\Actions\Reservations\TransitionReservation;
use App\Http\Controllers\Tenant\SurveyPageController;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\StaySurvey;
use App\Models\User;
use App\Services\Channels\DirectGuestMessenger;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '801',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 700,
    ]);
});

function surveySettings(array $settings): void
{
    $property = Property::firstOrFail();
    $property->update(['settings' => array_merge($property->settings ?? [], $settings)]);
}

/** Walk-in activo con contacto: el agradecimiento sale directo al Guest. */
function makeSurveyStay(): Stay
{
    $guest = Guest::create(['first_name' => 'Paola', 'phone' => '5511223344']);

    return Stay::create([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->plan->id,
        'guest_id' => $guest->id,
        'guest_name' => 'Paola',
        'num_people' => 1,
        'check_in_at' => now()->subHours(5),
        'planned_end_at' => now()->addHours(1),
        'status' => Stay::STATUS_ACTIVE,
        'amount' => 700,
        'channel' => 'walk_in',
    ]);
}

it('el agradecimiento crea la encuesta e incluye su link', function () {
    $captured = '';
    $this->mock(DirectGuestMessenger::class, function ($mock) use (&$captured) {
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('sendToGuestFull')
            ->once()
            ->withArgs(function (Guest $guest, string $subject, string $body) use (&$captured) {
                $captured = $body;

                return true;
            });
    });

    $stay = makeSurveyStay();

    app(TransitionReservation::class)->checkOut($stay);

    $survey = StaySurvey::firstOrFail();

    expect($survey->stay_id)->toBe($stay->id)
        ->and($survey->guest_id)->toBe($stay->guest_id)
        ->and($survey->isSubmitted())->toBeFalse()
        ->and($captured)->toContain("/encuesta/{$survey->token}");
});

it('con el cuestionario apagado no se crea encuesta ni viaja el link', function () {
    surveySettings(['post_stay_survey_enabled' => false]);

    $this->mock(DirectGuestMessenger::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('sendToGuestFull')
            ->once()
            ->withArgs(fn (Guest $guest, string $subject, string $body) => ! str_contains($body, '/encuesta/'));
    });

    app(TransitionReservation::class)->checkOut(makeSurveyStay());

    expect(StaySurvey::count())->toBe(0);
});

it('la respuesta se guarda una sola vez por token', function () {
    $stay = makeSurveyStay();
    $survey = StaySurvey::forStay($stay);

    $respond = fn (array $data) => app(SurveyPageController::class)->store(
        Request::create("/api/encuesta/{$survey->token}", 'POST', $data),
        $survey->token,
    );

    $first = $respond([
        'rating' => 5,
        'answers' => ['cleanliness' => 4],
        'comment' => 'Todo excelente, volveremos.',
    ]);

    $survey->refresh();

    expect($first->getStatusCode())->toBe(200)
        ->and($survey->isSubmitted())->toBeTrue()
        ->and($survey->rating)->toBe(5)
        ->and($survey->answerFor('cleanliness'))->toBe(4)
        ->and($survey->comment)->toBe('Todo excelente, volveremos.');

    // Segundo intento: el token ya se usó.
    expect($respond(['rating' => 1])->getStatusCode())->toBe(409)
        ->and($survey->refresh()->rating)->toBe(5);
});

it('la encuesta es una por estancia aunque se pida dos veces', function () {
    $stay = makeSurveyStay();

    $primera = StaySurvey::forStay($stay);
    $segunda = StaySurvey::forStay($stay);

    expect(StaySurvey::count())->toBe(1)
        ->and($primera->id)->toBe($segunda->id);
});

it('sin personalizar, los aspectos son los ocho del cuestionario base', function () {
    expect(StaySurvey::aspects())->toBe(StaySurvey::DEFAULT_ASPECTS)
        ->and(StaySurvey::aspects())->toHaveCount(8);
});

it('los aspectos personalizados mandan y las respuestas se guardan por llave', function () {
    surveySettings(['survey_aspects' => [
        ['key' => 'alberca', 'label' => 'Alberca'],
        ['key' => 'desayuno', 'label' => 'Desayuno'],
    ]]);

    expect(collect(StaySurvey::aspects())->pluck('key')->all())->toBe(['alberca', 'desayuno']);

    $survey = StaySurvey::forStay(makeSurveyStay());

    $response = app(SurveyPageController::class)->store(
        Request::create("/api/encuesta/{$survey->token}", 'POST', [
            'rating' => 4,
            // 'colada' no es un aspecto del hotel: se descarta en silencio.
            'answers' => ['alberca' => 5, 'desayuno' => 3, 'colada' => 1],
        ]),
        $survey->token,
    );

    $survey->refresh();

    expect($response->getStatusCode())->toBe(200)
        ->and($survey->answers)->toBe(['alberca' => 5, 'desayuno' => 3])
        ->and($survey->answerFor('alberca'))->toBe(5)
        ->and($survey->answerFor('colada'))->toBeNull();
});

it('las respuestas viejas en columnas se siguen leyendo por su llave legacy', function () {
    $survey = StaySurvey::forStay(makeSurveyStay());
    $survey->update(['rating' => 5, 'rating_cleanliness' => 4, 'submitted_at' => now()]);

    expect($survey->refresh()->answerFor('cleanliness'))->toBe(4)
        ->and($survey->answerFor('service'))->toBeNull();
});

it('al guardar aspectos, el backend genera llaves estables y sin duplicar', function () {
    $property = Property::firstOrFail();

    $request = Request::create("/api/properties/{$property->id}", 'PATCH', [
        'settings' => [
            'survey_aspects' => [
                ['key' => null, 'label' => 'Alberca'],
                ['key' => null, 'label' => 'Alberca'],
                ['key' => 'service', 'label' => 'Trato del equipo'],
            ],
        ],
    ]);
    $request->setUserResolver(fn () => User::factory()->create());

    app(\App\Http\Controllers\Tenant\PropertyController::class)->update($request, $property);

    $aspects = StaySurvey::aspects();

    expect(collect($aspects)->pluck('key')->all())->toBe(['alberca', 'alberca-2', 'service'])
        // Renombrar conserva la llave: las respuestas históricas de
        // 'service' siguen agrupando con el texto nuevo.
        ->and(collect($aspects)->firstWhere('key', 'service')['label'])->toBe('Trato del equipo');
});

it('el QR de la habitación lleva a la encuesta de la estancia en curso', function () {
    $stay = makeSurveyStay();

    $response = app(SurveyPageController::class)->room($this->room);
    $survey = StaySurvey::firstOrFail();

    expect($response)->toBeInstanceOf(\Illuminate\Http\RedirectResponse::class)
        ->and($response->getTargetUrl())->toContain("/encuesta/{$survey->token}")
        ->and($survey->stay_id)->toBe($stay->id);

    // Escanear dos veces no duplica la encuesta.
    app(SurveyPageController::class)->room($this->room);
    expect(StaySurvey::count())->toBe(1);
});

it('el QR sin estancia reciente muestra el aviso amable en lugar del cuestionario', function () {
    $request = Request::create('/encuesta/habitacion/'.$this->room->id, 'GET');
    $request->headers->set('X-Inertia', 'true');

    $props = app(SurveyPageController::class)->room($this->room)
        ->toResponse($request)->getData(true)['props'];

    expect($props['unavailable'])->toBeTrue()
        ->and($props['token'])->toBeNull()
        ->and(StaySurvey::count())->toBe(0);
});

it('una evaluación baja avisa a la campana del staff; una buena no', function () {
    $survey = StaySurvey::forStay(makeSurveyStay());

    app(SurveyPageController::class)->store(
        Request::create("/api/encuesta/{$survey->token}", 'POST', [
            'rating' => 2,
            'comment' => 'La habitación olía a humedad.',
        ]),
        $survey->token,
    );

    $alert = \App\Models\StaffNotification::query()
        ->where('type', \App\Models\StaffNotification::TYPE_SURVEY)
        ->first();

    expect($alert)->not->toBeNull()
        ->and($alert->title)->toContain('2/5')
        ->and($alert->body)->toContain('801');

    // Una buena evaluación no molesta a nadie.
    $happy = StaySurvey::forStay(makeSurveyStay());
    app(SurveyPageController::class)->store(
        Request::create("/api/encuesta/{$happy->token}", 'POST', [
            'rating' => 5,
            'answers' => ['cleanliness' => 5],
        ]),
        $happy->token,
    );

    expect(\App\Models\StaffNotification::where('type', \App\Models\StaffNotification::TYPE_SURVEY)->count())->toBe(1);
});
