<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import TenantHeader from './TenantHeader.vue';
import type { PlanOption, TenantShell } from './types';

interface TeamUser {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    role: string | null;
    role_label: string | null;
    rank: number;
    on_shift: boolean;
    two_factor: boolean;
    created_at: string | null;
    can_delete: boolean;
}

interface RoleOption {
    name: string;
    label: string;
    description: string;
}

const props = defineProps<{
    tenant: TenantShell;
    plans: PlanOption[];
    users: TeamUser[];
    roles: RoleOption[];
    maxUsers: number | null;
}>();

const toast = useToasts();
const users = ref<TeamUser[]>([...props.users]);

// Cada rol con su tono: el color hace de agrupación sin partir la lista.
const roleTone: Record<string, string> = {
    owner: 'bg-primary/10 text-primary',
    manager: 'bg-info/10 text-info',
    'front-desk': 'bg-success/10 text-success',
    housekeeping: 'bg-warning/10 text-warning',
    kitchen: 'bg-pending/10 text-pending',
};
const toneOf = (role: string | null) =>
    (role && roleTone[role]) ||
    'bg-slate-100 text-slate-500 dark:bg-darkmode-400 dark:text-slate-300';

const initialsOf = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('') || '?';

const search = ref('');
const roleFilter = ref('');

const countByRole = computed(() => {
    const counts: Record<string, number> = {};
    for (const u of users.value) {
        if (u.role) counts[u.role] = (counts[u.role] ?? 0) + 1;
    }
    return counts;
});

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();
    return users.value.filter((u) => {
        if (roleFilter.value && u.role !== roleFilter.value) return false;
        if (term === '') return true;
        return (
            u.name.toLowerCase().includes(term) ||
            u.email.toLowerCase().includes(term) ||
            (u.phone ?? '').toLowerCase().includes(term)
        );
    });
});

const owners = computed(
    () => users.value.filter((u) => u.role === 'owner').length,
);
const onShift = computed(() => users.value.filter((u) => u.on_shift).length);
const withTwoFactor = computed(
    () => users.value.filter((u) => u.two_factor).length,
);

const atLimit = computed(
    () => props.maxUsers !== null && users.value.length >= props.maxUsers,
);

// El único dueño no se borra ni se degrada. Se recalcula en cliente: al
// nombrar un segundo dueño el primero queda libre (y al revés).
const canDelete = (u: TeamUser) => !(u.role === 'owner' && owners.value <= 1);
const isLastOwner = (u: TeamUser | null) =>
    !!u && u.role === 'owner' && owners.value <= 1;

function replaceRow(row: TeamUser) {
    const prev = users.value.find((u) => u.id === row.id);
    // La respuesta no sabe del turno abierto: se conserva el que había.
    const merged = { ...row, on_shift: prev?.on_shift ?? false };
    users.value = (
        prev
            ? users.value.map((u) => (u.id === row.id ? merged : u))
            : [...users.value, merged]
    ).sort((a, b) => a.rank - b.rank || a.name.localeCompare(b.name, 'es'));
}

// ── Alta y edición ──
const modal = ref(false);
const editing = ref<TeamUser | null>(null);
const saving = ref(false);
const showPassword = ref(false);
const errors = reactive<Record<string, string>>({});
const form = reactive({
    name: '',
    email: '',
    phone: '',
    password: '',
    role: props.roles[0]?.name ?? 'front-desk',
});

const roleHelp = computed(
    () => props.roles.find((r) => r.name === form.role)?.description ?? '',
);
const demotingLastOwner = computed(
    () => isLastOwner(editing.value) && form.role !== 'owner',
);

function openModal(u: TeamUser | null = null) {
    editing.value = u;
    form.name = u?.name ?? '';
    form.email = u?.email ?? '';
    form.phone = u?.phone ?? '';
    form.password = '';
    form.role =
        u?.role ??
        props.roles.find((r) => r.name === 'front-desk')?.name ??
        props.roles[0]?.name ??
        'front-desk';
    showPassword.value = false;
    Object.keys(errors).forEach((k) => delete errors[k]);
    modal.value = true;
}

function closeModal() {
    if (saving.value) return;
    modal.value = false;
}

