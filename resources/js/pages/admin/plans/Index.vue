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
    price_monthly: number;
    max_properties: number | null;
    max_rooms: number | null;
    max_users: number | null;
    ai_enabled: boolean;
    ai_monthly_replies: number | null;
    active: boolean;
    tenants: number;
}

defineProps<{ plans: PlanRow[] }>();

const money = (n: number) => `$${n.toLocaleString('es-MX')}`;
const limit = (n: number | null) => (n === null ? 'Sin límite' : String(n));

// Crear / editar comparten formulario; editing !== null distingue.
const showForm = ref(false);
const editing = ref<PlanRow | null>(null);
const form = useForm({
    key: '',
    label: '',
    price_monthly: 0 as number | string,
    max_properties: '' as number | string,
    max_rooms: '' as number | string,
    max_users: '' as number | string,
    ai_enabled: false,
    ai_monthly_replies: '' as number | string,
    active: true,
});

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
    form.price_monthly = plan.price_monthly;
    form.max_properties = plan.max_properties ?? '';
    form.max_rooms = plan.max_rooms ?? '';
    form.max_users = plan.max_users ?? '';
    form.ai_enabled = plan.ai_enabled;
    form.ai_monthly_replies = plan.ai_monthly_replies ?? '';
    form.active = plan.active;
    showForm.value = true;
}

function submit() {
    const transform = (data: Record<string, unknown>) => ({
        ...data,
        max_properties: data.max_properties === '' ? null : Number(data.max_properties),
        max_rooms: data.max_rooms === '' ? null : Number(data.max_rooms),
        max_users: data.max_users === '' ? null : Number(data.max_users),
        ai_monthly_replies: data.ai_monthly_replies === '' ? null : Number(data.ai_monthly_replies),
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
        price_monthly: plan.price_monthly,
        max_properties: plan.max_properties,
        max_rooms: plan.max_rooms,
        max_users: plan.max_users,
        ai_enabled: plan.ai_enabled,
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
                <p class="text-sm text-slate-500">Límites, precio e IA por plan — los cambios aplican de inmediato a los hoteles del plan</p>
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
                            <Lucide icon="Bot" class="h-4 w-4 stroke-[1.5]" :class="plan.ai_enabled ? 'text-info' : 'text-slate-400'" />
                            <span class="text-slate-500">Asistente IA</span>
                            <span v-if="plan.ai_enabled" class="ml-auto rounded-full bg-info/10 px-2 py-0.5 text-xs font-medium text-info">
                                {{ plan.ai_monthly_replies === null ? 'Sin límite' : `${plan.ai_monthly_replies} resp/mes` }}
                            </span>
                            <span v-else class="ml-auto text-xs text-slate-400">No incluida</span>
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
        <Dialog :open="showForm" @close="showForm = false">
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="submit">
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="Layers" class="h-5 w-5 text-primary" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">{{ editing ? `Editar plan ${editing.label}` : 'Nuevo plan' }}</h2>
                            <p class="mt-0.5 text-xs text-slate-500">Los límites vacíos significan "sin límite"</p>
                        </div>
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400" @click="showForm = false">
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="max-h-[70vh] space-y-4 overflow-y-auto px-6 py-5">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div v-if="!editing">
                                <label class="mb-1 block text-sm">Clave (interna)</label>
                                <FormInput v-model="form.key" type="text" class="font-mono" placeholder="premium" />
                                <FormHelp v-if="form.errors.key" class="text-danger">{{ form.errors.key }}</FormHelp>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Nombre del plan</label>
                                <FormInput v-model="form.label" type="text" placeholder="Premium" />
                                <FormHelp v-if="form.errors.label" class="text-danger">{{ form.errors.label }}</FormHelp>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Precio mensual (MXN)</label>
                                <div class="relative">
                                    <Lucide icon="DollarSign" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormInput v-model="form.price_monthly" type="number" min="0" class="pl-9" />
                                </div>
                                <FormHelp v-if="form.errors.price_monthly" class="text-danger">{{ form.errors.price_monthly }}</FormHelp>
                                <FormHelp v-else>Informativo por ahora; el cobro automático llega con la fase de facturación.</FormHelp>
                            </div>
                        </div>

                        <div class="border-t border-dashed border-slate-300/70 pt-4">
                            <p class="mb-3 text-xs font-medium uppercase tracking-wide text-slate-400">Límites del plan</p>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <label class="mb-1 block text-sm">Propiedades</label>
                                    <FormInput v-model="form.max_properties" type="number" min="1" placeholder="Sin límite" />
                                    <FormHelp v-if="form.errors.max_properties" class="text-danger">{{ form.errors.max_properties }}</FormHelp>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm">Habitaciones</label>
                                    <FormInput v-model="form.max_rooms" type="number" min="1" placeholder="Sin límite" />
                                    <FormHelp v-if="form.errors.max_rooms" class="text-danger">{{ form.errors.max_rooms }}</FormHelp>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm">Usuarios</label>
                                    <FormInput v-model="form.max_users" type="number" min="1" placeholder="Sin límite" />
                                    <FormHelp v-if="form.errors.max_users" class="text-danger">{{ form.errors.max_users }}</FormHelp>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-dashed border-slate-300/70 pt-4">
                            <p class="mb-3 text-xs font-medium uppercase tracking-wide text-slate-400">Asistente IA</p>
                            <div class="flex items-center gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400">
                                <FormSwitch>
                                    <FormSwitch.Input v-model="form.ai_enabled" type="checkbox" :checked="form.ai_enabled" />
                                </FormSwitch>
                                <span class="text-sm">IA incluida en el plan</span>
                                <div v-if="form.ai_enabled" class="ml-auto flex items-center gap-2">
                                    <span class="text-xs text-slate-500">Respuestas/mes</span>
                                    <FormInput v-model="form.ai_monthly_replies" type="number" min="1" class="!w-28 !py-1.5 text-sm" placeholder="Sin límite" />
                                </div>
                            </div>
                            <FormHelp v-if="form.errors.ai_monthly_replies" class="text-danger">{{ form.errors.ai_monthly_replies }}</FormHelp>
                        </div>

                        <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400">
                            <FormSwitch>
                                <FormSwitch.Input v-model="form.active" type="checkbox" :checked="form.active" />
                            </FormSwitch>
                            <span class="text-sm">Activo (se ofrece al crear hoteles nuevos)</span>
                        </label>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
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
