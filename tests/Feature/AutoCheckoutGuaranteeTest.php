<?php

use App\Actions\Reservations\CreateWalkInStay;
use App\Events\RoomStatusChanged;
use App\Models\CashCut;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Refund;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\CashCutService;
use Illuminate\Support\Facades\Event;

/**
 * Regla del hotel: la fianza se devuelve al salir, siempre y cuando no quede
 * nada por cobrar. La salida automática de las 11:15 cerraba la estancia sin
 * tocarla y el corte esperaba en el cajón dinero que ya se había regresado.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $type = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $type->id]);
    $this->plan = RatePlan::factory()->block(180, 250)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $type->id,
    ]);
    $this->clerk = User::factory()->create();
});

/** Estancia vencida con fianza en efectivo; `$paid` liquida el hospedaje. */
function overdueWithGuarantee(bool $paid): array
{
    $stay = app(CreateWalkInStay::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'guest_name' => 'Huésped',
    ]);
    $stay->forceFill(['planned_end_at' => now()->subMinutes(30)])->saveQuietly();

    if ($paid) {
        $stay->payments()->create([
            'amount' => $stay->folio()['grand_pending'],
            'method' => 'cash',
            'kind' => Payment::KIND_LODGING,
            'received_by' => test()->clerk->id,
            'paid_at' => now()->subHour(),
        ]);
    }

    $guarantee = $stay->payments()->create([
        'amount' => 1500,
        'method' => 'cash',
        'kind' => Payment::KIND_GUARANTEE,
        'received_by' => test()->clerk->id,
        'paid_at' => now()->subHour(),
    ]);

    return [$stay->refresh(), $guarantee];
}

it('sin nada por cobrar, la salida automática devuelve la fianza', function () {
    [$stay, $guarantee] = overdueWithGuarantee(paid: true);

    $this->artisan('stays:auto-checkout')->assertSuccessful();

    $refund = Refund::query()->where('payment_id', $guarantee->id)->first();

    expect($stay->refresh()->status)->toBe(Stay::STATUS_COMPLETED)
        ->and($refund)->not->toBeNull()
        ->and((float) $refund->amount)->toEqual(1500.0)
        // A nombre de quien la cobró: sale de SU cajón en el corte.
        ->and($refund->created_by)->toBe($this->clerk->id);

    $totals = app(CashCutService::class)->compute($this->clerk, now()->subDay(), now()->addMinute(), null, CashCut::SCOPE_ROOMS);

    expect($totals['guarantees_cash_in'])->toEqual(1500.0)
        ->and($totals['guarantees_cash_out'])->toEqual(1500.0);
});

it('con saldo pendiente la fianza no se toca: la decide una persona', function () {
    [$stay, $guarantee] = overdueWithGuarantee(paid: false);

    $this->artisan('stays:auto-checkout')->assertSuccessful();

    expect($stay->refresh()->status)->toBe(Stay::STATUS_COMPLETED)
        ->and(Refund::query()->where('payment_id', $guarantee->id)->exists())->toBeFalse();
});

it('el comando de las colgadas solo lista sin --apply y fecha la devolución en la salida', function () {
    [$stay, $guarantee] = overdueWithGuarantee(paid: true);

    // Como quedaron antes de la regla: cerrada por el reloj, fianza viva.
    $exit = now()->subDays(3)->setTime(11, 15);
    $stay->forceFill([
        'status' => Stay::STATUS_COMPLETED,
        'check_out_at' => $exit,
        'auto_closed_at' => $exit,
    ])->saveQuietly();

    $this->artisan('guarantees:return-auto-closed')->assertSuccessful();
    expect(Refund::query()->count())->toBe(0);

    $this->artisan('guarantees:return-auto-closed', ['--apply' => true])->assertSuccessful();

    $refund = Refund::query()->where('payment_id', $guarantee->id)->firstOrFail();

    expect($refund->refunded_at->format('Y-m-d H:i'))->toBe($exit->format('Y-m-d H:i'))
        ->and($guarantee->refresh()->refundableAmount())->toEqual(0.0);

    // Correrlo otra vez no duplica nada.
    $this->artisan('guarantees:return-auto-closed', ['--apply' => true])->assertSuccessful();
    expect(Refund::query()->count())->toBe(1);
});
