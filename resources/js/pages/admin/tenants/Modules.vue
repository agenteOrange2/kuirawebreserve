<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { FormInput, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import TenantHeader from './TenantHeader.vue';
import type { PlanOption, TenantShell } from './types';

interface ModuleRow {
    key: string;
    label: string;
    description: string;
    available: boolean;
    group_label: string | null;
    in_plan: boolean;
    in_addon: boolean;
    override: boolean | null;
    enabled: boolean;
    requested_at: string | null;
}

type Mode = 'inherit' | 'on' | 'off';

const props = defineProps<{
    tenant: TenantShell;
    plans: PlanOption[];
    modules: ModuleRow[];
}>();

const toast = useToasts();

const search = ref('');
const only = ref<'all' | 'on' | 'off' | 'forced' | 'requested'>(
    props.modules.some((m) => m.requested_at) ? 'requested' : 'all',
);
const group = ref('');

const counts = computed(() => ({
    enabled: props.modules.filter((m) => m.enabled).length,
    forced: props.modules.filter((m) => m.override !== null).length,
    requested: props.modules.filter((m) => m.requested_at).length,
}));

const groups = computed(
    () =>
        [
            ...new Set(props.modules.map((m) => m.group_label).filter(Boolean)),
        ] as string[],
);

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();

    return (
        props.modules
            .filter((mod) => {
                if (only.value === 'on' && !mod.enabled) return false;
                if (only.value === 'off' && mod.enabled) return false;
                if (only.value === 'forced' && mod.override === null)
                    return false;
                if (only.value === 'requested' && !mod.requested_at)
                    return false;
                if (group.value && mod.group_label !== group.value)
                    return false;
                if (term === '') return true;

                return (
                    mod.label.toLowerCase().includes(term) ||
                    mod.description.toLowerCase().includes(term) ||
                    (mod.group_label ?? '').toLowerCase().includes(term)
                );
            })
            // Lo que el hotel pidió va arriba: es lo único que espera respuesta.
            .sort(
                (a, b) =>
                    Number(Boolean(b.requested_at)) -
                    Number(Boolean(a.requested_at)),
            )
    );
});

const hasFilters = computed(
    () => !!search.value.trim() || only.value !== 'all' || !!group.value,
);
function clearFilters() {
    search.value = '';
    only.value = 'all';
    group.value = '';
}

const moduleMode = (mod: ModuleRow): Mode =>
    mod.override === null ? 'inherit' : mod.override ? 'on' : 'off';

// Lo que daría heredar, para que "Plan" diga en qué queda el módulo.
const inherited = (mod: ModuleRow) => mod.in_plan || mod.in_addon;

function originLabel(mod: ModuleRow): string {
    if (mod.override !== null) {
        const base = inherited(mod) ? 'lo trae' : 'no lo trae';
        return mod.override
            ? `Forzado encendido (su plan ${base})`
            : `Forzado apagado (su plan ${base})`;
    }
    if (mod.in_plan) return `Incluido en el plan ${props.tenant.plan_label}`;
    if (mod.in_addon) return 'Lo aporta un servicio adicional contratado';

    return `No viene en el plan ${props.tenant.plan_label}`;
}

const pendingKey = ref<string | null>(null);

function setModule(mod: ModuleRow, mode: Mode) {
    if (pendingKey.value || moduleMode(mod) === mode) return;
    pendingKey.value = mod.key;
    router.patch(
        route('admin.tenants.modules', props.tenant.id),
        { module: mod.key, mode },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    'Módulo actualizado',
                    mode === 'inherit'
                        ? `${mod.label}: sigue a su plan (${inherited(mod) ? 'encendido' : 'apagado'})`
                        : `${mod.label}: forzado ${mode === 'on' ? 'encendido' : 'apagado'}`,
                ),
            onError: () =>
                toast.error('No se pudo actualizar', 'Ocurrió un error.'),
            onFinish: () => (pendingKey.value = null),
        },
    );
}

