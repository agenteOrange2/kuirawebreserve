<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aprendizaje del asistente: una corrección/regla del hotel nacida de una
 * conversación real. Las activas se inyectan al system prompt del bot.
 */
class AgentGuideline extends Model
{
    protected $fillable = [
        'instruction',
        'source_conversation_id',
        'active',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function sourceConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'source_conversation_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
