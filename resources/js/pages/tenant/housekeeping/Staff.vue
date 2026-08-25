<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { reactive, ref } from 'vue';
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
import RazeLayout from '@/layouts/RazeLayout.vue';

interface Housekeeper {
    id: number;
    name: string;
    phone: string | null;
    active: boolean;
    notes: string | null;
    month_count: number;
}

defineProps<{
    housekeepers: Housekeeper[];
    periodLabel: string;
    canManage: boolean;
}>();

const toast = useToasts();
const busy = ref(false);

const modal = ref(false);
const editing = ref<Housekeeper | null>(null);
const form = reactive({ name: '', phone: '', active: true, notes: '' });

function openModal(housekeeper: Housekeeper | null = null) {
    editing.value = housekeeper;
    Object.assign(form, {
        name: housekeeper?.name ?? '',
        phone: housekeeper?.phone ?? '',
        active: housekeeper?.active ?? true,
        notes: housekeeper?.notes ?? '',
    });
    modal.value = true;
}

async function save() {
    if (form.name.trim() === '') {
        toast.error('Falta el nombre', 'Escribe cómo se llama.');
        return;
    }
    busy.value = true;
    try {
        if (editing.value) {
            await axios.patch(`/api/housekeepers/${editing.value.id}`, {
                ...form,
            });
        } else {
            await axios.post('/api/housekeepers', { ...form });
        }
        modal.value = false;
        router.reload({ only: ['housekeepers'] });
        toast.success('Guardado', 'La lista de camaristas quedó actualizada.');
    } catch (e: any) {
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        busy.value = false;
    }
}

async function remove(housekeeper: Housekeeper) {
    busy.value = true;
    try {
        const { data } = await axios.delete(
            `/api/housekeepers/${housekeeper.id}`,
        );
        router.reload({ only: ['housekeepers'] });
        toast.success(
            data.archived ? 'Dada de baja' : 'Eliminada',
            data.message ?? `${housekeeper.name} ya no aparece para asignar.`,
        );
    } catch (e: any) {
        toast.error(
            'No se pudo quitar',
            e.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        busy.value = false;
    }
}

async function toggleActive(housekeeper: Housekeeper) {
    busy.value = true;
    try {
        await axios.patch(`/api/housekeepers/${housekeeper.id}`, {
            name: housekeeper.name,
            phone: housekeeper.phone,
            notes: housekeeper.notes,
            active: !housekeeper.active,
        });
        router.reload({ only: ['housekeepers'] });
    } catch (e: any) {
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Camaristas">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-wrap items-center justify-between gap-4 p-5"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <div
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Users" class="h-7 w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl font-medium">Camaristas</h1>
                        <p class="mt-1 text-sm text-slate-500">
                            El personal que limpia. No entran al sistema: se dan
                            de alta aquí solo para registrar su trabajo.
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        as="a"
                        :href="route('tenant.housekeeping')"
                        variant="outline-secondary"
                        class="min-h-11 rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="ArrowLeft"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Volver
                    </Button>
                    <Button
                        v-if="canManage"
                        variant="primary"
                        class="min-h-11 rounded-[0.5rem] shadow-md shadow-primary/20"
                        @click="openModal()"
                    >
                        <Lucide icon="Plus" class="mr-2 h-4 w-4" />
                        Agregar camarista
                    </Button>
                </div>
            </div>

            <div class="box box--stacked mt-5 p-5">
                <p
                    v-if="housekeepers.length === 0"
                    class="rounded-lg border border-dashed border-slate-300/70 py-8 text-center text-sm text-slate-500 dark:border-darkmode-400"
                >
                    Todavía no hay camaristas dadas de alta. Sin ellas no se
                    puede registrar quién limpió cada habitación.
                </p>

                <div v-else class="flex flex-col gap-2">
                    <div
                        v-for="housekeeper in housekeepers"
                        :key="housekeeper.id"
                        class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                        :class="housekeeper.active ? '' : 'opacity-60'"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Brush" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{
                                    housekeeper.name
                                }}</span>
                                <span
                                    v-if="!housekeeper.active"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400"
                                    >Dada de baja</span
                                >
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                <template v-if="housekeeper.phone">
                                    {{ housekeeper.phone }} ·
                                </template>
                                {{ housekeeper.month_count }} habitación(es) en
                                {{ periodLabel }}
                            </p>
                        </div>
                        <FormSwitch
                            v-if="canManage"
                            title="Solo las activas aparecen al asignar una limpieza"
                        >
                            <FormSwitch.Input
                                :checked="housekeeper.active"
                                type="checkbox"
                                :disabled="busy"
                                @change="toggleActive(housekeeper)"
                            />
                        </FormSwitch>
                        <Button
                            v-if="canManage"
                            variant="outline-secondary"
                            size="sm"
                            class="shrink-0 rounded-[0.5rem] bg-white"
                            @click="openModal(housekeeper)"
                        >
                            <Lucide
                                icon="Settings"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Editar
                        </Button>
                        <button
                            v-if="canManage"
                            type="button"
                            class="shrink-0 rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                            title="Quitar"
                            :disabled="busy"
                            @click="remove(housekeeper)"
                        >
                            <Lucide icon="Trash2" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <Dialog :open="modal" @close="modal = false">
            <Dialog.Panel>
                <Dialog.Title>
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Brush" class="h-5 w-5" />
                    </div>
                    <h2 class="ml-3 text-base font-medium">
                        {{ editing ? 'Editar camarista' : 'Agregar camarista' }}
                    </h2>
                </Dialog.Title>
                <Dialog.Description>
                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-sm">Nombre</label>
                            <FormInput
                                v-model="form.name"
                                type="text"
                                maxlength="80"
                                placeholder="Como la conoce el equipo"
                                @keyup.enter="save"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">
                                Teléfono (opcional)
                            </label>
                            <FormInput
                                v-model="form.phone"
                                type="tel"
                                placeholder="Para localizarla"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">
                                Notas (opcional)
                            </label>
                            <FormTextarea
                                v-model="form.notes"
                                rows="2"
                                placeholder="Turno habitual, piso asignado..."
                            />
                        </div>
                        <FormSwitch>
                            <FormSwitch.Input
                                v-model="form.active"
                                type="checkbox"
                            />
                            <FormSwitch.Label class="ml-2 text-sm">
                                Activa
                                <span class="block text-xs text-slate-500">
                                    Solo las activas aparecen al asignar una
                                    limpieza.
                                </span>
                            </FormSwitch.Label>
                        </FormSwitch>
                        <FormHelp>
                            No se le crea cuenta ni contraseña: no consume
                            usuarios de tu plan.
                        </FormHelp>
                    </div>
                </Dialog.Description>
                <Dialog.Footer>
                    <Button
                        variant="outline-secondary"
                        class="mr-2 w-24"
                        @click="modal = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        class="w-28"
                        :disabled="busy"
                        @click="save"
                    >
                        {{ busy ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </Dialog.Footer>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
