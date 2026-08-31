<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref, useTemplateRef } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormLabel,
    FormSelect,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import type { TenantShell } from './types';

const props = defineProps<{
    tenant: TenantShell;
    plans: { value: string; label: string }[];
    // Área abierta: el mismo componente pinta la cabecera y sabe qué
    // pestaña marcar, para que la identidad del hotel y sus acciones no
    // se pierdan al cambiar de sub-vista.
    active:
        | 'overview'
        | 'plan'
        | 'modules'
        | 'team'
        | 'assistant'
        | 'channels'
        | 'payments';
}>();

const tabs: Array<{
    key: typeof props.active;
    label: string;
    icon: Icon;
    routeName: string;
}> = [
    {
        key: 'overview',
        label: 'Resumen',
        icon: 'LayoutDashboard',
        routeName: 'admin.tenants.show',
    },
    {
        key: 'plan',
        label: 'Plan y facturación',
        icon: 'Layers',
        routeName: 'admin.tenants.plan',
    },
    {
        key: 'modules',
        label: 'Módulos',
        icon: 'ToggleRight',
        routeName: 'admin.tenants.modules',
    },
    {
        key: 'team',
        label: 'Equipo',
        icon: 'UserCog',
        routeName: 'admin.tenants.team',
    },
    {
        key: 'assistant',
        label: 'Asistente IA',
        icon: 'Bot',
        routeName: 'admin.tenants.assistant',
    },
    {
        key: 'channels',
        label: 'Canales',
        icon: 'Share2',
        routeName: 'admin.tenants.channels',
    },
    {
        key: 'payments',
        label: 'Cobros',
        icon: 'CreditCard',
        routeName: 'admin.tenants.payments',
    },
];

const actionError = ref<string | null>(null);
const impersonating = ref(false);

async function impersonate() {
    impersonating.value = true;
    actionError.value = null;
    // Abrir la pestaña ANTES del await: tras una respuesta asíncrona el
    // navegador ya no lo trata como gesto del usuario y bloquea el popup
    // (y el token de impersonación solo vive 60 s, no admite copiar/pegar).
    const win = window.open('', '_blank');
    try {
        const { data } = await axios.post<{ url: string }>(
            route('admin.tenants.impersonate', props.tenant.id),
        );
        if (win) {
            win.location.href = data.url;
        } else {
            window.location.href = data.url;
        }
    } catch (e: any) {
        win?.close();
        actionError.value =
            e?.response?.data?.message ?? 'No se pudo generar el acceso.';
    } finally {
        impersonating.value = false;
    }
}

function toggleSuspend() {
    router.patch(
        route('admin.tenants.suspend', props.tenant.id),
        {},
        { preserveScroll: true },
    );
}

const showEdit = ref(false);
const editForm = useForm({ name: props.tenant.name, plan: props.tenant.plan });

function openEdit() {
    editForm.name = props.tenant.name;
    editForm.plan = props.tenant.plan;
    showEdit.value = true;
}

function submitEdit() {
    editForm.put(route('admin.tenants.update', props.tenant.id), {
        onSuccess: () => (showEdit.value = false),
    });
}

// La pestaña abierta se trae a la vista: en celular la barra se
// desplaza y "Cobros" (la última) quedaba fuera de cuadro.
const nav = useTemplateRef<HTMLElement>('nav');

onMounted(() => {
    nav.value
        ?.querySelector('[data-activa]')
        ?.scrollIntoView({ block: 'nearest', inline: 'center' });
});

const tabClass = computed(
    () => (key: string) =>
        key === props.active
            ? 'border-primary text-primary'
            : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300',
);
</script>

