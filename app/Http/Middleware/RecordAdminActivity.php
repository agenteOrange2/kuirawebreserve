<?php

namespace App\Http\Middleware;

use App\Models\Central\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\AdminActivityRecorder;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja en la bitácora toda acción del panel de plataforma que cambia algo
 * (POST/PUT/PATCH/DELETE bajo /admin) y que salió bien. Va en el grupo de
 * rutas de admin, así que una ruta nueva queda registrada sin tocar nada.
 *
 * El sujeto se lee ANTES de la acción: después de un borrado ya no existe
 * y la bitácora tiene que seguir diciendo qué se borró.
 */
class RecordAdminActivity
{
    /** Parámetros de ruta que no son el hotel, en orden de preferencia. */
    protected const SUBJECTS = [
        'user' => 'user',
        'plan' => 'plan',
        'addonService' => 'service',
        'planProspect' => 'prospect',
        'prospectDocument' => 'document',
        'platformAiProvider' => 'ai_provider',
        'metaChannelLink' => 'channel',
        'telegramChannelLink' => 'channel',
        'tiktokChannelLink' => 'channel',
    ];

    /**
     * Acciones de limpieza que no cambian nada de un hotel: marcar avisos
     * como leídos llenaría la bitácora de ruido. Quién descartó un aviso ya
     * se guarda en el aviso mismo (dismissed_by).
     */
    protected const NOT_RECORDED = ['admin.alerts.update', 'admin.alerts.read-all', 'admin.alerts.scan'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! $request->user()
            || in_array($request->route()?->getName(), self::NOT_RECORDED, true)) {
            return $next($request);
        }

        $action = $request->route()?->getName() ?? 'admin.'.strtolower($request->method()).':'.$request->path();
        [$subject, $model, $before] = $this->subject($request);

        $response = $next($request);

        if (! $this->succeeded($request, $response)) {
            return $response;
        }

        // La bitácora nunca tumba una acción que ya se guardó.
        try {
            $this->record($request, $action, $subject, $model, $before);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Bitácora del admin sin registrar', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>|null  $subject
     * @param  array<string, mixed>  $before
     */
    protected function record(Request $request, string $action, ?array $subject, ?Model $model, array $before): void
    {

        $properties = ['input' => AdminActivityRecorder::sanitize($request->except(array_keys($request->route()?->parameters() ?? [])))];
        if ($changes = $this->changes($request, $model, $before)) {
            $properties['changes'] = $changes;
        }
        // Lo que el controlador sabe y el middleware no (p. ej. el modo del
        // hotel, que vive dentro de su propia base).
        foreach ((array) $request->attributes->get('admin_activity.changes', []) as $field => $pair) {
            $properties['changes'][$field] = $pair;
        }
        if ($action === 'admin.tenants.suspend' && $model instanceof Tenant) {
            $properties['suspended'] = $model->fresh()?->isSuspended();
        }
        foreach (['userId' => 'staff_id', 'module' => 'module'] as $param => $key) {
            if ($value = $request->route($param)) {
                $properties[$key] = (string) $value;
            }
        }
        if ($request->route('addonService') && $request->route('tenant')) {
            $properties['service'] = $request->route('addonService')->name ?? null;
        }

        // Altas: el sujeto no existía antes de la acción.
        if (! $subject && str_ends_with($action, '.store')) {
            $subject = $this->created($action, $request);
        }

        AdminActivityRecorder::record(
            $request->user(),
            $action,
            $subject ?? [],
            array_filter($properties, fn ($v) => $v !== null && $v !== []),
            $request,
        );
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: Model|null, 2: array<string, mixed>}
     */
    protected function subject(Request $request): array
    {
        $tenant = $request->route('tenant');
        $tenant = $tenant instanceof Tenant ? $tenant : null;

        foreach (self::SUBJECTS as $param => $type) {
            $model = $request->route($param);
            if (! $model instanceof Model || ($param === 'addonService' && $tenant)) {
                continue;
            }

            $tenantId = $tenant?->id ?? $model->getAttribute('tenant_id');

            return [[
                'type' => $type,
                'id' => $model->getKey(),
                'label' => $this->labelOf($model),
                'tenant_id' => $tenantId,
            ], $model, $model->getAttributes()];
        }

        if ($tenant) {
            return [[
                'type' => 'tenant',
                'id' => $tenant->id,
                'label' => $tenant->name ?? $tenant->id,
                'tenant_id' => $tenant->id,
            ], $tenant, ['name' => $tenant->name, 'plan' => $tenant->plan]];
        }

        return [null, null, []];
    }

    protected function labelOf(Model $model): ?string
    {
        return match (true) {
            $model instanceof Plan => $model->label,
            method_exists($model, 'label') => $model->label(),
            default => $model->getAttribute('hotel_name')
                ?? $model->getAttribute('name')
                ?? $model->getAttribute('title')
                ?? (string) $model->getKey(),
        };
    }

    /**
     * Qué cambió de verdad en el sujeto, solo en los campos que se mandaron.
     *
     * @param  array<string, mixed>  $before
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    protected function changes(Request $request, ?Model $model, array $before): array
    {
        if (! $model || ! $model->exists || ! ($after = $model->fresh())) {
            return [];
        }

        $changes = [];
        foreach (array_keys($request->all()) as $key) {
            if (! is_string($key) || ! array_key_exists($key, $before) || preg_match(AdminActivityRecorder::SENSITIVE, $key)) {
                continue;
            }
            // Crudo contra crudo: el casteado de una columna json es un
            // arreglo y el crudo un texto; compararlos tronaba la petición.
            $old = $before[$key];
            $new = $after->getAttributes()[$key] ?? null;
            if ((is_scalar($old) || $old === null) && (is_scalar($new) || $new === null)
                && (string) $old !== (string) $new) {
                $changes[$key] = [$old, $new];
            }
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function created(string $action, Request $request): ?array
    {
        if ($action === 'admin.tenants.store' && ($tenant = Tenant::find($request->input('subdomain')))) {
            return ['type' => 'tenant', 'id' => $tenant->id, 'label' => $tenant->name ?? $tenant->id, 'tenant_id' => $tenant->id];
        }
        if ($action === 'admin.users.store' && ($user = User::where('email', $request->input('email'))->first())) {
            return ['type' => 'user', 'id' => $user->id, 'label' => $user->name];
        }

        $label = $request->input('name') ?? $request->input('label') ?? $request->input('title');

        return $label ? ['label' => (string) $label, 'tenant_id' => $request->input('tenant_id')] : null;
    }

    protected function succeeded(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return false;
        }

        // Inertia: la validación fallida vuelve con 302 y errores recién
        // flasheados (los de la petición anterior viven en _flash.old).
        return ! ($request->hasSession()
            && in_array('errors', (array) $request->session()->get('_flash.new', []), true));
    }
}
