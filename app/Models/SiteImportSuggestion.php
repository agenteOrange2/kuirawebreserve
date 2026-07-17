<?php

namespace App\Models;

use App\Actions\Catalog\AddBaseRatePlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sugerencia del agente importador (pendiente hasta que un humano la
 * aplique o descarte). El payload trae SOLO ficha (nombre, descripción,
 * capacidad, amenidades) — jamás precios: el precio nace en las tarifas.
 */
class SiteImportSuggestion extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_DISCARDED = 'discarded';

    protected $fillable = [
        'source_url',
        'room_type_id',
        'action',
        'payload',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * Resultado transitorio de la última llamada a apply() — no persiste,
     * solo lo lee el controlador para el mensaje al usuario.
     */
    public ?string $createdRoomNumber = null;

    public ?string $roomSkippedReason = null;

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Aplica la sugerencia al catálogo. La ficha (nombre/descripción/
     * amenidades) siempre se aplica; la tarifa es aparte y opcional:
     *
     * - $rate viene del humano en ESTE mismo paso (nunca del scrape a
     *   ciegas) — el precio propuesto por la IA solo llega como valor por
     *   defecto editable en el formulario de aprobación.
     * - Si el tipo YA tiene una tarifa activa, $rate se IGNORA siempre:
     *   jamás se toca un precio que el hotel ya está vendiendo (el
     *   precio-fantasma que el sistema entero evita).
     * - Un tipo nuevo sin tarifa confirmada nace INACTIVO y sin tarifa:
     *   la guarda "Sin tarifa — no reservable" obliga a ponerle precio
     *   conscientemente después, si no se hizo aquí.
     *
     * También CREA la habitación física si el tipo aún no tiene ninguna
     * (número autogenerado, sin pedir nada extra) — nunca duplica si el
     * tipo ya tiene al menos una habitación: ahí solo se completa el
     * nombre si estaba vacío, sin tocar nada que el hotel ya haya
     * capturado a mano.
     *
     * @param  array{price: mixed, rate_type: string, duration_unit?: ?string, duration_value?: mixed}|null  $rate
     */
    public function apply(?int $userId = null, ?array $rate = null): RoomType
    {
        $payload = $this->payload;
        $this->createdRoomNumber = null;
        $this->roomSkippedReason = null;

        if ($this->action === 'update' && $this->room_type_id) {
            $type = RoomType::findOrFail($this->room_type_id);

            $type->fill(array_filter([
                'description' => $payload['description'] ?? null,
                'capacity' => $payload['capacity'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''));

            if (! empty($payload['amenities'])) {
                $type->amenities = collect([...$type->amenities ?? [], ...$payload['amenities']])
                    ->map(fn (string $a) => trim($a))
                    ->filter()
                    ->unique(fn (string $a) => mb_strtolower($a))
                    ->values()
                    ->all();
            }

            $type->save();
        } else {
            $type = RoomType::create([
                'property_id' => Property::query()->value('id'),
                'name' => $payload['name'],
                'description' => $payload['description'] ?? null,
                'capacity' => $payload['capacity'] ?? 2,
                'amenities' => $payload['amenities'] ?? [],
                'sort_order' => (int) RoomType::query()->max('sort_order') + 1,
                'active' => false,
            ]);
        }

        if ($rate && ! empty($rate['price']) && ! $type->hasActiveRate()) {
            app(AddBaseRatePlan::class)->execute($type, $rate);

            // Nace con precio confirmado: ya no hace falta esconderlo tras
            // la guarda de "sin tarifa" (solo para tipos NUEVOS; un tipo
            // existente que el hotel apagó a propósito no se reactiva solo).
            if ($this->action === 'create') {
                $type->update(['active' => true]);
            }
        }

        if ($type->rooms()->exists()) {
            // No duplica: solo completa el nombre de habitaciones que
            // todavía no tuvieran uno (nunca pisa un nombre ya capturado).
            $type->rooms()->whereNull('name')->update(['name' => $payload['name'] ?? null]);
        } else {
            $max = tenant()?->planLimit('max_rooms');

            if ($max !== null && Room::count() >= $max) {
                $this->roomSkippedReason = "Límite del plan alcanzado: máximo {$max} habitaciones. Créala manualmente tras liberar espacio o mejorar el plan.";
            } else {
                $room = Room::create([
                    'property_id' => $type->property_id,
                    'room_type_id' => $type->id,
                    'number' => Room::nextAvailableNumber('100', $type->property_id),
                    'name' => $payload['name'] ?? null,
                ]);
                $this->createdRoomNumber = $room->number;
            }
        }

        $this->update(['status' => self::STATUS_APPLIED, 'created_by' => $this->created_by ?? $userId]);

        return $type;
    }
}
