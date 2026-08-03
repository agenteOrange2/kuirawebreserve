<script setup lang="ts">
import axios from 'axios';
import { ref } from 'vue';
import Button from '@/components/Base/Button';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface EntryRow {
    id: number;
    guest_name: string;
    guest_phone: string | null;
    guest_email: string | null;
    room_type: string | null;
    starts_at: string;
    ends_at: string;
    status: string;
    status_label: string;
    notified_at: string | null;
    created_at: string;
}

const props = defineProps<{
    entries: EntryRow[];
    canManage: boolean;
}>();

const toast = useToasts();
const entries = ref<EntryRow[]>([...props.entries]);

const statusClass: Record<string, string> = {
    waiting: 'bg-warning/10 text-warning',
    notified: 'bg-primary/10 text-primary',
    converted: 'bg-success/10 text-success',
    expired: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
};

async function markConverted(entry: EntryRow) {
    try {
        const { data } = await axios.patch(
            `/api/waitlist-entries/${entry.id}/convert`,
        );
        entries.value = entries.value.map((e) =>
            e.id === entry.id
                ? { ...e, status: data.status, status_label: data.status_label }
                : e,
        );
        toast.success(
            'Marcada como convertida',
            `${entry.guest_name} ya tiene su reserva.`,
        );
    } catch (e: any) {
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ?? 'Ocurrió un error inesperado.',
        );
    }
}

const deleting = ref<EntryRow | null>(null);

async function destroy() {
    if (!deleting.value) return;
    try {
        await axios.delete(`/api/waitlist-entries/${deleting.value.id}`);
        entries.value = entries.value.filter(
            (e) => e.id !== deleting.value!.id,
        );
        toast.success('Entrada eliminada');
        deleting.value = null;
    } catch (e: any) {
        toast.error(
            'No se pudo eliminar',
            e.response?.data?.message ?? 'Ocurrió un error inesperado.',
        );
    }
}
</script>

