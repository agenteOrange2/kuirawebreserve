<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles del personal de un hotel: qué áreas del panel ve cada uno y qué
 * permisos tiene (config/panel-areas.php). Corre SIEMPRE dentro del tenant;
 * lo usan el editor del propietario (/usuarios/roles), el del admin
 * (/admin/tenants/{id}/roles), el middleware EnsurePanelArea y el menú.
 *
 * Las áreas se guardan como OCULTAS (roles.hidden_areas): sin nada guardado
 * un rol ve todo lo que su permiso le abre, que es como funcionaba el panel
 * antes de esta capa. Así desplegarla no le quita nada a nadie.
 */
class StaffAccess
{
    /** Ve y puede todo; no se edita para que el hotel nunca quede sin quién lo administre. */
    public const OWNER = 'owner';

    /** Identidad de los bots: ni se asigna ni se edita a mano. */
    public const AGENT = 'agent';

    /**
     * Roles de fábrica: su nombre y su descripción mientras el hotel no los
     * cambie. El orden es el de jerarquía (listas y selectores).
     */
    public const SYSTEM_ROLES = [
        'owner' => ['label' => 'Propietario', 'description' => 'Acceso total, incluida la gestión de usuarios, roles y propiedades.'],
        'manager' => ['label' => 'Gerente', 'description' => 'Opera todo el hotel: reservas, habitaciones, inventario, reportes.'],
        'front-desk' => ['label' => 'Recepción', 'description' => 'Reservas, check-in/out, huéspedes (con INE) y ventas.'],
        'housekeeping' => ['label' => 'Limpieza', 'description' => 'Solo ve habitaciones y cambia su estado (limpieza).'],
        'kitchen' => ['label' => 'Cocina / Bar', 'description' => 'POS e inventario (productos e insumos).'],
        'agent' => ['label' => 'Bot / Agente IA', 'description' => 'Identidad de agentes automatizados (no se asigna manualmente).'],
    ];

    /**
     * Etiqueta y descripción de cada rol del hotel: lo que el hotel escribió
     * o, si no, el texto de fábrica. Una consulta, para pintar listas.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function roleMeta(): array
    {
        $meta = [];

        foreach (self::roles() as $role) {
            $meta[$role->name] = [
                'label' => $role->label ?: (self::SYSTEM_ROLES[$role->name]['label'] ?? $role->name),
                'description' => $role->description ?? (self::SYSTEM_ROLES[$role->name]['description'] ?? ''),
            ];
        }

        return $meta;
    }

    public static function labelFor(?string $role): ?string
    {
        if ($role === null) {
            return null;
        }

        return self::roleMeta()[$role]['label'] ?? $role;
    }

    /**
     * Posición de un rol en la jerarquía: los de fábrica en su orden y los
     * propios del hotel después, alfabéticos.
     */
    public static function rank(?string $role): int
    {
        $order = array_keys(self::SYSTEM_ROLES);
        $i = $role ? array_search($role, $order, true) : false;

        return $i === false ? count($order) : $i;
    }

    /**
     * Roles que se pueden asignar a una persona (todos menos el del bot),
     * de fábrica primero.
     *
     * @return list<string>
     */
    public static function assignableRoles(): array
    {
        return self::roles()
            ->reject(fn (Role $role) => $role->name === self::AGENT)
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * Áreas que el usuario NO ve. Con varios roles, un área queda oculta
     * solo si todos la esconden; el propietario no tiene ninguna oculta.
     *
     * @return list<string>
     */
    public static function hiddenAreasFor(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $roles = $user->relationLoaded('roles') ? $user->roles : $user->roles()->get();

        if ($roles->isEmpty() || $roles->contains('name', self::OWNER)) {
            return [];
        }

        $hidden = null;
        foreach ($roles as $role) {
            $own = self::hiddenAreasOf($role);
            $hidden = $hidden === null ? $own : array_values(array_intersect($hidden, $own));
        }

        return $hidden ?? [];
    }

    public static function canSeeArea(?User $user, string $area): bool
    {
        return ! in_array($area, self::hiddenAreasFor($user), true);
    }

    /**
     * El área a la que pertenece una ruta del panel, o null si no es de
     * ninguna (dashboard, perfil, campana). Gana el patrón más largo.
     */
    public static function areaForPath(string $path): ?string
    {
        $path = trim($path, '/');
        $best = null;
        $bestLength = -1;

        foreach (config('panel-areas.areas') as $key => $area) {
            foreach ($area['paths'] as $pattern) {
                if (Str::is($pattern, $path) && strlen($pattern) > $bestLength) {
                    $best = $key;
                    $bestLength = strlen($pattern);
                }
            }
        }

        return $best;
    }

    public static function areaForRequest(Request $request): ?string
    {
        return self::areaForPath($request->path());
    }

    /**
     * Todo lo que necesita el editor: los roles con sus áreas y permisos, y
     * los catálogos recortados a lo que ESTE hotel tiene (módulos y modo).
     *
     * @return array<string, mixed>
     */
    public static function editorPayload(): array
    {
        $tenant = tenant();
        $mode = app(PropertyMode::class)->mode();
        $groups = config('panel-areas.groups');

        $areas = collect(config('panel-areas.areas'))
            ->filter(fn (array $a) => (empty($a['module']) || $tenant?->hasModule($a['module']))
                && (empty($a['mode']) || in_array($mode, $a['mode'], true)))
            ->map(fn (array $a, string $key) => [
                'key' => $key,
                'group' => $a['group'],
                'label' => $a['label'],
                'description' => $a['description'],
                'icon' => $a['icon'],
                'permission' => $a['permission'],
            ])
            ->values();

        $capabilities = collect(config('panel-areas.capabilities'))
            ->map(fn (array $c, string $key) => [
                'key' => $key,
                'group' => $c['group'],
                'label' => $c['label'],
                'description' => $c['description'] ?? null,
            ])
            ->values();

        $counts = DB::table(config('permission.table_names.model_has_roles'))
            ->select('role_id', DB::raw('count(*) as total'))
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        $meta = self::roleMeta();

        $roles = self::roles()
            ->reject(fn (Role $role) => $role->name === self::AGENT)
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $meta[$role->name]['label'],
                'description' => $meta[$role->name]['description'],
                'system' => array_key_exists($role->name, self::SYSTEM_ROLES),
                'locked' => $role->name === self::OWNER,
                'users' => (int) ($counts[$role->id] ?? 0),
                'permissions' => $role->permissions->pluck('name')
                    ->filter(fn (string $p) => isset(config('panel-areas.capabilities')[$p]))
                    ->values(),
                'hidden_areas' => self::hiddenAreasOf($role),
            ])
            ->values();

