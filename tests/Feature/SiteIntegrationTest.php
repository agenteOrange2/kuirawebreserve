<?php

use App\Models\Central\SiteIntegration;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\SiteImportSuggestion;
use App\Services\Integration\SiteImporter;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
});

it('genera tokens hasheados que se resuelven solo activos y del tenant correcto', function () {
    $result = SiteIntegration::generate('motel-x', 'WordPress del motel', ['motel-x.com']);
    $token = $result['token'];

    expect($token)->toStartWith('ksk_')
        ->and(strlen($token))->toBeGreaterThan(40)
        // El token en claro NO se guarda: solo el hash y el prefijo.
        ->and($result['integration']->token_hash)->toBe(hash('sha256', $token))
        ->and($result['integration']->token_prefix)->toBe(substr($token, 0, 12));

    expect(SiteIntegration::findByToken($token, 'motel-x')?->id)->toBe($result['integration']->id)
        ->and(SiteIntegration::findByToken($token, 'otro-hotel'))->toBeNull()
        ->and(SiteIntegration::findByToken('ksk_invalido', 'motel-x'))->toBeNull()
        ->and(SiteIntegration::findByToken(null, 'motel-x'))->toBeNull();

    $result['integration']->update(['active' => false]);
    expect(SiteIntegration::findByToken($token, 'motel-x'))->toBeNull();
});

it('parsea la respuesta del LLM tolerando cercos y saneando campos', function () {
    $raw = <<<'RAW'
    ```json
    [
      {"name": "Suite Jacuzzi", "description": "Amplia con jacuzzi doble", "capacity": 2, "amenities": ["Wifi", "wifi", "Jacuzzi", ""], "price": 1200},
      {"name": "", "description": "sin nombre: se ignora"},
      {"capacity": 4},
      {"name": "Sencilla", "capacity": "99", "price": "no es numero"},
      {"name": "Con precio invalido", "price": 0}
    ]
    ```
    RAW;

    $rooms = SiteImporter::parseRoomsJson($raw);

    expect($rooms)->toHaveCount(3)
        ->and($rooms[0]['name'])->toBe('Suite Jacuzzi')
        ->and($rooms[0]['amenities'])->toBe(['Wifi', 'Jacuzzi']) // dedupe case-insensitive y vacíos fuera
        ->and($rooms[0]['price'])->toBe(1200.0)
        ->and($rooms[1]['name'])->toBe('Sencilla')
        ->and($rooms[1]['capacity'])->toBe(20) // clamp 1..20
        ->and($rooms[1]['price'])->toBeNull() // no numérico: se ignora, no se inventa
        ->and($rooms[2]['price'])->toBeNull(); // 0 no es un precio válido

    expect(SiteImporter::parseRoomsJson('no hay json aquí'))->toBeNull()
        ->and(SiteImporter::parseRoomsJson('[]'))->toBe([]);
});

it('reduce html a texto sin scripts y hace match tolerante de tipos', function () {
    $text = SiteImporter::htmlToText('<html><script>alert(1)</script><style>.x{}</style><body><h1>Habitación Jacuzzi</h1><img alt="Suite Master"><p>Wifi   y  minibar</p></body></html>');

    expect($text)->toContain('Habitación Jacuzzi')
        ->and($text)->toContain('Suite Master')
        ->and($text)->not->toContain('alert');

    $property = Property::factory()->create();
    RoomType::factory()->create(['property_id' => $property->id, 'name' => 'Habitación Jacuzzi Sencillo']);

    $importer = app(SiteImporter::class);

    expect($importer->matchType('Jacuzzi Sencillo')?->name)->toBe('Habitación Jacuzzi Sencillo')
        ->and($importer->matchType('HABITACIÓN JACUZZI SENCILLO')?->name)->toBe('Habitación Jacuzzi Sencillo')
        ->and($importer->matchType('Cabaña del lago'))->toBeNull();
});

