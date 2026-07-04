<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface UserRow {
    id: number;
    name: string;
    email: string;
    roles: string[];
    role_labels: string[];
    is_self: boolean;
    on_shift: boolean;
    created_at: string | null;
}
interface RoleOption { name: string; label: string; description: string }

const props = defineProps<{
    property: { id: number; name: string };
    users: UserRow[];
    roles: RoleOption[];
    maxUsers: number | null;
    canManage: boolean;
}>();

const toast = useToasts();
const initials = (name: string) =>
    name.trim().split(/\s+/).slice(0, 2).map((p) => p.charAt(0).toUpperCase()).join('') || '?';

const roleBadge: Record<string, string> = {
    owner: 'bg-primary/10 text-primary',
    manager: 'bg-info/10 text-info',
    'front-desk': 'bg-success/10 text-success',
    housekeeping: 'bg-warning/10 text-warning',
    kitchen: 'bg-pending/10 text-pending',
    agent: 'bg-dark/10 text-dark',
};

const atLimit = computed(() => props.maxUsers !== null && props.users.length >= props.maxUsers);

const cellClass =
    'box shadow-[5px_3px_5px_#00000005] first:border-l last:border-r first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] rounded-l-none rounded-r-none border-x-0 dark:bg-darkmode-600';
const iconInput = 'absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400';

// ── Crear / editar ──
const showForm = ref(false);
const editing = ref<UserRow | null>(null);
const saving = ref(false);
const errors = reactive<Record<string, string>>({});
const generalError = ref<string | null>(null);
const showPassword = ref(false);
const form = reactive({ name: '', email: '', password: '', role: 'front-desk' });

function clearErrors() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    generalError.value = null;
}

function openCreate() {
    editing.value = null;
    form.name = '';
    form.email = '';
    form.password = '';
    form.role = 'front-desk';
    showPassword.value = false;
    clearErrors();
    showForm.value = true;
}

function openEdit(user: UserRow) {
    editing.value = user;
    form.name = user.name;
    form.email = user.email;
    form.password = '';
    form.role = user.roles[0] ?? 'front-desk';
    showPassword.value = false;
    clearErrors();
    showForm.value = true;
}

function generatePassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    form.password = Array.from(crypto.getRandomValues(new Uint32Array(12)), (n) => chars[n % chars.length]).join('');
    showPassword.value = true;
}

async function submit() {
    saving.value = true;
    clearErrors();
    try {
        if (editing.value) {
            await axios.patch(`/api/users/${editing.value.id}`, {
                name: form.name,
                email: form.email,
                password: form.password || null,
                role: form.role,
            });
            toast.success('Usuario actualizado', `${form.name} se guardó correctamente.`);
        } else {
            await axios.post('/api/users', form);
            toast.success('Usuario creado', `${form.name} ya puede entrar al sistema.`);
        }
        showForm.value = false;
        router.reload({ only: ['users'] });
    } catch (error: any) {
        const data = error.response?.data;
        if (data?.errors) Object.entries(data.errors).forEach(([key, msgs]) => (errors[key] = (msgs as string[])[0]));
        else generalError.value = data?.message ?? 'Ocurrió un error.';
    } finally {
        saving.value = false;
    }
}

// ── Eliminar ──
const deleting = ref<UserRow | null>(null);
const deleteError = ref<string | null>(null);

