<?php

use App\Http\Controllers\Tenant\PropertyController;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * /reservas/ajustes (2026-10-08): además de fondo y acento, el hotel elige
 * el color del texto sobre el fondo, el de los títulos, el de los botones,
 * la letra de los títulos y los textos del primer paso del wizard.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->property = Property::factory()->create(['settings' => []]);
});

function saveAppearance(array $settings): void
{
    $request = Request::create('/api/properties/'.test()->property->id, 'PATCH', ['settings' => $settings]);
    app(PropertyController::class)->update($request, test()->property);
    test()->property->refresh();
}

it('sin personalizar entrega los textos y colores de siempre', function () {
    $appearance = $this->property->wizardAppearance();

    expect($appearance['header_text'])->toBe('#ffffff')
        ->and($appearance['title_color'])->toBeNull()
        ->and($appearance['button_text'])->toBe('auto')
        ->and($appearance['title_font'])->toBe('default')
        ->and($appearance['texts'])->toBe(Property::WIZARD_TEXT_DEFAULTS);
});

it('guarda colores de texto, letra y textos propios', function () {
    saveAppearance([
        'wizard_header_text' => '#3f2a14',
        'wizard_title_color' => '#8a5a2b',
        'wizard_button_text' => '#0f172a',
        'wizard_title_font' => 'serif',
        'wizard_text_title' => '¿Cuándo vienes a la sierra?',
        'wizard_text_search_button' => 'Ver cabañas libres',
    ]);

    $appearance = $this->property->wizardAppearance();

    expect($appearance['header_text'])->toBe('#3f2a14')
        ->and($appearance['title_color'])->toBe('#8a5a2b')
        ->and($appearance['button_text'])->toBe('#0f172a')
        ->and($appearance['title_font'])->toBe('serif')
        ->and($appearance['texts']['title'])->toBe('¿Cuándo vienes a la sierra?')
        ->and($appearance['texts']['search_button'])->toBe('Ver cabañas libres')
        // Lo que no se tocó sigue con el texto de siempre.
        ->and($appearance['texts']['tagline'])->toBe(Property::WIZARD_TEXT_DEFAULTS['tagline']);
});

it('un texto vacío vuelve al de siempre', function () {
    saveAppearance(['wizard_text_title' => 'Algo propio']);
    saveAppearance(['wizard_text_title' => null]);

    expect($this->property->wizardAppearance()['texts']['title'])
        ->toBe(Property::WIZARD_TEXT_DEFAULTS['title']);
});

it('rechaza colores y letras inválidos', function (array $settings) {
    expect(fn () => saveAppearance($settings))->toThrow(ValidationException::class);
})->with([
    'hex corto' => [['wizard_header_text' => '#fff']],
    'texto de botón raro' => [['wizard_button_text' => 'negro']],
    'letra desconocida' => [['wizard_title_font' => 'comic-sans']],
    'título muy largo' => [['wizard_text_title' => str_repeat('a', 71)]],
]);