        return [
            'roles' => $roles,
            'areas' => $areas,
            'areaGroups' => collect($groups)
                ->map(fn (string $label, string $key) => [
                    'key' => $key,
                    'label' => $key === 'propiedad' ? self::propertyGroupLabel($mode) : $label,
                ])
                ->values(),
            'capabilities' => $capabilities,
            'capabilityGroups' => collect(config('panel-areas.capability_groups'))
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
        ];
    }

    /**
     * Reglas del formulario de rol (crear y editar comparten).
     *
     * @return array<string, mixed>
     */
    public static function rules(?Role $role = null): array
    {
        return [
            'label' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:200'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(config('panel-areas.capabilities')))],
            'hidden_areas' => ['present', 'array'],
            'hidden_areas.*' => ['string', Rule::in(array_keys(config('panel-areas.areas')))],
        ];
    }

    /**
     * Crea (o actualiza) un rol con sus áreas y permisos.
     *
     * @param  array{label: string, description?: ?string, permissions: list<string>, hidden_areas: list<string>}  $data
     */
    public static function save(?Role $role, array $data): Role
    {
        if ($role && in_array($role->name, [self::OWNER, self::AGENT], true)) {
            throw ValidationException::withMessages([
                'label' => $role->name === self::OWNER
                    ? 'El propietario siempre ve y puede todo; no se edita.'
                    : 'El rol del asistente no se edita a mano.',
            ]);
        }

        $label = trim($data['label']);
        $duplicate = self::roles()->first(fn (Role $r) => $r->id !== $role?->id
            && Str::lower(self::roleMeta()[$r->name]['label'] ?? '') === Str::lower($label));
        if ($duplicate) {
            throw ValidationException::withMessages(['label' => 'Ya hay un rol con ese nombre.']);
        }

        return DB::transaction(function () use ($role, $data, $label) {
            $role ??= Role::create(['name' => self::uniqueName($label), 'guard_name' => 'web']);

            // Un permiso de fábrica que todavía no exista en un hotel viejo
            // se crea aquí en vez de tronar el guardado.
            foreach ($data['permissions'] as $permission) {
                \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            }

            // Permisos fuera del catálogo (si algún día hay) se respetan: el
            // editor solo maneja los que conoce.
            $unknown = $role->permissions->pluck('name')
                ->reject(fn (string $p) => isset(config('panel-areas.capabilities')[$p]))
                ->all();

            $role->forceFill([
                'label' => $label,
                'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
                'hidden_areas' => json_encode(array_values(array_unique($data['hidden_areas']))),
            ])->save();

            $role->syncPermissions([...array_values(array_unique($data['permissions'])), ...$unknown]);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role->refresh();
        });
    }

    /**
     * Solo los roles propios del hotel y solo si nadie lo tiene: quitárselo
     * a alguien en silencio lo dejaría sin entrar a nada.
     */
    public static function delete(Role $role): void
    {
        if (array_key_exists($role->name, self::SYSTEM_ROLES)) {
            throw ValidationException::withMessages(['role' => 'Los roles de fábrica no se eliminan; puedes cambiarles lo que ven.']);
        }

        $users = DB::table(config('permission.table_names.model_has_roles'))->where('role_id', $role->id)->count();
        if ($users > 0) {
            throw ValidationException::withMessages([
                'role' => $users === 1
                    ? 'Una persona tiene este rol; cámbiale el rol antes de eliminarlo.'
                    : "{$users} personas tienen este rol; cámbiales el rol antes de eliminarlo.",
            ]);
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    protected static function hiddenAreasOf(Role $role): array
    {
        $raw = $role->getAttribute('hidden_areas');
        $list = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

        return array_values(array_filter($list, 'is_string'));
    }

    /**
     * Roles del hotel ordenados: de fábrica en jerarquía y luego los propios.
     *
     * @return \Illuminate\Support\Collection<int, Role>
     */
    protected static function roles(): \Illuminate\Support\Collection
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->with('permissions:id,name')
            ->get()
            ->sortBy([
                fn (Role $a, Role $b) => self::rank($a->name) <=> self::rank($b->name),
                fn (Role $a, Role $b) => strcasecmp((string) ($a->label ?? $a->name), (string) ($b->label ?? $b->name)),
            ])
            ->values();
    }

    protected static function uniqueName(string $label): string
    {
        $base = Str::slug($label) ?: 'rol';
        $name = $base;
        $i = 2;

        while (Role::query()->where('name', $name)->exists() || array_key_exists($name, self::SYSTEM_ROLES)) {
            $name = "{$base}-{$i}";
            $i++;
        }

        return $name;
    }

    protected static function propertyGroupLabel(string $mode): string
    {
        return match ($mode) {
            PropertyMode::MOTEL => 'Motel',
            PropertyMode::BOTH => 'Propiedad',
            default => 'Hotel',
        };
    }
}