<template>
    <RazeLayout title="Lista de espera">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                    >
                        <Lucide icon="BellRing" class="h-5 w-5 sm:h-7 sm:w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-lg font-medium sm:text-xl">
                            Lista de espera
                        </h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Interesados que no encontraron disponibilidad en el
                            wizard y dejaron su contacto. Cuando una
                            cancelación libera sus fechas, se les avisa solo
                            por WhatsApp o correo.
                        </p>
                    </div>
                </div>
            </div>

            <div class="box box--stacked mt-5">
                <template v-if="entries.length">
                    <!-- Móvil: tarjetas apiladas (patrón rooms/Index.vue) -->
                    <div class="space-y-2.5 p-5 sm:hidden">
                        <div
                            v-for="entry in entries"
                            :key="`card-${entry.id}`"
                            class="rounded-lg border border-slate-200/70 bg-white p-3.5 dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <div class="min-w-0 truncate font-medium">
                                    {{ entry.guest_name }}
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        statusClass[entry.status] ??
                                        'bg-slate-100 text-slate-500'
                                    "
                                >
                                    {{ entry.status_label }}
                                </span>
                            </div>
                            <div
                                class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                            >
                                <span
                                    >{{ entry.starts_at }} →
                                    {{ entry.ends_at }}</span
                                >
                                <span>{{
                                    entry.room_type ?? 'Cualquier tipo'
                                }}</span>
                            </div>
                            <div
                                class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                            >
                                <span v-if="entry.guest_phone">{{
                                    entry.guest_phone
                                }}</span>
                                <span v-if="entry.guest_email">{{
                                    entry.guest_email
                                }}</span>
                            </div>
                            <div
                                v-if="canManage"
                                class="mt-3 flex items-center gap-2 border-t border-dashed border-slate-200/70 pt-2.5 dark:border-darkmode-400"
                            >
                                <button
                                    v-if="entry.status !== 'converted'"
                                    type="button"
                                    class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200/70 text-success dark:border-darkmode-400"
                                    title="Marcar convertida (ya reservó)"
                                    @click="markConverted(entry)"
                                >
                                    <Lucide icon="UserCheck" class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200/70 text-danger dark:border-darkmode-400"
                                    title="Eliminar"
                                    @click="deleting = entry"
                                >
                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Escritorio: tabla -->
                    <div class="hidden overflow-auto p-5 sm:block lg:overflow-visible">
                        <Table>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th class="whitespace-nowrap"
                                        >Interesado</Table.Th
                                    >
                                    <Table.Th class="whitespace-nowrap"
                                        >Fechas</Table.Th
                                    >
                                    <Table.Th class="whitespace-nowrap"
                                        >Tipo</Table.Th
                                    >
                                    <Table.Th class="whitespace-nowrap"
                                        >Estado</Table.Th
                                    >
                                    <Table.Th
                                        class="text-right whitespace-nowrap"
                                        >Acciones</Table.Th
                                    >
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr
                                    v-for="entry in entries"
                                    :key="entry.id"
                                >
                                    <Table.Td>
                                        <div class="font-medium">
                                            {{ entry.guest_name }}
                                        </div>
                                        <div
                                            class="mt-0.5 text-xs text-slate-500"
                                        >
                                            <span v-if="entry.guest_phone">{{
                                                entry.guest_phone
                                            }}</span>
                                            <span
                                                v-if="
                                                    entry.guest_phone &&
                                                    entry.guest_email
                                                "
                                            >
                                                ·
                                            </span>
                                            <span v-if="entry.guest_email">{{
                                                entry.guest_email
                                            }}</span>
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="whitespace-nowrap">
                                        {{ entry.starts_at }} →
                                        {{ entry.ends_at }}
                                    </Table.Td>
                                    <Table.Td>{{
                                        entry.room_type ?? 'Cualquiera'
                                    }}</Table.Td>
                                    <Table.Td>
                                        <span
                                            class="rounded-full px-2.5 py-1 text-xs font-medium"
                                            :class="
                                                statusClass[entry.status] ??
                                                'bg-slate-100 text-slate-500'
                                            "
                                        >
                                            {{ entry.status_label }}
                                        </span>
                                        <div
                                            v-if="entry.notified_at"
                                            class="mt-0.5 text-xs text-slate-400"
                                        >
                                            Avisado {{ entry.notified_at }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td>
                                        <div
                                            v-if="canManage"
                                            class="flex items-center justify-end gap-3"
                                        >
                                            <a
                                                v-if="
                                                    entry.status !== 'converted'
                                                "
                                                href="#"
                                                class="flex items-center gap-1 text-success"
                                                title="Marcar convertida (ya reservó)"
                                                @click.prevent="
                                                    markConverted(entry)
                                                "
                                            >
                                                <Lucide
                                                    icon="UserCheck"
                                                    class="h-4 w-4"
                                                />
                                            </a>
                                            <a
                                                href="#"
                                                class="flex items-center text-danger"
                                                title="Eliminar"
                                                @click.prevent="
                                                    deleting = entry
                                                "
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
                </template>
                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <Lucide icon="BellRing" class="h-10 w-10 text-slate-300" />
                    <div>
                        <p class="text-sm font-medium text-slate-600">
                            Nadie está en espera por ahora
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cuando el wizard no tenga disponibilidad, ofrecerá
                            al huésped dejar su contacto y aparecerá aquí.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Confirmar eliminación -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide
                        icon="AlertTriangle"
                        class="mx-auto mb-3 h-12 w-12 text-danger"
                    />
                    <h2 class="text-base font-medium">
                        ¿Eliminar a "{{ deleting?.guest_name }}"?
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Se borra su entrada de la lista de espera; ya no
                        recibirá avisos.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            class="min-h-11"
                            @click="deleting = null"
                            >Cancelar</Button
                        >
                        <Button variant="danger" class="min-h-11" @click="destroy"
                            >Sí, eliminar</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
