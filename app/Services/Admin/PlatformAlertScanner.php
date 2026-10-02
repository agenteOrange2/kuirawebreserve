<?php

namespace App\Services\Admin;

use App\Models\Central\EvolutionChannelLink;
use App\Models\Central\MetaChannelLink;
use App\Models\Central\ModuleActivationRequest;
use App\Models\Central\PaymentGatewayLink;
use App\Models\Central\PlanProspect;
use App\Models\Central\PlatformAiProvider;
use App\Models\Central\PlatformAlert;
use App\Models\Central\TelegramChannelLink;
use App\Models\Central\TenantAgentSetting;
use App\Models\Central\TenantAiUsage;
use App\Models\Central\TiktokChannelLink;
use App\Models\Tenant;
use App\Services\Agent\PlatformAgentGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Arma los avisos del panel de plataforma: revisa las condiciones que hoy
 * se dan en los hoteles y las guarda en platform_alerts con una llave
 * estable. Lo que deja de darse se marca resuelto; lo que empeora (de
 * aviso a urgente) vuelve a abrirse aunque se hubiera leído o descartado.
 *
 * Corre cada 10 minutos (admin:scan-alerts) y desde el botón "Revisar
 * ahora". Lo central es barato; lo que vive en la base de cada hotel
 * (dueño, topes, mensajes no entregados, huéspedes esperando) abre una
 * conexión por hotel, por eso no se hace en cada carga de página.
 */
class PlatformAlertScanner
{
    public const LAST_SCAN_KEY = 'platform-alerts:last-scan';

    /** Avisos que salen de la base del hotel: si no responde, se conservan. */
    public const TENANT_DB_TYPES = ['no_owner', 'plan_cap', 'undelivered', 'guests_waiting'];

    /** Los resueltos se borran después de esto. */
    public const KEEP_RESOLVED_DAYS = 60;

    public const AI_WARNING_PERCENT = 80;

    /** Un canal activo sin eventos en tanto tiempo probablemente perdió el webhook. */
    public const CHANNEL_SILENT_DAYS = 3;

    /** Un huésped esperando al personal más de esto ya es "se está ignorando". */
    public const GUEST_WAIT_MINUTES = 60;

    public const UNDELIVERED_THRESHOLD = 3;

    /** @var array<string, string> tenant id => nombre */
    protected array $names = [];

    /**
     * @return array{open: int, created: int, resolved: int, scanned_at: string}
     */
    public function scan(): array
    {
        $tenants = Tenant::query()->get();
        $this->names = $tenants->pluck('name', 'id')->map(fn ($n, $id) => $n ?: $id)->all();

        $found = collect()
            ->merge($this->aiQuota($tenants))
            ->merge($this->aiProviders())
            ->merge($this->newTenants($tenants))
            ->merge($this->prospects())
            ->merge($this->moduleRequests())
            ->merge($this->gateways($tenants))
            ->merge($this->silentChannels($tenants));

        // Lo que sale de la base de cada hotel. Si un hotel no responde, sus
        // avisos de ese tipo no se dan por resueltos: no se sabe.
        $keepTenants = [];
        foreach ($tenants->reject(fn (Tenant $t) => $t->isSuspended()) as $tenant) {
            $result = $this->tenantChecks($tenant);
            if ($result === null) {
                $keepTenants[] = $tenant->id;
                $found->push($this->alert('tenant_unreachable', "tenant_unreachable:{$tenant->id}", 'danger', $tenant->id,
                    'Su base de datos no responde',
                    'No se pudo leer su información; su panel probablemente también falla.',
                    route('admin.tenants.show', $tenant, false)));

                continue;
            }
            $found = $found->merge($result);
        }

        return $this->persist($found->keyBy('key'), $keepTenants);
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $found
     * @param  list<string>  $keepTenants
     * @return array{open: int, created: int, resolved: int, scanned_at: string}
     */
    protected function persist(Collection $found, array $keepTenants): array
    {
        $now = now();
        $created = 0;
        $resolved = 0;

        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($found, $keepTenants, $now, &$created, &$resolved) {
            $existing = PlatformAlert::query()->whereIn('key', $found->keys())->get()->keyBy('key');

            foreach ($found as $key => $row) {
                /** @var PlatformAlert|null $alert */
                $alert = $existing->get($key);

                if (! $alert) {
                    PlatformAlert::create($row + ['first_seen_at' => $now, 'last_seen_at' => $now]);
                    $created++;

                    continue;
                }

                $changes = $row + ['last_seen_at' => $now];

                // Volvió a darse algo que ya estaba resuelto: es nuevo otra vez.
                if ($alert->resolved_at) {
                    $changes += ['resolved_at' => null, 'read_at' => null, 'dismissed_at' => null,
                        'dismissed_by' => null, 'snoozed_until' => null, 'first_seen_at' => $now];
                    $created++;
                } elseif (PlatformAlert::rank($row['severity']) < PlatformAlert::rank($alert->severity)) {
                    // Empeoró (de aviso a urgente): se vuelve a enseñar aunque
                    // ya se hubiera leído, pospuesto o descartado.
                    $changes += ['read_at' => null, 'dismissed_at' => null, 'dismissed_by' => null, 'snoozed_until' => null];
                }

                $alert->update($changes);
            }

            // Lo que no apareció en esta revisión ya no se da.
            $resolved = PlatformAlert::query()
                ->whereNull('resolved_at')
                ->whereNotIn('key', $found->keys())
                ->where(fn ($q) => $q->whereNull('tenant_id')
                    ->orWhereNotIn('tenant_id', $keepTenants)
                    ->orWhereNotIn('type', self::TENANT_DB_TYPES))
                ->update(['resolved_at' => $now]);

            PlatformAlert::query()
                ->where('resolved_at', '<', $now->copy()->subDays(self::KEEP_RESOLVED_DAYS))
                ->delete();
        });

        Cache::forever(self::LAST_SCAN_KEY, $now->toIso8601String());

        return [
            'open' => PlatformAlert::query()->open()->count(),
            'created' => $created,
            'resolved' => (int) ($resolved ?? 0),
            'scanned_at' => $now->toIso8601String(),
        ];
    }

