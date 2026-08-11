<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSwitch, FormTextarea } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ServiceRow {
    key: string;
    name: string;
    summary: string | null;
    objective: string | null;
    recommendation: string | null;
    price_monthly: number;
    activation_fee: number;
    includes: { label: string; available: boolean }[];
    ai_monthly_replies: number | null;
    requires: string | null;
    active: boolean;
    tenants: string[];
}

interface TenantRow {
    id: string;
    name: string;
    plan: string;
    plan_label: string;
}

const props = defineProps<{
    services: ServiceRow[];
    tenants: TenantRow[];
    stats: { mrr_addons: number; contracts: number };
}>();

const money = (n: number) => `$${n.toLocaleString('es-MX')}`;

// Los nombres largos del documento ("Servicios Digitales… – Modalidad N:
// Título") se parten: el prefijo va como overline y el título manda.
function displayTitle(service: ServiceRow) {
    const parts = service.name.split(' – ');
    return parts.length > 1 ? parts[parts.length - 1] : service.name;
}
function displayGroup(service: ServiceRow) {
    const parts = service.name.split(' – ');
    return parts.length > 1 ? parts.slice(0, -1).join(' – ') : null;
}

const serviceIcons: Record<string, Icon> = {
    'reservas-m1-motor': 'Globe',
    'reservas-m2-ia-mensajes': 'Bot',
    'reservas-m3-ia-redes': 'Share2',
    'menu-digital': 'UtensilsCrossed',
    'inventario-costos': 'Boxes',
    'crm-frecuentes': 'HeartHandshake',
};
const serviceIcon = (key: string): Icon => serviceIcons[key] ?? 'PackagePlus';

// ── Editar servicio ─────────────────────────────────────────────────────
const editing = ref<ServiceRow | null>(null);
const form = useForm({
    name: '',
    summary: '',
    objective: '',
    recommendation: '',
    price_monthly: 0 as number | string,
    activation_fee: 0 as number | string,
    active: true,
});

function openEdit(service: ServiceRow) {
    editing.value = service;
    form.clearErrors();
    form.name = service.name;
    form.summary = service.summary ?? '';
    form.objective = service.objective ?? '';
    form.recommendation = service.recommendation ?? '';
    form.price_monthly = service.price_monthly;
    form.activation_fee = service.activation_fee;
    form.active = service.active;
}

function submit() {
    if (!editing.value) return;
    form.transform((data) => ({
        ...data,
        summary: data.summary === '' ? null : data.summary,
        objective: data.objective === '' ? null : data.objective,
        recommendation:
            data.recommendation === '' ? null : data.recommendation,
        price_monthly: Number(data.price_monthly || 0),
        activation_fee: Number(data.activation_fee || 0),
    })).patch(route('admin.services.update', editing.value.key), {
        onSuccess: () => (editing.value = null),
    });
}

// Activar/retirar del catálogo sin abrir el modal.
function toggleActive(service: ServiceRow) {
    useForm({
        name: service.name,
        summary: service.summary,
        objective: service.objective,
        recommendation: service.recommendation,
        price_monthly: service.price_monthly,
        activation_fee: service.activation_fee,
        active: !service.active,
    }).patch(route('admin.services.update', service.key), {
        preserveScroll: true,
    });
}

// ── Contratación por hotel ──────────────────────────────────────────────
const managing = ref<ServiceRow | null>(null);
const contractForm = useForm({ contracted: false });

const managingCurrent = computed(() =>
    managing.value
        ? (props.services.find((s) => s.key === managing.value?.key) ??
          managing.value)
        : null,
);

function toggleTenant(tenantId: string) {
    const service = managingCurrent.value;
    if (!service) return;
    contractForm.contracted = !service.tenants.includes(tenantId);
    contractForm.patch(
        route('admin.tenants.addon-services', {
            tenant: tenantId,
            addonService: service.key,
        }),
        { preserveScroll: true },
    );
}
</script>

