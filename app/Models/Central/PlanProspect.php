<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contacto comercial generado desde la landing pública.
 */
class PlanProspect extends CentralModel
{
    /** @use HasFactory<\Database\Factories\Central\PlanProspectFactory> */
    use HasFactory;

    /** @var list<string> */
    public const STATUSES = ['new', 'contacted', 'qualified', 'won', 'lost'];

    /** @var array<string, string> */
    public const SERVICES = [
        'web' => 'Página web',
        'social' => 'Manejo de redes sociales',
        'reservas' => 'Sistema de reservas',
    ];

    protected $fillable = [
        'name',
        'hotel_name',
        'email',
        'phone',
        'has_whatsapp',
        'rooms',
        'plan_key',
        'plan_label',
        'message',
        'services',
        'status',
        'notes',
        'source',
        'ip_hash',
        'contacted_at',
        'docs_email_sent_at',
        'docs_whatsapp_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'rooms' => 'integer',
            'has_whatsapp' => 'boolean',
            'services' => 'array',
            'contacted_at' => 'datetime',
            'docs_email_sent_at' => 'datetime',
            'docs_whatsapp_sent_at' => 'datetime',
        ];
    }

    /**
     * Etiquetas legibles de los servicios de interés.
     *
     * @return list<string>
     */
    public function serviceLabels(): array
    {
        return collect($this->services ?? [])
            ->map(fn (string $key) => self::SERVICES[$key] ?? $key)
            ->values()
            ->all();
    }

    /**
     * Teléfono normalizado para wa.me; lada 52 por defecto en números de 10 dígitos.
     */
    public function whatsappNumber(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->phone) ?? '';

        if ($digits === '') {
            return null;
        }

        return strlen($digits) === 10 ? '52'.$digits : $digits;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_key', 'key');
    }
}