    public static function lastScan(): ?Carbon
    {
        $at = Cache::get(self::LAST_SCAN_KEY);

        return $at ? Carbon::parse($at) : null;
    }

    // ── Detectores centrales ────────────────────────────────────────────

    /** Cuota de respuestas del bot del mes: al 80 % avisa, al 100 % el bot ya no contesta. */
    protected function aiQuota(Collection $tenants): Collection
    {
        $used = TenantAiUsage::query()
            ->where('date', '>=', now()->startOfMonth()->toDateString())
            ->selectRaw('tenant_id, SUM(replies) as replies')
            ->groupBy('tenant_id')
            ->pluck('replies', 'tenant_id');
        $settings = TenantAgentSetting::query()->get()->keyBy('tenant_id');
        $month = now()->format('Y-m');

        return $tenants
            ->reject(fn (Tenant $t) => $t->isSuspended())
            ->map(function (Tenant $tenant) use ($used, $settings, $month) {
                $setting = $settings->get($tenant->id);
                if ($setting && ! $setting->enabled) {
                    return null;
                }

                $limit = $setting?->monthly_reply_limit ?? PlatformAgentGate::defaultLimit($tenant);
                // Sin tope, o con tope 0 a propósito (plan sin IA): nada que vigilar.
                if (! $limit) {
                    return null;
                }

                $count = (int) ($used[$tenant->id] ?? 0);
                $percent = (int) floor($count / $limit * 100);
                if ($percent < self::AI_WARNING_PERCENT) {
                    return null;
                }

                $full = $count >= $limit;

                return $this->alert('ai_quota', "ai_quota:{$tenant->id}:{$month}", $full ? 'danger' : 'warning', $tenant->id,
                    $full ? 'Se le acabó la cuota de IA del mes' : "Lleva {$percent} % de su cuota de IA",
                    number_format($count).' de '.number_format($limit).' respuestas este mes. '
                        .($full ? 'El bot ya no contesta solo hasta el día 1 o hasta que se le suba la cuota.'
                            : 'A este ritmo se le acaba antes de fin de mes.'),
                    route('admin.tenants.assistant', $tenant, false),
                    ['used' => $count, 'limit' => $limit, 'percent' => $percent]);
            })
            ->filter()
            ->values();
    }

    protected function aiProviders(): Collection
    {
        if (PlatformAiProvider::query()->active()->exists()) {
            return collect();
        }

        return collect([$this->alert('ai_providers', 'ai_providers:none', 'danger', null,
            'No hay llaves de IA activas',
            'Ningún bot que use las llaves de la plataforma puede contestar.',
            route('admin.ai', [], false))]);
    }

