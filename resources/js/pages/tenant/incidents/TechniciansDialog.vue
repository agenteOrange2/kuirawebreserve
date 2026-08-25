<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSwitch,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import type { TechnicianRow } from './types';

const props = defineProps<{
    open: boolean;
    technicians: TechnicianRow[];
    canManage: boolean;
}>();
const emit = defineEmits<{ (e: 'close'): void }>();

const toast = useToasts();
const busy = ref(false);

// Alta y edición viven en el mismo modal (segunda vista, no un modal
// encima de otro): apilarlos deja al de abajo capturando clics.
const view = ref<'list' | 'form'>('list');
const editing = ref<TechnicianRow | null>(null);
const removing = ref<TechnicianRow | null>(null);
const form = reactive({
    name: '',
    phone: '',
    specialty: '',
    external: false,
    active: true,
    notes: '',
});

watch(
    () => props.open,
    (open) => {
        if (open) {
            view.value = 'list';
            editing.value = null;
            removing.value = null;
        }
    },
);

const ordered = computed(() =>
    [...props.technicians].sort(
        (a, b) => Number(a.external) - Number(b.external),
    ),
);

function openForm(technician: TechnicianRow | null = null) {
    editing.value = technician;
    Object.assign(form, {
        name: technician?.name ?? '',
        phone: technician?.phone ?? '',
        specialty: technician?.specialty ?? '',
        external: technician?.external ?? false,
        active: technician?.active ?? true,
        notes: technician?.notes ?? '',
    });
    view.value = 'form';
}

async function save() {
    if (form.name.trim() === '') {
        toast.error('Falta el nombre', 'Escribe cómo se llama o el taller.');
        return;
    }
    busy.value = true;
    try {
        if (editing.value) {
            await axios.patch(`/api/technicians/${editing.value.id}`, {
                ...form,
            });
        } else {
            await axios.post('/api/technicians', { ...form });
        }
        router.reload({ only: ['technicians'] });
        view.value = 'list';
        toast.success('Guardado', 'La lista de quién repara quedó al día.');
    } catch (e: any) {
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        busy.value = false;
    }
}