function dismissRequest(mod: ModuleRow) {
    if (pendingKey.value) return;
    pendingKey.value = mod.key;
    router.delete(
        route('admin.tenants.module-requests.dismiss', [
            props.tenant.id,
            mod.key,
        ]),
        {
            preserveScroll: true,
            onSuccess: () => toast.success('Solicitud descartada', mod.label),
            onFinish: () => (pendingKey.value = null),
        },
    );
}

const modeButtons = (mod: ModuleRow) =>
    [
        {
            mode: 'inherit' as Mode,
            label: 'Plan',
            title: `Seguir al plan: hoy quedaría ${inherited(mod) ? 'encendido' : 'apagado'}`,
        },
        { mode: 'on' as Mode, label: 'Encendido', title: 'Forzar encendido' },
        { mode: 'off' as Mode, label: 'Apagado', title: 'Forzar apagado' },
    ] as const;
</script>

<template>
    <RazeLayout :title="`${tenant.name} · Módulos`">
        <TenantHeader :tenant="tenant" :plans="plans" active="modules" />

        <!-- Cifras -->
        <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
            <button
                v-for="kpi in [
                    {
                        key: 'on',
                        value: counts.enabled,
                        label: 'Encendidos',
                        icon: 'CircleCheck',
                        tone: 'border-success/10 bg-success/10 text-success',
                    },
                    {
                        key: 'off',
                        value: modules.length - counts.enabled,
                        label: 'Apagados',
                        icon: 'CircleOff',
                        tone: 'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400',
                    },
                    {
                        key: 'forced',
                        value: counts.forced,
                        label: 'Forzados a mano',
                        icon: 'Hand',
                        tone: 'border-info/10 bg-info/10 text-info',
                    },
                    {
                        key: 'requested',
                        value: counts.requested,
                        label: 'Solicitudes del hotel',
                        icon: 'BellRing',
                        tone: 'border-pending/10 bg-pending/10 text-pending',
                    },
                ] as const"
                :key="kpi.key"
                type="button"
                class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 text-left transition xl:col-span-3"
                :class="{ 'ring-1 ring-primary/30': only === kpi.key }"
                @click="only = only === kpi.key ? 'all' : kpi.key"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                    :class="kpi.tone"
                >
                    <Lucide :icon="kpi.icon" class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <div class="text-sm font-medium">{{ kpi.value }}</div>
                    <div class="text-xs leading-tight text-slate-500">
                        {{ kpi.label }}
                    </div>
                </div>
            </button>
        </div>

        <!-- Listado -->
        <div class="box box--stacked mt-4">
            <div
                class="flex flex-col gap-2 rounded-t-[0.6rem] border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 lg:flex-row lg:items-center dark:border-darkmode-400 dark:bg-darkmode-600/40"
            >
                <div class="relative lg:w-64">
                    <Lucide
                        icon="Search"
                        class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                    />
                    <FormInput
                        v-model="search"
                        type="text"
                        class="h-9 pl-9 text-xs"
                        placeholder="Buscar módulo"
                    />
                </div>
                <div class="grid grid-cols-2 gap-2 lg:flex">
                    <FormSelect v-model="only" class="h-9 text-xs lg:w-44">
                        <option value="all">Todos los estados</option>
                        <option value="on">Encendidos</option>
                        <option value="off">Apagados</option>
                        <option value="forced">Forzados a mano</option>
                        <option value="requested">Con solicitud</option>
                    </FormSelect>
                    <FormSelect v-model="group" class="h-9 text-xs lg:w-48">
                        <option value="">Todas las familias</option>
                        <option v-for="g in groups" :key="g" :value="g">
                            {{ g }}
                        </option>
                    </FormSelect>
                </div>
                <div class="flex items-center gap-3 text-xs lg:ml-auto">
                    <button
                        v-if="hasFilters"
                        type="button"
                        class="font-medium text-primary"
                        @click="clearFilters"
                    >
                        Quitar filtros
                    </button>
                    <span class="text-slate-500"
                        >{{ visible.length }} de {{ modules.length }}</span
                    >
                </div>
            </div>

            <div
                v-if="visible.length"
                class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
            >
                <div
                    v-for="mod in visible"
                    :key="mod.key"
                    class="flex flex-col gap-3 px-4 py-3 sm:px-5 lg:flex-row lg:items-center"
                    :class="{ 'bg-pending/5': mod.requested_at }"
                >
                    <div class="flex min-w-0 flex-1 items-start gap-3">
                        <span
                            class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                            :class="mod.enabled ? 'bg-success' : 'bg-slate-300'"
                            :title="mod.enabled ? 'Encendido' : 'Apagado'"
                        />
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="text-sm font-medium">{{
                                    mod.label
                                }}</span>
                                <span
                                    v-if="mod.group_label"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                                    >{{ mod.group_label }}</span
                                >
                                <span
                                    v-if="!mod.available"
                                    class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                    title="Se puede dejar encendido desde ya; su área aparecerá sola cuando esté lista"
                                    >En desarrollo</span
                                >
                            </div>
                            <p
                                class="mt-0.5 line-clamp-1 text-xs text-slate-500"
                                :title="mod.description"
                            >
                                {{ mod.description }}
                            </p>
                            <div
                                class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px]"
                            >
                                <span
                                    :class="
                                        mod.override !== null
                                            ? 'font-medium text-info'
                                            : 'text-slate-400'
                                    "
                                    >{{ originLabel(mod) }}</span
                                >
                                <template v-if="mod.requested_at">
                                    <span
                                        class="inline-flex items-center gap-1 font-medium text-pending"
                                    >
                                        <Lucide
                                            icon="BellRing"
                                            class="h-3 w-3"
                                        />
                                        Lo pidió el {{ mod.requested_at }}
                                    </span>
                                    <button
                                        type="button"
                                        class="text-slate-400 underline-offset-2 hover:text-slate-600 hover:underline dark:hover:text-slate-300"
                                        :disabled="pendingKey !== null"
                                        @click="dismissRequest(mod)"
                                    >
                                        Descartar sin encender
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div
                        class="inline-flex h-8 shrink-0 self-start rounded-[0.5rem] border border-slate-200 bg-white p-0.5 text-xs lg:self-center dark:border-darkmode-400 dark:bg-darkmode-600"
                        :class="{ 'opacity-60': pendingKey === mod.key }"
                    >
                        <button
                            v-for="b in modeButtons(mod)"
                            :key="b.mode"
                            type="button"
                            class="rounded-md px-2.5 font-medium whitespace-nowrap transition"
                            :class="
                                moduleMode(mod) === b.mode
                                    ? b.mode === 'off'
                                        ? 'bg-slate-200 text-slate-700 dark:bg-darkmode-400 dark:text-slate-200'
                                        : b.mode === 'on'
                                          ? 'bg-success/10 text-success'
                                          : 'bg-primary/10 text-primary'
                                    : 'text-slate-500 hover:text-primary'
                            "
                            :title="b.title"
                            :disabled="pendingKey !== null"
                            @click="setModule(mod, b.mode)"
                        >
                            {{ b.label }}
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
                    <Lucide icon="SearchX" class="h-4 w-4" />
                </div>
                <p class="text-xs text-slate-500">
                    {{
                        only === 'requested' && !hasFilters
                            ? 'No hay solicitudes pendientes.'
                            : 'Ningún módulo con ese filtro.'
                    }}
                </p>
                <button
                    v-if="hasFilters"
                    type="button"
                    class="text-xs font-medium text-primary"
                    @click="clearFilters"
                >
                    Quitar filtros
                </button>
            </div>

            <div
                class="flex flex-wrap items-center gap-2 border-t border-slate-200/60 px-4 py-2.5 text-[11px] text-slate-400 dark:border-darkmode-400"
            >
                <span class="flex-1">
                    "Plan" sigue lo que traiga su plan o sus servicios y cambia
                    solo si cambian; Encendido y Apagado lo fijan para este
                    hotel. Apagar oculta el área pero no borra datos. Encenderlo
                    atiende la solicitud.
                </span>
                <Link
                    :href="route('admin.plans')"
                    class="inline-flex items-center gap-1 font-medium text-primary"
                >
                    Qué trae cada plan
                    <Lucide icon="ArrowRight" class="h-3 w-3" />
                </Link>
            </div>
        </div>
    </RazeLayout>
</template>