    protected function newTenants(Collection $tenants): Collection
    {
        return $tenants
            ->filter(fn (Tenant $t) => $t->created_at?->gte(now()->subDays(7)))
            ->map(fn (Tenant $t) => $this->alert('new_tenant', "new_tenant:{$t->id}", 'info', $t->id,
                'Hotel nuevo en la plataforma',
                'Se dio de alta el '.$t->created_at->format('d/m/Y').' con el plan '.config("plans.{$t->plan}.label", $t->plan).'.',
                route('admin.tenants.show', $t, false)))
            ->values();
    }

    /** Registros del formulario público que nadie ha contactado. */
    protected function prospects(): Collection
    {
        return PlanProspect::query()
            ->where('status', 'new')
            ->whereNull('contacted_at')
            ->where('created_at', '>=', now()->subDays(30))
            ->get()
            ->map(function (PlanProspect $p) {
                $days = (int) $p->created_at->diffInDays(now());
                $who = trim(($p->name ?: 'Alguien').($p->hotel_name ? " ({$p->hotel_name})" : ''));

                return $this->alert('new_prospect', "prospect:{$p->id}", $days >= 2 ? 'warning' : 'info', null,
                    $days >= 2 ? "Prospecto sin contactar hace {$days} días" : 'Registro nuevo de prospecto',
                    $who.($p->plan_label ? " se interesó en el plan {$p->plan_label}" : ' llenó el registro')
                        .($p->rooms ? ", {$p->rooms} habitaciones" : '').'.',
                    route('admin.prospects', [], false),
                    ['prospect_id' => $p->id]);
            })
            ->values();
    }

    protected function moduleRequests(): Collection
    {
        return ModuleActivationRequest::query()->get()
            ->filter(fn ($r) => isset($this->names[$r->tenant_id]))
            ->map(function ($r) {
                $label = config("modules.{$r->module}.label", $r->module);
                $days = (int) Carbon::parse($r->created_at)->diffInDays(now());

                return $this->alert('module_request', "module_request:{$r->tenant_id}:{$r->module}", $days >= 2 ? 'warning' : 'info', $r->tenant_id,
                    "Pidió activar {$label}",
                    $days >= 1 ? "Lo solicitó desde su panel hace {$days} días y sigue sin respuesta." : 'Lo solicitó hoy desde su panel.',
                    route('admin.tenants.modules', $r->tenant_id, false),
                    ['module' => $r->module]);
            })
            ->values();
    }

    protected function gateways(Collection $tenants): Collection
    {
        $byId = $tenants->keyBy('id');

        return PaymentGatewayLink::query()->where('active', true)->get()
            ->map(function (PaymentGatewayLink $g) use ($byId) {
                $tenant = $byId->get($g->tenant_id);

                if (! $tenant) {
                    return $this->alert('orphan_gateway', "orphan_gateway:{$g->id}", 'warning', null,
                        "Pasarela {$g->providerLabel()} sin hotel",
                        "Quedó guardada con llaves del hotel «{$g->tenant_id}», que ya no existe.",
                        route('admin.payments', [], false));
                }

                if ($g->mode === 'live' || $tenant->isSuspended()) {
                    return null;
                }

                return $this->alert('gateway_test', "gateway_test:{$g->id}", 'warning', $g->tenant_id,
                    "{$g->providerLabel()} en modo pruebas",
                    'Las ligas de pago se ven normales pero no cobran dinero real.',
                    route('admin.tenants.payments', $g->tenant_id, false));
            })
            ->filter()
            ->values();
    }

    /** Canales activos que no reciben nada: casi siempre un webhook perdido. */
    protected function silentChannels(Collection $tenants): Collection
    {
        $active = $tenants->reject(fn (Tenant $t) => $t->isSuspended())->pluck('id');
        $since = now()->subDays(self::CHANNEL_SILENT_DAYS);
        $kinds = [
            'meta' => [MetaChannelLink::class, fn ($l) => $l->name ?: ucfirst((string) $l->type)],
            'evolution' => [EvolutionChannelLink::class, fn ($l) => $l->name ?: "WhatsApp {$l->instance}"],
            'telegram' => [TelegramChannelLink::class, fn ($l) => $l->name ?: 'Telegram'],
            'tiktok' => [TiktokChannelLink::class, fn ($l) => $l->name ?: 'TikTok'],
        ];

        return collect($kinds)->flatMap(fn (array $def, string $kind) => $def[0]::query()
            ->where('active', true)
            ->whereIn('tenant_id', $active)
            ->where('created_at', '<', now()->subDay())
            ->where(fn ($q) => $q->whereNull('last_event_at')->orWhere('last_event_at', '<', $since))
            ->get()
            ->map(fn ($link) => $this->alert('channel_silent', "channel_silent:{$kind}:{$link->id}", 'warning', $link->tenant_id,
                $def[1]($link).' no recibe mensajes',
                $link->last_event_at
                    ? 'Su último evento fue '.$link->last_event_at->diffForHumans().'. Revisa el webhook o el token.'
                    : 'Está activo y nunca ha recibido un evento. Revisa el webhook o el token.',
                route('admin.tenants.channels', $link->tenant_id, false),
                ['kind' => $kind, 'id' => $link->id])))
            ->values();
    }

