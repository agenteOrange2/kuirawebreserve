<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, onMounted, ref, useTemplateRef } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import { modeOption, tenantUrl } from './modes';
import TenantEditModal from './TenantEditModal.vue';
import TenantSuspendDialog from './TenantSuspendDialog.vue';
import type { PlanOption, TenantShell } from './types';
import { useImpersonate } from './useImpersonate';

const props = defineProps<{
    tenant: TenantShell;
    plans: PlanOption[];
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

const { impersonating, impersonateError, impersonate } = useImpersonate();

const editing = ref(false);
const suspending = ref(false);

const mode = computed(() => modeOption(props.tenant.mode));

// La pestaña abierta se trae a la vista: en celular la barra se
// desplaza y "Cobros" (la última) quedaba fuera de cuadro.
const nav = useTemplateRef<HTMLElement>('nav');

onMounted(() => {
    nav.value
        ?.querySelector('[data-activa]')
        ?.scrollIntoView({ block: 'nearest', inline: 'center' });
});

const tabClass = (key: string) =>
    key === props.active
        ? 'border-primary text-primary font-medium'
        : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300';
</script>

<template>
    <div class="box box--stacked mt-2 overflow-hidden">
        <!-- Identidad y acciones: viven en todas las sub-vistas, para no
             perder de vista de qué hotel se está hablando -->
        <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-center">
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border"
                    :class="
                        tenant.suspended
                            ? 'border-danger/10 bg-danger/10 text-danger'
                            : 'border-primary/10 bg-primary/10 text-primary'
                    "
                >
                    <Lucide :icon="mode.icon" class="h-5 w-5" />
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <h1 class="mr-1 min-w-0 truncate text-base font-medium">
                            {{ tenant.name }}
                        </h1>
                        <span
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium"
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
                        <Link
                            :href="route('admin.tenants.plan', tenant.id)"
                            class="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary hover:bg-primary/20"
                            title="Ver plan y facturación"
                            >{{ tenant.plan_label }}</Link
                        >
                        <span
                            class="inline-flex shrink-0 items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                            title="Modo de operación"
                        >
                            <Lucide :icon="mode.icon" class="h-3 w-3" />
                            {{ mode.label }}
                        </span>
                    </div>
                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                        <a
                            v-if="tenant.domain"
                            :href="tenantUrl(tenant.domain)"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex max-w-full min-w-0 items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 transition hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                        >
                            <Lucide icon="Globe" class="h-3 w-3 shrink-0" />
                            <span class="truncate">{{ tenant.domain }}</span>
                            <Lucide
                                icon="ExternalLink"
                                class="h-3 w-3 shrink-0"
                            />
                        </a>
                        <span
                            v-if="tenant.created_at"
                            class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs whitespace-nowrap text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                        >
                            <Lucide icon="CalendarDays" class="h-3 w-3" />
                            Cliente desde {{ tenant.created_at }}
                        </span>
                    </div>
                </div>
            </div>
            <div
                class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center"
            >
                <Link
                    :href="route('admin.tenants.index')"
                    class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                >
                    <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                    Volver a hoteles
                </Link>
                <Button
                    variant="outline-secondary"
                    class="h-9 rounded-[0.5rem] text-xs"
                    @click="editing = true"
                >
                    <Lucide icon="Pencil" class="mr-1.5 h-3.5 w-3.5" />
                    Editar
                </Button>
                <Button
                    variant="outline-secondary"
                    class="h-9 rounded-[0.5rem] text-xs"
                    :class="
                        tenant.suspended
                            ? 'col-span-2 !text-success sm:col-span-1'
                            : '!text-warning'
                    "
                    @click="suspending = true"
                >
                    <Lucide
                        :icon="tenant.suspended ? 'Play' : 'Pause'"
                        class="mr-1.5 h-3.5 w-3.5"
                    />
                    {{ tenant.suspended ? 'Reactivar' : 'Suspender' }}
                </Button>
                <Button
                    v-if="!tenant.suspended"
                    variant="primary"
                    class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                    :disabled="impersonating !== null"
                    title="Abre su panel como el dueño (acceso de soporte, un solo uso)"
                    @click="impersonate(tenant.id)"
                >
                    <Lucide icon="LogIn" class="mr-1.5 h-3.5 w-3.5" />
                    {{ impersonating ? 'Abriendo...' : 'Entrar como' }}
                </Button>
            </div>
        </div>

        <!-- Avisos que cambian cómo se atiende al hotel -->
        <div
            v-if="tenant.suspended"
            class="flex items-start gap-2 border-t border-danger/15 bg-danger/5 px-5 py-3 text-xs text-danger"
        >
            <Lucide icon="CirclePause" class="mt-px h-3.5 w-3.5 shrink-0" />
            <span>
                Suspendido<template v-if="tenant.suspended_since">
                    desde el {{ tenant.suspended_since }}</template
                >: su equipo ve la pantalla de hotel suspendido. "Entrar como"
                no está disponible hasta reactivarlo.
            </span>
        </div>
        <div
            v-if="tenant.module_requests"
            class="flex flex-wrap items-center gap-2 border-t border-pending/15 bg-pending/5 px-5 py-3 text-xs text-pending"
        >
            <Lucide icon="BellRing" class="h-3.5 w-3.5 shrink-0" />
            <span>
                {{ tenant.module_requests }}
                {{
                    tenant.module_requests === 1
                        ? 'solicitud de módulo espera'
                        : 'solicitudes de módulo esperan'
                }}
                respuesta.
            </span>
            <Link
                v-if="active !== 'modules'"
                :href="route('admin.tenants.modules', tenant.id)"
                class="font-medium underline-offset-2 hover:underline sm:ml-auto"
                >Atenderlas</Link
            >
        </div>
        <div
            v-if="impersonateError"
            class="flex items-start gap-2 border-t border-danger/15 bg-danger/5 px-5 py-3 text-xs text-danger"
        >
            <Lucide icon="TriangleAlert" class="mt-px h-3.5 w-3.5 shrink-0" />
            {{ impersonateError }}
        </div>

        <!-- Pestañas: cada área con su URL propia -->
        <div
            class="overflow-x-auto border-t border-slate-200/60 bg-slate-50/70 dark:border-darkmode-400 dark:bg-darkmode-600/40"
        >
            <nav ref="nav" class="flex min-w-max gap-1 px-3">
                <Link
                    v-for="tab in tabs"
                    :key="tab.key"
                    :href="route(tab.routeName, tenant.id)"
                    :data-activa="tab.key === active ? '' : null"
                    class="flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-xs whitespace-nowrap transition"
                    :class="tabClass(tab.key)"
                >
                    <Lucide :icon="tab.icon" class="h-3.5 w-3.5" />
                    {{ tab.label }}
                    <span
                        v-if="tab.key === 'modules' && tenant.module_requests"
                        class="flex h-4 min-w-4 items-center justify-center rounded-full bg-pending px-1 text-[10px] font-medium text-white"
                        >{{ tenant.module_requests }}</span
                    >
                </Link>
            </nav>
        </div>

        <TenantEditModal
            :tenant="editing ? tenant : null"
            :plans="plans"
            @close="editing = false"
        />
        <TenantSuspendDialog
            :tenant="suspending ? tenant : null"
            @close="suspending = false"
        />
    </div>
</template>