async function submitDelete() {
    if (!deleting.value) return;
    saving.value = true;
    deleteError.value = null;
    try {
        await axios.delete(`/api/users/${deleting.value.id}`);
        toast.success('Usuario eliminado');
        deleting.value = null;
        router.reload({ only: ['users'] });
    } catch (error: any) {
        deleteError.value = error.response?.data?.message ?? 'No se pudo eliminar.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Usuarios">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Usuarios del sistema</h1>
                    <p class="text-sm text-slate-500">
                        {{ users.length }}<span v-if="maxUsers"> de {{ maxUsers }}</span> usuario(s) · {{ property.name }}
                    </p>
                </div>
                <Button v-if="canManage" variant="primary" class="rounded-[0.5rem] shadow-md shadow-primary/20" :disabled="atLimit" @click="openCreate">
                    <Lucide icon="UserPlus" class="mr-2 h-4 w-4 stroke-[1.3]" /> Nuevo usuario
                </Button>
            </div>

            <div v-if="atLimit" class="mt-4 flex items-center gap-2 rounded-lg border-l-4 border-l-warning bg-warning/5 px-4 py-3 text-sm">
                <Lucide icon="TriangleAlert" class="h-4 w-4 shrink-0 text-warning" />
                Alcanzaste el límite de usuarios de tu plan ({{ maxUsers }}). Mejora el plan para agregar más.
            </div>

            <div class="mt-5 overflow-auto lg:overflow-visible">
                <Table class="border-separate border-spacing-y-[8px]">
                    <Table.Thead>
                        <Table.Tr>
                            <Table.Th class="border-b-0 !bg-transparent">Usuario</Table.Th>
                            <Table.Th class="border-b-0 !bg-transparent">Rol</Table.Th>
                            <Table.Th class="border-b-0 !bg-transparent">Estado</Table.Th>
                            <Table.Th class="border-b-0 !bg-transparent">Alta</Table.Th>
                            <Table.Th class="border-b-0 !bg-transparent text-right">Acciones</Table.Th>
                        </Table.Tr>
                    </Table.Thead>
                    <Table.Tbody>
                        <Table.Tr v-for="u in users" :key="u.id">
                            <Table.Td :class="cellClass">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-xs font-semibold text-white">
                                        {{ initials(u.name) }}
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5 font-medium">
                                            {{ u.name }}
                                            <span v-if="u.is_self" class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400">tú</span>
                                        </div>
                                        <div class="flex items-center gap-1.5 text-xs text-slate-500"><Lucide icon="Mail" class="h-3 w-3" /> {{ u.email }}</div>
                                    </div>
                                </div>
                            </Table.Td>
                            <Table.Td :class="cellClass">
                                <span
                                    v-for="(role, i) in u.roles"
                                    :key="role"
                                    class="mr-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="roleBadge[role] ?? 'bg-slate-100 text-slate-500'"
                                >
                                    {{ u.role_labels[i] ?? role }}
                                </span>
                                <span v-if="!u.roles.length" class="text-xs text-slate-400">Sin rol</span>
                            </Table.Td>
                            <Table.Td :class="cellClass">
                                <span v-if="u.on_shift" class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success">
                                    <span class="h-1.5 w-1.5 rounded-full bg-success" /> En turno
                                </span>
                                <span v-else class="text-xs text-slate-400">—</span>
                            </Table.Td>
                            <Table.Td :class="cellClass" class="whitespace-nowrap text-sm text-slate-500">{{ u.created_at ?? '—' }}</Table.Td>
                            <Table.Td :class="cellClass" class="text-right">
                                <div v-if="canManage" class="flex items-center justify-end gap-2">
                                    <button type="button" title="Editar" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary" @click="openEdit(u)">
                                        <Lucide icon="Pencil" class="h-4 w-4" />
                                    </button>
                                    <button
                                        type="button"
                                        :title="u.is_self ? 'No puedes eliminar tu propia cuenta' : 'Eliminar'"
                                        class="flex h-8 w-8 items-center justify-center rounded-full transition"
                                        :class="u.is_self ? 'cursor-not-allowed text-slate-300 dark:text-darkmode-300' : 'text-slate-500 hover:bg-danger/10 hover:text-danger'"
                                        :disabled="u.is_self"
                                        @click="deleteError = null; deleting = u"
                                    >
                                        <Lucide icon="Trash2" class="h-4 w-4" />
                                    </button>
                                </div>
                            </Table.Td>
                        </Table.Tr>
                    </Table.Tbody>
                </Table>
            </div>
        </div>

        <!-- Modal crear / editar -->
        <Dialog size="lg" :open="showForm" @close="showForm = false">
            <Dialog.Panel>
                <form class="flex max-h-[85vh] flex-col" @submit.prevent="submit">
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Lucide :icon="editing ? 'Pencil' : 'UserPlus'" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">{{ editing ? `Editar a ${editing.name}` : 'Nuevo usuario' }}</h2>
                            <p class="mt-0.5 text-xs text-slate-500">Acceso al panel de {{ property.name }}</p>
                        </div>
                        <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400" @click="showForm = false">
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                        <div class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm">Nombre</label>
                                <div class="relative">
                                    <Lucide icon="User" :class="iconInput" />
                                    <FormInput v-model="form.name" type="text" class="pl-9" placeholder="Nombre y apellido" />
                                </div>
                                <FormHelp v-if="errors.name" class="text-danger">{{ errors.name }}</FormHelp>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Email (para entrar)</label>
                                <div class="relative">
                                    <Lucide icon="Mail" :class="iconInput" />
                                    <FormInput v-model="form.email" type="email" class="pl-9" placeholder="correo@hotel.com" />
                                </div>
                                <FormHelp v-if="errors.email" class="text-danger">{{ errors.email }}</FormHelp>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm">
                                Contraseña
                                <span v-if="editing" class="text-slate-400">(déjala vacía para no cambiarla)</span>
                            </label>
                            <div class="flex gap-2">
                                <div class="relative flex-1">
                                    <Lucide icon="KeyRound" :class="iconInput" />
                                    <FormInput v-model="form.password" :type="showPassword ? 'text' : 'password'" class="pl-9 pr-10" placeholder="Mínimo 8 caracteres" autocomplete="new-password" />
                                    <button type="button" class="absolute inset-y-0 right-0 z-10 my-auto mr-3 text-slate-400 hover:text-slate-600" @click="showPassword = !showPassword">
                                        <Lucide :icon="showPassword ? 'EyeOff' : 'Eye'" class="h-4 w-4" />
                                    </button>
                                </div>
                                <Button type="button" variant="outline-secondary" class="rounded-[0.5rem] whitespace-nowrap bg-white" @click="generatePassword">
                                    <Lucide icon="Sparkles" class="mr-1.5 h-4 w-4" /> Generar
                                </Button>
                            </div>
                            <FormHelp v-if="errors.password" class="text-danger">{{ errors.password }}</FormHelp>
                        </div>

                        <!-- Rol -->
                        <div>
                            <label class="mb-2 block text-sm">Rol (qué puede hacer)</label>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label
                                    v-for="r in roles"
                                    :key="r.name"
                                    class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition"
                                    :class="form.role === r.name ? 'border-primary/40 bg-primary/5' : 'border-slate-200/70 hover:bg-slate-50 dark:border-darkmode-400'"
                                >
                                    <input v-model="form.role" type="radio" :value="r.name" class="mt-1 h-4 w-4 border-slate-300 text-primary focus:ring-primary/30" />
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-1.5 text-sm font-medium">
                                            {{ r.label }}
                                            <span class="rounded-full px-1.5 py-0.5 text-[10px]" :class="roleBadge[r.name] ?? 'bg-slate-100 text-slate-500'">{{ r.name }}</span>
                                        </span>
                                        <span class="mt-0.5 block text-xs text-slate-500">{{ r.description }}</span>
                                    </span>
                                </label>
                            </div>
                            <FormHelp v-if="errors.role" class="text-danger">{{ errors.role }}</FormHelp>
                        </div>

                        <p v-if="generalError" class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ generalError }}</p>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <Button type="button" variant="outline-secondary" @click="showForm = false">Cancelar</Button>
                        <Button type="submit" variant="primary" class="shadow-md shadow-primary/20" :disabled="saving">
                            <Lucide icon="Check" class="mr-2 h-4 w-4" /> {{ saving ? 'Guardando…' : editing ? 'Guardar cambios' : 'Crear usuario' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal eliminar -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div v-if="deleting" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger">
                            <Lucide icon="Trash2" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">¿Eliminar a {{ deleting.name }}?</h2>
                            <p class="mt-0.5 text-sm text-slate-500">Perderá el acceso al sistema de inmediato.</p>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700">
                        <Lucide icon="Info" class="h-4 w-4 shrink-0" /> Si tiene ventas, turnos o cortes registrados no podrá eliminarse (se conserva por auditoría).
                    </div>
                    <p v-if="deleteError" class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ deleteError }}</p>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button variant="outline-secondary" @click="deleting = null">Cancelar</Button>
                        <Button variant="danger" :disabled="saving" @click="submitDelete">
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" /> {{ saving ? 'Eliminando…' : 'Sí, eliminar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
