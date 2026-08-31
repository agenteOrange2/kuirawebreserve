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

/**
 * Seguimiento desde /encuestas: una queja de dos estrellas tiene que
 * poder cerrarse, contestarse y convertirse en trabajo — antes la
 * pantalla era solo lectura.
 */
function makeAnsweredSurvey(int $rating, ?string $comment = null): StaySurvey
{
    $stay = makeSurveyStay();

    return StaySurvey::create([
        'stay_id' => $stay->id,
        'guest_id' => $stay->guest_id,
        'token' => \Illuminate\Support\Str::random(40),
        'rating' => $rating,
        'answers' => ['service' => $rating],
        'comment' => $comment,
        'submitted_at' => now(),
    ]);
}

it('cerrar el caso deja constancia de quién y cuándo, y reabrirlo lo limpia', function () {
    $user = User::factory()->create(['name' => 'Karla Recepción']);
    $survey = makeAnsweredSurvey(2, 'El aire no enfriaba.');

    $request = Request::create("/api/stay-surveys/{$survey->id}/handle", 'PATCH', [
        'handled' => true,
        'notes' => 'Se le llamó y se le dio una noche de cortesía.',
    ]);
    $request->setUserResolver(fn () => $user);

    app(\App\Http\Controllers\Tenant\StaySurveyController::class)->handle($request, $survey);

    $survey->refresh();

    expect($survey->handled_at)->not->toBeNull()
        ->and($survey->handled_by)->toBe($user->id)
        ->and($survey->handled_notes)->toContain('cortesía')
        ->and($survey->isHandled())->toBeTrue();

    $reopen = Request::create("/api/stay-surveys/{$survey->id}/handle", 'PATCH', ['handled' => false]);
    $reopen->setUserResolver(fn () => $user);

    app(\App\Http\Controllers\Tenant\StaySurveyController::class)->handle($reopen, $survey);

    expect($survey->refresh()->handled_at)->toBeNull()
        ->and($survey->handled_by)->toBeNull();
});

it('la queja se convierte en incidencia ligada, y no se duplica', function () {
    $user = User::factory()->create();
    $survey = makeAnsweredSurvey(1, 'La regadera tira agua fría.');

    $request = Request::create("/api/stay-surveys/{$survey->id}/incident", 'POST', [
        'title' => 'Regadera sin agua caliente',
        'category' => 'plomeria',
        'priority' => 'high',
    ]);
    $request->setUserResolver(fn () => $user);

    $response = app(\App\Http\Controllers\Tenant\StaySurveyController::class)->raiseIncident($request, $survey);

    expect($response->getStatusCode())->toBe(201);

    $incident = \App\Models\Incident::firstOrFail();

    expect($survey->refresh()->incident_id)->toBe($incident->id)
        ->and($incident->room_id)->toBe($this->room->id)
        ->and($incident->stay_id)->toBe($survey->stay_id)
        // La levantó el huésped en su encuesta, no el staff.
        ->and($incident->source)->toBe(\App\Models\Incident::SOURCE_GUEST)
        ->and($incident->description)->toContain('La regadera tira agua fría');

    // Segundo intento: no se levanta otra sobre la misma queja.
    $again = Request::create("/api/stay-surveys/{$survey->id}/incident", 'POST', [
        'title' => 'Otra vez',
        'priority' => 'medium',
    ]);
    $again->setUserResolver(fn () => $user);

    expect(app(\App\Http\Controllers\Tenant\StaySurveyController::class)->raiseIncident($again, $survey)->getStatusCode())
        ->toBe(422)
        ->and(\App\Models\Incident::query()->count())->toBe(1);
});

it('la lista filtra por pendientes y el indicador cuenta lo que nadie ha cerrado', function () {
    $user = User::factory()->create();

    // Pide seguimiento: calificó bajo.
    makeAnsweredSurvey(2, 'Ruido toda la noche.');
    // Pide seguimiento: dejó comentario aunque calificó bien.
    makeAnsweredSurvey(5, 'Todo excelente, solo faltó café.');
    // No pide nada: cinco estrellas sin comentario.
    makeAnsweredSurvey(5);
    // Ya atendida: no cuenta como pendiente.
    $handled = makeAnsweredSurvey(1, 'Pésimo servicio.');
    $handled->update(['handled_at' => now(), 'handled_by' => $user->id]);

    $request = Request::create('/encuestas', 'GET', ['show' => 'pending']);
    $request->setUserResolver(fn () => $user);

    $props = app(\App\Http\Controllers\Tenant\SurveysPageController::class)($request)
        ->toResponse($request)->getOriginalContent()->getData()['page']['props'];

    expect($props['kpis']['answered'])->toBe(4)
        ->and($props['kpis']['pending'])->toBe(2)
        ->and($props['matching'])->toBe(2);

    // El buscador mira huésped, habitación y comentario.
    $search = Request::create('/encuestas', 'GET', ['q' => 'café']);
    $search->setUserResolver(fn () => $user);

    $found = app(\App\Http\Controllers\Tenant\SurveysPageController::class)($search)
        ->toResponse($search)->getOriginalContent()->getData()['page']['props'];

    expect($found['matching'])->toBe(1);
});

it('una respuesta de prueba se puede borrar y deja de contar', function () {
    $survey = makeAnsweredSurvey(1, 'prueba');

    app(\App\Http\Controllers\Tenant\StaySurveyController::class)->destroy($survey);

    expect(StaySurvey::query()->count())->toBe(0);
});
