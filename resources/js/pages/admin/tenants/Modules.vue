<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { FormHelp, FormInput, FormSelect } from '@/components/Base/Form';
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
    in_plan: boolean;
    in_addon: boolean;
    override: boolean | null;
    enabled: boolean;
    requested_at: string | null;
}

const props = defineProps<{
    tenant: TenantShell;
    plans: PlanOption[];
    modules: ModuleRow[];
}>();

const toast = useToasts();

const search = ref('');
const only = ref<'all' | 'on' | 'off' | 'forced' | 'requested'>('all');

const counts = computed(() => ({
    enabled: props.modules.filter((m) => m.enabled).length,
    forced: props.modules.filter((m) => m.override !== null).length,
    requested: props.modules.filter((m) => m.requested_at).length,
}));

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.modules.filter((mod) => {
        if (only.value === 'on' && !mod.enabled) return false;
        if (only.value === 'off' && mod.enabled) return false;
        if (only.value === 'forced' && mod.override === null) return false;
        if (only.value === 'requested' && !mod.requested_at) return false;
        if (term === '') return true;

        return (
            mod.label.toLowerCase().includes(term) ||
            mod.description.toLowerCase().includes(term)
        );
    });
});

// Lo que el hotel pidió va arriba: es lo único que espera respuesta.
const ordered = computed(() =>
    [...visible.value].sort(
        (a, b) =>
            Number(Boolean(b.requested_at)) - Number(Boolean(a.requested_at)),
    ),
);

const moduleMode = (mod: ModuleRow) =>
    mod.override === null ? 'inherit' : mod.override ? 'on' : 'off';

function originLabel(mod: ModuleRow): string {
    if (mod.override !== null) {
        return mod.override
            ? 'Forzado: activado para este hotel'
            : 'Forzado: apagado para este hotel';
    }
    if (mod.in_plan) return `Incluido en el plan ${props.tenant.plan_label}`;
    if (mod.in_addon) return 'Lo aporta un servicio adicional contratado';

    return `No incluido en el plan ${props.tenant.plan_label}`;
}

function setModule(mod: ModuleRow, mode: 'inherit' | 'on' | 'off') {
    router.patch(
        route('admin.tenants.modules', props.tenant.id),
        { module: mod.key, mode },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    'Módulo actualizado',
                    mode === 'inherit'
                        ? `${mod.label}: hereda del plan`
                        : `${mod.label}: forzado ${mode === 'on' ? 'activado' : 'apagado'}`,
                ),
        },
    );
}

function dismissRequest(mod: ModuleRow) {
    router.delete(
        route('admin.tenants.module-requests.dismiss', [
            props.tenant.id,
            mod.key,
        ]),
        {
            preserveScroll: true,
            onSuccess: () => toast.success('Solicitud descartada', mod.label),
        },
    );
}
</script>