async function confirmRemove() {
    if (!removing.value) return;
    busy.value = true;
    try {
        const { data } = await axios.delete(
            `/api/technicians/${removing.value.id}`,
        );
        router.reload({ only: ['technicians'] });
        toast.success(
            data.archived ? 'Dado de baja' : 'Eliminado',
            data.message ?? `${removing.value.name} ya no aparece al resolver.`,
        );
        removing.value = null;
    } catch (e: any) {
        toast.error(
            'No se pudo quitar',
            e.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" size="lg" @close="emit('close')">
        <Dialog.Panel>
            <!-- Listado -->
            <template v-if="view === 'list' && removing === null">
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 p-5 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Wrench" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-base font-medium">Quién repara</h2>
                        <p class="text-xs text-slate-500">
                            Personal de casa y proveedores externos. No entran
                            al sistema: existen para registrar quién arregló
                            cada falla y cuánto cobró.
                        </p>
                    </div>
                </div>

                <div class="max-h-96 space-y-2.5 overflow-auto p-5">
                    <div
                        v-for="technician in ordered"
                        :key="technician.id"
                        class="flex items-center gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                            :class="
                                technician.external
                                    ? 'bg-info/10 text-info'
                                    : 'bg-primary/10 text-primary'
                            "
                        >
                            <Lucide
                                :icon="
                                    technician.external ? 'Truck' : 'HardHat'
                                "
                                class="h-4 w-4"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">
                                {{ technician.name }}
                            </div>
                            <div
                                class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-500"
                            >
                                <span>{{ technician.kind_label }}</span>
                                <span v-if="technician.specialty">
                                    · {{ technician.specialty }}
                                </span>
                                <span v-if="technician.phone">
                                    · {{ technician.phone }}
                                </span>
                            </div>
                        </div>
                        <div v-if="canManage" class="flex shrink-0 gap-2">
                            <button
                                type="button"
                                class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200/70 text-slate-500 dark:border-darkmode-400"
                                title="Editar"
                                @click="openForm(technician)"
                            >
                                <Lucide icon="Pencil" class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200/70 text-danger dark:border-darkmode-400"
                                title="Quitar"
                                @click="removing = technician"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="!ordered.length"
                        class="rounded-lg border border-dashed border-slate-300/70 p-6 text-center dark:border-darkmode-400"
                    >
                        <Lucide
                            icon="Wrench"
                            class="mx-auto h-8 w-8 text-slate-300"
                        />
                        <p class="mt-2 text-sm font-medium text-slate-600">
                            Nadie dado de alta
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Agrega al de mantenimiento de la casa y a los
                            plomeros o electricistas a los que se les habla.
                        </p>
                    </div>
                </div>

                <div
                    class="flex justify-end gap-2 border-t border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <Button
                        variant="outline-secondary"
                        class="min-h-11"
                        @click="emit('close')"
                        >Cerrar</Button
                    >
                    <Button
                        v-if="canManage"
                        variant="primary"
                        class="min-h-11"
                        @click="openForm()"
                    >
                        <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Agregar
                    </Button>
                </div>
            </template>

            <!-- Alta / edición -->
            <template v-else-if="view === 'form' && removing === null">
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 p-5 dark:border-darkmode-400"
                >
                    <button
                        type="button"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200/70 text-slate-500 dark:border-darkmode-400"
                        title="Volver"
                        @click="view = 'list'"
                    >
                        <Lucide icon="ArrowLeft" class="h-4 w-4" />
                    </button>
                    <h2 class="text-base font-medium">
                        {{ editing ? 'Editar' : 'Agregar a quien repara' }}
                    </h2>
                </div>

                <div class="grid grid-cols-12 gap-5 p-5">
                    <div class="col-span-12 sm:col-span-6">
                        <label class="mb-1 block text-sm">Nombre</label>
                        <FormInput
                            v-model="form.name"
                            placeholder="Don Chuy / Plomería del Valle"
                        />
                    </div>
                    <div class="col-span-12 sm:col-span-6">
                        <label class="mb-1 block text-sm">Teléfono</label>
                        <FormInput
                            v-model="form.phone"
                            placeholder="55 1234 5678"
                        />
                    </div>
                    <div class="col-span-12 sm:col-span-6">
                        <label class="mb-1 block text-sm">Especialidad</label>
                        <FormInput
                            v-model="form.specialty"
                            placeholder="Plomería, aire, electricidad…"
                        />
                    </div>
                    <div class="col-span-12 sm:col-span-6">
                        <label class="mb-1 block text-sm">Notas</label>
                        <FormTextarea
                            v-model="form.notes"
                            rows="2"
                            placeholder="Cobra visita, solo viene por la mañana…"
                        />
                    </div>
                    <div class="col-span-12 grid gap-3 sm:grid-cols-2">
                        <div
                            class="flex items-center justify-between rounded-lg border border-dashed border-slate-300/70 px-3 py-2.5 dark:border-darkmode-400"
                        >
                            <div class="pr-3">
                                <span class="text-sm">Proveedor externo</span>
                                <FormHelp class="mt-0">
                                    Se le paga por trabajo, no es de la casa.
                                </FormHelp>
                            </div>
                            <FormSwitch>
                                <FormSwitch.Input
                                    :checked="form.external"
                                    type="checkbox"
                                    @change="form.external = !form.external"
                                />
                            </FormSwitch>
                        </div>
                        <div
                            class="flex items-center justify-between rounded-lg border border-dashed border-slate-300/70 px-3 py-2.5 dark:border-darkmode-400"
                        >
                            <div class="pr-3">
                                <span class="text-sm">Disponible</span>
                                <FormHelp class="mt-0">
                                    Apagado deja de aparecer al resolver.
                                </FormHelp>
                            </div>
                            <FormSwitch>
                                <FormSwitch.Input
                                    :checked="form.active"
                                    type="checkbox"
                                    @change="form.active = !form.active"
                                />
                            </FormSwitch>
                        </div>
                    </div>
                </div>

                <div
                    class="flex justify-end gap-2 border-t border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <Button
                        variant="outline-secondary"
                        class="min-h-11"
                        @click="view = 'list'"
                        >Cancelar</Button
                    >
                    <Button
                        variant="primary"
                        class="min-h-11"
                        :disabled="busy"
                        @click="save"
                    >
                        {{ busy ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </div>
            </template>

            <!-- Confirmar baja -->
            <template v-else>
                <div class="p-5 text-center">
                    <Lucide
                        icon="AlertTriangle"
                        class="mx-auto mb-3 h-12 w-12 text-danger"
                    />
                    <h2 class="text-base font-medium">
                        ¿Quitar a {{ removing?.name }}?
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Si ya tiene reparaciones registradas se da de baja sin
                        borrar su historial, para que los reportes de periodos
                        pasados sigan cuadrando.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            class="min-h-11"
                            @click="removing = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="min-h-11"
                            :disabled="busy"
                            @click="confirmRemove"
                            >Sí, quitar</Button
                        >
                    </div>
                </div>
            </template>
        </Dialog.Panel>
    </Dialog>
</template>
