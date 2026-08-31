<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormLabel,
    FormSelect,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
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

const users = ref<TeamUser[]>([...props.users]);

// Cada rol con su tono: el color hace de agrupación sin partir la tabla.
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
const roleFilter = ref<string | null>(null);

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

const atLimit = computed(
    () => props.maxUsers !== null && users.value.length >= props.maxUsers,
);

// Sin dueño el hotel se queda sin quien reciba avisos de plan y cobranza.
const sinDueno = computed(() => !users.value.some((u) => u.role === 'owner'));

function replaceRow(row: TeamUser) {
    const i = users.value.findIndex((u) => u.id === row.id);
    users.value =
        i === -1
            ? [...users.value, row]
            : users.value.map((u) => (u.id === row.id ? row : u));
    users.value = [...users.value].sort(
        (a, b) => a.rank - b.rank || a.name.localeCompare(b.name),
    );
}

// ── Alta y edición ──
const modal = ref(false);
const editing = ref<TeamUser | null>(null);
const saving = ref(false);
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

function openModal(u: TeamUser | null = null) {
    editing.value = u;
    form.name = u?.name ?? '';
    form.email = u?.email ?? '';
    form.phone = u?.phone ?? '';
    form.password = '';
    form.role = u?.role ?? props.roles[0]?.name ?? 'front-desk';
    Object.keys(errors).forEach((k) => delete errors[k]);
    modal.value = true;
}

