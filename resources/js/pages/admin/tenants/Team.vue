<script setup lang="ts">
import { router } from '@inertiajs/vue3';
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
import RazeLayout from '@/layouts/RazeLayout.vue';
import TenantHeader from './TenantHeader.vue';
import type { PlanOption, TenantShell } from './types';

interface TeamUser {
    id: number;
    name: string;
    email: string;
    role: string | null;
}

const props = defineProps<{
    tenant: TenantShell;
    plans: PlanOption[];
    owner: { name: string; email: string } | null;
    users: TeamUser[];
    assignableRoles: string[];
    maxUsers: number | null;
}>();

const roleLabels: Record<string, string> = {
    owner: 'Propietario',
    manager: 'Gerente',
    'front-desk': 'Recepción',
    housekeeping: 'Limpieza',
    kitchen: 'Cocina',
};
const roleLabel = (r: string | null) => (r ? (roleLabels[r] ?? r) : '—');
const initialsOf = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('') || '?';

const users = ref<TeamUser[]>([...props.users]);
const atLimit = computed(
    () => props.maxUsers !== null && users.value.length >= props.maxUsers,
);

// Agrupados por rol: en un hotel se piensa por puesto, no por lista plana.
const groups = computed(() => {
    const order = ['owner', ...props.assignableRoles];
    const seen = new Set<string>();
    const rows: Array<{ role: string; label: string; people: TeamUser[] }> = [];

    for (const role of order) {
        if (seen.has(role)) continue;
        seen.add(role);
        const people = users.value.filter((u) => u.role === role);
        if (people.length) {
            rows.push({ role, label: roleLabel(role), people });
        }
    }

    const rest = users.value.filter((u) => !u.role || !seen.has(u.role));
    if (rest.length) {
        rows.push({ role: 'otros', label: 'Sin rol', people: rest });
    }

    return rows;
});

const modal = ref(false);
const editing = ref<TeamUser | null>(null);
const deleting = ref<TeamUser | null>(null);
const saving = ref(false);
const errors = reactive<Record<string, string>>({});
const form = reactive({
    name: '',
    email: '',
    password: '',
    role: props.assignableRoles[0] ?? 'front-desk',
});

function openModal(u: TeamUser | null = null) {
    editing.value = u;
    form.name = u?.name ?? '';
    form.email = u?.email ?? '';
    form.password = '';
    form.role = u?.role ?? props.assignableRoles[0] ?? 'front-desk';
    Object.keys(errors).forEach((k) => delete errors[k]);
    modal.value = true;
}

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        if (editing.value) {
            const payload: Record<string, unknown> = {
                name: form.name,
                email: form.email,
                role: form.role,
            };
            if (form.password) payload.password = form.password;
            const { data } = await axios.patch<TeamUser>(
                route('admin.tenants.users.update', [
                    props.tenant.id,
                    editing.value.id,
                ]),
                payload,
            );
            users.value = users.value.map((u) => (u.id === data.id ? data : u));
        } else {
            const { data } = await axios.post<TeamUser>(
                route('admin.tenants.users.store', props.tenant.id),
                { ...form },
            );
            users.value = [...users.value, data];
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
        router.reload({ only: ['owner'] });
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

        <div class="mt-5 grid grid-cols-12 gap-5">
            <div class="col-span-12">
                <div class="box box--stacked">
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
                            class="ml-auto rounded-[0.5rem] bg-white"
                            :disabled="atLimit"
                            :title="
                                atLimit
                                    ? 'Límite del plan alcanzado'
                                    : 'Agregar usuario'
                            "
                            @click="openModal()"
                        >
                            <Lucide icon="Plus" class="mr-1 h-3.5 w-3.5" />
                            Nuevo usuario
                        </Button>
                    </div>

                    <div class="space-y-5 p-5">
                        <div v-for="group in groups" :key="group.role">
                            <div
                                class="mb-2 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                {{ group.label }}
                                <span class="text-slate-300"
                                    >· {{ group.people.length }}</span
                                >
                            </div>
                            <div class="grid gap-3 lg:grid-cols-2">
                                <div
                                    v-for="u in group.people"
                                    :key="u.id"
                                    class="flex items-center gap-3 rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-[11px] font-semibold text-white"
                                    >
                                        {{ initialsOf(u.name) }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="truncate text-sm font-medium"
                                        >
                                            {{ u.name }}
                                        </div>
                                        <div
                                            class="truncate text-xs text-slate-500"
                                        >
                                            {{ u.email }}
                                        </div>
                                    </div>
                                    <div
                                        class="flex shrink-0 items-center gap-1"
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
                                            title="Eliminar"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                            @click="deleting = u"
                                        >
                                            <Lucide
                                                icon="Trash2"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="!users.length"
                            class="py-10 text-center text-sm text-slate-400"
                        >
                            Sin usuarios. Agrega el primero con "Nuevo usuario".
                        </div>
                    </div>

                    <div
                        class="border-t border-dashed border-slate-300/70 px-5 py-3.5"
                    >
                        <FormHelp class="mt-0">
                            El propietario es quien recibe los avisos de plan y
                            facturación. El bot del asistente no aparece aquí:
                            es una identidad técnica, no una persona.
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
                        <div>
                            <FormLabel htmlFor="user-email"
                                >Correo (usuario de acceso)</FormLabel
                            >
                            <FormInput
                                id="user-email"
                                v-model="form.email"
                                type="email"
                                placeholder="ana@hotel.com"
                            />
                            <FormHelp v-if="errors.email" class="text-danger">{{
                                errors.email
                            }}</FormHelp>
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
                                    v-for="r in assignableRoles"
                                    :key="r"
                                    :value="r"
                                >
                                    {{ roleLabel(r) }}
                                </option>
                            </FormSelect>
                            <FormHelp v-if="errors.role" class="text-danger">{{
                                errors.role
                            }}</FormHelp>
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
