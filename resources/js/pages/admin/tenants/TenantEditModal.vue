<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { modeOption, modeOptions } from './modes';
import type { PlanOption, TenantShell } from './types';

// Editar nombre, plan y modo del hotel. Lo abren el listado y la cabecera
// de la ficha: un solo modal para que no se desfasen.
const props = defineProps<{
    tenant: Pick<TenantShell, 'id' | 'name' | 'plan' | 'mode'> | null;
    plans: PlanOption[];
}>();
const emit = defineEmits<{ close: [] }>();

const form = useForm({ name: '', plan: '', mode: 'hotel' as string });

watch(
    () => props.tenant,
    (tenant) => {
        if (!tenant) return;
        form.clearErrors();
        form.name = tenant.name;
        form.plan = tenant.plan;
        form.mode = tenant.mode;
        form.defaults();
    },
    { immediate: true },
);

// Un plan inactivo solo aparece si es el que ya tiene el hotel: no se
// vende, pero no se le quita a quien lo trae.
const selectablePlans = computed(() =>
    props.plans.filter(
        (p) => p.active !== false || p.value === props.tenant?.plan,
    ),
);
const planChanged = computed(
    () => !!props.tenant && form.plan !== props.tenant.plan,
);
const modeChanged = computed(
    () => !!props.tenant && form.mode !== props.tenant.mode,
);

function close() {
    if (form.processing) return;
    emit('close');
}

function submit() {
    if (!props.tenant) return;
    form.put(route('admin.tenants.update', props.tenant.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Dialog :open="tenant !== null" size="lg" @close="close">
        <Dialog.Panel class="sm:w-[94vw] lg:w-[680px]">
            <form
                v-if="tenant"
                class="flex max-h-[calc(100dvh-6rem)] flex-col"
                @submit.prevent="submit"
            >
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Pencil" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-base font-medium">
                            Editar {{ tenant.name }}
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            El subdominio
                            <span class="font-medium">{{ tenant.id }}</span>
                            no cambia: es la llave de su base de datos.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                        title="Cerrar"
                        @click="close"
                    >
                        <Lucide icon="X" class="h-4 w-4" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4">
                    <section>
                        <div
                            class="mb-3 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            El hotel
                        </div>
                        <div class="grid grid-cols-12 gap-4">
                            <div class="col-span-12 sm:col-span-7">
                                <label
                                    for="tenant-edit-name"
                                    class="mb-1.5 block text-xs font-medium"
                                    >Nombre</label
                                >
                                <FormInput
                                    id="tenant-edit-name"
                                    v-model="form.name"
                                    type="text"
                                    maxlength="255"
                                    class="h-9 text-xs"
                                />
                                <FormHelp
                                    v-if="form.errors.name"
                                    class="text-danger"
                                    >{{ form.errors.name }}</FormHelp
                                >
                            </div>
                            <div class="col-span-12 sm:col-span-5">
                                <label
                                    for="tenant-edit-plan"
                                    class="mb-1.5 block text-xs font-medium"
                                    >Plan</label
                                >
                                <FormSelect
                                    id="tenant-edit-plan"
                                    v-model="form.plan"
                                    class="h-9 text-xs"
                                >
                                    <option
                                        v-for="p in selectablePlans"
                                        :key="p.value"
                                        :value="p.value"
                                    >
                                        {{ p.label
                                        }}{{
                                            p.active === false
                                                ? ' (ya no se vende)'
                                                : ''
                                        }}
                                    </option>
                                </FormSelect>
                                <FormHelp
                                    v-if="form.errors.plan"
                                    class="text-danger"
                                    >{{ form.errors.plan }}</FormHelp
                                >
                            </div>
                            <div
                                v-if="planChanged"
                                class="col-span-12 flex items-start gap-2 rounded-lg bg-pending/10 px-3 py-2 text-xs text-pending"
                            >
                                <Lucide
                                    icon="TriangleAlert"
                                    class="mt-px h-3.5 w-3.5 shrink-0"
                                />
                                Cambiar de plan le enciende o apaga módulos y
                                topes al instante, y cambia lo que paga al mes.
                                Los módulos forzados a mano se respetan.
                            </div>
                        </div>
                    </section>

                    <section
                        class="border-t border-dashed border-slate-200/70 pt-5 dark:border-darkmode-400"
                    >
                        <div
                            class="mb-3 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            Modo de operación
                        </div>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <button
                                v-for="option in modeOptions"
                                :key="option.value"
                                type="button"
                                class="flex h-full w-full items-start gap-2.5 rounded-lg border p-3 text-left transition"
                                :class="
                                    form.mode === option.value
                                        ? 'border-primary bg-primary/5'
                                        : 'border-slate-200/70 hover:border-slate-300 dark:border-darkmode-400'
                                "
                                @click="form.mode = option.value"
                            >
                                <Lucide
                                    :icon="option.icon"
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                    :class="
                                        form.mode === option.value
                                            ? 'text-primary'
                                            : 'text-slate-400'
                                    "
                                />
                                <span class="min-w-0">
                                    <span class="block text-xs font-medium">{{
                                        option.label
                                    }}</span>
                                    <span
                                        class="mt-0.5 block text-[11px] leading-snug text-slate-500"
                                        >{{ option.description }}</span
                                    >
                                </span>
                            </button>
                        </div>
                        <FormHelp v-if="form.errors.mode" class="text-danger">{{
                            form.errors.mode
                        }}</FormHelp>
                        <FormHelp v-else-if="modeChanged">
                            Pasa de {{ modeOption(tenant.mode).label }} a
                            {{ modeOption(form.mode).label }}. No toca lo demás
                            que el hotel ya configuró.
                        </FormHelp>
                        <FormHelp v-else>
                            Motel y Ambos encienden el registro exprés del
                            plano. El hotel no lo ve en sus ajustes.
                        </FormHelp>
                    </section>
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        type="button"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        @click="close"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                        :disabled="form.processing || !form.isDirty"
                    >
                        <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                        {{ form.processing ? 'Guardando...' : 'Guardar' }}
                    </Button>
                </div>
            </form>
        </Dialog.Panel>
    </Dialog>
</template>
