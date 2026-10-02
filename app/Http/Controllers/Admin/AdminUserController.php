<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\AdminActivity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\AdminActivityCatalog;
use App\Services\Admin\AdminActivityPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD de los usuarios del panel de plataforma (BD central). El acceso es
 * el rol platform-admin: con él se entra a /admin y, desde ahí, a cualquier
 * hotel con "Entrar como". Resguardos: nadie se quita el acceso a sí mismo
 * ni se elimina, y siempre queda al menos un administrador.
 *
 * La ficha (show) lee la bitácora admin_activities que escribe
 * RecordAdminActivity: qué hizo cada quien y en qué hotel.
 */
class AdminUserController extends Controller
{
    public const ROLE = 'platform-admin';

    public function index(): Response
    {
        // Última acción y volumen del mes en UNA consulta agrupada: la lista
        // no consulta la bitácora por renglón.
        $activity = AdminActivity::query()
            ->selectRaw('user_id, MAX(created_at) as last_at, SUM(created_at >= ?) as recent', [now()->subDays(30)])
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        return Inertia::render('admin/users/Index', [
            'users' => User::with('roles:id,name')
                ->orderBy('name')->get()
                ->map(function (User $u) use ($activity) {
                    $row = $activity->get($u->id);
                    $last = $row?->last_at ? \Illuminate\Support\Carbon::parse($row->last_at) : null;

                    return $this->serialize($u) + [
                        'last_activity_ago' => $last?->diffForHumans(),
                        'last_activity_at' => $last?->format('d/m/Y H:i'),
                        'actions_30d' => (int) ($row?->recent ?? 0),
                    ];
                })
                ->values(),
        ]);
    }

