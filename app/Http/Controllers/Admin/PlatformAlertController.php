<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\PlatformAlert;
use App\Models\Tenant;
use App\Services\Admin\PlatformAlertScanner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Avisos del panel de plataforma: lo que pasa en los hoteles y necesita a
 * un administrador. Los arma PlatformAlertScanner; aquí solo se leen y se
 * marcan (leído, pospuesto, descartado).
 */
class PlatformAlertController extends Controller
{
    /** Etiqueta y familia de cada tipo, para filtros y chips. */
    public const TYPES = [
        'ai_quota' => 'Cuota de IA',
        'ai_providers' => 'Llaves de IA',
        'new_tenant' => 'Hotel nuevo',
        'new_prospect' => 'Prospecto',
        'module_request' => 'Solicitud de módulo',
        'gateway_test' => 'Pasarela en pruebas',
        'orphan_gateway' => 'Pasarela huérfana',
        'channel_silent' => 'Canal sin mensajes',
        'tenant_unreachable' => 'Base sin respuesta',
        'no_owner' => 'Sin propietario',
        'plan_cap' => 'Tope del plan',
        'undelivered' => 'Mensajes no entregados',
        'guests_waiting' => 'Huéspedes esperando',
    ];

    public const STATES = ['open', 'snoozed', 'dismissed', 'resolved'];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(self::STATES)],
            'severity' => ['nullable', Rule::in(PlatformAlert::SEVERITIES)],
            'type' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'tenant' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $state = $filters['state'] ?? 'open';

        $query = PlatformAlert::query()
            ->{$state}()
            ->when($filters['severity'] ?? null, fn (Builder $q, $v) => $q->where('severity', $v))
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->where('type', $v))
            ->when($filters['tenant'] ?? null, fn (Builder $q, $v) => $q->where('tenant_id', $v))
            ->when($filters['q'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $w) => $w
                ->where('title', 'like', "%{$v}%")->orWhere('body', 'like', "%{$v}%")));

        // Abiertos: lo urgente primero y lo no leído antes que lo leído.
        $state === 'open'
            ? $query->orderByRaw("CASE severity WHEN 'danger' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
                ->orderByRaw('read_at IS NOT NULL')->orderByDesc('first_seen_at')
            : $query->orderByDesc($state === 'resolved' ? 'resolved_at' : 'updated_at');

        $names = Tenant::query()->pluck('name', 'id');

        $alerts = $query->with('dismisser:id,name')->paginate(25)->withQueryString()
            ->through(fn (PlatformAlert $a) => [
                'id' => $a->id,
                'type' => $a->type,
                'type_label' => self::TYPES[$a->type] ?? $a->type,
                'severity' => $a->severity,
                'title' => $a->title,
                'body' => $a->body,
                'url' => $a->url,
                'tenant' => $a->tenant_id ? [
                    'id' => $a->tenant_id,
                    'name' => $names[$a->tenant_id] ?? $a->tenant_id,
                    'exists' => $names->has($a->tenant_id),
                ] : null,
                'read' => $a->read_at !== null,
                'first_seen_ago' => $a->first_seen_at?->diffForHumans(),
                'first_seen_at' => $a->first_seen_at?->format('d/m/Y H:i'),
                'last_seen_ago' => $a->last_seen_at?->diffForHumans(),
                'resolved_ago' => $a->resolved_at?->diffForHumans(),
                'snoozed_until' => $a->snoozed_until?->locale('es')->isoFormat('ddd D MMM, HH:mm'),
                'dismissed_ago' => $a->dismissed_at?->diffForHumans(),
                'dismissed_by' => $a->dismisser?->name,
            ]);

        $open = PlatformAlert::query()->open();

        return Inertia::render('admin/alerts/Index', [
            'alerts' => $alerts,
            'filters' => ['state' => $state] + $filters,
            'counts' => [
                'danger' => (clone $open)->where('severity', 'danger')->count(),
                'warning' => (clone $open)->where('severity', 'warning')->count(),
                'info' => (clone $open)->where('severity', 'info')->count(),
                'unread' => (clone $open)->whereNull('read_at')->count(),
                'open' => (clone $open)->count(),
                'snoozed' => PlatformAlert::query()->snoozed()->count(),
                'dismissed' => PlatformAlert::query()->dismissed()->count(),
                'resolved_week' => PlatformAlert::query()->resolved()->where('resolved_at', '>=', now()->subWeek())->count(),
            ],
            'types' => collect(self::TYPES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'tenants' => $names->map(fn ($name, $id) => ['value' => $id, 'label' => $name ?: $id])->sortBy('label')->values(),
            'lastScan' => PlatformAlertScanner::lastScan()?->diffForHumans(),
        ]);
    }

    /** "Revisar ahora": corre el escáner sin esperar al programador. */
    public function scan(PlatformAlertScanner $scanner): RedirectResponse
    {
        $result = $scanner->scan();

        return back()->with('success', $result['created']
            ? "Revisión lista: {$result['created']} aviso(s) nuevo(s)."
            : 'Revisión lista: nada nuevo.');
    }

    /**
     * Acciones sobre uno o varios avisos: leído, no leído, posponer,
     * descartar o restaurar.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['read', 'unread', 'snooze', 'dismiss', 'restore'])],
            'hours' => ['required_if:action,snooze', 'nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $query = PlatformAlert::query()->whereIn('id', $data['ids']);

        $query->update(match ($data['action']) {
            'read' => ['read_at' => now()],
            'unread' => ['read_at' => null],
            'snooze' => ['snoozed_until' => now()->addHours((int) $data['hours']), 'read_at' => now()],
            'dismiss' => ['dismissed_at' => now(), 'dismissed_by' => $request->user()->id, 'read_at' => now()],
            'restore' => ['dismissed_at' => null, 'dismissed_by' => null, 'snoozed_until' => null],
        });

        return back();
    }

    /** Marca como leídos todos los abiertos. */
    public function readAll(): RedirectResponse
    {
        PlatformAlert::query()->open()->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }
}
