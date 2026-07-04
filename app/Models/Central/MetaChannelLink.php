<?php

namespace App\Models\Central;

/**
 * Canal de Meta conectado a un hotel (DB central): mapea el id externo del
 * webhook (phone_number_id / page_id) al tenant dueño de la conversación.
 * Hoy se registran a mano desde /admin (entorno de prueba); el Embedded
 * Signup de producción creará estas filas automáticamente.
 */
class MetaChannelLink extends CentralModel
{
    public const TYPES = ['whatsapp', 'messenger', 'instagram'];

    protected $table = 'meta_channel_links';

    protected $fillable = [
        'tenant_id',
        'type',
        'external_id',
        'access_token',
        'name',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'active' => 'boolean',
        ];
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'whatsapp' => 'WhatsApp',
            'messenger' => 'Messenger',
            'instagram' => 'Instagram',
            default => $this->type,
        };
    }

    public function maskedToken(): string
    {
        $token = (string) $this->access_token;

        return strlen($token) > 8 ? '••••'.substr($token, -6) : '••••';
    }
}
