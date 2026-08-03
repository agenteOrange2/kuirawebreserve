<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormLabel,
    FormSwitch,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface AdminUser {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    is_admin: boolean;
    two_factor: boolean;
    created_at: string | null;
}

const props = defineProps<{ users: AdminUser[] }>();

const page = usePage();
const currentUserId = computed(
    () => (page.props.auth as { user: { id: number } }).user.id,
);

// Copia local: el CRUD actualiza en cliente sin recargar la página.
const users = ref<AdminUser[]>([...props.users]);

const initialsOf = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('') || '?';

const cellClass =
    'box shadow-[5px_3px_5px_#00000005] first:border-l last:border-r first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] rounded-l-none rounded-r-none border-x-0 dark:bg-darkmode-600';

// ── KPIs ──
const stats = computed(() => ({
    admins: users.value.filter((u) => u.is_admin).length,
    two_factor: users.value.filter((u) => u.is_admin && u.two_factor).length,
    no_access: users.value.filter((u) => !u.is_admin).length,
}));

// ── Búsqueda (en cliente: el listado carga completo) ──
const search = ref('');

const filtered = computed(() =>
    users.value.filter((u) => {
        const q = search.value.trim().toLowerCase();
        if (!q) return true;
        return (
            u.name.toLowerCase().includes(q) ||
            u.email.toLowerCase().includes(q) ||
            (u.phone ?? '').toLowerCase().includes(q)
        );
    }),
);

// El switch de acceso se bloquea para uno mismo y para el último admin;
// el backend aplica los mismos resguardos.
const isSelf = (u: AdminUser | null) => u?.id === currentUserId.value;
const isLastAdmin = (u: AdminUser | null) =>
    (u?.is_admin ?? false) && stats.value.admins <= 1;

// ── Crear / editar ──
const userModal = ref(false);
const userEditing = ref<AdminUser | null>(null);
const userSaving = ref(false);
const userErrors = reactive<Record<string, string>>({});
const userForm = reactive({
    name: '',
    email: '',
    phone: '',
    password: '',
    is_admin: true,
});

const switchLocked = computed(
    () =>
        userEditing.value !== null &&
        (isSelf(userEditing.value) || isLastAdmin(userEditing.value)),
);

function openUserModal(u: AdminUser | null = null) {
    userEditing.value = u;
    userForm.name = u?.name ?? '';
    userForm.email = u?.email ?? '';
    userForm.phone = u?.phone ?? '';
    userForm.password = '';
    userForm.is_admin = u?.is_admin ?? true;
    Object.keys(userErrors).forEach((k) => delete userErrors[k]);
    userModal.value = true;
}

async function submitUser() {
    userSaving.value = true;
    Object.keys(userErrors).forEach((k) => delete userErrors[k]);
    try {
        if (userEditing.value) {
            const payload: Record<string, unknown> = {
                name: userForm.name,
                email: userForm.email,
                phone: userForm.phone || null,
                is_admin: userForm.is_admin,
            };
            if (userForm.password) payload.password = userForm.password;
            const { data } = await axios.patch<AdminUser>(
                route('admin.users.update', userEditing.value.id),
                payload,
            );
            users.value = users.value.map((u) => (u.id === data.id ? data : u));
        } else {
            const { data } = await axios.post<AdminUser>(
                route('admin.users.store'),
                {
                    name: userForm.name,
                    email: userForm.email,
                    phone: userForm.phone || null,
                    password: userForm.password,
                    is_admin: userForm.is_admin,
                },
            );
            users.value = [...users.value, data].sort((a, b) =>
                a.name.localeCompare(b.name),
            );
        }
        userModal.value = false;
    } catch (e: any) {
        const d = e.response?.data;
        if (d?.errors) {
            Object.entries(d.errors).forEach(
                ([k, msgs]) => (userErrors[k] = (msgs as string[])[0]),
            );
        } else {
            userErrors._ = d?.message ?? 'No se pudo guardar el usuario.';
        }
    } finally {
        userSaving.value = false;
    }
}

