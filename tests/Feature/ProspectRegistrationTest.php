<?php

use App\Mail\ProspectDocumentsMail;
use App\Models\Central\PlanProspect;
use App\Models\Central\PlatformSetting;
use App\Models\Central\ProspectDocument;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

test('el formulario de registro por QR muestra los servicios', function () {
    $this->get(route('prospects.register'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Registro')
            ->has('services', 3)
            ->where('services.0.key', 'web')
        );
});

test('un prospecto se registra, se guarda y recibe los documentos por correo', function () {
    Mail::fake();

    $document = ProspectDocument::factory()->create(['service' => 'web']);
    ProspectDocument::factory()->create(['service' => 'general']);
    ProspectDocument::factory()->create(['service' => 'social']);

    $this->post(route('prospects.register.store'), [
        'name' => 'Laura Méndez',
        'hotel_name' => 'Motel Paraíso',
        'email' => 'LAURA@PARAISO.test',
        'phone_code' => '+52',
        'phone' => '555 000 1234',
        'has_whatsapp' => true,
        'services' => ['web', 'reservas'],
        'privacy' => true,
        'website' => '',
    ])->assertRedirect();

    $prospect = PlanProspect::query()->firstOrFail();

    expect($prospect->email)->toBe('laura@paraiso.test')
        ->and($prospect->source)->toBe('evento')
        ->and($prospect->phone)->toBe('+52 555 000 1234')
        ->and($prospect->whatsappNumber())->toBe('525550001234')
        ->and($prospect->has_whatsapp)->toBeTrue()
        ->and($prospect->services)->toBe(['web', 'reservas'])
        ->and($prospect->plan_key)->toBeNull()
        ->and($prospect->plan_label)->toBeNull()
        ->and($prospect->docs_email_sent_at)->not->toBeNull();

    // Recibe los documentos de sus servicios más los generales; no los de otros.
    Mail::assertSent(ProspectDocumentsMail::class, function (ProspectDocumentsMail $mail) use ($prospect, $document) {
        return $mail->hasTo($prospect->email)
            && $mail->documents->contains('id', $document->id)
            && $mail->documents->count() === 2;
    });
});

test('el registro exige servicios, privacidad y rechaza el honeypot', function () {
    Mail::fake();

    $base = [
        'name' => 'Laura Méndez',
        'hotel_name' => 'Motel Paraíso',
        'email' => 'laura@paraiso.test',
        'phone_code' => '+52',
        'phone' => '5550001234',
        'services' => ['web'],
        'privacy' => true,
        'website' => '',
    ];

    $this->post(route('prospects.register.store'), [...$base, 'services' => []])
        ->assertSessionHasErrors('services');

    $this->post(route('prospects.register.store'), [...$base, 'services' => ['drones']])
        ->assertSessionHasErrors('services.0');

    $this->post(route('prospects.register.store'), [...$base, 'privacy' => false])
        ->assertSessionHasErrors('privacy');

    $this->post(route('prospects.register.store'), [...$base, 'phone_code' => 'MX'])
        ->assertSessionHasErrors('phone_code');

    $this->post(route('prospects.register.store'), [...$base, 'website' => 'https://spam.test'])
        ->assertSessionHasErrors('website');

    expect(PlanProspect::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

test('con el envío automático apagado el registro no dispara correo', function () {
    Mail::fake();

    PlatformSetting::set('prospects_auto_email', '0');
    ProspectDocument::factory()->create(['service' => 'web']);

    $this->post(route('prospects.register.store'), [
        'name' => 'Laura Méndez',
        'hotel_name' => 'Motel Paraíso',
        'email' => 'laura@paraiso.test',
        'phone_code' => '+52',
        'phone' => '5550001234',
        'services' => ['web'],
        'privacy' => true,
        'website' => '',
    ])->assertRedirect();

    $prospect = PlanProspect::query()->firstOrFail();

    expect($prospect->docs_email_sent_at)->toBeNull();
    Mail::assertNothingSent();
});

test('sin documentos cargados el registro se guarda sin marcar envío', function () {
    Mail::fake();

    $this->post(route('prospects.register.store'), [
        'name' => 'Laura Méndez',
        'hotel_name' => 'Motel Paraíso',
        'email' => 'laura@paraiso.test',
        'phone_code' => '52',
        'phone' => '5550001234',
        'services' => ['social'],
        'privacy' => true,
        'website' => '',
    ])->assertRedirect();

    $prospect = PlanProspect::query()->firstOrFail();

    expect($prospect->docs_email_sent_at)->toBeNull();
    Mail::assertNothingSent();
});
