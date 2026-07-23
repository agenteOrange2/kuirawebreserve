<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormCheck, FormInput } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import GuestFormModal from './GuestFormModal.vue';

interface GuestRow {
    id: number;
    full_name: string;
    phone: string | null;
    email: string | null;
    visits: number;
    is_blacklisted: boolean;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    guests: { data: GuestRow[]; links: PaginationLink[]; total: number };
    filters: { q: string; blacklisted: boolean };
    canManage: boolean;
    canViewDocuments: boolean;
    documentTypes: string[];
}>();

const toast = useToasts();
const q = ref(props.filters.q);
const blacklisted = ref(props.filters.blacklisted);

let timer: ReturnType<typeof setTimeout> | null = null;
watch([q, blacklisted], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            route('tenant.guests'),
            {
                q: q.value || undefined,
                blacklisted: blacklisted.value || undefined,
            },
            { preserveState: true, replace: true, only: ['guests', 'filters'] },
        );
    }, 350);
});

const showCreate = ref(false);

function onSaved(id: number) {
    showCreate.value = false;
    router.visit(route('tenant.guests.show', id));
}

// Eliminar (con confirmación; el backend bloquea si hay historial).
const deleting = ref<GuestRow | null>(null);
const deleteBusy = ref(false);
const deleteError = ref<string | null>(null);

function askDelete(g: GuestRow) {
    deleteError.value = null;
    deleting.value = g;
}

async function submitDelete() {
    if (!deleting.value) return;
    deleteBusy.value = true;
    deleteError.value = null;
    try {
        await axios.delete(`/api/guests/${deleting.value.id}`);
        deleting.value = null;
        router.reload({ only: ['guests'] });
    } catch (error: any) {
        deleteError.value =
            error.response?.data?.message ?? 'No se pudo eliminar el huésped.';
    } finally {
        deleteBusy.value = false;
    }
}

// ── Selección múltiple (opera sobre la página visible; el backend
// conserva los que tienen historial de reservas) ──
const selectedIds = ref<number[]>([]);
const bulkDeleteOpen = ref(false);
const bulkDeleting = ref(false);

const allSelected = computed(
    () =>
        props.guests.data.length > 0 &&
        props.guests.data.every((g) => selectedIds.value.includes(g.id)),
);
const selectedRows = computed(() =>
    props.guests.data.filter((g) => selectedIds.value.includes(g.id)),
);

function toggleRow(id: number) {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((x) => x !== id)
        : [...selectedIds.value, id];
}
function toggleAll() {
    selectedIds.value = allSelected.value
        ? []
        : props.guests.data.map((g) => g.id);
}

