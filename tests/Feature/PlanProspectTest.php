<?php

use App\Mail\ProspectDocumentsMail;
use App\Models\Central\Plan;
use App\Models\Central\PlanProspect;
use App\Models\Central\ProspectDocument;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function platformAdminForProspects(): User
{
    Role::findOrCreate('platform-admin');
    $user = User::factory()->create();
    $user->assignRole('platform-admin');

    return $user;
}

test('landing page muestra los planes y módulos activos', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->has('plans', 3)
            ->where('plans.0.key', 'esencial')
            ->has('modules')
        );
});

test('un visitante puede solicitar información sobre un plan', function () {
    $this->post(route('prospects.store'), [
        'name' => 'Ana Torres',
        'hotel_name' => 'Hotel del Lago',
        'email' => 'ANA@HOTEL.test',
        'phone' => '+52 555 000 1234',
        'rooms' => 28,
        'plan_key' => 'profesional',
        'message' => 'Queremos centralizar las reservas.',
        'source' => 'landing',
        'privacy' => true,
        'website' => '',
    ])->assertRedirect();

    $this->assertDatabaseHas('plan_prospects', [
        'name' => 'Ana Torres',
        'hotel_name' => 'Hotel del Lago',
        'email' => 'ana@hotel.test',
        'plan_key' => 'profesional',
        'plan_label' => 'Profesional',
        'status' => 'new',
    ]);
});

test('no se puede solicitar un plan inactivo', function () {
    Plan::query()->findOrFail('basic')->update(['active' => false]);

    $this->post(route('prospects.store'), [
        'name' => 'Ana Torres',
        'hotel_name' => 'Hotel del Lago',
        'email' => 'ana@hotel.test',
        'phone' => '5550001234',
        'plan_key' => 'basic',
        'privacy' => true,
    ])->assertSessionHasErrors('plan_key');

    expect(PlanProspect::query()->count())->toBe(0);
});

