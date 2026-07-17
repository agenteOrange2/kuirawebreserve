<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Http\Controllers\Tenant\BookingController;
use App\Http\Controllers\Tenant\BookingExtrasController;
use App\Models\Central\PaymentGatewayLink;
use App\Models\Central\TenantModule;
use App\Models\Order;
use App\Models\Product;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Tenant en memoria (sin save) enlazado al contenedor: mismo patrón de
 * tests/Feature/PlanModulesTest.php, para que tenant()->hasModule('pos')
 * resuelva sin disparar el pipeline completo de tenancy. Plan "basic" ya
 * incluye pos (config/plans.php); para el caso "sin pos" se fuerza el
 * override en tenant_modules en vez de inventar un plan que no existe
 * (un plan inexistente cae de vuelta a "basic", que sí tiene pos).
 */
function bindWizardExtrasTenant(bool $hasPos = true): Tenant
{
    $tenant = new Tenant;
    $tenant->id = 'hotel-extras-test';
    $tenant->plan = 'basic';

    if (! $hasPos) {
        TenantModule::create(['tenant_id' => $tenant->id, 'module' => 'pos', 'enabled' => false]);
    }

    // El helper global tenant() resuelve contra la INTERFAZ de stancl, no
    // contra App\Models\Tenant directamente (ver vendor/stancl/tenancy/src/helpers.php).
    app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);
    app()->instance(Tenant::class, $tenant);

    return $tenant;
}

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->ratePlan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
});

it('CreateReservation suma los productos elegidos al total y congela su snapshot', function () {
    $soda = Product::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Refresco',
        'price' => 35,
        'active' => true,
        'available_in_wizard' => true,
    ]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => false,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
        'products' => [['product_id' => $soda->id, 'qty' => 2]],
    ]);

    // qty/total pasan por el cast JSON (array) sin JSON_PRESERVE_ZERO_FRACTION:
    // un 2.0/70.0 exacto vuelve como int tras decode, así que se compara con
    // ->toEqual() (igualdad laxa), no ->toBe().
    expect((float) $reservation->total_amount)->toBe(970.0) // 900 + 2×35
        ->and($reservation->products)->toHaveCount(1)
        ->and($reservation->products[0]['name'])->toBe('Refresco')
        ->and($reservation->products[0]['qty'])->toEqual(2.0)
        ->and($reservation->products[0]['total'])->toEqual(70.0);
});

it('CreateReservation ignora productos que no son vendibles en el wizard (inactivos o no curados)', function () {
    $hidden = Product::factory()->create([
        'property_id' => $this->property->id,
        'price' => 50,
        'active' => true,
        'available_in_wizard' => false, // no curado para el wizard
    ]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => false,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
        'products' => [['product_id' => $hidden->id, 'qty' => 1]],
    ]);

    expect((float) $reservation->total_amount)->toBe(900.0)
        ->and($reservation->products)->toBeNull();
});

it('el check-in materializa una Order real de los extras y descuenta stock', function () {
    $soda = Product::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Refresco',
        'price' => 35,
        'active' => true,
        'available_in_wizard' => true,
        'track_stock' => true,
        'stock_qty' => 10,
    ]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => true,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
        'products' => [['product_id' => $soda->id, 'qty' => 2]],
    ]);

    $stay = app(TransitionReservation::class)->checkIn($reservation);

    expect(Order::count())->toBe(1);
    $order = Order::first();
    expect($order->stay_id)->toBe($stay->id)
        ->and($order->payment_method)->toBe('room')
        ->and((float) $order->total)->toBe(70.0)
        ->and((float) $soda->refresh()->stock_qty)->toBe(8.0)
        ->and($order->settled_at)->not->toBeNull();
});

it('los extras del wizard NUNCA se cobran dos veces al huésped en el folio', function () {
    $soda = Product::factory()->create([
        'property_id' => $this->property->id,
        'price' => 35,
        'active' => true,
        'available_in_wizard' => true,
        'track_stock' => false,
    ]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => true,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
        'products' => [['product_id' => $soda->id, 'qty' => 2]],
    ]);

    $stay = app(TransitionReservation::class)->checkIn($reservation);

    $folio = $stay->refresh()->folio();

    // total_amount (970) ya incluye los 70 de extras vía lodging_pending;
    // consumption_pending debe quedar en 0 porque la Order ya está settled.
    expect($folio['lodging_pending'])->toBe(970.0)
        ->and($folio['consumption_pending'])->toBe(0.0)
        ->and($folio['grand_pending'])->toBe(970.0)
        ->and($folio['orders'])->toHaveCount(0);
});

it('sin productos elegidos, el check-in no crea ninguna Order', function () {
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => true,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
    ]);

    app(TransitionReservation::class)->checkIn($reservation);

    expect(Order::count())->toBe(0);
});

