<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Canal de conversación (webchat, WhatsApp, Messenger, Instagram). El modo
 * define cuánto poder tiene el bot: auto responde solo, copilot sugiere y
 * un humano aprueba, off deja todo al staff.
 */
class Channel extends Model
{
    public const MODES = ['auto', 'copilot', 'off'];

    public const TYPE_WEBCHAT = 'webchat';

    /** WhatsApp vía Evolution API self-hosted (alternativa a Meta Cloud API). */
    public const TYPE_WHATSAPP_EVOLUTION = 'whatsapp_evo';

    public const TYPE_LABELS = [
        'webchat' => 'Webchat',
        'whatsapp' => 'WhatsApp',
        'whatsapp_evo' => 'WhatsApp (Evolution)',
        'messenger' => 'Messenger',
        'instagram' => 'Instagram',
    ];

    protected $fillable = [
        'property_id',
        'type',
        'external_id',
        'name',
        'mode',
        'credentials',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'active' => 'boolean',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** Canal webchat del hotel (se crea solo la primera vez). */
    public static function webchat(): self
    {
        return self::firstOrCreate(
            ['property_id' => Property::firstOrFail()->id, 'type' => self::TYPE_WEBCHAT],
            ['name' => 'Webchat', 'mode' => 'auto', 'active' => true],
        );
    }
}