    /**
     * Ficha del usuario: quién es, desde dónde está conectado, en qué
     * hoteles ha trabajado y la bitácora completa de lo que ha hecho.
     */
    public function show(Request $request, User $user): Response
    {
        $filters = $request->validate([
            'category' => ['nullable', Rule::in(array_keys(AdminActivityCatalog::CATEGORIES))],
            'tenant' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $mine = AdminActivity::query()->where('user_id', $user->id);

        $history = (clone $mine)
            ->when($filters['category'] ?? null, function ($q, string $category) {
                $actions = AdminActivityCatalog::actionsIn($category);
                // Las rutas que no están en el catálogo se leen como "Plataforma".
                $category === 'platform'
                    ? $q->where(fn ($w) => $w->whereIn('action', $actions)
                        ->orWhereNotIn('action', array_keys(AdminActivityCatalog::ACTIONS)))
                    : $q->whereIn('action', $actions);
            })
            ->when($filters['tenant'] ?? null, fn ($q, string $t) => $q->where('tenant_id', $t))
            ->when($filters['q'] ?? null, fn ($q, string $term) => $q->where('subject_label', 'like', '%'.$term.'%'))
            ->latest('created_at')->latest('id')
            ->paginate(25)
            ->withQueryString();

        $since = now()->subDays(30);
        $tenantCounts = (clone $mine)->whereNotNull('tenant_id')
            ->selectRaw('tenant_id, COUNT(*) as total, MAX(created_at) as last_at')
            ->groupBy('tenant_id')->orderByDesc('total')->get();
        $tenantNames = Tenant::query()->whereIn('id', $tenantCounts->pluck('tenant_id'))->get()
            ->mapWithKeys(fn (Tenant $t) => [$t->id => $t->name ?? $t->id])->all();

        $lastLogin = (clone $mine)->where('action', 'auth.login')->latest('created_at')->first();
        $lastAction = (clone $mine)->latest('created_at')->first();

        $sessions = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')->limit(5)->get()
            ->map(fn ($s) => [
                'current' => $s->id === $request->session()->getId(),
                'ip' => $s->ip_address,
                'device' => AdminActivityPresenter::device($s->user_agent),
                'ago' => \Illuminate\Support\Carbon::createFromTimestamp($s->last_activity)->diffForHumans(),
            ])->values();

        return Inertia::render('admin/users/Show', [
            'user' => $this->serialize($user) + [
                'last_login_ago' => $lastLogin?->created_at?->diffForHumans(),
                'last_login_at' => $lastLogin?->created_at?->format('d/m/Y H:i'),
                'last_login_ip' => $lastLogin?->ip,
                'last_activity_ago' => $lastAction?->created_at?->diffForHumans(),
                'is_last_admin' => $this->isLastAdmin($user),
            ],
            'sessions' => $sessions,
            'stats' => [
                'actions_30d' => (clone $mine)->where('created_at', '>=', $since)->where('action', 'not like', 'auth.%')->count(),
                'tenants_30d' => (clone $mine)->where('created_at', '>=', $since)->whereNotNull('tenant_id')->distinct()->count('tenant_id'),
                'logins_30d' => (clone $mine)->where('created_at', '>=', $since)->where('action', 'auth.login')->count(),
                'failed_30d' => (clone $mine)->where('created_at', '>=', $since)->where('action', 'auth.failed')->count(),
                'impersonations_30d' => (clone $mine)->where('created_at', '>=', $since)->where('action', 'admin.tenants.impersonate')->count(),
            ],
            'history' => $history->through(fn (AdminActivity $a) => AdminActivityPresenter::present($a, $tenantNames)),
            'tenants' => $tenantCounts->take(6)->map(fn ($row) => [
                'id' => $row->tenant_id,
                'name' => $tenantNames[$row->tenant_id] ?? $row->tenant_id,
                'exists' => isset($tenantNames[$row->tenant_id]),
                'total' => (int) $row->total,
                'last_ago' => \Illuminate\Support\Carbon::parse($row->last_at)->diffForHumans(),
            ])->values(),
            'tenantOptions' => $tenantCounts->map(fn ($row) => [
                'value' => $row->tenant_id,
                'label' => $tenantNames[$row->tenant_id] ?? $row->tenant_id,
            ])->sortBy('label')->values(),
            'categories' => collect(AdminActivityCatalog::CATEGORIES)
                ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values(),
            'filters' => [
                'category' => $filters['category'] ?? '',
                'tenant' => $filters['tenant'] ?? '',
                'q' => $filters['q'] ?? '',
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'is_admin' => ['required', 'boolean'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);

        if ($data['is_admin']) {
            $user->assignRole(self::ROLE);
        }

        return response()->json($this->serialize($user), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:8'],
            'is_admin' => ['sometimes', 'required', 'boolean'],
        ]);

        if (array_key_exists('is_admin', $data) && ! $data['is_admin'] && $user->hasRole(self::ROLE)) {
            if ($user->id === $request->user()->id) {
                return response()->json([
                    'message' => 'No puedes quitarte el acceso a ti mismo; pide a otro administrador que lo haga.',
                ], 422);
            }
            if ($this->isLastAdmin($user)) {
                return response()->json([
                    'message' => 'Es el único administrador de la plataforma; da acceso a otro antes de quitárselo.',
                ], 422);
            }
        }

        $user->fill(collect($data)->only(['name', 'email', 'phone'])->all());
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        if (array_key_exists('is_admin', $data)) {
            $data['is_admin']
                ? $user->assignRole(self::ROLE)
                : $user->removeRole(self::ROLE);
        }

        return response()->json($this->serialize($user->refresh()));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'No puedes eliminar tu propia cuenta desde aquí; hazlo desde Configuración o pide a otro administrador.',
            ], 422);
        }

        if ($this->isLastAdmin($user)) {
            return response()->json([
                'message' => 'Es el único administrador de la plataforma; da acceso a otro antes de eliminarlo.',
            ], 422);
        }

        $user->delete();

        return response()->json(status: 204);
    }

    protected function isLastAdmin(User $user): bool
    {
        return $user->hasRole(self::ROLE) && User::role(self::ROLE)->count() <= 1;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_admin' => $user->hasRole(self::ROLE),
            'two_factor' => $user->two_factor_confirmed_at !== null,
            'created_at' => $user->created_at?->format('d/m/Y'),
        ];
    }
}