test('el admin puede consultar y dar seguimiento a prospectos', function () {
    $prospect = PlanProspect::factory()->create([
        'hotel_name' => 'Hotel Mirador',
        'plan_key' => 'pro',
        'plan_label' => 'Pro',
    ]);

    $this->actingAs(platformAdminForProspects())
        ->get(route('admin.prospects'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/prospects/Index')
            ->where('stats.total', 1)
            ->where('stats.new', 1)
            ->where('prospects.data.0.hotel_name', 'Hotel Mirador')
        );

    $this->patch(route('admin.prospects.update', $prospect), [
        'status' => 'qualified',
        'notes' => 'Solicitó una demo para el lunes.',
    ])->assertRedirect();

    $prospect->refresh();

    expect($prospect->status)->toBe('qualified')
        ->and($prospect->notes)->toBe('Solicitó una demo para el lunes.')
        ->and($prospect->contacted_at)->not->toBeNull();
});

test('el área de prospectos requiere acceso de plataforma', function () {
    $this->get(route('admin.prospects'))->assertRedirect(route('login'));
});

test('el admin puede reenviar los documentos por correo', function () {
    Mail::fake();

    $prospect = PlanProspect::factory()->create([
        'plan_key' => null,
        'plan_label' => null,
        'services' => ['web'],
        'source' => 'evento',
    ]);
    ProspectDocument::factory()->create(['service' => 'web']);

    $this->actingAs(platformAdminForProspects())
        ->post(route('admin.prospects.sendDocuments', $prospect))
        ->assertRedirect();

    Mail::assertSent(ProspectDocumentsMail::class, fn (ProspectDocumentsMail $mail) => $mail->hasTo($prospect->email));

    expect($prospect->refresh()->docs_email_sent_at)->not->toBeNull();
});

test('sin documentos cargados el reenvío avisa y no envía nada', function () {
    Mail::fake();

    $prospect = PlanProspect::factory()->create([
        'plan_key' => null,
        'plan_label' => null,
        'services' => ['web'],
    ]);

    $this->actingAs(platformAdminForProspects())
        ->post(route('admin.prospects.sendDocuments', $prospect))
        ->assertRedirect()
        ->assertSessionHas('error');

    Mail::assertNothingSent();
    expect($prospect->refresh()->docs_email_sent_at)->toBeNull();
});

test('el clic en WhatsApp sella el envío manual', function () {
    $prospect = PlanProspect::factory()->create([
        'plan_key' => null,
        'plan_label' => null,
        'services' => ['reservas'],
    ]);

    $this->actingAs(platformAdminForProspects())
        ->patch(route('admin.prospects.markWhatsapp', $prospect))
        ->assertRedirect();

    expect($prospect->refresh()->docs_whatsapp_sent_at)->not->toBeNull();
});

test('el listado expone teléfono wa.me normalizado y mensaje con links', function () {
    $document = ProspectDocument::factory()->create(['service' => 'reservas']);
    PlanProspect::factory()->create([
        'plan_key' => null,
        'plan_label' => null,
        'phone' => '555 000 1234',
        'services' => ['reservas'],
        'source' => 'evento',
    ]);

    $this->actingAs(platformAdminForProspects())
        ->get(route('admin.prospects'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/prospects/Index')
            ->where('prospects.data.0.wa_phone', '525550001234')
            ->where('prospects.data.0.services_labels.0', 'Sistema de reservas')
            ->where(
                'prospects.data.0.wa_text',
                fn ($text) => str_contains((string) $text, route('prospects.documents.file', $document)),
            )
            ->where('registerUrl', route('prospects.register'))
        );
});

test('cambiar el estado desde el renglón conserva las notas', function () {
    $prospect = PlanProspect::factory()->create([
        'status' => 'new',
        'notes' => 'Llamar el martes.',
    ]);

    $this->actingAs(platformAdminForProspects())
        ->patch(route('admin.prospects.update', $prospect), ['status' => 'contacted'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $prospect->refresh();

    expect($prospect->status)->toBe('contacted')
        ->and($prospect->notes)->toBe('Llamar el martes.')
        ->and($prospect->contacted_at)->not->toBeNull();
});

test('los filtros de origen, seguimiento y documentos pendientes acotan la bandeja', function () {
    PlanProspect::factory()->create(['hotel_name' => 'Landing Nuevo', 'source' => 'landing', 'status' => 'new']);
    PlanProspect::factory()->create(['hotel_name' => 'QR Calificado', 'source' => 'evento', 'status' => 'qualified']);
    PlanProspect::factory()->create(['hotel_name' => 'QR Ganado', 'source' => 'evento', 'status' => 'won']);
    PlanProspect::factory()->create([
        'hotel_name' => 'Ya con docs',
        'status' => 'contacted',
        'docs_email_sent_at' => now(),
    ]);

    $admin = platformAdminForProspects();

    $this->actingAs($admin)
        ->get(route('admin.prospects', ['source' => 'evento']))
        ->assertInertia(fn (Assert $page) => $page->has('prospects.data', 2));

    $this->actingAs($admin)
        ->get(route('admin.prospects', ['status' => 'open']))
        ->assertInertia(fn (Assert $page) => $page->has('prospects.data', 2));

    // Sin documentos = vivos sin correo ni WhatsApp (el ganado no cuenta).
    $this->actingAs($admin)
        ->get(route('admin.prospects', ['docs' => 'pending']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('prospects.data', 2)
            ->where('stats.docs_pending', 2)
            ->where('stats.won', 1)
            ->where('stats.conversion', 25)
        );

    $this->actingAs($admin)
        ->get(route('admin.prospects', ['search' => '100%']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('prospects.data', 0));
});

test('el listado trae el historial del prospecto desde la bitácora', function () {
    $prospect = PlanProspect::factory()->create(['status' => 'new']);
    $admin = platformAdminForProspects();

    $this->actingAs($admin)
        ->patch(route('admin.prospects.update', $prospect), ['status' => 'qualified', 'notes' => 'Demo el lunes']);

    $this->actingAs($admin)
        ->get(route('admin.prospects'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('prospects.data.0.history.0.label', 'Actualizó el prospecto')
            ->where('prospects.data.0.history.0.user', $admin->name)
            ->where('prospects.data.0.history.0.details.0', 'Nuevo → Calificado')
        );
});

test('un prospecto de la landing con plan tiene WhatsApp de saludo y de documentos generales', function () {
    ProspectDocument::factory()->create(['service' => ProspectDocument::GENERAL_SERVICE]);
    PlanProspect::factory()->create([
        'name' => 'Ana',
        'phone' => '6561234567',
        'services' => null,
        'plan_key' => 'pro',
        'plan_label' => 'Pro',
    ]);

    $this->actingAs(platformAdminForProspects())
        ->get(route('admin.prospects'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('prospects.data.0.docs_available', 1)
            ->where('prospects.data.0.wa_text', fn ($text) => str_contains((string) $text, 'el plan'))
            ->where('prospects.data.0.wa_greeting', fn ($text) => str_starts_with((string) $text, 'Hola Ana'))
        );
});

test('el admin exporta a CSV lo que muestran los filtros', function () {
    PlanProspect::factory()->create(['hotel_name' => 'Hotel Exportado', 'status' => 'won']);
    PlanProspect::factory()->create(['hotel_name' => 'Hotel Fuera', 'status' => 'new']);

    $response = $this->actingAs(platformAdminForProspects())
        ->get(route('admin.prospects.export', ['status' => 'won']))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toContain('Hotel Exportado')
        ->and($csv)->toContain('Ganado')
        ->and($csv)->not->toContain('Hotel Fuera');
});

test('la página de inicio agrupa los módulos por familia y anuncia los servicios activos', function () {
    \App\Models\Central\AddonService::query()->update(['active' => false]);
    \App\Models\Central\AddonService::create([
        'key' => 'svc-landing', 'name' => 'Servicios Digitales – Modalidad 1: Motor de Reservas Online',
        'summary' => 'Reservas desde tu sitio.', 'price_monthly' => 800, 'activation_fee' => 1000,
        'modules' => ['motor-web'], 'active' => true, 'sort_order' => 1,
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('moduleGroups.0.key', 'reservas')
            ->where('moduleGroups', fn ($groups) => collect($groups)->sum('count') === count(config('modules')))
            ->where('modules', fn ($modules) => collect($modules)->every(fn ($m) => filled($m['group'])))
            ->has('addons', 1)
            ->missing('addons.0.price_monthly')
            ->missing('addons.0.activation_fee')
            ->where('plans.0.activation_fee', fn ($fee) => is_int($fee))
        );
});