// ── Eliminar ──
const userDeleting = ref<AdminUser | null>(null);
const actionError = ref<string | null>(null);

async function deleteUser() {
    if (!userDeleting.value) return;
    userSaving.value = true;
    actionError.value = null;
    try {
        await axios.delete(route('admin.users.destroy', userDeleting.value.id));
        users.value = users.value.filter(
            (u) => u.id !== userDeleting.value!.id,
        );
        userDeleting.value = null;
    } catch (e: any) {
        actionError.value =
            e.response?.data?.message ?? 'No se pudo eliminar el usuario.';
        userDeleting.value = null;
    } finally {
        userSaving.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Usuarios">
        <!-- Encabezado -->
        <div
            class="mt-2 flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center"
        >
            <div class="text-base font-medium group-[.mode--light]:text-white">
                Usuarios del admin
            </div>
            <div class="flex flex-col gap-x-3 gap-y-2 sm:flex-row md:ml-auto">
                <Button
                    variant="primary"
                    class="shadow-md shadow-primary/20"
                    @click="openUserModal()"
                >
                    <Lucide icon="Plus" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    Nuevo usuario
                </Button>
            </div>
        </div>

        <div class="mt-3.5 grid grid-cols-12 gap-5">
            <!-- KPIs -->
            <div class="col-span-12 sm:col-span-6 xl:col-span-4">
                <div class="box box--stacked h-full p-5">
                    <div class="flex items-center">
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide
                                icon="ShieldCheck"
                                class="h-6 w-6 fill-primary/10 text-primary"
                            />
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl font-medium">
                                {{ stats.admins }}
                            </div>
                            <div class="mt-0.5 text-xs text-slate-500">
                                Administradores
                            </div>
                        </div>
                    </div>
                    <div
                        class="mt-4 border-t border-dashed border-slate-300/70 pt-3 text-xs text-slate-500"
                    >
                        Entran a este panel y a cualquier hotel con "Entrar
                        como".
                    </div>
                </div>
            </div>
            <div class="col-span-12 sm:col-span-6 xl:col-span-4">
                <div class="box box--stacked h-full p-5">
                    <div class="flex items-center">
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-full border border-success/10 bg-success/10"
                        >
                            <Lucide
                                icon="KeyRound"
                                class="h-6 w-6 fill-success/10 text-success"
                            />
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl font-medium">
                                {{ stats.two_factor }} / {{ stats.admins }}
                            </div>
                            <div class="mt-0.5 text-xs text-slate-500">
                                Con doble factor
                            </div>
                        </div>
                    </div>
                    <div
                        class="mt-4 border-t border-dashed border-slate-300/70 pt-3 text-xs text-slate-500"
                    >
                        Cada quien lo activa desde Configuración; recomendado
                        para todos.
                    </div>
                </div>
            </div>
            <div class="col-span-12 sm:col-span-6 xl:col-span-4">
                <div class="box box--stacked h-full p-5">
                    <div class="flex items-center">
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-full border border-warning/10 bg-warning/10"
                        >
                            <Lucide
                                icon="UserX"
                                class="h-6 w-6 fill-warning/10 text-warning"
                            />
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl font-medium">
                                {{ stats.no_access }}
                            </div>
                            <div class="mt-0.5 text-xs text-slate-500">
                                Sin acceso
                            </div>
                        </div>
                    </div>
                    <div
                        class="mt-4 border-t border-dashed border-slate-300/70 pt-3 text-xs text-slate-500"
                    >
                        Cuentas centrales sin rol de administrador: no pueden
                        entrar al panel.
                    </div>
                </div>
            </div>

            <!-- Listado -->
            <div class="col-span-12">
                <div
                    v-if="actionError"
                    class="mb-1 flex items-center rounded-md border border-danger/20 bg-danger/5 px-4 py-3 text-sm text-danger"
                >
                    <Lucide
                        icon="TriangleAlert"
                        class="mr-2 h-4 w-4 shrink-0"
                    />
                    {{ actionError }}
                </div>

                <!-- Búsqueda -->
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <div class="relative lg:w-72">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                        />
                        <FormInput
                            v-model="search"
                            type="text"
                            class="pl-9"
                            placeholder="Buscar nombre, correo o teléfono…"
                        />
                    </div>
                </div>

                <!-- Tabla card-row -->
                <div class="mt-2 overflow-auto lg:overflow-visible">
                    <table
                        v-if="filtered.length"
                        class="w-full min-w-[800px] border-separate border-spacing-y-[8px] text-sm"
                    >
                        <thead>
                            <tr>
                                <th
                                    class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500"
                                >
                                    Usuario
                                </th>
                                <th
                                    class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500"
                                >
                                    Teléfono
                                </th>
                                <th
                                    class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500"
                                >
                                    Acceso
                                </th>
                                <th
                                    class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500"
                                >
                                    Doble factor
                                </th>
                                <th
                                    class="border-b-0 px-5 pb-1 text-left text-xs font-medium text-slate-500"
                                >
                                    Alta
                                </th>
                                <th
                                    class="border-b-0 px-5 pb-1 text-right text-xs font-medium text-slate-500"
                                >
                                    Acciones
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="u in filtered" :key="u.id">
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-xs font-semibold text-white"
                                        >
                                            {{ initialsOf(u.name) }}
                                        </div>
                                        <div class="min-w-0">
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <span
                                                    class="truncate font-medium"
                                                    >{{ u.name }}</span
                                                >
                                                <span
                                                    v-if="isSelf(u)"
                                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary"
                                                    >Tú</span
                                                >
                                            </div>
                                            <div
                                                class="truncate text-xs text-slate-500"
                                            >
                                                {{ u.email }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td
                                    :class="cellClass"
                                    class="px-5 py-3.5 text-xs text-slate-500"
                                >
                                    {{ u.phone ?? '—' }}
                                </td>
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <span
                                        class="flex w-fit items-center gap-1.5 rounded-full px-2 py-0.5 text-xs"
                                        :class="
                                            u.is_admin
                                                ? 'bg-primary/10 text-primary'
                                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                        "
                                    >
                                        <Lucide
                                            :icon="
                                                u.is_admin
                                                    ? 'ShieldCheck'
                                                    : 'UserX'
                                            "
                                            class="h-3 w-3"
                                        />
                                        {{
                                            u.is_admin
                                                ? 'Administrador'
                                                : 'Sin acceso'
                                        }}
                                    </span>
                                </td>
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <span
                                        class="flex w-fit items-center gap-1.5 rounded-full px-2 py-0.5 text-xs"
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
                                        {{
                                            u.two_factor
                                                ? 'Activo'
                                                : 'Sin activar'
                                        }}
                                    </span>
                                </td>
                                <td
                                    :class="cellClass"
                                    class="px-5 py-3.5 text-xs text-slate-500"
                                >
                                    {{ u.created_at ?? '—' }}
                                </td>
                                <td :class="cellClass" class="px-5 py-3.5">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        <button
                                            type="button"
                                            title="Editar acceso"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                            @click="openUserModal(u)"
                                        >
                                            <Lucide
                                                icon="Pencil"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                        <button
                                            v-if="!isSelf(u)"
                                            type="button"
                                            title="Eliminar"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                            @click="userDeleting = u"
                                        >
                                            <Lucide
                                                icon="Trash2"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div
                        v-else
                        class="box box--stacked flex flex-col items-center gap-3 py-14 text-center"
                    >
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="Users" class="h-6 w-6" />
                        </div>
                        <p class="max-w-md px-6 text-sm text-slate-500">
                            Ningún usuario coincide con la búsqueda.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: crear / editar usuario -->
        <Dialog :open="userModal" @close="userModal = false">
            <Dialog.Panel>
                <form class="p-5" @submit.prevent="submitUser">
                    <div class="mb-4 flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide
                                :icon="userEditing ? 'UserCog' : 'UserPlus'"
                                class="h-5 w-5 text-primary"
                            />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                {{
                                    userEditing
                                        ? 'Editar acceso'
                                        : 'Nuevo usuario'
                                }}
                            </h2>
                            <p class="text-xs text-slate-500">
                                Panel de plataforma (admin general)
                            </p>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <FormLabel htmlFor="user-name">Nombre</FormLabel>
                            <FormInput
                                id="user-name"
                                v-model="userForm.name"
                                type="text"
                                placeholder="Ana López"
                            />
                            <FormHelp
                                v-if="userErrors.name"
                                class="text-danger"
                                >{{ userErrors.name }}</FormHelp
                            >
                        </div>
                        <div>
                            <FormLabel htmlFor="user-email"
                                >Correo (usuario de acceso)</FormLabel
                            >
                            <FormInput
                                id="user-email"
                                v-model="userForm.email"
                                type="email"
                                placeholder="ana@kuiraweb.com"
                            />
                            <FormHelp
                                v-if="userErrors.email"
                                class="text-danger"
                                >{{ userErrors.email }}</FormHelp
                            >
                        </div>
                        <div>
                            <FormLabel htmlFor="user-phone"
                                >Teléfono (opcional)</FormLabel
                            >
                            <FormInput
                                id="user-phone"
                                v-model="userForm.phone"
                                type="text"
                                placeholder="614 123 4567"
                            />
                            <FormHelp
                                v-if="userErrors.phone"
                                class="text-danger"
                                >{{ userErrors.phone }}</FormHelp
                            >
                        </div>
                        <div>
                            <FormLabel htmlFor="user-password">
                                Contraseña
                                <span v-if="userEditing" class="text-slate-400"
                                    >(vacío = conservar la actual)</span
                                >
                            </FormLabel>
                            <FormInput
                                id="user-password"
                                v-model="userForm.password"
                                type="password"
                                :placeholder="
                                    userEditing
                                        ? '••••••••'
                                        : 'Mínimo 8 caracteres'
                                "
                                autocomplete="new-password"
                            />
                            <FormHelp
                                v-if="userErrors.password"
                                class="text-danger"
                                >{{ userErrors.password }}</FormHelp
                            >
                        </div>
                        <div
                            class="flex items-start justify-between gap-4 rounded-lg border border-slate-200/80 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div>
                                <div class="text-sm font-medium">
                                    Administrador de plataforma
                                </div>
                                <div class="mt-0.5 text-xs text-slate-500">
                                    {{
                                        switchLocked
                                            ? isSelf(userEditing)
                                                ? 'No puedes quitarte el acceso a ti mismo.'
                                                : 'Es el único administrador; da acceso a otro antes.'
                                            : 'Con acceso a este panel y a los hoteles con "Entrar como".'
                                    }}
                                </div>
                            </div>
                            <FormSwitch class="shrink-0">
                                <FormSwitch.Input
                                    :checked="userForm.is_admin"
                                    :disabled="switchLocked"
                                    type="checkbox"
                                    @change="
                                        userForm.is_admin = !userForm.is_admin
                                    "
                                />
                            </FormSwitch>
                        </div>
                        <p
                            v-if="userErrors._"
                            class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                        >
                            {{ userErrors._ }}
                        </p>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="userModal = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            :disabled="userSaving"
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{
                                userSaving
                                    ? 'Guardando…'
                                    : userEditing
                                      ? 'Guardar cambios'
                                      : 'Crear usuario'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal: eliminar usuario -->
        <Dialog :open="userDeleting !== null" @close="userDeleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide
                        icon="TriangleAlert"
                        class="mx-auto mb-3 h-12 w-12 text-danger"
                    />
                    <h2 class="text-base font-medium">
                        ¿Eliminar a {{ userDeleting?.name }}?
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Perderá el acceso al panel de plataforma. Esta acción no
                        se puede deshacer; si solo quieres cortar el acceso,
                        apaga su interruptor de administrador.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="userDeleting = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="userSaving"
                            @click="deleteUser"
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
