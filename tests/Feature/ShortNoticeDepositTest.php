<?php

use App\Actions\Payments\IssuePaymentRequest;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Actions\Reservations\UpdateReservation;
use App\Enums\ReservationStatus;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Agent\AgentToolsController;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\ReservationPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;

// Cabañas 2026-10-01: quien reserva con menos de 7 días deja 70% de anticipo
// y paga el resto al llegar; si cancela no hay reembolso, se reagenda o su
// pago queda como "fecha pendiente".

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);
    $this->travelTo(Carbon::parse('2026-10-01 10:00'));

    $this->property = Property::factory()->create([
        'settings' => [
            'short_notice_days' => 7,
            'short_notice_deposit_percent' => 70,
            'short_notice_policy_text' => 'No hay reembolso: puedes reagendar si el hotel lo autoriza o dejar tu pago con fecha pendiente.',
            'counter_methods' => ['cash', 'card', 'transfer'],
        ],
    ]);
    $type = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $type->id]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $type->id,
        'price' => 3000,
        'deposit_percent' => 50,
        'payment_due_unit' => 'day',
        'payment_due_value' => 3,
    ]);
});

function shortNoticeBooking(Carbon $start): Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->plan->id,
        'room_id' => test()->room->id,
        'starts_at' => $start,
        'ends_at' => $start->copy()->addDay()->setTime(11, 0),
        'guest_name' => 'Huésped de último momento',
    ]);
}

it('con llegada en menos de 7 días pide 70% y el saldo no tiene fecha límite', function () {
    // Llega el 7 de octubre: 6 días de calendario desde hoy.
    $reserva = shortNoticeBooking(Carbon::parse('2026-10-07 14:00'));

    expect((float) $reserva->deposit_amount)->toBe(2100.0)
        ->and($reserva->payment_due_at)->toBeNull();
});

it('a 7 días o más sigue el anticipo de la tarifa y su fecha límite', function () {
    $reserva = shortNoticeBooking(Carbon::parse('2026-10-08 14:00'));

    expect((float) $reserva->deposit_amount)->toBe(1500.0)
        ->and($reserva->payment_due_at?->toDateString())->toBe('2026-10-05');
});

it('con la regla apagada todo sigue como siempre', function () {
    $this->property->update(['settings' => ['short_notice_days' => 0, 'short_notice_deposit_percent' => 70]]);

    $reserva = shortNoticeBooking(Carbon::parse('2026-10-03 14:00'));

    expect((float) $reserva->deposit_amount)->toBe(1500.0);
});

it('una tarifa sin anticipo no inventa uno', function () {
    $this->plan->update(['deposit_percent' => null]);

    expect(app(ReservationPolicy::class)->depositFor($this->plan->fresh(), 3000, Carbon::parse('2026-10-03 14:00')))->toBeNull();
});

it('mover cerca de la llegada una reserva hecha con tiempo no le sube el anticipo', function () {
    $reserva = shortNoticeBooking(Carbon::parse('2026-10-30 14:00'));
    expect((float) $reserva->deposit_amount)->toBe(1500.0);

    $this->travelTo(Carbon::parse('2026-10-25 10:00'));
    app(UpdateReservation::class)->handle($reserva, [
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => Carbon::parse('2026-10-27 14:00'),
        'ends_at' => Carbon::parse('2026-10-28 11:00'),
    ]);

    expect((float) $reserva->fresh()->deposit_amount)->toBe(1500.0);
});

it('el cobro del anticipo pide el 70%', function () {
    $reserva = shortNoticeBooking(Carbon::parse('2026-10-04 14:00'));

    $cobro = app(IssuePaymentRequest::class)->handle($reserva);

    expect((float) $cobro->amount)->toBe(2100.0);
});

it('el bot conoce la regla y dice que el saldo se paga al llegar', function () {
    $policies = app(AgentToolsController::class)->policies()->getData(true);

    expect($policies['short_notice']['deposit_percent'])->toEqual(70)
        ->and($policies['short_notice']['cancellation'])->toContain('fecha pendiente')
        ->and(app(ReservationPolicy::class)->balanceDueNotice($this->plan, Carbon::parse('2026-10-04 14:00')))
        ->toBe('Como tu llegada es en menos de 7 días, el resto se paga al llegar al hotel en efectivo, terminal o transferencia.');
});

it('fecha pendiente: libera la cabaña, conserva lo pagado y no sugiere reembolso', function () {
    $reserva = shortNoticeBooking(Carbon::parse('2026-10-04 14:00'));
    app(TransitionReservation::class)->confirm($reserva);
    Payment::create(['reservation_id' => $reserva->id, 'amount' => 2100, 'method' => 'transfer', 'paid_at' => now()]);

    $user = User::factory()->create();
    app(TransitionReservation::class)->setDatePending($reserva->fresh(), $user, 'Quiere venir en diciembre');

    $reserva->refresh();
    expect($reserva->status)->toBe(ReservationStatus::Cancelled)
        ->and($reserva->date_pending_at)->not->toBeNull()
        ->and($reserva->date_pending_note)->toBe('Quiere venir en diciembre')
        ->and($reserva->paidTotal())->toBe(2100.0)
        ->and($reserva->suggestedRefund())->toBeNull();

    // Reagendar con fechas nuevas la saca de "pendiente" sin tocar el pago.
    app(TransitionReservation::class)->reopen($reserva, $user, [
        'starts_at' => Carbon::parse('2026-12-12 14:00'),
        'ends_at' => Carbon::parse('2026-12-13 11:00'),
    ]);

    $reserva->refresh();
    expect($reserva->date_pending_at)->toBeNull()
        ->and($reserva->starts_at->toDateString())->toBe('2026-12-12')
        ->and($reserva->paidTotal())->toBe(2100.0);
});
