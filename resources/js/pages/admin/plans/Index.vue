<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSwitch } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface PlanRow {
    key: string;
    label: string;
    description: string | null;
    price_monthly: number;
    max_properties: number | null;
    max_rooms: number | null;
    max_users: number | null;
    max_channels: number | null;
    max_gateways: number | null;
    modules: string[];
    ai_enabled: boolean;
    ai_monthly_replies: number | null;
    active: boolean;
    tenants: number;
}

interface ModuleDef {
    label: string;
    description: string;
    available: boolean;
}

const props = defineProps<{
    plans: PlanRow[];
    moduleCatalog: Record<string, ModuleDef>;
}>();

const money = (n: number) => `$${n.toLocaleString('es-MX')}`;
const limit = (n: number | null) => (n === null ? 'Sin límite' : String(n));

// Catálogo como lista ordenada (config/modules.php define el orden).
const moduleList = Object.entries(props.moduleCatalog).map(([key, def]) => ({ key, ...def }));
const moduleLabel = (key: string) => props.moduleCatalog[key]?.label ?? key;

// Crear / editar comparten formulario; editing !== null distingue.
const showForm = ref(false);
const editing = ref<PlanRow | null>(null);
const form = useForm({
    key: '',
    label: '',
    description: '',
    price_monthly: 0 as number | string,
    max_properties: '' as number | string,
    max_rooms: '' as number | string,
    max_users: '' as number | string,
    max_channels: '' as number | string,
    max_gateways: '' as number | string,
    modules: [] as string[],
    ai_monthly_replies: '' as number | string,
    active: true,
});

function toggleModule(key: string) {
    form.modules = form.modules.includes(key)
        ? form.modules.filter((m) => m !== key)
        : [...form.modules, key];
}

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}

function openEdit(plan: PlanRow) {
    editing.value = plan;
    form.clearErrors();
    form.key = plan.key;
    form.label = plan.label;
    form.description = plan.description ?? '';
    form.price_monthly = plan.price_monthly;
    form.max_properties = plan.max_properties ?? '';
    form.max_rooms = plan.max_rooms ?? '';
    form.max_users = plan.max_users ?? '';
    form.max_channels = plan.max_channels ?? '';
    form.max_gateways = plan.max_gateways ?? '';
    form.modules = [...plan.modules];
    // 0 heredado del seed se muestra como vacío (placeholder "Sin límite").
    form.ai_monthly_replies = plan.ai_monthly_replies && plan.ai_monthly_replies > 0 ? plan.ai_monthly_replies : '';
    form.active = plan.active;
    showForm.value = true;
}

function submit() {
    const transform = (data: Record<string, unknown>) => ({
        ...data,
        description: data.description === '' ? null : data.description,
        max_properties: data.max_properties === '' ? null : Number(data.max_properties),
        max_rooms: data.max_rooms === '' ? null : Number(data.max_rooms),
        max_users: data.max_users === '' ? null : Number(data.max_users),
        max_channels: data.max_channels === '' ? null : Number(data.max_channels),
        max_gateways: data.max_gateways === '' ? null : Number(data.max_gateways),
        // Sin módulo agente-ia o valor vacío/inválido = sin límite (null).
        ai_monthly_replies:
            !(data.modules as string[]).includes('agente-ia') || data.ai_monthly_replies === '' || Number(data.ai_monthly_replies) < 1
                ? null
                : Number(data.ai_monthly_replies),
        price_monthly: Number(data.price_monthly || 0),
    });

    if (editing.value) {
        form.transform(transform).patch(route('admin.plans.update', editing.value.key), {
            onSuccess: () => (showForm.value = false),
        });
    } else {
        form.transform(transform).post(route('admin.plans.store'), {
            onSuccess: () => (showForm.value = false),
        });
    }
}

// Activar/desactivar rápido (sin abrir el modal).
function toggleActive(plan: PlanRow) {
    useForm({
        label: plan.label,
        description: plan.description,
        price_monthly: plan.price_monthly,
        max_properties: plan.max_properties,
        max_rooms: plan.max_rooms,
        max_users: plan.max_users,
        max_channels: plan.max_channels,
        max_gateways: plan.max_gateways,
        modules: plan.modules,
        ai_monthly_replies: plan.ai_monthly_replies,
        active: !plan.active,
    }).patch(route('admin.plans.update', plan.key), { preserveScroll: true });
}