it('el catálogo público de extras está vacío si el módulo pos está apagado', function () {
    bindWizardExtrasTenant(hasPos: false);
    $this->property->update(['settings' => ['wizard_extras_enabled' => true]]);
    Product::factory()->create([
        'property_id' => $this->property->id,
        'active' => true,
        'available_in_wizard' => true,
    ]);

    $data = app(BookingExtrasController::class)->products()->getData(true);

    expect($data['enabled'])->toBeFalse()
        ->and($data['products'])->toBeEmpty();
});

it('el catálogo público de extras está vacío si el hotel no activó el paso', function () {
    bindWizardExtrasTenant(hasPos: true);
    $this->property->update(['settings' => ['wizard_extras_enabled' => false]]);
    Product::factory()->create([
        'property_id' => $this->property->id,
        'active' => true,
        'available_in_wizard' => true,
    ]);

    $data = app(BookingExtrasController::class)->products()->getData(true);

    expect($data['enabled'])->toBeFalse()
        ->and($data['products'])->toBeEmpty();
});

it('el catálogo público de extras filtra productos sin stock y ordena por categoría', function () {
    bindWizardExtrasTenant(hasPos: true);
    $this->property->update(['settings' => ['wizard_extras_enabled' => true]]);

    $inStock = Product::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Cerveza',
        'category' => 'Bebidas',
        'active' => true,
        'available_in_wizard' => true,
        'track_stock' => true,
        'stock_qty' => 5,
    ]);
    Product::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'Agotado',
        'category' => 'Bebidas',
        'active' => true,
        'available_in_wizard' => true,
        'track_stock' => true,
        'stock_qty' => 0,
    ]);
    Product::factory()->create([
        'property_id' => $this->property->id,
        'name' => 'No curado',
        'active' => true,
        'available_in_wizard' => false,
    ]);

    $data = app(BookingExtrasController::class)->products()->getData(true);

    expect($data['enabled'])->toBeTrue()
        ->and($data['products'])->toHaveCount(1)
        ->and($data['products'][0]['id'])->toBe($inStock->id);
});

it('payment-options reporta pasarela y transferencia solo cuando existen de verdad', function () {
    bindWizardExtrasTenant(hasPos: true);
    $this->property->update(['settings' => [
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel Demo', 'clabe' => '012345678901234567', 'active' => true]],
    ]]);

    $data = app(BookingExtrasController::class)->paymentOptions()->getData(true);

    expect($data['gateways'])->toBeEmpty()
        ->and($data['transfer']['available'])->toBeTrue()
        ->and($data['transfer']['accounts_count'])->toBe(1);
});

it('payment-options lista TODAS las pasarelas activas, no solo la primera (spec-reservas-avanzado §1.4)', function () {
    bindWizardExtrasTenant(hasPos: true);

    \App\Models\Central\PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'),
        'provider' => 'stripe',
        'mode' => 'test',
        'public_key' => 'pk_test_1',
        'secret_key' => 'sk_test_1',
        'webhook_token' => \App\Models\Central\PaymentGatewayLink::generateToken(),
        'active' => true,
    ]);
    \App\Models\Central\PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'),
        'provider' => 'paypal',
        'mode' => 'test',
        'public_key' => 'client_id_1',
        'secret_key' => 'secret_1',
        'webhook_token' => \App\Models\Central\PaymentGatewayLink::generateToken(),
        'active' => true,
    ]);
    // Conectada pero apagada: no debe aparecer.
    \App\Models\Central\PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'),
        'provider' => 'mercadopago',
        'mode' => 'test',
        'public_key' => 'app_usr_1',
        'secret_key' => 'access_token_1',
        'webhook_token' => \App\Models\Central\PaymentGatewayLink::generateToken(),
        'active' => false,
    ]);

    $data = app(BookingExtrasController::class)->paymentOptions()->getData(true);

    expect($data['gateways'])->toHaveCount(2)
        ->and(collect($data['gateways'])->pluck('provider')->all())->toBe(['stripe', 'paypal']);
});

it('payment() respeta el método explícito "transfer" aunque haya pasarela conectada', function () {
    bindWizardExtrasTenant(hasPos: true);
    PaymentGatewayLink::create([
        'tenant_id' => (string) tenant('id'),
        'provider' => 'stripe',
        'mode' => 'test',
        'public_key' => 'pk_test_123',
        'secret_key' => 'sk_test_123',
        'webhook_secret' => 'whsec_test_123',
        'webhook_token' => PaymentGatewayLink::generateToken(),
        'active' => true,
    ]);
    $this->property->update(['settings' => [
        'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel Demo', 'clabe' => '012345678901234567', 'active' => true]],
    ]]);

    $plan = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'deposit_percent' => 30,
    ]);

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addHour(),
        'confirmed' => false,
        'source_channel' => 'web',
        'guest_name' => 'Ana García',
    ]);

    $request = Request::create("/api/booking/holds/{$reservation->code}/payment", 'POST', ['method' => 'transfer']);
    $response = app(BookingController::class)->payment($request, $reservation->code, app(\App\Actions\Payments\IssuePaymentRequest::class));

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getData(true)['method'])->toBe('transfer');
});
