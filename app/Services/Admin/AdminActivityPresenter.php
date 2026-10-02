<?php

namespace App\Services\Admin;

use App\Models\Central\AdminActivity;
use App\Services\PropertyMode;

/**
 * Convierte un renglón de la bitácora en lo que se lee en pantalla: la
 * frase de la acción, sobre qué cayó y, debajo, los detalles que importan
 * (qué cambió, qué módulo, qué modo). Nada de volcar el formulario entero.
 */
class AdminActivityPresenter
{
    protected const FIELDS = [
        'name' => 'Nombre',
        'label' => 'Nombre',
        'plan' => 'Plan',
        'email' => 'Correo',
        'phone' => 'Teléfono',
        'active' => 'Activo',
        'public' => 'Público',
        'status' => 'Estado',
        'price' => 'Precio',
        'price_monthly' => 'Precio mensual',
        'model' => 'Modelo',
        'provider' => 'Proveedor',
        'title' => 'Título',
        'mode' => 'Modo',
        'is_admin' => 'Administrador',
        'role' => 'Rol',
        'module' => 'Módulo',
        'hotel_name' => 'Hotel',
        'notes' => 'Notas',
    ];

    /**
     * @param  array<string, string>  $tenantNames  id => nombre, para no consultar por renglón
     * @return array<string, mixed>
     */
    public static function present(AdminActivity $a, array $tenantNames = []): array
    {
        $meta = AdminActivityCatalog::describe($a->action);
        $p = $a->properties ?? [];

        if ($a->action === 'admin.tenants.suspend' && array_key_exists('suspended', $p)) {
            $meta['label'] = $p['suspended'] ? 'Suspendió el hotel' : 'Reactivó el hotel';
            $meta['icon'] = $p['suspended'] ? 'Pause' : 'Play';
            $meta['tone'] = $p['suspended'] ? 'warning' : 'success';
        }

        $tenantLabel = $a->tenant_id
            ? ($a->subject_type === 'tenant' ? $a->subject_label : null) ?? $tenantNames[$a->tenant_id] ?? $a->tenant_id
            : null;

        return [
            'id' => $a->id,
            'action' => $a->action,
            'label' => $meta['label'],
            'icon' => $meta['icon'],
            'tone' => $meta['tone'],
            'category' => $meta['category'],
            'category_label' => $meta['category_label'],
            // El hotel va en su propia pastilla; el sujeto solo si es otra cosa.
            'subject' => $a->subject_type === 'tenant' ? null : $a->subject_label,
            'subject_type' => $a->subject_type,
            'subject_id' => $a->subject_id,
            'tenant' => $a->tenant_id ? [
                'id' => $a->tenant_id,
                'name' => $tenantLabel,
                // Solo se enlaza si el hotel sigue existiendo.
                'exists' => isset($tenantNames[$a->tenant_id]),
            ] : null,
            'day' => $a->created_at?->format('Y-m-d'),
            'details' => self::details($a->action, $p),
            'ip' => $a->ip,
            'device' => self::device($a->user_agent),
            'at' => $a->created_at?->format('d/m/Y H:i'),
            'ago' => $a->created_at?->diffForHumans(),
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<string>
     */
    public static function details(string $action, array $p): array
    {
        $lines = [];
        $input = is_array($p['input'] ?? null) ? $p['input'] : [];

        foreach ((array) ($p['changes'] ?? []) as $field => [$old, $new]) {
            $lines[] = self::field($field).': '.self::value($field, $old).' → '.self::value($field, $new);
        }

        if ($action === 'admin.tenants.modules' && isset($input['module'])) {
            $mode = match ($input['mode'] ?? null) {
                'on' => 'forzado activado',
                'off' => 'forzado apagado',
                default => 'hereda del plan',
            };
            $lines[] = "Módulo {$input['module']}: {$mode}";
        } elseif (isset($p['module'])) {
            $lines[] = "Módulo {$p['module']}";
        }
        if (isset($p['service'])) {
            $lines[] = 'Servicio: '.$p['service'];
        }
        if (isset($p['staff_id'])) {
            $lines[] = 'Personal #'.$p['staff_id'];
        }

        // Sin cambios medibles (altas, pruebas, acciones sobre listas): los
        // primeros datos que se mandaron dan contexto suficiente.
        if (! $lines && ! str_starts_with($action, 'auth.')) {
            foreach ($input as $field => $value) {
                if (count($lines) >= 3 || is_array($value) || $value === null || $value === '' || $value === '(oculto)') {
                    continue;
                }
                $lines[] = self::field((string) $field).': '.self::value((string) $field, $value);
            }
        }

        return $lines;
    }

    protected static function field(string $field): string
    {
        return self::FIELDS[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    protected static function value(string $field, mixed $value): string
    {
        if ($field === 'mode' && is_string($value)) {
            return PropertyMode::LABELS[$value] ?? $value;
        }
        if ($field === 'plan' && is_string($value)) {
            return config("plans.{$value}.label", $value);
        }

        return match (true) {
            $value === null || $value === '' => 'vacío',
            is_bool($value) => $value ? 'sí' : 'no',
            default => mb_strimwidth((string) $value, 0, 80, '…'),
        };
    }

    /** "Chrome en Windows": lo que hace falta para reconocer el equipo. */
    public static function device(?string $agent): ?string
    {
        if (! $agent) {
            return null;
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };
        $os = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return trim(($browser ?? 'Navegador').($os ? " en {$os}" : '')) ?: null;
    }
}