function generatePassword() {
    const alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const bytes = new Uint32Array(12);
    crypto.getRandomValues(bytes);
    form.password = Array.from(
        bytes,
        (b) => alphabet[b % alphabet.length],
    ).join('');
    showPassword.value = true;
}

async function submit() {
    if (demotingLastOwner.value) return;
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    const payload: Record<string, unknown> = {
        name: form.name,
        email: form.email,
        phone: form.phone || null,
        role: form.role,
    };
    try {
        if (editing.value) {
            if (form.password) payload.password = form.password;
            const { data } = await axios.patch<TeamUser>(
                route('admin.tenants.users.update', [
                    props.tenant.id,
                    editing.value.id,
                ]),
                payload,
            );
            replaceRow(data);
            toast.success('Acceso actualizado', data.name);
        } else {
            const { data } = await axios.post<TeamUser>(
                route('admin.tenants.users.store', props.tenant.id),
                { ...payload, password: form.password },
            );
            replaceRow(data);
            toast.success('Usuario creado', `${data.name} ya puede entrar.`);
        }
        modal.value = false;
    } catch (e: any) {
        const d = e.response?.data;
        if (d?.errors) {
            Object.entries(d.errors).forEach(
                ([k, msgs]) => (errors[k] = (msgs as string[])[0]),
            );
        } else {
            errors._ = d?.message ?? 'No se pudo guardar el usuario.';
        }
    } finally {
        saving.value = false;
    }
}

// ── Baja: el error se queda en el diálogo, junto a lo que lo provocó ──
const deleting = ref<TeamUser | null>(null);
const deleteError = ref<string | null>(null);

function openDelete(u: TeamUser) {
    deleteError.value = null;
    deleting.value = u;
}