it('el match prefiere el tipo más específico (caso Master Junior VIP)', function () {
    $property = Property::factory()->create();
    $junior = RoomType::factory()->create(['property_id' => $property->id, 'name' => 'Habitación Master Junior']);
    $vip = RoomType::factory()->create(['property_id' => $property->id, 'name' => 'Habitación Master Junior VIP']);
    RoomType::factory()->create(['property_id' => $property->id, 'name' => 'Habitación Sencilla']);
    $remodelada = RoomType::factory()->create(['property_id' => $property->id, 'name' => 'Habitación Remodelada']);

    $importer = app(SiteImporter::class);

    // Bug de la primera corrida: "VIP" caía en "Master Junior" a secas.
    expect($importer->matchType('Habitación Master Junior VIP')?->id)->toBe($vip->id)
        ->and($importer->matchType('Habitación Master Junior')?->id)->toBe($junior->id)
        ->and($importer->matchType('Habitación Sencilla Remodelada')?->id)->toBe($remodelada->id);
});

it('aplicar una sugerencia de actualización mezcla ficha sin tocar precios', function () {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create([
        'property_id' => $property->id,
        'name' => 'Suite',
        'description' => null,
        'capacity' => 2,
        'amenities' => ['TV'],
    ]);

    $suggestion = SiteImportSuggestion::create([
        'source_url' => 'https://motel.test/habitaciones',
        'room_type_id' => $type->id,
        'action' => 'update',
        'payload' => [
            'name' => 'Suite',
            'description' => 'Suite amplia con jacuzzi y balcón.',
            'capacity' => 3,
            'amenities' => ['tv', 'Jacuzzi', 'Wifi'],
        ],
    ]);

    $suggestion->apply(1);
    $type->refresh();

    expect($type->description)->toBe('Suite amplia con jacuzzi y balcón.')
        ->and($type->capacity)->toBe(3)
        // Mezcla sin duplicar (TV ya estaba, "tv" no se repite).
        ->and($type->amenities)->toBe(['TV', 'Jacuzzi', 'Wifi'])
        ->and($suggestion->refresh()->status)->toBe(SiteImportSuggestion::STATUS_APPLIED);
});

it('aplicar una sugerencia de creación genera el tipo inactivo y sin tarifa', function () {
    Property::factory()->create();

    $suggestion = SiteImportSuggestion::create([
        'source_url' => 'https://motel.test/habitaciones',
        'room_type_id' => null,
        'action' => 'create',
        'payload' => [
            'name' => 'Master Junior VIP',
            'description' => 'La más amplia del motel.',
            'capacity' => 4,
            'amenities' => ['Jacuzzi', 'Cochera privada'],
        ],
    ]);

    $type = $suggestion->apply(1);

    // Nace apagado y sin tarifa: la guarda "Sin tarifa — no reservable"
    // obliga a ponerle precio conscientemente antes de venderlo.
    expect($type->active)->toBeFalse()
        ->and($type->hasActiveRate())->toBeFalse()
        ->and($type->capacity)->toBe(4)
        ->and($type->amenities)->toBe(['Jacuzzi', 'Cochera privada']);
});

it('con precio confirmado por el humano, crear una sugerencia también arma la tarifa y activa el tipo', function () {
    Property::factory()->create();

    $suggestion = SiteImportSuggestion::create([
        'source_url' => 'https://motel.test/habitaciones',
        'room_type_id' => null,
        'action' => 'create',
        'payload' => ['name' => 'Master Junior VIP', 'description' => null, 'capacity' => 4, 'amenities' => [], 'price' => 3500],
    ]);

    // El precio que aplica NO es el del payload (el que propuso la IA):
    // es el que el humano confirmó/corrigió en el formulario de aprobación.
    $type = $suggestion->apply(1, ['price' => 3600, 'rate_type' => 'night']);

    expect($type->active)->toBeTrue()
        ->and($type->hasActiveRate())->toBeTrue()
        ->and($type->priceFrom())->toBe(3600.0)
        ->and($type->ratePlans()->first()->name)->toBe('Tarifa base');
});

it('si el tipo destino YA tiene tarifa activa, el precio sugerido se ignora siempre', function () {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create(['property_id' => $property->id, 'name' => 'Suite']);
    \App\Models\RatePlan::factory()->create(['property_id' => $property->id, 'room_type_id' => $type->id, 'price' => 900]);

    $suggestion = SiteImportSuggestion::create([
        'source_url' => 'https://motel.test/habitaciones',
        'room_type_id' => $type->id,
        'action' => 'update',
        'payload' => ['name' => 'Suite', 'description' => 'Nueva descripción', 'capacity' => null, 'amenities' => [], 'price' => 1200],
    ]);

    // Aunque el humano intente confirmar un precio, si ya hay tarifa activa
    // NUNCA se toca (es el precio que el hotel ya está vendiendo).
    $result = $suggestion->apply(1, ['price' => 1200, 'rate_type' => 'night']);

    expect($result->ratePlans()->count())->toBe(1)
        ->and($result->priceFrom())->toBe(900.0)
        ->and($result->description)->toBe('Nueva descripción');
});