    // ── Dentro de la base de cada hotel ─────────────────────────────────

    /** @return Collection<int, array<string, mixed>>|null null = la base no respondió */
    protected function tenantChecks(Tenant $tenant): ?Collection
    {
        try {
            return $tenant->run(function () use ($tenant) {
                $found = collect();
                $notAgent = fn ($q) => $q->whereDoesntHave('roles', fn ($r) => $r->where('name', 'agent'));

                if (\App\Models\User::role('owner')->count() === 0) {
                    $found->push($this->alert('no_owner', "no_owner:{$tenant->id}", 'danger', $tenant->id,
                        'No tiene propietario',
                        'Nadie recibe los avisos de plan y facturación, y "Entrar como" no funciona.',
                        route('admin.tenants.team', $tenant, false)));
                }

                foreach ([
                    'max_rooms' => ['Habitaciones', fn () => \App\Models\Room::count()],
                    'max_users' => ['Usuarios', fn () => \App\Models\User::query()->where($notAgent)->count()],
                ] as $limitKey => [$label, $count]) {
                    $cap = $tenant->planLimit($limitKey);
                    $used = $cap ? $count() : 0;
                    if ($cap && $used > $cap) {
                        $found->push($this->alert('plan_cap', "plan_cap:{$tenant->id}:{$limitKey}", 'warning', $tenant->id,
                            "Excede su plan en {$label}",
                            "Tiene {$used} y su plan permite {$cap}. Toca subirlo de plan o ajustar el tope.",
                            route('admin.tenants.plan', $tenant, false),
                            ['used' => $used, 'cap' => $cap]));
                    }
                }

                $undelivered = \App\Models\Message::query()
                    ->where('created_at', '>=', now()->subDay())
                    ->where('meta->undelivered', true)
                    ->count();
                if ($undelivered >= self::UNDELIVERED_THRESHOLD) {
                    $found->push($this->alert('undelivered', "undelivered:{$tenant->id}", 'warning', $tenant->id,
                        "{$undelivered} mensajes no se entregaron hoy",
                        'El canal los rechazó en las últimas 24 horas. Suele ser un token vencido o un número mal escrito.',
                        route('admin.tenants.channels', $tenant, false),
                        ['count' => $undelivered]));
                }

                $pending = \App\Models\Conversation::query()
                    ->whereNull('archived_at')
                    ->where('status', \App\Models\Conversation::STATUS_PENDING)
                    ->pluck('id');
                $waiting = collect(\App\Models\Conversation::waitingSinceFor($pending->all()))
                    ->filter(fn ($since) => $since
                        && $since->lte(now()->subMinutes(self::GUEST_WAIT_MINUTES))
                        && $since->gte(now()->subHours(\App\Console\Commands\EscalateHandoffs::MAX_AGE_HOURS)));
                if ($waiting->isNotEmpty()) {
                    $oldest = (int) $waiting->min()->diffInMinutes(now());
                    $found->push($this->alert('guests_waiting', "guests_waiting:{$tenant->id}", $waiting->count() >= 3 || $oldest >= 180 ? 'danger' : 'warning', $tenant->id,
                        $waiting->count() === 1 ? 'Un huésped espera respuesta del hotel' : "{$waiting->count()} huéspedes esperan respuesta del hotel",
                        'El bot pasó la conversación al personal y nadie ha contestado; el más antiguo lleva '
                            .($oldest >= 60 ? intdiv($oldest, 60).' h '.($oldest % 60).' min' : "{$oldest} min").'.',
                        route('admin.tenants.show', $tenant, false),
                        ['count' => $waiting->count(), 'oldest_minutes' => $oldest]));
                }

                return $found;
            });
        } catch (\Throwable $e) {
            report($e);

            return null;
        } finally {
            // run() no regresa a central si el callback truena.
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function alert(string $type, string $key, string $severity, ?string $tenantId, string $title, ?string $body, ?string $url, array $data = []): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'severity' => $severity,
            'tenant_id' => $tenantId,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'data' => $data ?: null,
        ];
    }
}
