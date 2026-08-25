<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    use InteractsWithMedia;

    public const TYPE_SIMPLE = 'simple';

    public const TYPE_COMPOSITE = 'composite';

    protected $fillable = [
        'property_id',
        'sku',
        'name',
        'category',
        'type',
        'unit',
        'price',
        'cost',
        'track_stock',
        'stock_qty',
        'reorder_point',
        'active',
        // Curación del wizard público (spec-plan-maestro, área aislada
        // /ajustes/wizard): "activo" solo dice si se puede vender en el
        // POS; esto decide si además se ofrece SIN staff de por medio al
        // huésped en /reservar. Default false — el admin elige a propósito.
        'available_in_wizard',
        // Curación del menú digital (/menu, módulo menu-digital): mismo
        // criterio que available_in_wizard pero para la carta del huésped.
        'available_in_menu',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'track_stock' => 'boolean',
            'stock_qty' => 'decimal:3',
            'reorder_point' => 'decimal:3',
            'active' => 'boolean',
            'available_in_wizard' => 'boolean',
            'available_in_menu' => 'boolean',
        ];
    }

    /**
     * Una sola foto por producto: el POS se usa de un vistazo y con prisa,
     * y una galería no aporta nada ahí. singleFile() hace que subir una
     * nueva reemplace la anterior sin dejar basura.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')->singleFile()->useDisk('public');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Sin cola para que la foto aparezca al instante tras subirla,
        // igual que en las fotos de tipos de habitación.
        $this->addMediaConversion('thumb')
            ->width(400)
            ->nonQueued()
            ->performOnCollections('photo');
    }

    /** @return array{id: int, url: string, thumb_url: string}|null */
    public function photoPayload(): ?array
    {
        $media = $this->getFirstMedia('photo');

        if ($media === null) {
            return null;
        }

        return [
            'id' => $media->id,
            'url' => route('tenant.product-photo', ['mediaId' => $media->id]),
            'thumb_url' => route('tenant.product-photo', ['mediaId' => $media->id, 'v' => 'thumb']),
        ];
    }

    /**
     * Cómo ve el producto quien vende: la página del POS y el panel de
     * consumos del plano. En un solo lugar para que no diverjan — si la
     * caseta ve un precio distinto al del mostrador, el corte no cuadra.
     *
     * @return array<string, mixed>
     */
    public function posPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'type' => $this->type,
            'price' => $this->price,
            'track_stock' => $this->track_stock,
            'stock_qty' => (float) $this->stock_qty,
            'photo' => $this->photoPayload(),
        ];
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'stockable');
    }

    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function isComposite(): bool
    {
        return $this->type === self::TYPE_COMPOSITE;
    }

    public function isLowStock(): bool
    {
        return $this->type === self::TYPE_SIMPLE
            && $this->track_stock
            && $this->reorder_point !== null
            && (float) $this->stock_qty <= (float) $this->reorder_point;
    }

    /**
     * Costo unitario actual: propio (simple) o el de su receta (composite).
     */
    public function currentUnitCost(): float
    {
        if (! $this->isComposite()) {
            return (float) $this->cost;
        }

        return round($this->recipeItems->sum(
            fn (Recipe $item) => (float) $item->quantity * (float) $item->ingredient->cost,
        ), 2);
    }
}
