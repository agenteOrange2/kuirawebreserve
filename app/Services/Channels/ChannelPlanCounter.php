<?php

namespace App\Services\Channels;

use App\Models\Central\EvolutionChannelLink;
use App\Models\Central\MetaChannelLink;
use App\Models\Central\TelegramChannelLink;
use App\Models\Central\TiktokChannelLink;

/**
 * Cuenta los canales de mensajería conectados por un hotel que consumen el
 * cupo del plan (max_channels): Meta + Evolution + Telegram + TikTok. El
 * webchat no cuenta. Punto único para que ningún alta se salte el límite.
 */
class ChannelPlanCounter
{
    public static function connected(string $tenantId): int
    {
        return MetaChannelLink::query()->where('tenant_id', $tenantId)->count()
            + EvolutionChannelLink::query()->where('tenant_id', $tenantId)->count()
            + TelegramChannelLink::query()->where('tenant_id', $tenantId)->count()
            + TiktokChannelLink::query()->where('tenant_id', $tenantId)->count();
    }
}
