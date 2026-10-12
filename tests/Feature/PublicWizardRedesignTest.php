<?php

use App\Http\Controllers\Tenant\BookingExtrasController;
use App\Http\Controllers\Tenant\ExperienceWizardController;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RatePlanSeason;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Rediseño de las páginas públicas de reserva (oct-2026): la gráfica de
 * precio por noche tiene que sumar lo mismo que se cobra, el aviso de
 * horario de transferencias tiene que llegar a las tres páginas, y un
 * huésped sin sesión nunca debe caer en la pantalla del panel cuando un
 * módulo está apagado.
 */
function bindWizardRedesignTenant(array $modules): void
{
    $tenant = new class extends Tenant
    {
        /** @var list<string> */
        public array $testModules = [];

        public function hasModule(string $module): bool
        {
            return in_array($module, $this->testModules, true);
        }
    };
    $tenant->id = 'hotel-wizard-test';
    $tenant->plan = 'basic';
    $tenant->testModules = $modules;

    app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);
    app()->instance(Tenant::class, $tenant);
}

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    bindWizardRedesignTenant(['motor-web']);

    $this->property = Property::factory()->create([
        'settings' => [
            'bank_accounts' => [['bank' => 'BBVA', 'holder' => 'Hotel Demo', 'clabe' => '012345678901234567', 'active' => true]],
            'transfer_hours_enabled' => true,
            'transfer_hours_open' => '09:00',
            'transfer_hours_close' => '17:00',
        ],
    ]);
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 800,
    ]);
});

it('el precio por noche suma exactamente lo que se cobra, con temporada y ajuste del cuarto', function () {
    $wednesday = now()->addMonth()->next(CarbonInterface::WEDNESDAY)->setTime(15, 0);
    $saturday = $wednesday->copy()->addDays(3)->setTime(12, 0);
    RatePlanSeason::factory()->create([
        'rate_plan_id' => $this->plan->id,
        'name' => 'Fin de semana',
        'starts_on' => null,
        'ends_on' => null,
        'weekdays' => [5, 6],
        'price' => 1200,
    ]);
    $room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price_modifier' => 100,
    ]);

    $nights = $this->plan->nightlyPrices($wednesday, $saturday, $room);

    expect($nights)->toHaveCount(3)
        ->and(array_column($nights, 'price'))->toEqual([900.0, 900.0, 1300.0])
        ->and($nights[2]['season'])->toBe('Fin de semana')
        ->and($nights[0]['date'])->toBe($wednesday->toDateString())
        ->and(array_sum(array_column($nights, 'price')))->toEqual($this->plan->priceFor($wednesday, $saturday, $room));
});

it('por bloque no hay precio por noche', function () {
    $block = RatePlan::factory()->block(720, 900)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);

    expect($block->nightlyPrices(now()->addDay(), now()->addDay()->addHours(12)))->toBe([]);
});

it('fuera de horario las formas de pago dicen cuándo se reciben transferencias', function () {
    $this->travelTo(now()->setTime(22, 0));
    $night = app(BookingExtrasController::class)->paymentOptions()->getData(true);

    $this->travelTo(now()->setTime(11, 0));
    $day = app(BookingExtrasController::class)->paymentOptions()->getData(true);

    expect($night['transfer']['available'])->toBeFalse()
        ->and($night['transfer']['notice'])->toContain('Las transferencias se reciben')
        ->and($day['transfer']['available'])->toBeTrue()
        ->and($day['transfer']['notice'])->toBeNull();
});

it('grupos y experiencias responden las mismas formas de pago que el wizard', function () {
    $this->travelTo(now()->setTime(22, 0));

    expect(app(ExperienceWizardController::class)->paymentOptions()->getData(true))
        ->toEqual(app(BookingExtrasController::class)->paymentOptions()->getData(true));
});

it('un huésped en el wizard de grupos con el módulo apagado va al de habitaciones, no al panel', function () {
    $response = (new EnsureModuleEnabled)->handle(
        Request::create('/reservar/grupos', 'GET'),
        fn () => response('ok'),
        'grupos',
    );

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toEndWith('/reservar');
});

it('sin el wizard ni sesión, una página pública apagada da 404 y no la del panel', function () {
    bindWizardRedesignTenant([]);

    try {
        (new EnsureModuleEnabled)->handle(Request::create('/menu', 'GET'), fn () => response('ok'), 'menu-digital');
        $status = 200;
    } catch (HttpException $e) {
        $status = $e->getStatusCode();
    }

    expect($status)->toBe(404);
});
