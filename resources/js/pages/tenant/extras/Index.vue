<script setup lang="ts">
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
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ExtraRow {
    id: number;
    name: string;
    description: string | null;
    price: number;
    active: boolean;
    sort_order: number;
}

const props = defineProps<{
    extras: ExtraRow[];
    canManage: boolean;
}>();

const toast = useToasts();
const extras = ref<ExtraRow[]>([...props.extras]);

const money = (n: number) =>
    `$${n.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

// ── Modal crear/editar ──
const showForm = ref(false);
const editing = ref<ExtraRow | null>(null);
const saving = ref(false);
const errors = reactive<Record<string, string>>({});
const form = reactive({
    name: '',
    description: '',
    price: '' as string | number,
    sort_order: 0 as string | number,
    active: true,
});

function openForm(extra: ExtraRow | null = null) {
    editing.value = extra;
    form.name = extra?.name ?? '';
    form.description = extra?.description ?? '';
    form.price = extra?.price ?? '';
    form.sort_order = extra?.sort_order ?? extras.value.length;
    form.active = extra?.active ?? true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    showForm.value = true;
}

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    const payload = {
        name: form.name,
        description: form.description.trim() === '' ? null : form.description,
        price: form.price,
        sort_order: form.sort_order === '' ? 0 : Number(form.sort_order),
        active: form.active,
    };
    try {
        if (editing.value) {
            const { data } = await axios.patch<ExtraRow>(
                `/api/extras/${editing.value.id}`,
                payload,
            );
            extras.value = extras.value.map((e) =>
                e.id === data.id ? { ...data, price: Number(data.price) } : e,
            );
            toast.success(
                'Extra actualizado',
                'Las reservas ya hechas conservan su precio congelado.',
            );
        } else {
            const { data } = await axios.post<ExtraRow>('/api/extras', payload);
            extras.value = [
                ...extras.value,
                { ...data, price: Number(data.price) },
            ];
            toast.success(
                'Extra agregado',
                'El wizard ya puede ofrecerlo en el paso de extras.',
            );
        }
        showForm.value = false;
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(
                ([key, msgs]) => (errors[key] = (msgs as string[])[0]),
            );
        } else {
            toast.error('Error', data?.message ?? 'No se pudo guardar.');
        }
    } finally {
        saving.value = false;
    }
}

async function toggleActive(extra: ExtraRow) {
    try {
        const { data } = await axios.patch<ExtraRow>(
            `/api/extras/${extra.id}`,
            { active: !extra.active },
        );
        extras.value = extras.value.map((e) =>
            e.id === data.id ? { ...e, active: data.active } : e,
        );
    } catch {
        toast.error('Error', 'No se pudo cambiar el estado.');
    }
}

// ── Eliminar ──
const deleting = ref<ExtraRow | null>(null);

async function destroy() {
    if (!deleting.value) return;
    try {
        await axios.delete(`/api/extras/${deleting.value.id}`);
        extras.value = extras.value.filter((e) => e.id !== deleting.value!.id);
        toast.success(
            'Extra eliminado',
            'Las reservas existentes conservan sus líneas congeladas.',
        );
        deleting.value = null;
    } catch {
        toast.error('Error', 'No se pudo eliminar.');
    }
}
</script>

<template>
    <RazeLayout title="Extras de reserva">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Extras de reserva</h1>
                    <p class="mt-0.5 text-sm text-slate-500">
                        Add-ons que el huésped agrega a su reserva y suman al
                        total: decoración, desayuno, late checkout. El anticipo
                        y el saldo los incluyen solos.
                    </p>
                </div>
                <Button
                    v-if="canManage"
                    variant="primary"
                    class="rounded-[0.5rem] shadow-md shadow-primary/20"
                    @click="openForm()"
                >
                    <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Nuevo extra
                </Button>
            </div>

            <div class="box box--stacked mt-5">
                <div
                    v-if="extras.length"
                    class="overflow-auto p-5 lg:overflow-visible"
                >
                    <Table>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="whitespace-nowrap"
                                    >Extra</Table.Th
                                >
                                <Table.Th class="whitespace-nowrap"
                                    >Precio</Table.Th
                                >
                                <Table.Th class="whitespace-nowrap"
                                    >Visible en el wizard</Table.Th
                                >
                                <Table.Th class="text-right whitespace-nowrap"
                                    >Acciones</Table.Th
                                >
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="extra in extras" :key="extra.id">
                                <Table.Td>
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                                        >
                                            <Lucide
                                                icon="Gift"
                                                class="h-4 w-4 text-primary"
                                            />
                                        </div>
                                        <div class="min-w-0">
                                            <div
                                                class="font-medium"
                                                :class="{
                                                    'text-slate-400':
                                                        !extra.active,
                                                }"
                                            >
                                                {{ extra.name }}
                                            </div>
                                            <div
                                                v-if="extra.description"
                                                class="mt-0.5 max-w-md truncate text-xs text-slate-500"
                                            >
                                                {{ extra.description }}
                                            </div>
                                        </div>
                                    </div>
                                </Table.Td>
                                <Table.Td class="font-medium">{{
                                    money(extra.price)
                                }}</Table.Td>
                                <Table.Td>
                                    <FormSwitch
                                        v-if="canManage"
                                        title="Solo los extras activos se ofrecen al huésped"
                                    >
                                        <FormSwitch.Input
                                            :checked="extra.active"
                                            type="checkbox"
                                            @change="toggleActive(extra)"
                                        />
                                    </FormSwitch>
                                    <span
                                        v-else
                                        class="text-xs"
                                        :class="
                                            extra.active
                                                ? 'text-success'
                                                : 'text-slate-400'
                                        "
                                    >
                                        {{
                                            extra.active ? 'Activo' : 'Pausado'
                                        }}
                                    </span>
                                </Table.Td>
                                <Table.Td>
                                    <div
                                        v-if="canManage"
                                        class="flex items-center justify-end gap-3"
                                    >
                                        <a
                                            href="#"
                                            class="flex items-center text-primary"
                                            title="Editar"
                                            @click.prevent="openForm(extra)"
                                        >
                                            <Lucide
                                                icon="Pencil"
                                                class="h-4 w-4"
                                            />
                                        </a>
                                        <a
                                            href="#"
                                            class="flex items-center text-danger"
                                            title="Eliminar"
                                            @click.prevent="deleting = extra"
                                        >
                                            <Lucide
                                                icon="Trash2"
                                                class="h-4 w-4"
                                            />
                                        </a>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>
                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <Lucide icon="Gift" class="h-10 w-10 text-slate-300" />
                    <div>
                        <p class="text-sm font-medium text-slate-600">
                            Aún no tienes extras
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Crea tu primer add-on — decoración romántica,
                            desayuno a la habitación, late checkout — y el
                            wizard lo ofrecerá al reservar.
                        </p>
                    </div>
                    <Button
                        v-if="canManage"
                        variant="primary"
                        class="rounded-[0.5rem]"
                        @click="openForm()"
                    >
                        <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Nuevo extra
                    </Button>
                </div>
            </div>
        </div>

        <!-- Modal crear/editar -->
        <Dialog :open="showForm" @close="showForm = false">
            <Dialog.Panel>
                <form class="p-5" @submit.prevent="submit">
                    <div class="mb-4 flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide icon="Gift" class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                {{
                                    editing
                                        ? `Editar ${editing.name}`
                                        : 'Nuevo extra'
                                }}
                            </h2>
                            <p class="text-xs text-slate-500">
                                El precio se congela en cada reserva al momento
                                de crearla.
                            </p>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm">Nombre</label>
                            <FormInput
                                v-model="form.name"
                                type="text"
                                placeholder="Decoración romántica"
                            />
                            <FormHelp v-if="errors.name" class="text-danger">{{
                                errors.name
                            }}</FormHelp>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm"
                                >Descripción (opcional)</label
                            >
                            <FormTextarea
                                v-model="form.description"
                                rows="2"
                                placeholder="Pétalos, globos y botella de espumoso a la llegada…"
                            />
                            <FormHelp
                                v-if="errors.description"
                                class="text-danger"
                                >{{ errors.description }}</FormHelp
                            >
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Precio ($)</label
                                >
                                <FormInput
                                    v-model="form.price"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    placeholder="350.00"
                                />
                                <FormHelp
                                    v-if="errors.price"
                                    class="text-danger"
                                    >{{ errors.price }}</FormHelp
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Orden</label>
                                <FormInput
                                    v-model.number="form.sort_order"
                                    type="number"
                                    min="0"
                                />
                            </div>
                        </div>
                        <div
                            class="flex items-center justify-between rounded-lg border border-dashed border-slate-300/70 px-3 py-2.5 dark:border-darkmode-400"
                        >
                            <span class="text-sm">Visible en el wizard</span>
                            <FormSwitch>
                                <FormSwitch.Input
                                    :checked="form.active"
                                    type="checkbox"
                                    @change="form.active = !form.active"
                                />
                            </FormSwitch>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="showForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            :disabled="saving"
                        >
                            {{
                                saving
                                    ? 'Guardando…'
                                    : editing
                                      ? 'Guardar cambios'
                                      : 'Agregar extra'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmar eliminación -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide
                        icon="AlertTriangle"
                        class="mx-auto mb-3 h-12 w-12 text-danger"
                    />
                    <h2 class="text-base font-medium">
                        ¿Eliminar "{{ deleting?.name }}"?
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Las reservas que ya lo incluyen conservan su línea
                        congelada. Si solo quieres dejar de ofrecerlo, usa el
                        switch.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="deleting = null"
                            >Cancelar</Button
                        >
                        <Button variant="danger" @click="destroy"
                            >Sí, eliminar</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
