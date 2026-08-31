<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button/Button.vue';
import { FormInput, FormLabel } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import SettingsNav from '@/components/SettingsNav.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';
import { useSettingsRoutes } from '@/composables/useSettingsRoutes';
import { useToasts } from '@/composables/useToasts';

type Props = {
    mustVerifyEmail: boolean;
    status?: string;
    profile: {
        phone: string | null;
        roles: string[];
        member_since: string | null;
        two_factor_enabled: boolean;
        workspace: string;
    };
};

const props = defineProps<Props>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const { routes, isTenantPanel } = useSettingsRoutes();
const toast = useToasts();

// En el hotel esta pantalla es "Mi perfil"; en plataforma es la
// configuración de la cuenta del super-admin.
const pageTitle = computed(() =>
    isTenantPanel.value ? 'Mi perfil' : 'Configuración',
);

const profileForm = useForm({
    name: user.value.name,
    email: user.value.email,
});

const submitProfile = () => {
    profileForm.patch(route(routes.value.profileUpdate));
};

// Foto de perfil: se sube aparte del formulario (no es un campo del modelo,
// es un archivo) y se refleja al momento en el menú del header.
const avatarUrl = ref<string | null>(
    (page.props.auth as { avatar_url?: string | null }).avatar_url ?? null,
);
const avatarInput = ref<HTMLInputElement | null>(null);
const avatarBusy = ref(false);
const avatarEndpoint = computed(() =>
    isTenantPanel.value ? '/api/avatar' : '/avatar',
);

const initials = computed(() => {
    const name = (user.value?.name ?? '').trim();
    if (!name) return '?';
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((part: string) => part.charAt(0).toUpperCase())
        .join('');
});

async function onAvatarSelected(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file || avatarBusy.value) return;
    avatarBusy.value = true;
    try {
        const data = new FormData();
        data.append('avatar', file);
        const res = await axios.post<{ avatar_url: string }>(
            avatarEndpoint.value,
            data,
        );
        avatarUrl.value = res.data.avatar_url;
        toast.success('Foto actualizada', 'Ya aparece en tu menú del panel.');
        router.reload({ only: ['auth'] });
    } catch (error: any) {
        toast.error(
            'No se pudo subir',
            error.response?.data?.message ?? 'Intenta con otra imagen.',
        );
    } finally {
        avatarBusy.value = false;
        if (avatarInput.value) avatarInput.value.value = '';
    }
}

async function removeAvatar() {
    if (avatarBusy.value) return;
    avatarBusy.value = true;
    try {
        await axios.delete(avatarEndpoint.value);
        avatarUrl.value = null;
        toast.success('Foto quitada', 'Vuelven tus iniciales.');
        router.reload({ only: ['auth'] });
    } finally {
        avatarBusy.value = false;
    }
}

const deleteForm = useForm({ password: '' });
const showDeleteModal = ref(false);

const closeDelete = () => {
    showDeleteModal.value = false;
    deleteForm.reset();
};

const submitDelete = () => {
    deleteForm.delete(route(routes.value.profileDestroy), {
        onSuccess: () => {
            showDeleteModal.value = false;
        },
        onError: () => deleteForm.reset('password'),
    });
};

const roleLabels: Record<string, string> = {
    owner: 'Dueño',
    manager: 'Gerencia',
    'front-desk': 'Recepción',
    housekeeping: 'Limpieza',
    maintenance: 'Mantenimiento',
    pos: 'Punto de venta',
    'platform-admin': 'Administrador de plataforma',
};
const roleName = (role: string) => roleLabels[role] ?? role;

const twoFactorRoute = computed(() => route(routes.value.twoFactor));

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
</script>