<template>
    <RazeLayout :title="`${tenant.name} · Módulos`">
        <TenantHeader :tenant="tenant" :plans="plans" active="modules" />

        <div class="mt-5 grid grid-cols-12 gap-5">
            <div class="col-span-12">
                <div class="box box--stacked">
                    <!-- Qué hay encendido y qué está pidiendo el hotel -->
                    <div
                        class="flex flex-col gap-3 border-b border-dashed border-slate-300/70 p-5 lg:flex-row lg:items-center"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="rounded-full bg-success/10 px-2.5 py-1 text-xs font-medium text-success"
                            >
                                {{ counts.enabled }} activos
                            </span>
                            <span
                                class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500 dark:bg-darkmode-400 dark:text-slate-300"
                            >
                                {{ modules.length - counts.enabled }} apagados
                            </span>
                            <span
                                v-if="counts.forced"
                                class="rounded-full bg-info/10 px-2.5 py-1 text-xs font-medium text-info"
                            >
                                {{ counts.forced }} forzados
                            </span>
                            <button
                                v-if="counts.requested"
                                type="button"
                                class="flex items-center gap-1.5 rounded-full bg-warning/10 px-2.5 py-1 text-xs font-medium text-warning"
                                @click="only = 'requested'"
                            >
                                <Lucide icon="BellRing" class="h-3 w-3" />
                                {{ counts.requested }} solicitado(s) por el
                                hotel
                            </button>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row lg:ml-auto">
                            <div class="relative sm:w-64">
                                <Lucide
                                    icon="Search"
                                    class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400"
                                />
                                <FormInput
                                    v-model="search"
                                    class="pl-9"
                                    placeholder="Buscar módulo…"
                                />
                            </div>
                            <FormSelect v-model="only" class="sm:w-44">
                                <option value="all">Todos</option>
                                <option value="on">Solo activos</option>
                                <option value="off">Solo apagados</option>
                                <option value="forced">Solo forzados</option>
                                <option value="requested">Con solicitud</option>
                            </FormSelect>
                        </div>
                    </div>

                    <div class="grid gap-3 p-5 xl:grid-cols-2">
                        <div
                            v-for="mod in ordered"
                            :key="mod.key"
                            class="flex flex-col gap-3 rounded-lg border p-3.5 sm:flex-row sm:items-center"
                            :class="
                                mod.requested_at
                                    ? 'border-warning/30 bg-warning/5'
                                    : mod.enabled
                                      ? 'border-success/20'
                                      : 'border-slate-200/70 dark:border-darkmode-400'
                            "
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium">{{
                                        mod.label
                                    }}</span>
                                    <span
                                        class="flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs"
                                        :class="
                                            mod.enabled
                                                ? 'bg-success/10 text-success'
                                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                        "
                                    >
                                        <span
                                            class="h-1.5 w-1.5 rounded-full"
                                            :class="
                                                mod.enabled
                                                    ? 'bg-success'
                                                    : 'bg-slate-400'
                                            "
                                        />
                                        {{ mod.enabled ? 'Activo' : 'Apagado' }}
                                    </span>
                                    <span
                                        v-if="!mod.available"
                                        class="rounded-full bg-pending/10 px-2 py-0.5 text-[10px] font-medium text-pending"
                                        title="Se puede dejar activo desde ya; su área aparecerá sola cuando esté lista"
                                    >
                                        En desarrollo
                                    </span>
                                </div>
                                <div
                                    class="mt-0.5 text-xs text-slate-500"
                                    :title="mod.description"
                                >
                                    {{ originLabel(mod) }}
                                </div>
                                <div
                                    v-if="mod.requested_at"
                                    class="mt-1.5 flex flex-wrap items-center gap-2"
                                >
                                    <span
                                        class="flex items-center gap-1 rounded-full bg-warning/10 px-2 py-0.5 text-xs text-warning"
                                    >
                                        <Lucide
                                            icon="BellRing"
                                            class="h-3 w-3"
                                        />
                                        Lo pidió el {{ mod.requested_at }}
                                    </span>
                                    <button
                                        type="button"
                                        class="text-xs text-slate-400 underline transition hover:text-slate-600 dark:hover:text-slate-300"
                                        @click="dismissRequest(mod)"
                                    >
                                        Descartar
                                    </button>
                                </div>
                            </div>
                            <FormSelect
                                class="!w-full shrink-0 sm:!w-44"
                                :value="moduleMode(mod)"
                                @change="
                                    setModule(
                                        mod,
                                        ($event.target as HTMLSelectElement)
                                            .value as 'inherit' | 'on' | 'off',
                                    )
                                "
                            >
                                <option value="inherit">
                                    Heredar del plan
                                </option>
                                <option value="on">Forzar activado</option>
                                <option value="off">Forzar apagado</option>
                            </FormSelect>
                        </div>

                        <p
                            v-if="!ordered.length"
                            class="col-span-full py-8 text-center text-sm text-slate-400"
                        >
                            Ningún módulo con ese filtro.
                        </p>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2 border-t border-dashed border-slate-300/70 px-5 py-3.5"
                    >
                        <FormHelp class="mt-0 flex-1">
                            Heredar sigue lo que diga el plan (cambia solo si el
                            hotel cambia de plan); forzar fija el módulo para
                            este hotel sin importar el plan. Apagar un módulo
                            oculta su área pero no borra datos.
                        </FormHelp>
                        <Link
                            :href="route('admin.plans')"
                            class="flex items-center text-xs text-primary"
                        >
                            Qué trae cada plan
                            <Lucide icon="ArrowRight" class="ml-1 h-3 w-3" />
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
