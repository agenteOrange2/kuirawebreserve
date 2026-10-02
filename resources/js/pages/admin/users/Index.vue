<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';
import type { AdminUser } from './types';
import UserDeleteDialog from './UserDeleteDialog.vue';
import UserFormModal from './UserFormModal.vue';

const props = defineProps<{ users: AdminUser[] }>();

const page = usePage();
const currentUserId = computed(
    () => (page.props.auth as { user: { id: number } }).user.id,
);

// Copia local: el alta y la edición actualizan en cliente sin recargar.
const users = ref<AdminUser[]>([...props.users]);

const initialsOf = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('') || '?';

const stats = computed(() => ({
    admins: users.value.filter((u) => u.is_admin).length,
    two_factor: users.value.filter((u) => u.is_admin && u.two_factor).length,
    no_access: users.value.filter((u) => !u.is_admin).length,
    actions: users.value.reduce((sum, u) => sum + (u.actions_30d ?? 0), 0),
}));

// Búsqueda y filtro en cliente: son las cuentas de la plataforma, pocas.
const search = ref('');
const access = ref<'' | 'admin' | 'none'>('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return users.value.filter((u) => {
        if (access.value === 'admin' && !u.is_admin) return false;
        if (access.value === 'none' && u.is_admin) return false;
        if (!q) return true;
        return (
            u.name.toLowerCase().includes(q) ||
            u.email.toLowerCase().includes(q) ||
            (u.phone ?? '').toLowerCase().includes(q)
        );
    });
});

const isSelf = (u: AdminUser | null) => u?.id === currentUserId.value;
const isLastAdmin = (u: AdminUser | null) =>
    (u?.is_admin ?? false) && stats.value.admins <= 1;

// ── Alta / edición ──
const modalOpen = ref(false);
const editing = ref<AdminUser | null>(null);

function openModal(u: AdminUser | null = null) {
    editing.value = u;
    modalOpen.value = true;
}

function onSaved(saved: AdminUser) {
    const current = users.value.find((u) => u.id === saved.id);
    if (current) {
        // Lo que la edición no devuelve (actividad) se conserva.
        users.value = users.value.map((u) =>
            u.id === saved.id ? { ...u, ...saved } : u,
        );
    } else {
        users.value = [...users.value, { ...saved, actions_30d: 0 }].sort(
            (a, b) => a.name.localeCompare(b.name),
        );
    }
    modalOpen.value = false;
}

// ── Borrado ──
const deleting = ref<AdminUser | null>(null);

function onDeleted(gone: AdminUser) {
    users.value = users.value.filter((u) => u.id !== gone.id);
    deleting.value = null;
}

const openShow = (u: AdminUser) =>
    router.visit(route('admin.users.show', u.id));
</script>