<template>
    <Head :title="pageTitle" />

    <RazeLayout :title="pageTitle">
        <div class="mt-2">
            <!-- Ficha de la persona, en franjas: quién es, sus datos duros
                 y el estado de su acceso. Antes eran tres cajas apiladas que
                 repetían nombre y correo en cada una. -->
            <div class="box box--stacked overflow-hidden">
                <div
                    class="flex flex-col gap-4 p-5 md:flex-row md:items-start md:justify-between"
                >
                    <div class="flex min-w-0 gap-3.5">
                        <!-- La foto se cambia desde aquí mismo: pasar el cursor
                             encima descubre la cámara. -->
                        <button
                            type="button"
                            class="group relative flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-sm font-semibold text-white shadow-md"
                            :title="avatarUrl ? 'Cambiar foto' : 'Subir foto'"
                            :disabled="avatarBusy"
                            @click="avatarInput?.click()"
                        >
                            <img
                                v-if="avatarUrl"
                                :src="avatarUrl"
                                :alt="user?.name"
                                class="h-full w-full object-cover"
                            />
                            <template v-else>{{ initials }}</template>
                            <span
                                class="absolute inset-0 flex items-center justify-center bg-black/45 opacity-0 transition group-hover:opacity-100"
                            >
                                <Lucide
                                    :icon="
                                        avatarBusy ? 'LoaderCircle' : 'Camera'
                                    "
                                    :class="[
                                        'h-4 w-4 text-white',
                                        avatarBusy && 'animate-spin',
                                    ]"
                                />
                            </span>
                        </button>
                        <input
                            ref="avatarInput"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="hidden"
                            @change="onAvatarSelected"
                        />

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-base font-medium">
                                    {{ user?.name }}
                                </h1>
                                <span
                                    v-for="role in props.profile.roles"
                                    :key="role"
                                    class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-1 text-[11px] font-medium text-primary"
                                >
                                    <Lucide
                                        icon="BadgeCheck"
                                        class="h-3.5 w-3.5"
                                    />
                                    {{ roleName(role) }}
                                </span>
                            </div>
                            <div
                                class="mt-2 flex flex-wrap items-center gap-1.5"
                            >
                                <a
                                    :href="`mailto:${user?.email}`"
                                    class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 transition hover:bg-primary/10 hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                >
                                    <Lucide
                                        icon="Mail"
                                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                    />
                                    <span class="truncate">{{
                                        user?.email
                                    }}</span>
                                </a>
                                <a
                                    v-if="props.profile.phone"
                                    :href="`tel:${props.profile.phone}`"
                                    class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 transition hover:bg-primary/10 hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                >
                                    <Lucide
                                        icon="Phone"
                                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                    />
                                    <span class="truncate">{{
                                        props.profile.phone
                                    }}</span>
                                </a>
                                <span
                                    v-if="props.profile.member_since"
                                    class="text-xs text-slate-400"
                                >
                                    En el equipo desde
                                    {{ props.profile.member_since }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div
                        class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:shrink-0 md:items-center md:gap-2"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                            :disabled="avatarBusy"
                            @click="avatarInput?.click()"
                        >
                            <Lucide icon="Camera" class="mr-1.5 h-3.5 w-3.5" />
                            {{ avatarUrl ? 'Cambiar foto' : 'Subir foto' }}
                        </Button>
                        <Button
                            v-if="avatarUrl"
                            type="button"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                            :disabled="avatarBusy"
                            @click="removeAvatar"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            Quitar
                        </Button>
                    </div>
                </div>

                <!-- Datos duros: dónde trabaja y cómo entra. -->
                <div
                    class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-slate-200/60 bg-slate-50/70 px-5 py-3 text-xs dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500"
                    >
                        <Lucide
                            :icon="isTenantPanel ? 'Building2' : 'Layers'"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            {{ props.profile.workspace }}
                        </span>
                    </span>
                    <span
                        class="hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400"
                    />
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500"
                    >
                        <Lucide
                            icon="KeyRound"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        Entras con
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                        >
                            correo y contraseña
                        </span>
                    </span>
                    <Link
                        :href="twoFactorRoute"
                        :class="[
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium transition md:ml-auto',
                            props.profile.two_factor_enabled
                                ? 'bg-success/10 text-success hover:bg-success/20'
                                : 'bg-slate-100 text-slate-500 hover:bg-primary/10 hover:text-primary dark:bg-darkmode-400',
                        ]"
                        :title="
                            props.profile.two_factor_enabled
                                ? 'Ver la verificación en dos pasos'
                                : 'Activar la verificación en dos pasos'
                        "
                    >
                        <Lucide
                            :icon="
                                props.profile.two_factor_enabled
                                    ? 'ShieldCheck'
                                    : 'ShieldOff'
                            "
                            class="h-3.5 w-3.5"
                        />
                        {{
                            props.profile.two_factor_enabled
                                ? 'Dos pasos activada'
                                : 'Sin verificación en dos pasos'
                        }}
                    </Link>
                </div>
            </div>

            <div class="mt-4 flex flex-col gap-5 lg:flex-row">
                <SettingsNav />

                <div class="min-w-0 flex-1 space-y-4">
                    <!-- Datos -->
                    <div class="box box--stacked overflow-hidden">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-info/10 bg-info/10 text-info',
                                ]"
                            >
                                <Lucide icon="IdCard" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">Tus datos</div>
                                <div class="text-xs text-slate-500">
                                    Con este nombre te ve el resto del equipo.
                                </div>
                            </div>
                        </div>

                        <form class="px-4 py-3" @submit.prevent="submitProfile">
                            <div class="grid grid-cols-12 gap-4">
                                <div class="col-span-12 sm:col-span-6">
                                    <FormLabel for="name" class="text-xs">
                                        Nombre
                                    </FormLabel>
                                    <FormInput
                                        id="name"
                                        v-model="profileForm.name"
                                        type="text"
                                        required
                                        autocomplete="name"
                                        class="h-9 text-xs"
                                    />
                                    <div
                                        v-if="profileForm.errors.name"
                                        class="mt-1 text-[11px] text-danger"
                                    >
                                        {{ profileForm.errors.name }}
                                    </div>
                                </div>

                                <div class="col-span-12 sm:col-span-6">
                                    <FormLabel for="email" class="text-xs">
                                        Correo electrónico
                                    </FormLabel>
                                    <FormInput
                                        id="email"
                                        v-model="profileForm.email"
                                        type="email"
                                        required
                                        autocomplete="username"
                                        class="h-9 text-xs"
                                    />
                                    <div
                                        v-if="profileForm.errors.email"
                                        class="mt-1 text-[11px] text-danger"
                                    >
                                        {{ profileForm.errors.email }}
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="
                                    mustVerifyEmail && !user.email_verified_at
                                "
                                class="mt-3 rounded-lg bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:bg-darkmode-400/40"
                            >
                                Tu correo no está verificado.
                                <Link
                                    :href="route('verification.send')"
                                    method="post"
                                    as="button"
                                    class="text-primary"
                                >
                                    Reenviar verificación
                                </Link>
                                <span
                                    v-if="status === 'verification-link-sent'"
                                    class="ml-1 text-success"
                                >
                                    Te mandamos un enlace nuevo.
                                </span>
                            </div>

                            <div
                                class="mt-4 flex items-center justify-end gap-2.5 border-t border-slate-200/60 pt-3 dark:border-darkmode-400"
                            >
                                <Transition
                                    enter-active-class="transition ease-in-out"
                                    enter-from-class="opacity-0"
                                    leave-active-class="transition ease-in-out"
                                    leave-to-class="opacity-0"
                                >
                                    <span
                                        v-show="profileForm.recentlySuccessful"
                                        class="text-xs text-success"
                                    >
                                        Guardado.
                                    </span>
                                </Transition>
                                <Button
                                    variant="primary"
                                    type="submit"
                                    class="h-9 rounded-[0.5rem] px-5 text-xs"
                                    :disabled="profileForm.processing"
                                >
                                    <Lucide
                                        icon="Check"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    {{
                                        profileForm.processing
                                            ? 'Guardando…'
                                            : 'Guardar cambios'
                                    }}
                                </Button>
                            </div>
                        </form>
                    </div>

                    <!-- Eliminar cuenta: solo en el panel de plataforma. En un
                         hotel, quien da de baja a alguien del equipo es su
                         administrador desde Usuarios, no la persona misma. -->
                    <div
                        v-if="!isTenantPanel"
                        class="box box--stacked overflow-hidden"
                    >
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    'border-danger/10 bg-danger/10 text-danger',
                                ]"
                            >
                                <Lucide icon="TriangleAlert" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    Eliminar cuenta
                                </div>
                                <div class="text-xs text-slate-500">
                                    Se borra tu cuenta y sus datos. No se puede
                                    deshacer.
                                </div>
                            </div>
                            <Button
                                variant="danger"
                                class="h-8 rounded-[0.5rem] text-xs md:ml-auto"
                                @click="showDeleteModal = true"
                            >
                                Eliminar cuenta
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Dialog :open="showDeleteModal" @close="closeDelete">
            <Dialog.Panel class="sm:w-[94vw] lg:w-[520px]">
                <form
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                    @submit.prevent="submitDelete"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                        >
                            <Lucide icon="TriangleAlert" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-base font-medium">
                                Eliminar tu cuenta
                            </div>
                            <div class="text-xs text-slate-500">
                                Todos tus datos se borran para siempre.
                            </div>
                        </div>
                        <button
                            type="button"
                            class="ml-auto flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            title="Cerrar"
                            @click="closeDelete"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                        <FormLabel for="delete-password" class="text-xs">
                            Escribe tu contraseña para confirmar
                        </FormLabel>
                        <FormInput
                            id="delete-password"
                            v-model="deleteForm.password"
                            type="password"
                            class="h-9 text-xs"
                        />
                        <div
                            v-if="deleteForm.errors.password"
                            class="mt-1 text-[11px] text-danger"
                        >
                            {{ deleteForm.errors.password }}
                        </div>
                    </div>

                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            variant="secondary"
                            type="button"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            @click="closeDelete"
                        >
                            Cancelar
                        </Button>
                        <Button
                            variant="danger"
                            type="submit"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="deleteForm.processing"
                        >
                            Eliminar cuenta
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