it('un tipo existente sin tarifa recibe la tarifa confirmada pero no se reactiva solo', function () {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create(['property_id' => $property->id, 'name' => 'Suite', 'active' => false]);

    $suggestion = SiteImportSuggestion::create([
        'source_url' => 'https://motel.test/habitaciones',
        'room_type_id' => $type->id,
        'action' => 'update',
        'payload' => ['name' => 'Suite', 'description' => null, 'capacity' => null, 'amenities' => [], 'price' => 800],
    ]);

    $result = $suggestion->apply(1, ['price' => 800, 'rate_type' => 'night']);

    expect($result->hasActiveRate())->toBeTrue()
        // Un tipo EXISTENTE que el hotel apagó a propósito no se reactiva
        // solo (a diferencia de uno nuevo, aquí es decisión deliberada).
        ->and($result->active)->toBeFalse();
});

it('aplicar una sugerencia de creación también crea la habitación física con número automático', function () {
    Property::factory()->create();

    $suggestion = SiteImportSuggestion::create([
        'source_url' => 'https://motel.test/habitaciones',
        'room_type_id' => null,
        'action' => 'create',
        'payload' => ['name' => 'Master Junior VIP', 'description' => null, 'capacity' => 4, 'amenities' => []],
    ]);

    $type = $suggestion->apply(1);

    expect($type->rooms()->count())->toBe(1)
        ->and($type->rooms()->first()->number)->toBe('101')
        ->and($type->rooms()->first()->name)->toBe('Master Junior VIP')
        ->and($suggestion->createdRoomNumber)->toBe('101')
        ->and($suggestion->roomSkippedReason)->toBeNull();
});

it('si el tipo ya tiene habitación, aplicar NO duplica — solo completa el nombre si faltaba', function () {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create(['property_id' => $property->id, 'name' => 'Suite']);
    $existingRoom = Room::factory()->create([
        'property_id' => $property->id,
        'room_type_id' => $type->id,
        'number' => '301',
        'name' => null,
    ]);

    $suggestion = SiteImportSuggestion::create([
        'source_url' => 'https://motel.test/habitaciones',
        'room_type_id' => $type->id,
        'action' => 'update',
        'payload' => ['name' => 'Suite Jacuzzi', 'description' => 'Descripción nueva', 'capacity' => null, 'amenities' => []],
    ]);

    $suggestion->apply(1);

    expect($type->rooms()->count())->toBe(1) // no se creó una segunda
        ->and($existingRoom->refresh()->number)->toBe('301') // número intacto
        ->and($existingRoom->name)->toBe('Suite Jacuzzi') // se completó, estaba vacío
        ->and($suggestion->createdRoomNumber)->toBeNull();
});

it('si la habitación existente ya tiene nombre, aplicar no lo pisa', function () {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create(['property_id' => $property->id, 'name' => 'Suite']);
    Room::factory()->create([
        'property_id' => $property->id,
        'room_type_id' => $type->id,
        'number' => '301',
        'name' => 'Nombre capturado a mano',
    ]);

    $suggestion = SiteImportSuggestion::create([
        'source_url' => 'https://motel.test/habitaciones',
        'room_type_id' => $type->id,
        'action' => 'update',
        'payload' => ['name' => 'Nombre del scrape', 'description' => null, 'capacity' => null, 'amenities' => []],
    ]);

    $suggestion->apply(1);

    expect($type->rooms()->first()->name)->toBe('Nombre capturado a mano');
});

it('la habitación autogenerada salta números ya ocupados', function () {
    $property = Property::factory()->create();
    Room::factory()->create(['property_id' => $property->id, 'number' => '101']);
    Room::factory()->create(['property_id' => $property->id, 'number' => '102']);

    $suggestion = SiteImportSuggestion::create([
        'source_url' => 'https://motel.test/habitaciones',
        'room_type_id' => null,
        'action' => 'create',
        'payload' => ['name' => 'Cabaña Real', 'description' => null, 'capacity' => 2, 'amenities' => []],
    ]);

    $type = $suggestion->apply(1);

    expect($type->rooms()->first()->number)->toBe('103');
});
