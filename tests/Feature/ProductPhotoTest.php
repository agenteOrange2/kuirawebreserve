<?php

use App\Models\Product;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->product = Product::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Coca Cola 600ml',
    ]);

    Permission::findOrCreate('inventory.manage', 'web');
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('inventory.manage');
});

it('guarda la foto del producto y la expone con su miniatura', function () {
    app(\App\Http\Controllers\Tenant\ProductPhotoController::class)->store(
        tap(request(), fn ($r) => $r->files->set(
            'photo',
            UploadedFile::fake()->image('coca.jpg', 600, 600),
        )),
        $this->product,
    );

    $payload = $this->product->fresh()->photoPayload();

    expect($payload)->not->toBeNull()
        ->and($payload['url'])->toContain('/fotos/productos/')
        ->and($payload['thumb_url'])->toContain('v=thumb');
});

it('subir una foto nueva reemplaza la anterior en vez de acumular', function () {
    $this->product->addMedia(UploadedFile::fake()->image('vieja.jpg'))
        ->toMediaCollection('photo');
    $primera = $this->product->fresh()->photoPayload()['id'];

    $this->product->addMedia(UploadedFile::fake()->image('nueva.jpg'))
        ->toMediaCollection('photo');

    $product = $this->product->fresh();

    expect($product->getMedia('photo'))->toHaveCount(1)
        ->and($product->photoPayload()['id'])->not->toBe($primera);
});

it('un producto sin foto entrega null, no un arreglo vacío', function () {
    expect($this->product->photoPayload())->toBeNull();
});

it('quitar la foto deja el producto sin ninguna', function () {
    $this->product->addMedia(UploadedFile::fake()->image('coca.jpg'))
        ->toMediaCollection('photo');

    $this->product->clearMediaCollection('photo');

    expect($this->product->fresh()->photoPayload())->toBeNull();
});