async function bulkDelete() {
    bulkDeleting.value = true;
    try {
        const { data } = await axios.delete('/api/guests', {
            data: { ids: selectedIds.value },
        });
        toast.success(
            'Huéspedes eliminados',
            `${data.deleted} eliminado(s)` +
                (data.skipped
                    ? ` · ${data.skipped} conservado(s) por historial de reservas`
                    : ''),
        );
        selectedIds.value = [];
        bulkDeleteOpen.value = false;
        router.reload({ only: ['guests'] });
    } catch (error: any) {
        toast.error(
            'No se pudo eliminar',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        bulkDeleting.value = false;
    }
}

const initials = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('') || '?';

const cellClass =
    'box shadow-[5px_3px_5px_#00000005] first:border-l last:border-r first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] rounded-l-none rounded-r-none border-x-0 dark:bg-darkmode-600';
</script>

<template>
    <RazeLayout title="Huéspedes">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Huéspedes</h1>
                    <p class="text-sm text-slate-500">
                        {{ guests.total }} registrado(s) en el directorio
                    </p>
                </div>
                <Button
                    v-if="canManage"
                    variant="primary"
                    class="rounded-[0.5rem] shadow-md shadow-primary/20"
                    @click="showCreate = true"
                >
                    <Lucide icon="UserPlus" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    Nuevo huésped
                </Button>
            </div>

            <!-- Toolbar -->
            <div
                class="box box--stacked mt-5 flex flex-wrap items-center gap-3 p-3"
            >
                <div class="relative flex-1 sm:max-w-xs">
                    <Lucide
                        icon="Search"
                        class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                    />
                    <FormInput
                        v-model="q"
                        type="text"
                        placeholder="Buscar por nombre, teléfono o email…"
                        class="pl-9"
                    />
                </div>
                <label
                    class="flex cursor-pointer items-center gap-2 rounded-[0.5rem] border px-3 py-2 text-sm transition"
                    :class="
                        blacklisted
                            ? 'border-danger/30 bg-danger/5 text-danger'
                            : 'border-slate-200/70 text-slate-500 hover:bg-slate-50 dark:border-darkmode-400'
                    "
                >
                    <FormCheck.Input
                        id="f-blacklist"
                        v-model="blacklisted"
                        type="checkbox"
                        class="!mt-0"
                    />
                    <Lucide icon="ShieldAlert" class="h-4 w-4" /> Solo lista
                    negra
                </label>
                <template v-if="canManage && selectedIds.length">
                    <span class="ml-auto text-xs text-slate-500"
                        >{{ selectedIds.length }} seleccionado(s)</span
                    >
                    <button
                        type="button"
                        class="text-xs font-medium text-primary hover:underline"
                        @click="selectedIds = []"
                    >
                        Quitar selección
                    </button>
                    <Button
                        variant="danger"
                        class="rounded-[0.5rem] !px-3 !py-1.5 text-xs"
                        @click="bulkDeleteOpen = true"
                    >
                        <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                        Eliminar seleccionados
                    </Button>
                </template>
            </div>

            <div class="mt-5">
                <div class="overflow-auto lg:overflow-visible">
                    <Table
                        v-if="guests.data.length"
                        class="border-separate border-spacing-y-[8px]"
                    >
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th
                                    v-if="canManage"
                                    class="w-10 border-b-0 !bg-transparent"
                                >
                                    <FormCheck.Input
                                        type="checkbox"
                                        :checked="allSelected"
                                        title="Seleccionar esta página"
                                        @change="toggleAll"
                                    />
                                </Table.Th>
                                <Table.Th class="border-b-0 !bg-transparent"
                                    >Huésped</Table.Th
                                >
                                <Table.Th class="border-b-0 !bg-transparent"
                                    >Contacto</Table.Th
                                >
                                <Table.Th class="border-b-0 !bg-transparent"
                                    >Visitas</Table.Th
                                >
                                <Table.Th class="border-b-0 !bg-transparent"
                                    >Alta</Table.Th
                                >
                                <Table.Th
                                    class="border-b-0 !bg-transparent text-right"
                                    >Acciones</Table.Th
                                >
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="g in guests.data" :key="g.id">
                                <Table.Td
                                    v-if="canManage"
                                    :class="cellClass"
                                    class="w-10"
                                >
                                    <FormCheck.Input
                                        type="checkbox"
                                        :checked="selectedIds.includes(g.id)"
                                        @change="toggleRow(g.id)"
                                    />
                                </Table.Td>
                                <Table.Td :class="cellClass">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-xs font-semibold text-white"
                                        >
                                            {{ initials(g.full_name) }}
                                        </div>
                                        <div class="min-w-0">
                                            <Link
                                                :href="
                                                    route(
                                                        'tenant.guests.show',
                                                        g.id,
                                                    )
                                                "
                                                class="font-medium text-primary hover:underline"
                                                >{{ g.full_name }}</Link
                                            >
                                            <span
                                                v-if="g.is_blacklisted"
                                                class="ml-1.5 rounded-full bg-danger/10 px-2 py-0.5 text-xs font-medium text-danger"
                                                >Lista negra</span
                                            >
                                        </div>
                                    </div>
                                </Table.Td>
                                <Table.Td
                                    :class="cellClass"
                                    class="text-sm text-slate-500"
                                >
                                    <div
                                        v-if="g.phone"
                                        class="flex items-center gap-1.5 whitespace-nowrap"
                                    >
                                        <Lucide
                                            icon="Phone"
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ g.phone }}
                                    </div>
                                    <div
                                        v-if="g.email"
                                        class="flex items-center gap-1.5 whitespace-nowrap"
                                    >
                                        <Lucide
                                            icon="Mail"
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ g.email }}
                                    </div>
                                    <span
                                        v-if="!g.phone && !g.email"
                                        class="text-slate-400"
                                        >—</span
                                    >
                                </Table.Td>
                                <Table.Td :class="cellClass">
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            g.visits > 0
                                                ? 'bg-success/10 text-success'
                                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                        "
                                    >
                                        <Lucide
                                            icon="BedDouble"
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ g.visits }}
                                    </span>
                                </Table.Td>
                                <Table.Td
                                    :class="cellClass"
                                    class="text-sm whitespace-nowrap text-slate-500"
                                    >{{ g.created_at }}</Table.Td
                                >
                                <Table.Td :class="cellClass" class="text-right">
                                    <div
                                        class="flex items-center justify-end gap-2"
                                    >
                                        <Link
                                            :href="
                                                route(
                                                    'tenant.guests.show',
                                                    g.id,
                                                )
                                            "
                                            title="Ver perfil"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-primary dark:hover:bg-darkmode-400"
                                        >
                                            <Lucide
                                                icon="Eye"
                                                class="h-4 w-4"
                                            />
                                        </Link>
                                        <Link
                                            v-if="canManage"
                                            :href="`${route('tenant.guests.show', g.id)}?edit=1`"
                                            title="Editar"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                        >
                                            <Lucide
                                                icon="Pencil"
                                                class="h-4 w-4"
                                            />
                                        </Link>
                                        <button
                                            v-if="canManage"
                                            type="button"
                                            title="Eliminar"
                                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                            @click="askDelete(g)"
                                        >
                                            <Lucide
                                                icon="Trash2"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div
                        v-else
                        class="box box--stacked flex flex-col items-center gap-3 py-12 text-center"
                    >
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="Users" class="h-6 w-6" />
                        </div>
                        <p class="text-sm text-slate-500">
                            {{
                                filters.q
                                    ? 'Sin resultados para tu búsqueda.'
                                    : 'Aún no hay huéspedes; se crean solos al reservar, o da de alta uno.'
                            }}
                        </p>
                        <Button
                            v-if="canManage && !filters.q"
                            variant="outline-primary"
                            size="sm"
                            class="rounded-[0.5rem]"
                            @click="showCreate = true"
                        >
                            <Lucide icon="UserPlus" class="mr-1.5 h-4 w-4" />
                            Nuevo huésped
                        </Button>
                    </div>

                    <!-- Paginación -->
                    <div
                        v-if="guests.links.length > 3"
                        class="mt-4 flex flex-wrap justify-center gap-1"
                    >
                        <template v-for="(link, i) in guests.links" :key="i">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-state
                                class="rounded-md px-3 py-1.5 text-sm"
                                :class="
                                    link.active
                                        ? 'bg-primary text-white'
                                        : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400'
                                "
                            >
                                <span v-html="link.label" />
                            </Link>
                            <span
                                v-else
                                class="px-3 py-1.5 text-sm text-slate-400"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal alta de huésped -->
        <GuestFormModal
            :open="showCreate"
            :document-types="documentTypes"
            :can-view-documents="canViewDocuments"
            @close="showCreate = false"
            @saved="onSaved"
        />

        <!-- Modal eliminar -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div v-if="deleting" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                ¿Eliminar a {{ deleting.full_name }}?
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Se borran su ficha, fotos de INE y vehículo.
                                Esta acción no se puede deshacer.
                            </p>
                        </div>
                    </div>
                    <div
                        class="mt-4 flex items-center gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                    >
                        <Lucide icon="Info" class="h-4 w-4 shrink-0" /> Si el
                        huésped tiene reservas o estancias, no podrá eliminarse
                        (se conserva su historial).
                    </div>
                    <p
                        v-if="deleteError"
                        class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                    >
                        {{ deleteError }}
                    </p>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="deleting = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="deleteBusy"
                            @click="submitDelete"
                        >
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" />
                            {{ deleteBusy ? 'Eliminando…' : 'Sí, eliminar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmar borrado masivo -->
        <Dialog :open="bulkDeleteOpen" @close="bulkDeleteOpen = false">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="mb-3 flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full border border-danger/10 bg-danger/10"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5 text-danger" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                Eliminar {{ selectedRows.length }} huésped(es)
                            </h2>
                            <p class="text-xs text-slate-500">
                                Los que tengan historial de reservas o estancias
                                se conservan (rastro) y se te informa.
                            </p>
                        </div>
                    </div>
                    <div
                        class="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-dashed border-slate-300/70 p-2 text-sm dark:border-darkmode-400"
                    >
                        <div
                            v-for="row in selectedRows"
                            :key="row.id"
                            class="flex items-center justify-between gap-2 px-1"
                        >
                            <span class="font-medium">{{ row.full_name }}</span>
                            <span class="text-xs text-slate-500">{{
                                row.phone ?? row.email ?? ''
                            }}</span>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="bulkDeleteOpen = false"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="bulkDeleting"
                            @click="bulkDelete"
                        >
                            {{ bulkDeleting ? 'Eliminando…' : 'Sí, eliminar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