async function destroy() {
    if (!deleting.value) return;
    saving.value = true;
    deleteError.value = null;
    try {
        const gone = deleting.value;
        await axios.delete(
            route('admin.tenants.users.destroy', [props.tenant.id, gone.id]),
        );
        users.value = users.value.filter((u) => u.id !== gone.id);
        deleting.value = null;
        toast.success('Usuario eliminado', gone.name);
    } catch (e: any) {
        deleteError.value =
            e.response?.data?.message ?? 'No se pudo eliminar el usuario.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RazeLayout :title="`${tenant.name} · Equipo`">
        <TenantHeader :tenant="tenant" :plans="plans" active="team" />

        <div
            v-if="!owners"
            class="mt-4 flex items-start gap-2 rounded-lg border border-danger/20 bg-danger/5 px-4 py-3 text-xs text-danger"
        >
            <Lucide icon="TriangleAlert" class="mt-px h-3.5 w-3.5 shrink-0" />
            Este hotel no tiene propietario: nadie recibe los avisos de plan y
            facturación, y "Entrar como" no funciona. Dale el rol de Propietario
            a alguien.
        </div>

        <!-- Cifras -->
        <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
            <div
                class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                    :class="
                        atLimit
                            ? 'border-warning/10 bg-warning/10 text-warning'
                            : 'border-primary/10 bg-primary/10 text-primary'
                    "
                >
                    <Lucide icon="Users" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <div class="text-sm font-medium">
                        {{ users.length
                        }}<span
                            v-if="maxUsers"
                            class="text-[11px] font-normal text-slate-400"
                        >
                            / {{ maxUsers }}</span
                        >
                    </div>
                    <div class="text-xs leading-tight text-slate-500">
                        Usuarios
                    </div>
                    <div
                        class="truncate text-[11px]"
                        :class="
                            atLimit
                                ? 'font-medium text-warning'
                                : 'text-slate-400'
                        "
                    >
                        {{
                            atLimit ? 'Al tope de su plan' : 'Sin el asistente'
                        }}
                    </div>
                </div>
            </div>
            <div
                class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                >
                    <Lucide icon="Crown" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <div class="text-sm font-medium">{{ owners }}</div>
                    <div class="text-xs leading-tight text-slate-500">
                        Propietarios
                    </div>
                    <div class="truncate text-[11px] text-slate-400">
                        Reciben avisos de facturación
                    </div>
                </div>
            </div>
            <div
                class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                >
                    <Lucide icon="Clock" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <div class="text-sm font-medium">{{ onShift }}</div>
                    <div class="text-xs leading-tight text-slate-500">
                        En turno ahora
                    </div>
                    <div class="truncate text-[11px] text-slate-400">
                        Con turno abierto
                    </div>
                </div>
            </div>
            <div
                class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                >
                    <Lucide icon="ShieldCheck" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <div class="text-sm font-medium">
                        {{ withTwoFactor }} de {{ users.length }}
                    </div>
                    <div class="text-xs leading-tight text-slate-500">
                        Con doble factor
                    </div>
                    <div class="truncate text-[11px] text-slate-400">
                        Cada quien lo activa en su perfil
                    </div>
                </div>
            </div>
        </div>

        <!-- Listado -->
        <div class="box box--stacked mt-4">
            <div
                class="flex flex-col gap-2 rounded-t-[0.6rem] border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 lg:flex-row lg:items-center dark:border-darkmode-400 dark:bg-darkmode-600/40"
            >
                <div class="relative lg:w-72">
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
                <FormSelect v-model="roleFilter" class="h-9 text-xs lg:w-52">
                    <option value="">
                        Todos los roles ({{ users.length }})
                    </option>
                    <option
                        v-for="rol in roles"
                        :key="rol.name"
                        :value="rol.name"
                    >
                        {{ rol.label }} ({{ countByRole[rol.name] ?? 0 }})
                    </option>
                </FormSelect>
                <div class="flex items-center gap-3 lg:ml-auto">
                    <span class="text-xs text-slate-500">
                        {{ visible.length }}
                        {{ visible.length === 1 ? 'persona' : 'personas' }}
                    </span>
                    <Button
                        variant="primary"
                        class="ml-auto h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20 lg:ml-0"
                        :disabled="atLimit"
                        :title="
                            atLimit
                                ? 'Llegó al tope de usuarios de su plan: cámbiale el plan para agregar más'
                                : 'Dar acceso a alguien más'
                        "
                        @click="openModal()"
                    >
                        <Lucide icon="UserPlus" class="mr-1.5 h-3.5 w-3.5" />
                        Nuevo usuario
                    </Button>
                </div>
            </div>

            <div
                v-if="visible.length"
                class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
            >
                <div
                    v-for="u in visible"
                    :key="u.id"
                    class="relative flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:px-5"
                >
                    <div
                        class="flex min-w-0 flex-1 items-center gap-3 pr-16 sm:pr-0"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-[11px] font-semibold text-white"
                        >
                            {{ initialsOf(u.name) }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="truncate text-sm font-medium">{{
                                    u.name
                                }}</span>
                                <span
                                    v-if="u.on_shift"
                                    class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success"
                                    title="Tiene un turno abierto ahora mismo"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full bg-success"
                                    />
                                    En turno
                                </span>
                            </div>
                            <a
                                :href="`mailto:${u.email}`"
                                class="block truncate text-xs text-slate-500 hover:text-primary"
                                >{{ u.email }}</a
                            >
                        </div>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-x-4 gap-y-1.5 pl-12 sm:contents"
                    >
                        <div class="sm:w-36 sm:shrink-0">
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="toneOf(u.role)"
                                >{{ u.role_label ?? 'Sin rol' }}</span
                            >
                        </div>
                        <div class="text-xs sm:w-32 sm:shrink-0">
                            <a
                                v-if="u.phone"
                                :href="`tel:${u.phone}`"
                                class="text-slate-600 hover:text-primary dark:text-slate-300"
                                >{{ u.phone }}</a
                            >
                            <span v-else class="text-slate-400"
                                >Sin teléfono</span
                            >
                        </div>
                        <div
                            class="text-[11px] whitespace-nowrap sm:w-32 sm:shrink-0"
                        >
                            <span
                                class="inline-flex items-center gap-1"
                                :class="
                                    u.two_factor
                                        ? 'text-success'
                                        : 'text-slate-400'
                                "
                            >
                                <Lucide
                                    :icon="
                                        u.two_factor ? 'ShieldCheck' : 'Shield'
                                    "
                                    class="h-3 w-3"
                                />
                                {{ u.two_factor ? 'Doble factor' : 'Sin 2FA' }}
                            </span>
                            <div class="text-slate-400">
                                Desde {{ u.created_at ?? '' }}
                            </div>
                        </div>
                    </div>

                    <div
                        class="absolute top-2.5 right-3 flex items-center gap-0.5 sm:static"
                    >
                        <button
                            type="button"
                            title="Editar acceso"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                            @click="openModal(u)"
                        >
                            <Lucide icon="Pencil" class="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full transition"
                            :class="
                                canDelete(u)
                                    ? 'text-slate-500 hover:bg-danger/10 hover:text-danger'
                                    : 'cursor-not-allowed text-slate-300 dark:text-darkmode-400'
                            "
                            :disabled="!canDelete(u)"
                            :title="
                                canDelete(u)
                                    ? 'Quitarle el acceso'
                                    : 'Es el único propietario: nombra otro antes de quitarlo'
                            "
                            @click="openDelete(u)"
                        >
                            <Lucide icon="Trash2" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-else
                class="flex flex-col items-center gap-2 px-4 py-10 text-center"
            >
                <div
                    class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                >
                    <Lucide
                        :icon="users.length ? 'SearchX' : 'Users'"
                        class="h-4 w-4"
                    />
                </div>
                <p class="text-xs text-slate-500">
                    {{
                        users.length
                            ? 'Nadie coincide con el filtro.'
                            : 'Sin usuarios todavía: el primero debe ser el propietario.'
                    }}
                </p>
                <button
                    v-if="users.length && (search || roleFilter)"
                    type="button"
                    class="text-xs font-medium text-primary"
                    @click="
                        search = '';
                        roleFilter = '';
                    "
                >
                    Quitar filtros
                </button>
            </div>

            <p
                class="border-t border-slate-200/60 px-4 py-2.5 text-[11px] text-slate-400 dark:border-darkmode-400"
            >
                El asistente no aparece aquí: es una identidad técnica, no una
                persona. Quien ya tenga ventas, turnos o cortes registrados no
                se puede eliminar; se conserva por auditoría.
            </p>
        </div>

        <!-- Modal crear / editar usuario -->
        <Dialog :open="modal" size="lg" @close="closeModal">
            <Dialog.Panel class="sm:w-[94vw] lg:w-[640px]">
                <form
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                    @submit.prevent="submit"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide
                                :icon="editing ? 'UserCog' : 'UserPlus'"
                                class="h-4 w-4"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-base font-medium">
                                {{
                                    editing
                                        ? `Editar a ${editing.name}`
                                        : 'Nuevo usuario'
                                }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Acceso al panel de {{ tenant.name }}.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            title="Cerrar"
                            @click="closeModal"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>

                    <div
                        class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4"
                    >
                        <section>
                            <div
                                class="mb-3 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                La persona
                            </div>
                            <div class="grid grid-cols-12 gap-4">
                                <div class="col-span-12">
                                    <label
                                        for="user-name"
                                        class="mb-1.5 block text-xs font-medium"
                                        >Nombre</label
                                    >
                                    <FormInput
                                        id="user-name"
                                        v-model="form.name"
                                        type="text"
                                        maxlength="255"
                                        class="h-9 text-xs"
                                        placeholder="Ana López"
                                    />
                                    <FormHelp
                                        v-if="errors.name"
                                        class="text-danger"
                                        >{{ errors.name }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-7">
                                    <label
                                        for="user-email"
                                        class="mb-1.5 block text-xs font-medium"
                                        >Correo (su usuario)</label
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="Mail"
                                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                        />
                                        <FormInput
                                            id="user-email"
                                            v-model="form.email"
                                            type="email"
                                            class="h-9 pl-9 text-xs"
                                            placeholder="ana@hotel.com"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="errors.email"
                                        class="text-danger"
                                        >{{ errors.email }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-5">
                                    <label
                                        for="user-phone"
                                        class="mb-1.5 block text-xs font-medium"
                                        >Teléfono</label
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="Phone"
                                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                        />
                                        <FormInput
                                            id="user-phone"
                                            v-model="form.phone"
                                            type="tel"
                                            maxlength="30"
                                            class="h-9 pl-9 text-xs"
                                            placeholder="656 123 4567"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="errors.phone"
                                        class="text-danger"
                                        >{{ errors.phone }}</FormHelp
                                    >
                                </div>
                            </div>
                        </section>

                        <section
                            class="border-t border-dashed border-slate-200/70 pt-5 dark:border-darkmode-400"
                        >
                            <div
                                class="mb-3 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Acceso
                            </div>
                            <div class="grid grid-cols-12 gap-4">
                                <div class="col-span-12 sm:col-span-6">
                                    <label
                                        for="user-role"
                                        class="mb-1.5 block text-xs font-medium"
                                        >Rol</label
                                    >
                                    <FormSelect
                                        id="user-role"
                                        v-model="form.role"
                                        class="h-9 text-xs"
                                    >
                                        <option
                                            v-for="rol in roles"
                                            :key="rol.name"
                                            :value="rol.name"
                                        >
                                            {{ rol.label }}
                                        </option>
                                    </FormSelect>
                                    <FormHelp
                                        v-if="errors.role"
                                        class="text-danger"
                                        >{{ errors.role }}</FormHelp
                                    >
                                    <FormHelp
                                        v-else-if="demotingLastOwner"
                                        class="text-danger"
                                        >Es el único propietario: nombra a otro
                                        antes de cambiarle el rol.</FormHelp
                                    >
                                    <FormHelp v-else>{{ roleHelp }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <label
                                        for="user-password"
                                        class="mb-1.5 block text-xs font-medium"
                                        >{{
                                            editing
                                                ? 'Nueva contraseña'
                                                : 'Contraseña'
                                        }}</label
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="KeyRound"
                                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                        />
                                        <FormInput
                                            id="user-password"
                                            v-model="form.password"
                                            :type="
                                                showPassword
                                                    ? 'text'
                                                    : 'password'
                                            "
                                            autocomplete="new-password"
                                            class="h-9 pr-16 pl-9 text-xs"
                                            :placeholder="
                                                editing
                                                    ? 'Vacío = la conserva'
                                                    : 'Mínimo 8 caracteres'
                                            "
                                        />
                                        <div
                                            class="absolute inset-y-0 right-1 z-10 my-auto flex items-center"
                                        >
                                            <button
                                                type="button"
                                                class="flex h-7 w-7 items-center justify-center rounded-full text-slate-400 hover:text-primary"
                                                :title="
                                                    showPassword
                                                        ? 'Ocultar'
                                                        : 'Mostrar'
                                                "
                                                @click="
                                                    showPassword = !showPassword
                                                "
                                            >
                                                <Lucide
                                                    :icon="
                                                        showPassword
                                                            ? 'EyeOff'
                                                            : 'Eye'
                                                    "
                                                    class="h-3.5 w-3.5"
                                                />
                                            </button>
                                            <button
                                                type="button"
                                                class="flex h-7 w-7 items-center justify-center rounded-full text-slate-400 hover:text-primary"
                                                title="Generar una contraseña segura"
                                                @click="generatePassword"
                                            >
                                                <Lucide
                                                    icon="Shuffle"
                                                    class="h-3.5 w-3.5"
                                                />
                                            </button>
                                        </div>
                                    </div>
                                    <FormHelp
                                        v-if="errors.password"
                                        class="text-danger"
                                        >{{ errors.password }}</FormHelp
                                    >
                                    <FormHelp v-else-if="editing"
                                        >Solo si la olvidó; vacío la deja
                                        igual.</FormHelp
                                    >
                                </div>
                            </div>
                        </section>

                        <p
                            v-if="errors._"
                            class="flex items-start gap-2 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                        >
                            <Lucide
                                icon="TriangleAlert"
                                class="mt-px h-3.5 w-3.5 shrink-0"
                            />
                            {{ errors._ }}
                        </p>
                    </div>

                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="saving"
                            @click="closeModal"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                            :disabled="saving || demotingLastOwner"
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                saving
                                    ? 'Guardando...'
                                    : editing
                                      ? 'Guardar cambios'
                                      : 'Crear usuario'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmación: quitar acceso -->
        <Dialog :open="deleting !== null" @close="!saving && (deleting = null)">
            <Dialog.Panel>
                <div v-if="deleting" class="p-5">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                Quitarle el acceso a {{ deleting.name }}
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">
                                Deja de entrar al panel de {{ tenant.name }}. Si
                                ya tiene ventas, turnos o cortes registrados no
                                se puede borrar: se conserva por auditoría.
                            </p>
                            <p
                                v-if="deleteError"
                                class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                            >
                                {{ deleteError }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="saving"
                            @click="deleting = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="saving"
                            @click="destroy"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            {{ saving ? 'Eliminando...' : 'Sí, quitarlo' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
