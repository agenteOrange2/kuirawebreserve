<?php

use App\Models\Central\ProspectDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function platformAdminForDocuments(): User
{
    Role::findOrCreate('platform-admin');
    $user = User::factory()->create();
    $user->assignRole('platform-admin');

    return $user;
}

test('el admin puede ver y subir documentos', function () {
    Storage::fake('local');

    $this->actingAs(platformAdminForDocuments())
        ->get(route('admin.prospects.documents'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/prospects/Documents')
            ->has('documents', 0)
            ->has('services', 4)
        );

    $this->post(route('admin.prospects.documents.store'), [
        'title' => 'Presentación de páginas web',
        'service' => 'web',
        'file' => UploadedFile::fake()->create('folleto.pdf', 500, 'application/pdf'),
    ])->assertRedirect();

    $document = ProspectDocument::query()->firstOrFail();

    expect($document->title)->toBe('Presentación de páginas web')
        ->and($document->service)->toBe('web')
        ->and($document->original_name)->toBe('folleto.pdf')
        ->and($document->uuid)->not->toBeEmpty();

    Storage::disk('local')->assertExists($document->path);
});

test('reemplazar el archivo conserva el uuid y borra el anterior', function () {
    Storage::fake('local');

    $document = ProspectDocument::factory()->create(['service' => 'web']);
    Storage::disk('local')->put($document->path, 'pdf viejo');
    $originalUuid = $document->uuid;
    $originalPath = $document->path;

    $this->actingAs(platformAdminForDocuments())
        ->post(route('admin.prospects.documents.update', $document), [
            '_method' => 'PATCH',
            'title' => 'Versión nueva',
            'service' => 'general',
            'file' => UploadedFile::fake()->create('nuevo.pdf', 300, 'application/pdf'),
        ])->assertRedirect();

    $document->refresh();

    expect($document->uuid)->toBe($originalUuid)
        ->and($document->title)->toBe('Versión nueva')
        ->and($document->service)->toBe('general')
        ->and($document->path)->not->toBe($originalPath);

    Storage::disk('local')->assertMissing($originalPath);
    Storage::disk('local')->assertExists($document->path);
});

test('eliminar un documento borra el archivo y la fila', function () {
    Storage::fake('local');

    $document = ProspectDocument::factory()->create();
    Storage::disk('local')->put($document->path, 'pdf');

    $this->actingAs(platformAdminForDocuments())
        ->delete(route('admin.prospects.documents.destroy', $document))
        ->assertRedirect();

    expect(ProspectDocument::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($document->path);
});

test('solo se aceptan PDF de hasta 10 MB', function () {
    Storage::fake('local');

    $this->actingAs(platformAdminForDocuments());

    $this->post(route('admin.prospects.documents.store'), [
        'title' => 'Imagen',
        'service' => 'web',
        'file' => UploadedFile::fake()->create('foto.jpg', 200, 'image/jpeg'),
    ])->assertSessionHasErrors('file');

    $this->post(route('admin.prospects.documents.store'), [
        'title' => 'Gigante',
        'service' => 'web',
        'file' => UploadedFile::fake()->create('gigante.pdf', 11_000, 'application/pdf'),
    ])->assertSessionHasErrors('file');

    expect(ProspectDocument::query()->count())->toBe(0);
});

test('la gestión de documentos requiere acceso de plataforma', function () {
    $this->get(route('admin.prospects.documents'))->assertRedirect(route('login'));
});

test('el documento se sirve públicamente por uuid', function () {
    Storage::fake('local');

    $document = ProspectDocument::factory()->create([
        'original_name' => 'folleto-web.pdf',
    ]);
    Storage::disk('local')->put($document->path, '%PDF-1.4 contenido');

    $this->get(route('prospects.documents.file', $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->get('/documentos/'.fake()->uuid())->assertNotFound();
});