async function submit() {
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
        } else {
            const { data } = await axios.post<TeamUser>(
                route('admin.tenants.users.store', props.tenant.id),
                { ...payload, password: form.password },
            );
            replaceRow(data);
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

// ── Baja ──
const deleting = ref<TeamUser | null>(null);
const deleteError = ref<string | null>(null);

async function destroy() {
    if (!deleting.value) return;
    saving.value = true;
    deleteError.value = null;
    try {
        await axios.delete(
            route('admin.tenants.users.destroy', [
                props.tenant.id,
                deleting.value.id,
            ]),
        );
        users.value = users.value.filter((u) => u.id !== deleting.value!.id);
        deleting.value = null;
    } catch (e: any) {
        deleteError.value =
            e.response?.data?.message ?? 'No se pudo eliminar el usuario.';
        deleting.value = null;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RazeLayout :title="`${tenant.name} · Equipo`">
        <TenantHeader :tenant="tenant" :plans="plans" active="team" />

        <div
            v-if="deleteError"
            class="mt-4 flex items-center rounded-md border border-danger/20 bg-danger/5 px-4 py-3 text-sm text-danger"
        >
            <Lucide icon="TriangleAlert" class="mr-2 h-4 w-4 shrink-0" />
            {{ deleteError }}
        </div>

        <div
            v-if="sinDueno"
            class="mt-4 flex items-center rounded-md border border-warning/20 bg-warning/5 px-4 py-3 text-sm text-warning"
        >
            <Lucide icon="TriangleAlert" class="mr-2 h-4 w-4 shrink-0" />
            Este hotel no tiene propietario: nadie recibe los avisos de plan y
            facturación.
        </div>

        <div class="mt-5 grid grid-cols-12 gap-5">
            <div class="col-span-12">
                <div class="box box--stacked">
                    <!-- Cabecera: cuántos son, de qué plan y el alta -->
                    <div
                        class="flex flex-wrap items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                    >
                        <Lucide
                            icon="Users"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">Equipo y accesos</h2>
                        <span
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                        >
                            {{ users.length
                            }}<template v-if="maxUsers">
                                / {{ maxUsers }}</template
                            >
                        </span>
                        <Button
                            variant="outline-primary"
                            size="sm"
                            class="w-full rounded-[0.5rem] bg-white sm:ml-auto sm:w-auto"
                            :disabled="atLimit"
                            :title="
                                atLimit
                                    ? 'Límite del plan alcanzado: cámbiale el plan para agregar más'
                                    : 'Agregar usuario'
                            "
                            @click="openModal()"
                        >
                            <Lucide icon="Plus" class="mr-1 h-3.5 w-3.5" />
                            Nuevo usuario
                        </Button>
                    </div>

                    <!-- Filtros: los roles con su conteo hacen de índice -->
                    <div
                        class="flex flex-col gap-3 border-b border-slate-200/70 p-5 lg:flex-row lg:items-center dark:border-darkmode-400"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="rounded-full px-2.5 py-1 text-xs font-medium transition"
                                :class="
                                    roleFilter === null
                                        ? 'bg-primary text-white'
                                        : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400 dark:text-slate-300'
                                "
                                @click="roleFilter = null"
                            >
                                Todos · {{ users.length }}
                            </button>
                            <button
                                v-for="rol in roles"
                                :key="rol.name"
                                type="button"
                                class="rounded-full px-2.5 py-1 text-xs font-medium transition"
                                :class="
                                    roleFilter === rol.name
                                        ? 'bg-primary text-white'
                                        : toneOf(rol.name)
                                "
                                :title="rol.description"
                                @click="
                                    roleFilter =
                                        roleFilter === rol.name
                                            ? null
                                            : rol.name
                                "
                            >
                                {{ rol.label }} ·
                                {{ countByRole[rol.name] ?? 0 }}
                            </button>
                        </div>
                        <div class="relative lg:ml-auto lg:w-72">
                            <Lucide
                                icon="Search"
                                class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400"
                            />
                            <FormInput
                                v-model="search"
                                class="pl-9"
                                placeholder="Buscar por nombre, correo o teléfono…"
                            />
                        </div>
                    </div>

                    <div class="overflow-auto p-5 lg:overflow-visible">
                        <Table v-if="visible.length" striped>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>Persona</Table.Th>
                                    <Table.Th class="whitespace-nowrap"
                                        >Rol</Table.Th
                                    >
                                    <Table.Th class="whitespace-nowrap"
                                        >Teléfono</Table.Th
                                    >
                                    <Table.Th class="whitespace-nowrap"
                                        >Con acceso desde</Table.Th
                                    >
                                    <Table.Th
                                        class="text-right whitespace-nowrap"
                                        >Acciones</Table.Th
                                    >
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr v-for="u in visible" :key="u.id">
                                    <Table.Td>
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-[11px] font-semibold text-white"
                                            >
                                                {{ initialsOf(u.name) }}
                                            </div>
                                            <div class="min-w-0">
                                                <div
                                                    class="flex flex-wrap items-center gap-1.5"
                                                >
                                                    <span class="font-medium">{{
                                                        u.name
                                                    }}</span>
                                                    <span
                                                        v-if="u.on_shift"
                                                        class="rounded-full bg-success/10 px-2 py-0.5 text-[10px] font-medium text-success"
                                                        title="Tiene un turno abierto ahora mismo"
                                                    >
                                                        En turno
                                                    </span>
                                                    <span
                                                        v-if="u.two_factor"
                                                        class="flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-darkmode-400 dark:text-slate-300"
                                                        title="Tiene verificación en dos pasos activada"
                                                    >
                                                        <Lucide
                                                            icon="ShieldCheck"
                                                            class="h-3 w-3"
                                                        />
                                                        2FA
                                                    </span>
                                                </div>
                                                <div
                                                    class="truncate text-xs text-slate-500"
                                                >
                                                    {{ u.email }}
                                                </div>
                                            </div>
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="whitespace-nowrap">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="toneOf(u.role)"
                                        >
                                            {{ u.role_label ?? 'Sin rol' }}
                                        </span>
                                    </Table.Td>
                                    <Table.Td
                                        class="whitespace-nowrap"
                                        :class="u.phone ? '' : 'text-slate-400'"
                                    >
                                        {{ u.phone ?? 'Sin capturar' }}
                                    </Table.Td>
                                    <Table.Td
                                        class="text-xs whitespace-nowrap text-slate-500"
                                    >
                                        {{ u.created_at ?? '—' }}
                                    </Table.Td>
                                    <Table.Td>
                                        <div
                                            class="flex items-center justify-end gap-2"
                                        >
                                            <button
                                                type="button"
                                                title="Editar acceso"
                                                class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                                @click="openModal(u)"
                                            >
                                                <Lucide
                                                    icon="Pencil"
                                                    class="h-4 w-4"
                                                />
                                            </button>
                                            <button
                                                type="button"
                                                class="flex h-8 w-8 items-center justify-center rounded-full transition"
                                                :class="
                                                    u.can_delete
                                                        ? 'text-slate-400 hover:bg-danger/10 hover:text-danger'
                                                        : 'cursor-not-allowed text-slate-200 dark:text-darkmode-400'
                                                "
                                                :disabled="!u.can_delete"
                                                :title="
                                                    u.can_delete
                                                        ? 'Eliminar'
                                                        : 'Es el único propietario: asigna otro dueño antes de quitarlo'
                                                "
                                                @click="deleting = u"
                                            >
                                                <Lucide
                                                    icon="Trash2"
                                                    class="h-4 w-4"
                                                />
                                            </button>
                                        </div>
                                    </Table.Td>
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>

                        <div
                            v-else
                            class="flex flex-col items-center gap-2 py-12 text-center"
                        >
                            <Lucide
                                icon="Users"
                                class="h-9 w-9 text-slate-300"
                            />
                            <p class="text-sm font-medium text-slate-600">
                                {{
                                    users.length
                                        ? 'Nadie con ese filtro'
                                        : 'Sin usuarios todavía'
                                }}
                            </p>
                            <p class="max-w-sm text-xs text-slate-500">
                                {{
                                    users.length
                                        ? 'Prueba con otro rol o limpia la búsqueda.'
                                        : 'Agrega el primero con "Nuevo usuario": necesita al menos un propietario.'
                                }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="border-t border-dashed border-slate-300/70 px-5 py-3.5"
                    >
                        <FormHelp class="mt-0">
                            El propietario es quien recibe los avisos de plan y
                            facturación. El bot del asistente no aparece aquí:
                            es una identidad técnica, no una persona. Quien ya
                            tenga ventas, turnos o cortes registrados no se
                            puede eliminar — se conserva por auditoría.
                        </FormHelp>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal crear / editar usuario -->
        <Dialog :open="modal" @close="modal = false">
            <Dialog.Panel>
                <form class="p-5" @submit.prevent="submit">
                    <div class="mb-4 flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide
                                :icon="editing ? 'UserCog' : 'UserPlus'"
                                class="h-5 w-5 text-primary"
                            />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                {{
                                    editing ? 'Editar acceso' : 'Nuevo usuario'
                                }}
                            </h2>
                            <p class="text-xs text-slate-500">
                                {{ tenant.name }}
                            </p>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <FormLabel htmlFor="user-name">Nombre</FormLabel>
                            <FormInput
                                id="user-name"
                                v-model="form.name"
                                type="text"
                                placeholder="Ana López"
                            />
                            <FormHelp v-if="errors.name" class="text-danger">{{
                                errors.name
                            }}</FormHelp>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <FormLabel htmlFor="user-email"
                                    >Correo (su usuario)</FormLabel
                                >
                                <FormInput
                                    id="user-email"
                                    v-model="form.email"
                                    type="email"
                                    placeholder="ana@hotel.com"
                                />
                                <FormHelp
                                    v-if="errors.email"
                                    class="text-danger"
                                    >{{ errors.email }}</FormHelp
                                >
                            </div>
                            <div>
                                <FormLabel htmlFor="user-phone"
                                    >Teléfono</FormLabel
                                >
                                <FormInput
                                    id="user-phone"
                                    v-model="form.phone"
                                    type="text"
                                    placeholder="55 1234 5678"
                                />
                                <FormHelp
                                    v-if="errors.phone"
                                    class="text-danger"
                                    >{{ errors.phone }}</FormHelp
                                >
                            </div>
                        </div>
                        <div>
                            <FormLabel htmlFor="user-password">
                                Contraseña
                                <span v-if="editing" class="text-slate-400"
                                    >(vacío = conservar la actual)</span
                                >
                            </FormLabel>
                            <FormInput
                                id="user-password"
                                v-model="form.password"
                                type="password"
                                :placeholder="
                                    editing ? '••••••••' : 'Mínimo 8 caracteres'
                                "
                                autocomplete="new-password"
                            />
                            <FormHelp
                                v-if="errors.password"
                                class="text-danger"
                                >{{ errors.password }}</FormHelp
                            >
                        </div>
                        <div>
                            <FormLabel htmlFor="user-role">Rol</FormLabel>
                            <FormSelect id="user-role" v-model="form.role">
                                <option
                                    v-for="rol in roles"
                                    :key="rol.name"
                                    :value="rol.name"
                                >
                                    {{ rol.label }}
                                </option>
                            </FormSelect>
                            <FormHelp v-if="errors.role" class="text-danger">{{
                                errors.role
                            }}</FormHelp>
                            <FormHelp v-else>{{ roleHelp }}</FormHelp>
                        </div>
                        <p
                            v-if="errors._"
                            class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                        >
                            {{ errors._ }}
                        </p>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="modal = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            :disabled="saving"
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{
                                saving
                                    ? 'Guardando…'
                                    : editing
                                      ? 'Guardar cambios'
                                      : 'Crear usuario'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal eliminar usuario -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide
                        icon="TriangleAlert"
                        class="mx-auto mb-3 h-12 w-12 text-danger"
                    />
                    <h2 class="text-base font-medium">
                        ¿Eliminar a {{ deleting?.name }}?
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Perderá el acceso al panel de {{ tenant.name }}. Si
                        tiene ventas, turnos o cortes registrados, se conservará
                        por auditoría (no se podrá borrar).
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="deleting = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="saving"
                            @click="destroy"
                        >
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" /> Sí,
                            eliminar
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