<template>
    <RazeLayout title="Usuarios">
        <div class="mt-2">
            <!-- Encabezado -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="UserCog" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Usuarios del admin
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Quién entra al panel de plataforma y qué ha hecho en
                            cada hotel.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Button
                        variant="primary"
                        class="col-span-2 h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        @click="openModal()"
                    >
                        <Lucide icon="UserPlus" class="mr-1.5 h-3.5 w-3.5" />
                        Nuevo usuario
                    </Button>
                </div>
            </div>

            <!-- Cifras -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ShieldCheck" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.admins }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Administradores
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Entran al panel y a los hoteles
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="KeyRound" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.two_factor }} de {{ stats.admins }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Con doble factor
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Cada quien lo activa en Configuración
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="UserX" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.no_access }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Sin acceso
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Cuentas sin rol de administrador
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                    >
                        <Lucide icon="Activity" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.actions }}
                        </div>
                        <div class="text-xs leading-tight text-slate-500">
                            Acciones en 30 días
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            Cambios y accesos de todo el equipo
                        </div>
                    </div>
                </div>
            </div>

            <!-- Listado -->
            <div class="box box--stacked mt-4 overflow-hidden">
                <div
                    class="flex flex-col gap-2 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 sm:flex-row sm:items-center dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="relative sm:w-72">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                        />
                        <FormInput
                            v-model="search"
                            type="text"
                            class="h-9 pl-9 text-xs"
                            placeholder="Buscar nombre, correo o teléfono"
                        />
                    </div>
                    <FormSelect v-model="access" class="h-9 text-xs sm:w-44">
                        <option value="">Todos los accesos</option>
                        <option value="admin">Administradores</option>
                        <option value="none">Sin acceso</option>
                    </FormSelect>
                    <span class="text-xs text-slate-500 sm:ml-auto">
                        {{ filtered.length }}
                        {{ filtered.length === 1 ? 'usuario' : 'usuarios' }}
                    </span>
                </div>

                <div
                    v-if="filtered.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="u in filtered"
                        :key="u.id"
                        class="flex cursor-pointer flex-col gap-3 px-4 py-3 transition hover:bg-slate-50/70 sm:flex-row sm:items-center sm:px-5 dark:hover:bg-darkmode-400/30"
                        @click="openShow(u)"
                    >
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-[11px] font-semibold text-white"
                            >
                                {{ initialsOf(u.name) }}
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <Link
                                        :href="route('admin.users.show', u.id)"
                                        class="truncate text-sm font-medium hover:text-primary"
                                        @click.stop
                                        >{{ u.name }}</Link
                                    >
                                    <span
                                        v-if="isSelf(u)"
                                        class="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                        >Tú</span
                                    >
                                </div>
                                <div
                                    class="text-xs leading-tight text-slate-500"
                                >
                                    {{ u.email
                                    }}<template v-if="u.phone">
                                        · {{ u.phone }}</template
                                    >
                                </div>
                            </div>
                        </div>

                        <div
                            class="flex flex-wrap items-center gap-1.5 sm:w-56 sm:shrink-0"
                        >
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="
                                    u.is_admin
                                        ? 'bg-primary/10 text-primary'
                                        : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                "
                            >
                                <Lucide
                                    :icon="u.is_admin ? 'ShieldCheck' : 'UserX'"
                                    class="h-3 w-3"
                                />
                                {{
                                    u.is_admin ? 'Administrador' : 'Sin acceso'
                                }}
                            </span>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="
                                    u.two_factor
                                        ? 'bg-success/10 text-success'
                                        : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                                "
                            >
                                <span
                                    class="h-1.5 w-1.5 rounded-full"
                                    :class="
                                        u.two_factor
                                            ? 'bg-success'
                                            : 'bg-slate-300'
                                    "
                                />
                                {{ u.two_factor ? 'Doble factor' : 'Sin 2FA' }}
                            </span>
                        </div>

                        <div
                            class="flex items-end justify-between gap-3 sm:contents"
                        >
                            <div class="text-xs sm:w-48 sm:shrink-0">
                                <div
                                    v-if="u.last_activity_ago"
                                    class="text-slate-600 dark:text-slate-300"
                                    :title="u.last_activity_at ?? undefined"
                                >
                                    Última acción {{ u.last_activity_ago }}
                                </div>
                                <div v-else class="text-slate-400">
                                    Sin actividad registrada
                                </div>
                                <div class="text-[11px] text-slate-400">
                                    {{ u.actions_30d ?? 0 }} en 30 días · alta
                                    {{ u.created_at ?? '—' }}
                                </div>
                            </div>

                            <div
                                class="flex items-center justify-end gap-1 sm:shrink-0"
                                @click.stop
                            >
                                <Link
                                    :href="route('admin.users.show', u.id)"
                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                    title="Ver ficha e historial"
                                >
                                    <Lucide icon="History" class="h-4 w-4" />
                                </Link>
                                <button
                                    type="button"
                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                    title="Editar"
                                    @click="openModal(u)"
                                >
                                    <Lucide icon="Pencil" class="h-4 w-4" />
                                </button>
                                <button
                                    v-if="!isSelf(u)"
                                    type="button"
                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                    title="Eliminar"
                                    @click="deleting = u"
                                >
                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                </button>
                                <!-- Hueco del borrar en la fila propia: sin él
                                 las columnas se corren. -->
                                <span
                                    v-else
                                    class="h-8 w-8"
                                    aria-hidden="true"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-2 px-6 py-12 text-center"
                >
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="Users" class="h-4 w-4" />
                    </div>
                    <p class="text-xs text-slate-500">
                        Ningún usuario coincide con la búsqueda.
                    </p>
                </div>
            </div>
        </div>

        <UserFormModal
            :open="modalOpen"
            :user="editing"
            :is-self="isSelf(editing)"
            :is-last-admin="isLastAdmin(editing)"
            @close="modalOpen = false"
            @saved="onSaved"
        />
        <UserDeleteDialog
            :user="deleting"
            @close="deleting = null"
            @deleted="onDeleted"
        />
    </RazeLayout>
</template>
