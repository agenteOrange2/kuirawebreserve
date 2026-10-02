<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import { Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';
import type { AdminUser } from './types';
import UserDeleteDialog from './UserDeleteDialog.vue';
import UserFormModal from './UserFormModal.vue';

type Tone = 'primary' | 'success' | 'warning' | 'danger';

interface ActivityRow {
    id: number;
    action: string;
    label: string;
    icon: Icon;
    tone: Tone;
    category: string;
    category_label: string;
    subject: string | null;
    subject_type: string | null;
    subject_id: string | null;
    tenant: { id: string; name: string; exists: boolean } | null;
    details: string[];
    ip: string | null;
    device: string | null;
    at: string | null;
    ago: string | null;
    day: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Option {
    value: string;
    label: string;
}

const props = defineProps<{
    user: AdminUser & {
        last_login_ago: string | null;
        last_login_at: string | null;
        last_login_ip: string | null;
        last_activity_ago: string | null;
        is_last_admin: boolean;
    };
    stats: {
        actions_30d: number;
        tenants_30d: number;
        logins_30d: number;
        failed_30d: number;
        impersonations_30d: number;
    };
    history: {
        data: ActivityRow[];
        links: PaginationLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    tenants: {
        id: string;
        name: string;
        exists: boolean;
        total: number;
        last_ago: string;
    }[];
    tenantOptions: Option[];
    categories: Option[];
    filters: { category: string; tenant: string; q: string };
    sessions: {
        current: boolean;
        ip: string | null;
        device: string | null;
        ago: string;
    }[];
}>();

const page = usePage();
const currentUserId = computed(
    () => (page.props.auth as { user: { id: number } }).user.id,
);
const isSelf = computed(() => props.user.id === currentUserId.value);

const initials = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('') || '?';

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const stripItem =
    'inline-flex items-center gap-1.5 whitespace-nowrap text-slate-500';
const stripValue = 'font-medium text-slate-700 dark:text-slate-300';
const stripDivider =
    'hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400';

// Clases completas por tono: Tailwind no ve las que se arman con plantillas.
const toneClass: Record<Tone, string> = {
    primary: 'border-primary/10 bg-primary/10 text-primary',
    success: 'border-success/10 bg-success/10 text-success',
    warning: 'border-warning/10 bg-warning/10 text-warning',
    danger: 'border-danger/10 bg-danger/10 text-danger',
};

// ── Filtros del historial (servidor: la bitácora crece sin tope) ──
const category = ref(props.filters.category);
const tenant = ref(props.filters.tenant);
const q = ref(props.filters.q);

function applyFilters() {
    router.get(
        route('admin.users.show', props.user.id),
        {
            category: category.value || undefined,
            tenant: tenant.value || undefined,
            q: q.value.trim() || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['history', 'filters'],
        },
    );
}

watch([category, tenant], applyFilters);

let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
});

const hasFilters = computed(
    () => !!(category.value || tenant.value || q.value.trim()),
);

function clearFilters() {
    category.value = '';
    tenant.value = '';
    q.value = '';
}

// Renglones agrupados por día, con "Hoy" y "Ayer" en vez de la fecha.
const isoDay = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

function dayLabel(day: string | null): string {
    if (!day) return 'Sin fecha';
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);
    if (day === isoDay(today)) return 'Hoy';
    if (day === isoDay(yesterday)) return 'Ayer';
    const [y, m, d] = day.split('-');
    return `${d}/${m}/${y}`;
}

const groups = computed(() => {
    const out: { day: string; label: string; rows: ActivityRow[] }[] = [];
    for (const row of props.history.data) {
        const key = row.day ?? '';
        const last = out[out.length - 1];
        if (last && last.day === key) {
            last.rows.push(row);
        } else {
            out.push({ day: key, label: dayLabel(row.day), rows: [row] });
        }
    }
    return out;
});

// ── Edición y borrado desde la ficha ──
const editOpen = ref(false);
const deleting = ref<AdminUser | null>(null);

function onSaved() {
    editOpen.value = false;
    router.reload({ only: ['user', 'history', 'stats'] });
}

function onDeleted() {
    deleting.value = null;
    router.visit(route('admin.users'));
}
</script>