<template>
    <div class="box box--stacked mt-2">
        <!-- Identidad y acciones: viven en todas las sub-vistas, para no
             perder de vista de qué hotel se está hablando -->
        <div
            class="flex flex-col gap-y-3 p-5 md:flex-row md:items-center md:gap-4"
        >
            <div class="flex min-w-0 items-center gap-3.5">
                <div
                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                >
                    <Lucide
                        icon="Building2"
                        class="h-6 w-6 fill-primary/10 text-primary"
                    />
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="min-w-0 text-lg font-medium">
                            <span class="block truncate">{{
                                tenant.name
                            }}</span>
                        </h1>
                        <span
                            class="flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-xs"
                            :class="
                                tenant.suspended
                                    ? 'bg-danger/10 text-danger'
                                    : 'bg-success/10 text-success'
                            "
                        >
                            <span
                                class="h-1.5 w-1.5 rounded-full"
                                :class="
                                    tenant.suspended
                                        ? 'bg-danger'
                                        : 'bg-success'
                                "
                            />
                            {{ tenant.suspended ? 'Suspendido' : 'Activo' }}
                        </span>
                        <span
                            class="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary"
                            >{{ tenant.plan_label }}</span
                        >
                    </div>
                    <div
                        class="mt-0.5 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-sm text-slate-500"
                    >
                        <a
                            v-if="tenant.domain"
                            :href="`http://${tenant.domain}`"
                            target="_blank"
                            class="flex min-w-0 items-center gap-1 text-primary hover:underline"
                        >
                            <span class="truncate">{{ tenant.domain }}</span>
                            <Lucide
                                icon="ExternalLink"
                                class="h-3 w-3 shrink-0"
                            />
                        </a>
                        <span
                            v-if="tenant.created_at"
                            class="whitespace-nowrap"
                        >
                            Cliente desde {{ tenant.created_at }}
                        </span>
                    </div>
                </div>
            </div>
            <div
                class="grid grid-cols-2 gap-2 md:ml-auto md:flex md:flex-wrap md:items-center"
            >
                <Button
                    :as="Link"
                    :href="route('admin.tenants.index')"
                    variant="outline-secondary"
                    class="min-h-10 justify-center rounded-[0.5rem] bg-white/80 dark:bg-darkmode-400/80"
                >
                    <Lucide
                        icon="ArrowLeft"
                        class="mr-2 h-4 w-4 stroke-[1.3]"
                    />
                    Hoteles
                </Button>
                <Button
                    variant="outline-secondary"
                    class="min-h-10 justify-center rounded-[0.5rem] bg-white/80 dark:bg-darkmode-400/80"
                    @click="openEdit"
                >
                    <Lucide icon="Pencil" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    Editar
                </Button>
                <Button
                    variant="outline-secondary"
                    class="min-h-10 justify-center rounded-[0.5rem] bg-white/80 dark:bg-darkmode-400/80"
                    :class="
                        tenant.suspended
                            ? 'col-span-2 !text-success md:col-span-1'
                            : '!text-warning'
                    "
                    @click="toggleSuspend"
                >
                    <Lucide
                        :icon="tenant.suspended ? 'Play' : 'Pause'"
                        class="mr-2 h-4 w-4 stroke-[1.3]"
                    />
                    {{ tenant.suspended ? 'Reactivar' : 'Suspender' }}
                </Button>
                <Button
                    v-if="!tenant.suspended"
                    variant="primary"
                    class="min-h-10 justify-center rounded-[0.5rem] shadow-md shadow-primary/20"
                    :disabled="impersonating"
                    @click="impersonate"
                >
                    <Lucide icon="LogIn" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    {{ impersonating ? 'Abriendo…' : 'Entrar como' }}
                </Button>
            </div>
        </div>

        <div
            v-if="actionError"
            class="flex items-center border-t border-danger/20 bg-danger/5 px-5 py-3 text-sm text-danger"
        >
            <Lucide icon="TriangleAlert" class="mr-2 h-4 w-4 shrink-0" />
            {{ actionError }}
        </div>

        <!-- Pestañas: cada área con su URL propia -->
        <div
            class="overflow-x-auto border-t border-slate-200/70 dark:border-darkmode-400"
        >
            <nav ref="nav" class="flex min-w-max gap-1 px-3">
                <Link
                    v-for="tab in tabs"
                    :key="tab.key"
                    :href="route(tab.routeName, tenant.id)"
                    :data-activa="tab.key === active ? '' : null"
                    class="flex items-center gap-2 border-b-2 px-3.5 py-3 text-sm whitespace-nowrap transition"
                    :class="tabClass(tab.key)"
                >
                    <Lucide :icon="tab.icon" class="h-4 w-4 stroke-[1.3]" />
                    {{ tab.label }}
                </Link>
            </nav>
        </div>

        <!-- Modal editar -->
        <Dialog :open="showEdit" @close="showEdit = false">
            <Dialog.Panel>
                <div class="p-5">
                    <h2 class="mb-4 text-base font-medium">Editar hotel</h2>
                    <form class="space-y-4" @submit.prevent="submitEdit">
                        <div>
                            <FormLabel htmlFor="edit-name">Nombre</FormLabel>
                            <FormInput
                                id="edit-name"
                                v-model="editForm.name"
                                type="text"
                            />
                            <FormHelp
                                v-if="editForm.errors.name"
                                class="text-danger"
                                >{{ editForm.errors.name }}</FormHelp
                            >
                        </div>
                        <div>
                            <FormLabel htmlFor="edit-plan">Plan</FormLabel>
                            <FormSelect id="edit-plan" v-model="editForm.plan">
                                <option
                                    v-for="p in plans"
                                    :key="p.value"
                                    :value="p.value"
                                >
                                    {{ p.label }}
                                </option>
                            </FormSelect>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <Button
                                type="button"
                                variant="outline-secondary"
                                @click="showEdit = false"
                                >Cancelar</Button
                            >
                            <Button
                                type="submit"
                                variant="primary"
                                :disabled="editForm.processing"
                                >Guardar</Button
                            >
                        </div>
                    </form>
                </div>
            </Dialog.Panel>
        </Dialog>
    </div>
</template>
