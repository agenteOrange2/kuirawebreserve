<?php

use App\Http\Controllers\Tenant\PropertyController;
use App\Models\Property;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->property = Property::factory()->create(['settings' => ['phone' => '+525599887766']]);
});

function updateSettings(array $settings): Property
{
    $request = Request::create('/api/properties/'.test()->property->id, 'PATCH', [
        'settings' => $settings,
    ]);

    app(PropertyController::class)->update($request, test()->property);

    return test()->property->fresh();
}

it('deriva el teléfono y la lada principal del primer teléfono de la lista', function () {
    $property = updateSettings([
        'phones' => [
            ['code' => '1', 'number' => '2025550143'],
            ['code' => '52', 'number' => '6561234567'],
        ],
    ]);

    // El primero manda para los 7 lectores legacy que leen phone como string.
    expect($property->settings['phone'])->toBe('+12025550143')
        ->and($property->settings['phone_country_code'])->toBe('1')
        ->and($property->settings['phones'])->toHaveCount(2);
});

it('deriva el email principal del primero de la lista', function () {
    $property = updateSettings([
        'emails' => ['reservas@hotel.com', 'gerencia@hotel.com'],
    ]);

    expect($property->settings['email'])->toBe('reservas@hotel.com')
        ->and($property->settings['emails'])->toHaveCount(2);
});

it('guarda sitio web, link de maps y redes sociales', function () {
    $property = updateSettings([
        'website' => 'https://cabanas.com',
        'maps_url' => 'https://maps.app.goo.gl/abc',
        'socials' => [
            ['type' => 'facebook', 'url' => 'https://facebook.com/cabanas'],
            ['type' => 'instagram', 'url' => 'https://instagram.com/cabanas'],
        ],
    ]);

    expect($property->settings['website'])->toBe('https://cabanas.com')
        ->and($property->settings['maps_url'])->toBe('https://maps.app.goo.gl/abc')
        ->and($property->settings['socials'])->toHaveCount(2);
});

it('publicContact normaliza redes con su icono Lucide y filtra vacías', function () {
    $property = updateSettings([
        'website' => 'https://cabanas.com',
        'socials' => [
            ['type' => 'instagram', 'url' => 'https://instagram.com/cabanas'],
            ['type' => 'tiktok', 'url' => 'https://tiktok.com/@cabanas'],
        ],
    ]);

    $contact = $property->publicContact();

    expect($contact['website'])->toBe('https://cabanas.com')
        ->and($contact['socials'])->toHaveCount(2)
        ->and($contact['socials'][0]['icon'])->toBe('Instagram')
        ->and($contact['socials'][1]['icon'])->toBe('Music2'); // TikTok → Music2
});

it('otras llaves de settings no se pisan al guardar contacto', function () {
    test()->property->update(['settings' => ['payment_mode' => 'optional', 'currency' => 'MXN']]);

    $property = updateSettings(['website' => 'https://cabanas.com']);

    // El merge superficial conserva lo que esta pantalla no manda.
    expect($property->settings['payment_mode'])->toBe('optional')
        ->and($property->settings['currency'])->toBe('MXN')
        ->and($property->settings['website'])->toBe('https://cabanas.com');
});