<template>
    <RazeLayout :title="user.name">
        <div class="mt-2">
            <!-- Encabezado en franjas: quién es, los avisos que importan y
                 sus datos de acceso. -->
            <div class="box box--stacked overflow-hidden">
                <div
                    class="flex flex-col gap-4 p-5 md:flex-row md:items-start md:justify-between"
                >
                    <div class="flex min-w-0 gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-xs font-semibold text-white shadow-md"
                        >
                            {{ initials(user.name) }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-base font-medium">
                                    {{ user.name }}
                                </h1>
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-medium"
                                    :class="
                                        user.is_admin
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                >
                                    <Lucide
                                        :icon="
                                            user.is_admin
                                                ? 'ShieldCheck'
                                                : 'UserX'
                                        "
                                        class="h-3.5 w-3.5"
                                    />
                                    {{
                                        user.is_admin
                                            ? 'Administrador'
                                            : 'Sin acceso'
                                    }}
                                </span>
                                <span
                                    v-if="user.two_factor"
                                    class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-[11px] font-medium text-success"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full bg-success"
                                    />
                                    Doble factor
                                </span>
                                <span
                                    v-if="isSelf"
                                    class="rounded-full bg-primary/10 px-2.5 py-1 text-[11px] font-medium text-primary"
                                    >Tú</span
                                >
                            </div>
                            <div
                                class="mt-2 flex flex-wrap items-center gap-1.5"
                            >
                                <a
                                    :href="`mailto:${user.email}`"
                                    class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 transition hover:bg-primary/10 hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                    title="Escribirle"
                                >
                                    <Lucide
                                        icon="Mail"
                                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                    />
                                    <span class="truncate">{{
                                        user.email
                                    }}</span>
                                </a>
                                <a
                                    v-if="user.phone"
                                    :href="`tel:${user.phone}`"
                                    class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 transition hover:bg-primary/10 hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                    title="Llamarle"
                                >
                                    <Lucide
                                        icon="Phone"
                                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                    />
                                    <span class="truncate">{{
                                        user.phone
                                    }}</span>
                                </a>
                                <span class="text-xs text-slate-400">
                                    Alta {{ user.created_at ?? '—' }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div
                        class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:shrink-0 md:flex-wrap md:items-center md:gap-2"
                    >
                        <Link
                            :href="route('admin.users')"
                            class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                            Volver a usuarios
                        </Link>
                        <div class="flex items-center gap-2">
                            <Button
                                variant="outline-secondary"
                                class="h-9 flex-1 rounded-[0.5rem] bg-white text-xs md:flex-none"
                                @click="editOpen = true"
                            >
                                <Lucide
                                    icon="Pencil"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Editar
                            </Button>
                            <Menu v-if="!isSelf">
                                <Menu.Button
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-100 dark:border-darkmode-400 dark:bg-darkmode-600 dark:hover:bg-darkmode-400"
                                    title="Más acciones"
                                >
                                    <Lucide
                                        icon="EllipsisVertical"
                                        class="h-4 w-4"
                                    />
                                </Menu.Button>
                                <Menu.Items class="w-44">
                                    <Menu.Item
                                        as="button"
                                        type="button"
                                        class="text-danger"
                                        @click="deleting = user"
                                    >
                                        <Lucide
                                            icon="Trash2"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Eliminar usuario
                                    </Menu.Item>
                                </Menu.Items>
                            </Menu>
                        </div>
                    </div>
                </div>

                <!-- Avisos que cambian cómo se le trata -->
                <div
                    v-if="stats.failed_30d > 0"
                    class="flex items-start gap-2 border-t border-slate-200/60 bg-danger/5 px-5 py-3 text-xs dark:border-darkmode-400"
                >
                    <Lucide
                        icon="ShieldAlert"
                        class="mt-0.5 h-3.5 w-3.5 shrink-0 text-danger"
                    />
                    <span class="text-slate-600 dark:text-slate-300">
                        <span class="font-medium text-danger">
                            {{ stats.failed_30d }}
                            {{
                                stats.failed_30d === 1
                                    ? 'intento fallido'
                                    : 'intentos fallidos'
                            }}
                            de acceso
                        </span>
                        en los últimos 30 días. Si no fue esta persona, cámbiale
                        la contraseña.
                    </span>
                </div>
                <div
                    v-if="user.is_admin && !user.two_factor"
                    class="flex items-start gap-2 border-t border-slate-200/60 bg-warning/5 px-5 py-3 text-xs dark:border-darkmode-400"
                >
                    <Lucide
                        icon="ShieldOff"
                        class="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning"
                    />
                    <span class="text-slate-600 dark:text-slate-300">
                        <span class="font-medium">Sin doble factor.</span>
                        Con esta cuenta se entra a cualquier hotel; conviene que
                        lo active desde Configuración.
                    </span>
                </div>

                <!-- Datos duros -->
                <div
                    class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-slate-200/60 bg-slate-50/70 px-5 py-3 text-xs dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <span :class="stripItem" :title="user.last_login_at ?? ''">
                        <Lucide
                            icon="LogIn"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        Último acceso
                        <span :class="stripValue">{{
                            user.last_login_ago ?? 'sin registro'
                        }}</span>
                        <template v-if="user.last_login_ip">
                            desde {{ user.last_login_ip }}</template
                        >
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="Activity"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">{{ stats.actions_30d }}</span>
                        {{ stats.actions_30d === 1 ? 'acción' : 'acciones' }}
                        en 30 días
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="Building2"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">{{ stats.tenants_30d }}</span>
                        {{ stats.tenants_30d === 1 ? 'hotel' : 'hoteles' }}
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="KeyRound"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">{{
                            stats.impersonations_30d
                        }}</span>
                        veces "Entrar como"
                    </span>
                    <span
                        v-if="user.last_activity_ago"
                        class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-500 md:ml-auto dark:bg-darkmode-400"
                    >
                        Activo {{ user.last_activity_ago }}
                    </span>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 items-start gap-5">
                <!-- Historial -->
                <div class="col-span-12 xl:col-span-8">
                    <div class="box box--stacked overflow-hidden">
                        <div :class="cardHeader">
                            <div :class="[sectionIcon, toneClass.primary]">
                                <Lucide icon="History" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium">Historial</div>
                                <div class="text-xs text-slate-500">
                                    Lo que ha hecho en el panel, lo más reciente
                                    primero.
                                </div>
                            </div>
                            <span class="text-xs text-slate-500">
                                {{ history.total }}
                                {{
                                    history.total === 1
                                        ? 'registro'
                                        : 'registros'
                                }}
                            </span>
                        </div>

                        <div
                            class="flex flex-col gap-2 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center dark:border-darkmode-400 dark:bg-darkmode-600/40"
                        >
                            <FormSelect
                                v-model="category"
                                class="h-9 text-xs sm:w-44"
                            >
                                <option value="">Todas las áreas</option>
                                <option
                                    v-for="c in categories"
                                    :key="c.value"
                                    :value="c.value"
                                >
                                    {{ c.label }}
                                </option>
                            </FormSelect>
                            <FormSelect
                                v-model="tenant"
                                class="h-9 text-xs sm:w-48"
                            >
                                <option value="">Todos los hoteles</option>
                                <option
                                    v-for="t in tenantOptions"
                                    :key="t.value"
                                    :value="t.value"
                                >
                                    {{ t.label }}
                                </option>
                            </FormSelect>
                            <div class="relative sm:flex-1">
                                <Lucide
                                    icon="Search"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                />
                                <FormInput
                                    v-model="q"
                                    type="text"
                                    class="h-9 pl-9 text-xs"
                                    placeholder="Buscar hotel, usuario, plan..."
                                />
                            </div>
                            <button
                                v-if="hasFilters"
                                type="button"
                                class="text-xs font-medium text-primary hover:underline"
                                @click="clearFilters"
                            >
                                Quitar filtros
                            </button>
                        </div>

                        <template v-if="history.data.length">
                            <template v-for="g in groups" :key="g.day">
                                <div
                                    class="border-b border-slate-200/60 bg-slate-50/40 px-4 py-1.5 text-[11px] font-medium tracking-wide text-slate-400 uppercase sm:px-5 dark:border-darkmode-400 dark:bg-darkmode-600/20"
                                >
                                    {{ g.label }}
                                </div>
                                <div
                                    class="divide-y divide-slate-200/60 border-b border-slate-200/60 dark:divide-darkmode-400 dark:border-darkmode-400"
                                >
                                    <div
                                        v-for="row in g.rows"
                                        :key="row.id"
                                        class="flex gap-3 px-4 py-3 sm:px-5"
                                    >
                                        <div
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border"
                                            :class="toneClass[row.tone]"
                                        >
                                            <Lucide
                                                :icon="row.icon"
                                                class="h-3.5 w-3.5"
                                            />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div
                                                class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                                            >
                                                <div class="min-w-0">
                                                    <div class="text-sm">
                                                        {{ row.label }}
                                                        <template
                                                            v-if="row.subject"
                                                        >
                                                            <Link
                                                                v-if="
                                                                    row.subject_type ===
                                                                        'user' &&
                                                                    row.subject_id
                                                                "
                                                                :href="
                                                                    route(
                                                                        'admin.users.show',
                                                                        row.subject_id,
                                                                    )
                                                                "
                                                                class="font-medium hover:text-primary"
                                                                >{{
                                                                    row.subject
                                                                }}</Link
                                                            >
                                                            <span
                                                                v-else
                                                                class="font-medium"
                                                                >{{
                                                                    row.subject
                                                                }}</span
                                                            >
                                                        </template>
                                                    </div>
                                                    <div
                                                        class="mt-1 flex flex-wrap items-center gap-1.5"
                                                    >
                                                        <component
                                                            :is="
                                                                row.tenant
                                                                    ?.exists
                                                                    ? Link
                                                                    : 'span'
                                                            "
                                                            v-if="row.tenant"
                                                            :href="
                                                                row.tenant
                                                                    .exists
                                                                    ? route(
                                                                          'admin.tenants.show',
                                                                          row
                                                                              .tenant
                                                                              .id,
                                                                      )
                                                                    : undefined
                                                            "
                                                            class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600 transition dark:bg-darkmode-400 dark:text-slate-300"
                                                            :class="
                                                                row.tenant
                                                                    .exists &&
                                                                'hover:bg-primary/10 hover:text-primary'
                                                            "
                                                        >
                                                            <Lucide
                                                                icon="Building2"
                                                                class="h-3 w-3"
                                                            />
                                                            {{
                                                                row.tenant.name
                                                            }}
                                                        </component>
                                                        <span
                                                            class="rounded-full border border-slate-200/70 px-2 py-0.5 text-[11px] text-slate-400 dark:border-darkmode-400"
                                                            >{{
                                                                row.category_label
                                                            }}</span
                                                        >
                                                    </div>
                                                </div>
                                                <div
                                                    class="shrink-0 text-xs text-slate-500 sm:text-right"
                                                    :title="row.at ?? ''"
                                                >
                                                    {{ row.at?.slice(11) }}
                                                    <span class="text-slate-400"
                                                        >· {{ row.ago }}</span
                                                    >
                                                </div>
                                            </div>
                                            <ul
                                                v-if="row.details.length"
                                                class="mt-1.5 space-y-0.5 text-xs text-slate-500"
                                            >
                                                <li
                                                    v-for="(
                                                        line, i
                                                    ) in row.details"
                                                    :key="i"
                                                    class="break-words"
                                                >
                                                    {{ line }}
                                                </li>
                                            </ul>
                                            <div
                                                v-if="row.device || row.ip"
                                                class="mt-1 text-[11px] text-slate-400"
                                            >
                                                {{ row.device
                                                }}<template
                                                    v-if="row.device && row.ip"
                                                >
                                                    · </template
                                                >{{ row.ip }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </template>

                        <div
                            v-else
                            class="flex flex-col items-center gap-2 px-6 py-12 text-center"
                        >
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="History" class="h-4 w-4" />
                            </div>
                            <p class="max-w-sm text-xs text-slate-500">
                                {{
                                    hasFilters
                                        ? 'Nada coincide con los filtros.'
                                        : 'Todavía no hay nada registrado. La bitácora cuenta desde septiembre de 2026; lo anterior no quedó guardado.'
                                }}
                            </p>
                        </div>

                        <div
                            v-if="history.links.length > 3"
                            class="flex flex-wrap items-center gap-2 px-4 py-3"
                        >
                            <span class="text-xs text-slate-500">
                                {{ history.from }}–{{ history.to }} de
                                {{ history.total }}
                            </span>
                            <div class="ml-auto flex flex-wrap gap-1">
                                <component
                                    :is="link.url ? Link : 'span'"
                                    v-for="(link, i) in history.links"
                                    :key="i"
                                    :href="link.url ?? undefined"
                                    preserve-state
                                    preserve-scroll
                                    :only="['history', 'filters']"
                                    class="rounded-md px-2.5 py-1 text-xs"
                                    :class="
                                        link.active
                                            ? 'bg-primary text-white'
                                            : link.url
                                              ? 'text-slate-500 hover:bg-slate-100 dark:hover:bg-darkmode-400'
                                              : 'text-slate-300'
                                    "
                                >
                                    <!-- El rótulo trae las flechas « » de Laravel. -->
                                    <span v-html="link.label" />
                                </component>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lateral: dónde está conectado y en qué hoteles trabaja -->
                <div class="col-span-12 flex flex-col gap-5 xl:col-span-4">
                    <div class="box box--stacked overflow-hidden">
                        <div :class="cardHeader">
                            <div :class="[sectionIcon, toneClass.success]">
                                <Lucide
                                    icon="MonitorSmartphone"
                                    class="h-4 w-4"
                                />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Sesiones abiertas
                                </div>
                                <div class="text-xs text-slate-500">
                                    Equipos donde tiene la sesión iniciada
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="sessions.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="(s, i) in sessions"
                                :key="i"
                                class="flex items-center gap-3 px-4 py-3"
                            >
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex items-center gap-2 text-sm"
                                    >
                                        <span class="truncate">{{
                                            s.device ?? 'Equipo desconocido'
                                        }}</span>
                                        <span
                                            v-if="s.current"
                                            class="shrink-0 rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success"
                                            >Esta sesión</span
                                        >
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ s.ip ?? 'IP desconocida' }} ·
                                        {{ s.ago }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p v-else class="px-4 py-4 text-xs text-slate-500">
                            No tiene ninguna sesión abierta ahora.
                        </p>
                    </div>

                    <div class="box box--stacked overflow-hidden">
                        <div :class="cardHeader">
                            <div :class="[sectionIcon, toneClass.primary]">
                                <Lucide icon="Building2" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Hoteles en los que ha trabajado
                                </div>
                                <div class="text-xs text-slate-500">
                                    Los que más ha tocado
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="tenants.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="t in tenants"
                                :key="t.id"
                                class="flex items-center gap-3 px-4 py-3"
                            >
                                <div class="min-w-0 flex-1">
                                    <Link
                                        v-if="t.exists"
                                        :href="
                                            route('admin.tenants.show', t.id)
                                        "
                                        class="block truncate text-sm font-medium hover:text-primary"
                                        >{{ t.name }}</Link
                                    >
                                    <span
                                        v-else
                                        class="block truncate text-sm font-medium text-slate-400"
                                        >{{ t.name }} (eliminado)</span
                                    >
                                    <div class="text-xs text-slate-500">
                                        Última vez {{ t.last_ago }}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-600 transition hover:bg-primary/10 hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                    title="Ver solo lo de este hotel"
                                    @click="tenant = t.id"
                                >
                                    {{ t.total }}
                                    {{ t.total === 1 ? 'acción' : 'acciones' }}
                                </button>
                            </div>
                        </div>
                        <p v-else class="px-4 py-4 text-xs text-slate-500">
                            Aún no ha hecho cambios en ningún hotel.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <UserFormModal
            :open="editOpen"
            :user="user"
            :is-self="isSelf"
            :is-last-admin="user.is_last_admin"
            @close="editOpen = false"
            @saved="onSaved"
        />
        <UserDeleteDialog
            :user="deleting"
            @close="deleting = null"
            @deleted="onDeleted"
        />
    </RazeLayout>
</template>