const deleting = ref<PlanRow | null>(null);
const deleteForm = useForm({});

function submitDelete() {
    if (!deleting.value) return;
    deleteForm.delete(route('admin.plans.destroy', deleting.value.key), {
        onSuccess: () => (deleting.value = null),
        onError: () => (deleting.value = null),
    });
}
</script>

<template>
    <RazeLayout title="Planes">
        <div class="mt-2 flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center">
            <div>
                <h1 class="text-lg font-medium group-[.mode--light]:text-white">Planes de la plataforma</h1>
                <p class="text-sm text-slate-500">Límites, módulos y precio por plan — los cambios aplican de inmediato a los hoteles del plan</p>
            </div>
            <div class="md:ml-auto">
                <Button variant="primary" class="rounded-[0.5rem] shadow-md shadow-primary/20" @click="openCreate">
                    <Lucide icon="Plus" class="mr-2 h-4 w-4 stroke-[1.3]" /> Nuevo plan
                </Button>
            </div>
        </div>

        <div v-if="$page.props.errors?.plan" class="mt-5 flex items-center rounded-md border border-danger/20 bg-danger/5 px-4 py-3 text-sm text-danger">
            <Lucide icon="TriangleAlert" class="mr-2 h-4 w-4 shrink-0" />
            {{ $page.props.errors.plan }}
        </div>

        <div class="mt-5 grid grid-cols-12 gap-5">
            <div v-for="plan in plans" :key="plan.key" class="col-span-12 md:col-span-6 xl:col-span-4">
                <div class="box box--stacked flex h-full flex-col p-5" :class="{ 'opacity-60': !plan.active }">
                    <div class="flex items-start gap-3">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="Layers" class="h-5 w-5 text-primary" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="truncate text-base font-medium">{{ plan.label }}</span>
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] text-slate-500 dark:bg-darkmode-400">{{ plan.key }}</span>
                            </div>
                            <div class="mt-0.5 text-sm text-slate-500">
                                <span class="text-lg font-medium text-slate-700 dark:text-slate-200">{{ money(plan.price_monthly) }}</span>
                                <span class="text-xs"> MXN/mes</span>
                            </div>
                            <p v-if="plan.description" class="mt-1 line-clamp-2 text-xs text-slate-500">{{ plan.description }}</p>
                        </div>
                        <FormSwitch class="shrink-0" title="Inactivo: no se ofrece a hoteles nuevos (los existentes conservan el plan)">
                            <FormSwitch.Input :checked="plan.active" type="checkbox" @change="toggleActive(plan)" />
                        </FormSwitch>
                    </div>

                    <div class="mt-4 flex flex-1 flex-col gap-2.5 border-t border-dashed border-slate-300/70 pt-4 text-sm">
                        <div class="flex items-center gap-2.5">
                            <Lucide icon="Building2" class="h-4 w-4 stroke-[1.5] text-slate-400" />
                            <span class="text-slate-500">Propiedades</span>
                            <span class="ml-auto font-medium">{{ limit(plan.max_properties) }}</span>
                        </div>
                        <div class="flex items-center gap-2.5">
                            <Lucide icon="BedDouble" class="h-4 w-4 stroke-[1.5] text-slate-400" />
                            <span class="text-slate-500">Habitaciones</span>
                            <span class="ml-auto font-medium">{{ limit(plan.max_rooms) }}</span>
                        </div>
                        <div class="flex items-center gap-2.5">
                            <Lucide icon="Users" class="h-4 w-4 stroke-[1.5] text-slate-400" />
                            <span class="text-slate-500">Usuarios</span>
                            <span class="ml-auto font-medium">{{ limit(plan.max_users) }}</span>
                        </div>
                        <div class="flex items-center gap-2.5">
                            <Lucide icon="MessageCircle" class="h-4 w-4 stroke-[1.5] text-slate-400" />
                            <span class="text-slate-500">Canales de mensajería</span>
                            <span class="ml-auto font-medium">{{ limit(plan.max_channels) }}</span>
                        </div>
                        <div class="flex items-center gap-2.5">
                            <Lucide icon="CreditCard" class="h-4 w-4 stroke-[1.5] text-slate-400" />
                            <span class="text-slate-500">Pasarelas de pago</span>
                            <span class="ml-auto font-medium">{{ plan.max_gateways === 0 ? 'Solo transferencias' : limit(plan.max_gateways) }}</span>
                        </div>
                        <div class="border-t border-dashed border-slate-300/70 pt-2.5">
                            <div class="mb-2 flex items-center gap-2.5">
                                <Lucide icon="Blocks" class="h-4 w-4 stroke-[1.5] text-slate-400" />
                                <span class="text-slate-500">Módulos incluidos</span>
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <span
                                    v-for="key in plan.modules"
                                    :key="key"
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="moduleCatalog[key]?.available === false ? 'bg-slate-100 text-slate-500 dark:bg-darkmode-400' : 'bg-primary/10 text-primary'"
                                    :title="moduleCatalog[key]?.available === false ? 'En desarrollo: su área aparecerá cuando esté lista' : moduleCatalog[key]?.description"
                                >
                                    {{ moduleLabel(key) }}<template v-if="key === 'agente-ia'">
                                        · {{ plan.ai_monthly_replies === null ? 'sin límite' : `${plan.ai_monthly_replies}/mes` }}</template>
                                </span>
                                <span v-if="!plan.modules.length" class="text-xs text-slate-400">Solo el núcleo hotelero</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center gap-2 border-t border-dashed border-slate-300/70 pt-3.5">
                        <span class="flex items-center gap-1.5 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400">
                            <Lucide icon="Building2" class="h-3 w-3" /> {{ plan.tenants }} hotel(es)
                        </span>
                        <div class="ml-auto flex gap-1">
                            <button type="button" title="Editar plan" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary" @click="openEdit(plan)">
                                <Lucide icon="Pencil" class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                :title="plan.tenants ? 'Hay hoteles en este plan: desactívalo en su lugar' : 'Eliminar plan'"
                                class="flex h-8 w-8 items-center justify-center rounded-full transition"
                                :class="plan.tenants ? 'cursor-not-allowed text-slate-300 dark:text-darkmode-400' : 'text-slate-500 hover:bg-danger/10 hover:text-danger'"
                                @click="!plan.tenants && (deleting = plan)"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal crear / editar -->
        <Dialog :open="showForm" size="xl" @close="showForm = false">
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="submit">
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-8 py-5 dark:border-darkmode-400">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="Layers" class="h-5 w-5 text-primary" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">{{ editing ? `Editar plan ${editing.label}` : 'Nuevo plan' }}</h2>
                            <p class="mt-0.5 text-xs text-slate-500">Los cambios aplican de inmediato a los hoteles del plan; los límites vacíos significan "sin límite".</p>
                        </div>
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400" @click="showForm = false">
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="max-h-[75vh] space-y-7 overflow-y-auto px-8 py-6">
                        <!-- Identidad -->
                        <section>
                            <div class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="BadgeCheck" class="h-3.5 w-3.5" /> Identidad del plan
                            </div>
                            <div class="grid grid-cols-12 gap-5">
                                <div v-if="!editing" class="col-span-12 sm:col-span-4">
                                    <label class="mb-1.5 block text-sm font-medium">Clave (interna)</label>
                                    <FormInput v-model="form.key" type="text" class="font-mono" placeholder="premium" />
                                    <FormHelp v-if="form.errors.key" class="text-danger">{{ form.errors.key }}</FormHelp>
                                    <FormHelp v-else>Identificador técnico; no se puede cambiar después.</FormHelp>
                                </div>
                                <div class="col-span-12" :class="editing ? 'sm:col-span-8' : 'sm:col-span-4'">
                                    <label class="mb-1.5 block text-sm font-medium">Nombre del plan</label>
                                    <FormInput v-model="form.label" type="text" placeholder="Premium" />
                                    <FormHelp v-if="form.errors.label" class="text-danger">{{ form.errors.label }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <label class="mb-1.5 block text-sm font-medium">Precio mensual (MXN)</label>
                                    <div class="relative">
                                        <Lucide icon="DollarSign" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                        <FormInput v-model="form.price_monthly" type="number" min="0" class="pl-9" />
                                    </div>
                                    <FormHelp v-if="form.errors.price_monthly" class="text-danger">{{ form.errors.price_monthly }}</FormHelp>
                                    <FormHelp v-else>Informativo; el cobro llega con la fase de facturación.</FormHelp>
                                </div>
                                <div class="col-span-12">
                                    <label class="mb-1.5 block text-sm font-medium">Descripción <span class="font-normal text-slate-400">(opcional)</span></label>
                                    <FormInput v-model="form.description" type="text" maxlength="160" placeholder="Para hoteles y moteles que empiezan: lo esencial para operar en línea." />
                                    <FormHelp v-if="form.errors.description" class="text-danger">{{ form.errors.description }}</FormHelp>
                                    <FormHelp v-else>Una línea de venta; se muestra en el catálogo al asignar plan a un hotel.</FormHelp>
                                </div>
                            </div>
                        </section>

                        <!-- Límites -->
                        <section class="border-t border-dashed border-slate-300/70 pt-6">
                            <div class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="Gauge" class="h-3.5 w-3.5" /> Límites del plan
                            </div>
                            <div class="grid grid-cols-12 gap-5">
                                <div class="col-span-12 sm:col-span-4">
                                    <label class="mb-1.5 flex items-center gap-1.5 text-sm font-medium">
                                        <Lucide icon="Building2" class="h-4 w-4 stroke-[1.5] text-slate-400" /> Propiedades
                                    </label>
                                    <FormInput v-model="form.max_properties" type="number" min="1" placeholder="Sin límite" />
                                    <FormHelp v-if="form.errors.max_properties" class="text-danger">{{ form.errors.max_properties }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <label class="mb-1.5 flex items-center gap-1.5 text-sm font-medium">
                                        <Lucide icon="BedDouble" class="h-4 w-4 stroke-[1.5] text-slate-400" /> Habitaciones
                                    </label>
                                    <FormInput v-model="form.max_rooms" type="number" min="1" placeholder="Sin límite" />
                                    <FormHelp v-if="form.errors.max_rooms" class="text-danger">{{ form.errors.max_rooms }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <label class="mb-1.5 flex items-center gap-1.5 text-sm font-medium">
                                        <Lucide icon="Users" class="h-4 w-4 stroke-[1.5] text-slate-400" /> Usuarios
                                    </label>
                                    <FormInput v-model="form.max_users" type="number" min="1" placeholder="Sin límite" />
                                    <FormHelp v-if="form.errors.max_users" class="text-danger">{{ form.errors.max_users }}</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <label class="mb-1.5 flex items-center gap-1.5 text-sm font-medium">
                                        <Lucide icon="MessageCircle" class="h-4 w-4 stroke-[1.5] text-slate-400" /> Canales
                                    </label>
                                    <FormInput v-model="form.max_channels" type="number" min="0" placeholder="Sin límite" />
                                    <FormHelp v-if="form.errors.max_channels" class="text-danger">{{ form.errors.max_channels }}</FormHelp>
                                    <FormHelp v-else>WhatsApp y páginas conectadas; el webchat propio no cuenta.</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <label class="mb-1.5 flex items-center gap-1.5 text-sm font-medium">
                                        <Lucide icon="CreditCard" class="h-4 w-4 stroke-[1.5] text-slate-400" /> Pasarelas de pago
                                    </label>
                                    <FormInput v-model="form.max_gateways" type="number" min="0" placeholder="Sin límite" />
                                    <FormHelp v-if="form.errors.max_gateways" class="text-danger">{{ form.errors.max_gateways }}</FormHelp>
                                    <FormHelp v-else>Stripe o Mercado Pago; 0 = solo transferencias con verificación.</FormHelp>
                                </div>
                            </div>
                        </section>

                        <!-- Módulos incluidos -->
                        <section class="border-t border-dashed border-slate-300/70 pt-6">
                            <div class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="Blocks" class="h-3.5 w-3.5" /> Módulos incluidos
                            </div>
                            <div class="space-y-3">
                                <div v-for="mod in moduleList" :key="mod.key" class="rounded-lg border border-slate-200/70 dark:border-darkmode-400">
                                    <label class="flex cursor-pointer items-start gap-3.5 p-4">
                                        <FormSwitch class="mt-0.5">
                                            <FormSwitch.Input type="checkbox" :checked="form.modules.includes(mod.key)" @change="toggleModule(mod.key)" />
                                        </FormSwitch>
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-2 text-sm font-medium">
                                                {{ mod.label }}
                                                <span
                                                    v-if="!mod.available"
                                                    class="rounded-full bg-pending/10 px-2 py-0.5 text-[10px] font-medium text-pending"
                                                    title="Se puede incluir desde ya; su área aparecerá sola cuando esté lista"
                                                >
                                                    En desarrollo
                                                </span>
                                            </span>
                                            <span class="mt-0.5 block text-xs text-slate-500">{{ mod.description }}</span>
                                        </span>
                                    </label>
                                    <div
                                        v-if="mod.key === 'agente-ia' && form.modules.includes('agente-ia')"
                                        class="flex flex-wrap items-center gap-3 border-t border-dashed border-slate-300/70 px-4 py-3.5 dark:border-darkmode-400"
                                    >
                                        <span class="text-sm text-slate-500">Cuota mensual de respuestas del bot</span>
                                        <FormInput v-model="form.ai_monthly_replies" type="number" min="1" class="!w-32 !py-1.5 text-sm" placeholder="Sin límite" />
                                        <span class="text-xs text-slate-400">Se reinicia cada mes; al agotarse, las conversaciones pasan al staff.</span>
                                    </div>
                                </div>
                            </div>
                            <FormHelp v-if="form.errors.ai_monthly_replies" class="text-danger">{{ form.errors.ai_monthly_replies }}</FormHelp>
                            <FormHelp v-else class="mt-2">
                                El núcleo hotelero (plano, reservas, habitaciones, huéspedes, bandeja) va en todos los planes.
                                BYOK (key propia del hotel) y la API de integraciones se habilitan por hotel en la sección Agentes IA.
                            </FormHelp>
                        </section>

                        <!-- Disponibilidad -->
                        <section class="border-t border-dashed border-slate-300/70 pt-6">
                            <div class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="Store" class="h-3.5 w-3.5" /> Disponibilidad
                            </div>
                            <label class="flex cursor-pointer items-start gap-3.5 rounded-lg border border-slate-200/70 p-4 dark:border-darkmode-400">
                                <FormSwitch class="mt-0.5">
                                    <FormSwitch.Input v-model="form.active" type="checkbox" :checked="form.active" />
                                </FormSwitch>
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium">Activo en el catálogo</span>
                                    <span class="mt-0.5 block text-xs text-slate-500">Se ofrece al crear hoteles nuevos; al desactivarlo, los hoteles existentes conservan su plan.</span>
                                </span>
                            </label>
                        </section>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-8 py-4 dark:border-darkmode-400">
                        <Button type="button" variant="outline-secondary" @click="showForm = false">Cancelar</Button>
                        <Button type="submit" variant="primary" class="shadow-md shadow-primary/20" :disabled="form.processing">
                            <Lucide icon="Check" class="mr-2 h-4 w-4" /> {{ form.processing ? 'Guardando…' : 'Guardar plan' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal eliminar -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide icon="TriangleAlert" class="mx-auto mb-3 h-12 w-12 text-danger" />
                    <h2 class="text-base font-medium">¿Eliminar el plan {{ deleting?.label }}?</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Solo puede eliminarse porque ningún hotel lo usa. Si algún día quieres
                        retirarlo del catálogo sin borrarlo, desactívalo.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button variant="outline-secondary" @click="deleting = null">Cancelar</Button>
                        <Button variant="danger" :disabled="deleteForm.processing" @click="submitDelete">
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" /> Sí, eliminar
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
