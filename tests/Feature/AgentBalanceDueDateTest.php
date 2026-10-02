<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Http\Controllers\Agent\AgentToolsController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Channels\DirectGuestMessenger;
use App\Services\Channels\OutboundMessenger;
use App\Services\Payments\PaymentGuestNotifier;
use Carbon\Carbon;

/**
 * Caso real cabañas 2026-09-24 (Carlos, RES-2026-1792, llegada sábado
 * 17-oct). El bot le dijo que el saldo vencía el "miércoles 7 de octubre",
 * luego aceptó el 13 que traía el huésped y lo llamó lunes (es martes), y al
 * despedirse le listó el anticipo como "pendiente de confirmar" cuando ya
 * estaba confirmado. Antes, el sistema le mandó "está confirmada" dos veces
 * en un minuto y ninguna de las dos decía para cuándo era el saldo.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->travelTo(Carbon::parse('2026-09-24 09:50'));

    $property = Property::factory()->create(['name' => 'Cabañas Real de la Sierra']);
    $type = RoomType::factory()->create(['property_id' => $property->id, 'name' => 'Cabaña Sencilla 4']);
    Room::factory()->create(['property_id' => $property->id, 'room_type_id' => $type->id]);

    $plan = RatePlan::factory()->create([
        'property_id' => $property->id,
        'room_type_id' => $type->id,
        'price' => 3000,
        'deposit_percent' => 50,
        'payment_due_unit' => 'week',
        'payment_due_value' => 1,
    ]);

    $this->reserva = app(CreateReservation::class)->handle([
        'rate_plan_id' => $plan->id,
        'starts_at' => Carbon::parse('2026-10-17 14:00'),
        'ends_at' => Carbon::parse('2026-10-18 11:00'),
        'source_channel' => 'agent',
        'guest_name' => 'Carlos Arévalo',
    ]);

    $channel = Channel::firstOrCreate(
        ['property_id' => $property->id, 'type' => Channel::TYPE_WHATSAPP_EVOLUTION, 'external_id' => '1'],
        ['name' => 'WhatsApp', 'mode' => 'auto', 'active' => true],
    );
    $this->conversation = Conversation::create([
        'channel_id' => $channel->id,
        'contact_phone' => '5216560000000',
        'reservation_id' => $this->reserva->id,
        'status' => Conversation::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    $this->mock(OutboundMessenger::class)->shouldReceive('pushToConversation')->andReturn(true);
    $this->mock(DirectGuestMessenger::class)->shouldIgnoreMissing();
});

function registraAnticipo(Reservation $reserva): void
{
    Payment::create([
        'reservation_id' => $reserva->id,
        'amount' => 1500,
        'method' => 'transfer',
        'paid_at' => now(),
    ]);
    $reserva->refresh()->syncPaymentStatus();
}

it('le da al bot la fecha límite del saldo ya escrita con su día de la semana', function () {
    registraAnticipo($this->reserva);

    $data = app(AgentToolsController::class)->showReservation($this->reserva->code)->getData(true);

    expect($data['arrival_label'])->toBe('sábado 17 de octubre de 2026 a las 2:00 PM')
        ->and($data['departure_label'])->toBe('domingo 18 de octubre de 2026 a las 11:00 AM')
        ->and($data['balance_due_label'])->toBe('sábado 10 de octubre de 2026')
        ->and($data['payment_summary'])->toContain('Anticipo YA pagado')
        ->and($data['payment_summary'])->toContain('sábado 10 de octubre de 2026')
        ->and($data['instructions'])->toContain('no calcules fechas');
});

it('no inventa fecha límite cuando la reserva no tiene una', function () {
    $this->reserva->update(['payment_due_at' => null]);

    $data = app(AgentToolsController::class)->showReservation($this->reserva->code)->getData(true);

    expect($data['balance_due_label'])->toBeNull()
        ->and($data['instructions'])->toContain('NO tiene fecha límite registrada');
});

it('no repite "está confirmada" cuando el pago se registra justo después de confirmar', function () {
    app(TransitionReservation::class)->confirm($this->reserva->refresh());
    registraAnticipo($this->reserva);

    app(PaymentGuestNotifier::class)->manualPaymentReceived($this->reserva, 1500, 'transfer');

    $mensajes = $this->conversation->messages()->where('sender_type', 'system')->pluck('body');

    expect($mensajes)->toHaveCount(2)
        ->and(substr_count($mensajes->implode(' '), 'está confirmada'))->toBe(1)
        ->and($mensajes->last())->toContain('Quedó registrado en tu reserva')
        ->and($mensajes->last())->toContain('Saldo pendiente: $1,500.00, a liquidar a más tardar el sábado 10 de octubre de 2026.');
});

it('los textos del hotel toman el plazo del saldo del ajuste, no uno escrito a mano', function () {
    $property = Property::first();
    $property->update(['settings' => array_merge($property->settings ?? [], [
        'balance_due_value' => 7,
        'balance_due_unit' => 'day',
        'cancel_policy_text' => 'El saldo se liquida {plazo_saldo}; si no, se cancela.',
    ])]);
    $policy = fn () => app(\App\Services\ReservationPolicy::class);

    expect($policy()->fillTerms('El saldo se liquida {plazo_saldo}.'))->toBe('El saldo se liquida 7 días antes de la llegada.')
        ->and($policy()->cancellationPolicyText())->toBe('El saldo se liquida 7 días antes de la llegada; si no, se cancela.');

    // El hotel cambia el ajuste: los textos lo siguen sin que nadie los reescriba.
    $property->update(['settings' => array_merge($property->fresh()->settings, ['balance_due_value' => 10])]);
    app()->forgetInstance(\App\Services\ReservationPolicy::class);

    expect($policy()->fillTerms('El saldo se liquida {plazo_saldo}.'))->toBe('El saldo se liquida 10 días antes de la llegada.');
});