<template>
    <RazeLayout title="Servicios adicionales">
        <div
            class="mt-2 flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center"
        >
            <div>
                <h1 class="text-lg font-medium group-[.mode--light]:text-white">
                    Servicios adicionales
                </h1>
                <p class="text-sm text-slate-500">
                    Se contratan por encima del plan base y se cobran aparte;
                    lo que incluyen se enciende al hotel en cuanto se contratan
                </p>
            </div>
            <div class="md:ml-auto flex items-center gap-2">
                <span
                    class="flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500 dark:bg-darkmode-400"
                >
                    <Lucide icon="Receipt" class="h-3.5 w-3.5" />
                    {{ stats.contracts }} contratación(es)
                </span>
                <span
                    class="flex items-center gap-1.5 rounded-full bg-success/10 px-3 py-1 text-xs font-medium text-success"
                    title="Ingreso mensual por servicios adicionales contratados"
                >
                    <Lucide icon="BadgeDollarSign" class="h-3.5 w-3.5" />
                    {{ money(stats.mrr_addons) }} MXN/mes
                </span>
            </div>
        </div>

        <div
            v-if="$page.props.errors?.service"
            class="mt-5 flex items-center rounded-md border border-danger/20 bg-danger/5 px-4 py-3 text-sm text-danger"
        >
            <Lucide icon="TriangleAlert" class="mr-2 h-4 w-4 shrink-0" />
            {{ $page.props.errors.service }}
        </div>

        <div class="mt-5 grid grid-cols-12 gap-5">
            <div
                v-for="service in services"
                :key="service.key"
                class="col-span-12 md:col-span-6 xl:col-span-4"
            >
                <div
                    class="box box--stacked flex h-full flex-col p-5"
                    :class="{ 'opacity-60': !service.active }"
                >
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide
                                :icon="serviceIcon(service.key)"
                                class="h-5 w-5 text-primary"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div
                                v-if="displayGroup(service)"
                                class="text-[10px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                {{ displayGroup(service) }}
                            </div>
                            <div class="text-base font-medium">
                                {{ displayTitle(service) }}
                            </div>
                            <div class="mt-0.5 text-sm text-slate-500">
                                <span
                                    class="text-lg font-medium text-slate-700 dark:text-slate-200"
                                    >{{ money(service.price_monthly) }}</span
                                >
                                <span class="text-xs"> MXN/mes</span>
                                <span class="mx-1.5 text-slate-300">·</span>
                                <span class="text-xs"
                                    >{{ money(service.activation_fee) }}
                                    activación</span
                                >
                            </div>
                        </div>
                        <FormSwitch
                            class="shrink-0"
                            title="Inactivo: no se ofrece ni cuenta para hoteles nuevos (las contrataciones existentes dejan de aplicar)"
                        >
                            <FormSwitch.Input
                                :checked="service.active"
                                type="checkbox"
                                @change="toggleActive(service)"
                            />
                        </FormSwitch>
                    </div>

                    <div
                        class="mt-4 flex flex-1 flex-col gap-3 border-t border-dashed border-slate-300/70 pt-4 text-sm"
                    >
                        <p class="text-xs leading-relaxed text-slate-500">
                            {{ service.summary }}
                        </p>
                        <div v-if="service.recommendation">
                            <div
                                class="mb-1 flex items-center gap-1.5 text-[10px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide icon="BadgeCheck" class="h-3 w-3" />
                                Recomendado para
                            </div>
                            <p class="text-xs leading-relaxed text-slate-500">
                                {{ service.recommendation }}
                            </p>
                        </div>
                        <div
                            v-if="service.requires"
                            class="flex items-center gap-1.5 rounded-md bg-pending/10 px-2.5 py-1.5 text-xs text-pending"
                        >
                            <Lucide icon="Link" class="h-3.5 w-3.5 shrink-0" />
                            Amplía "{{ displayTitle(services.find((s) => s.key === service.requires) ?? service) }}":
                            requiere tenerlo contratado
                        </div>
                        <div v-if="service.includes.length" class="mt-auto">
                            <div
                                class="mb-2 flex items-center gap-1.5 text-[10px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide icon="Sparkles" class="h-3 w-3" />
                                Al contratarlo, el hotel recibe
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <span
                                    v-for="item in service.includes"
                                    :key="item.label"
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        item.available
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                    :title="
                                        item.available
                                            ? undefined
                                            : 'En desarrollo: se puede vender desde ya y su área aparecerá sola cuando esté lista'
                                    "
                                >
                                    {{ item.label
                                    }}<template v-if="!item.available">
                                        (en desarrollo)</template
                                    >
                                </span>
                                <span
                                    v-if="service.ai_monthly_replies"
                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                                    title="Cuota mensual de respuestas del bot que aporta este servicio"
                                >
                                    {{ service.ai_monthly_replies }} respuestas
                                    IA/mes
                                </span>
                            </div>
                        </div>
                    </div>

                    <div
                        class="mt-4 flex items-center gap-2 border-t border-dashed border-slate-300/70 pt-3.5"
                    >
                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-500 transition hover:bg-primary/10 hover:text-primary dark:bg-darkmode-400"
                            title="Ver y cambiar qué hoteles lo tienen contratado"
                            @click="managing = service"
                        >
                            <Lucide icon="Building2" class="h-3 w-3" />
                            {{ service.tenants.length }} hotel(es)
                        </button>
                        <div class="ml-auto">
                            <button
                                type="button"
                                title="Editar servicio"
                                class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                @click="openEdit(service)"
                            >
                                <Lucide icon="Pencil" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal contratación por hotel -->
        <Dialog :open="managing !== null" @close="managing = null">
            <Dialog.Panel>
                <div
                    class="flex items-center gap-3.5 border-b border-slate-200/70 px-8 py-5 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                    >
                        <Lucide
                            :icon="serviceIcon(managingCurrent?.key ?? '')"
                            class="h-5 w-5 text-primary"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium">
                            {{ managingCurrent ? displayTitle(managingCurrent) : '' }}
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ money(managingCurrent?.price_monthly ?? 0) }}
                            MXN/mes por hotel — se suma a su plan base
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                        @click="managing = null"
                    >
                        <Lucide icon="X" class="h-5 w-5" />
                    </button>
                </div>
                <div class="max-h-[65vh] overflow-y-auto px-8 py-5">
                    <div
                        v-if="$page.props.errors?.service"
                        class="mb-4 flex items-center rounded-md border border-danger/20 bg-danger/5 px-4 py-3 text-sm text-danger"
                    >
                        <Lucide
                            icon="TriangleAlert"
                            class="mr-2 h-4 w-4 shrink-0"
                        />
                        {{ $page.props.errors.service }}
                    </div>
                    <div class="space-y-2.5">
                        <label
                            v-for="tenant in tenants"
                            :key="tenant.id"
                            class="flex cursor-pointer items-center gap-3.5 rounded-lg border border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                        >
                            <FormSwitch>
                                <FormSwitch.Input
                                    type="checkbox"
                                    :checked="
                                        managingCurrent?.tenants.includes(
                                            tenant.id,
                                        )
                                    "
                                    :disabled="contractForm.processing"
                                    @change="toggleTenant(tenant.id)"
                                />
                            </FormSwitch>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium">{{
                                    tenant.name
                                }}</span>
                                <span class="block text-xs text-slate-500"
                                    >Plan {{ tenant.plan_label }}</span
                                >
                            </span>
                        </label>
                        <p
                            v-if="!tenants.length"
                            class="py-6 text-center text-sm text-slate-400"
                        >
                            Todavía no hay hoteles en la plataforma.
                        </p>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal editar servicio -->
        <Dialog
            :open="editing !== null"
            size="xl"
            @close="editing = null"
        >
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="submit">
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-8 py-5 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide
                                :icon="serviceIcon(editing?.key ?? '')"
                                class="h-5 w-5 text-primary"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                Editar servicio adicional
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Los cambios de precio aplican de inmediato a
                                los hoteles que lo tienen contratado.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="editing = null"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <div
                        class="max-h-[75vh] space-y-7 overflow-y-auto px-8 py-6"
                    >
                        <section>
                            <div
                                class="mb-4 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide icon="BadgeCheck" class="h-3.5 w-3.5" />
                                Identidad del servicio
                            </div>
                            <div class="grid grid-cols-12 gap-5">
                                <div class="col-span-12">
                                    <label
                                        class="mb-1.5 block text-sm font-medium"
                                        >Nombre</label
                                    >
                                    <FormInput
                                        v-model="form.name"
                                        type="text"
                                    />
                                    <FormHelp
                                        v-if="form.errors.name"
                                        class="text-danger"
                                        >{{ form.errors.name }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <label
                                        class="mb-1.5 block text-sm font-medium"
                                        >Inversión mensual (MXN)</label
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="DollarSign"
                                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                                        />
                                        <FormInput
                                            v-model="form.price_monthly"
                                            type="number"
                                            min="0"
                                            class="pl-9"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="form.errors.price_monthly"
                                        class="text-danger"
                                        >{{
                                            form.errors.price_monthly
                                        }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <label
                                        class="mb-1.5 block text-sm font-medium"
                                        >Cuota única de activación (MXN)</label
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="DollarSign"
                                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                                        />
                                        <FormInput
                                            v-model="form.activation_fee"
                                            type="number"
                                            min="0"
                                            class="pl-9"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="form.errors.activation_fee"
                                        class="text-danger"
                                        >{{
                                            form.errors.activation_fee
                                        }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12">
                                    <label
                                        class="mb-1.5 block text-sm font-medium"
                                        >Breve resumen</label
                                    >
                                    <FormTextarea
                                        v-model="form.summary"
                                        rows="2"
                                        maxlength="500"
                                    />
                                    <FormHelp
                                        v-if="form.errors.summary"
                                        class="text-danger"
                                        >{{ form.errors.summary }}</FormHelp
                                    >
                                    <FormHelp v-else
                                        >Como en la tabla de inversión del
                                        documento comercial.</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <label
                                        class="mb-1.5 block text-sm font-medium"
                                        >Objetivo</label
                                    >
                                    <FormTextarea
                                        v-model="form.objective"
                                        rows="3"
                                        maxlength="1000"
                                    />
                                    <FormHelp
                                        v-if="form.errors.objective"
                                        class="text-danger"
                                        >{{ form.errors.objective }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <label
                                        class="mb-1.5 block text-sm font-medium"
                                        >Recomendación</label
                                    >
                                    <FormTextarea
                                        v-model="form.recommendation"
                                        rows="3"
                                        maxlength="1000"
                                    />
                                    <FormHelp
                                        v-if="form.errors.recommendation"
                                        class="text-danger"
                                        >{{
                                            form.errors.recommendation
                                        }}</FormHelp
                                    >
                                </div>
                            </div>
                        </section>

                        <section
                            v-if="editing?.includes.length"
                            class="border-t border-dashed border-slate-300/70 pt-6"
                        >
                            <div
                                class="mb-4 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide icon="Sparkles" class="h-3.5 w-3.5" />
                                Al contratarlo, el hotel recibe
                            </div>
                            <div
                                class="rounded-lg border border-slate-200/70 p-4 dark:border-darkmode-400"
                            >
                                <div class="flex flex-wrap gap-1.5">
                                    <span
                                        v-for="item in editing.includes"
                                        :key="item.label"
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            item.available
                                                ? 'bg-primary/10 text-primary'
                                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                        "
                                    >
                                        {{ item.label
                                        }}<template v-if="!item.available">
                                            (en desarrollo)</template
                                        >
                                    </span>
                                    <span
                                        v-if="editing.ai_monthly_replies"
                                        class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                                    >
                                        {{ editing.ai_monthly_replies }}
                                        respuestas IA/mes
                                    </span>
                                </div>
                                <FormHelp class="mt-2.5">
                                    Es fijo por servicio y se enciende solo al
                                    contratarlo; qué tiene cada hotel se
                                    administra desde su ficha.
                                </FormHelp>
                            </div>
                        </section>

                        <section
                            class="border-t border-dashed border-slate-300/70 pt-6"
                        >
                            <label
                                class="flex cursor-pointer items-start gap-3.5 rounded-lg border border-slate-200/70 p-4 dark:border-darkmode-400"
                            >
                                <FormSwitch class="mt-0.5">
                                    <FormSwitch.Input
                                        v-model="form.active"
                                        type="checkbox"
                                        :checked="form.active"
                                    />
                                </FormSwitch>
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium"
                                        >Activo en el catálogo</span
                                    >
                                    <span
                                        class="mt-0.5 block text-xs text-slate-500"
                                        >Al desactivarlo deja de ofrecerse y lo
                                        que incluye deja de aplicar a los
                                        hoteles que lo tenían.</span
                                    >
                                </span>
                            </label>
                        </section>
                    </div>

                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-8 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="editing = null"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            :disabled="form.processing"
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{
                                form.processing
                                    ? 'Guardando…'
                                    : 'Guardar servicio'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
