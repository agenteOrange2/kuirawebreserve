<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSelect, FormTextarea } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ReservationDetail {
    id: number;
    code: string;
    room_type: string | null;
    room_type_id: number;
    rate_plan_id: number;
    room: string | null;
    adults: number;
    children: number;
    starts_at: string;
    ends_at: string | null;
    total: number;
    status: string;
    status_label: string;
}

interface GroupExperienceRow {
    id: number;
    code: string;
    name: string | null;
    starts_at: string | null;
    people: number;
    total: number;
    status: string;
    status_label: string;
}

interface PaymentRequestRow {
    id: number;
    method: string;
    amount: number;
    amount_label: string;
    status: string;
    checkout_url: string | null;
    expires_at: string | null;
    created_at: string;
}

interface GroupDetail {
    id: number;
    code: string;
    guest_name: string | null;
    guest_phone: string | null;
    guest_email: string | null;
    notes: string | null;
    mode: string;
    total: number;
    paid_total: number;
    pending_balance: number;
    starts_at: string | null;
    ends_at: string | null;
    reservations_detail: ReservationDetail[];
    experiences: GroupExperienceRow[];
    payment_requests: PaymentRequestRow[];
}

interface RoomTypeOption {
    id: number;
    name: string;
    capacity: number;
    rooms_count: number;
}

// Sesiones disponibles para agregar un recorrido (mismo endpoint del wizard).
interface ExperienceSessionOption {
    id: number;
    starts_at: string;
    remaining: number;
}

interface ExperienceOption {
    id: number;
    name: string;
    price_label: string;
    min_people: number;
    max_people: number | null;
    sessions: ExperienceSessionOption[];
}

const props = defineProps<{
    group: GroupDetail;
    roomTypes: RoomTypeOption[];
    hasExperiencesModule: boolean;
    canManage: boolean;
}>();

const toast = useToasts();
const money = (n: number) => `$${Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;
const formatDateTime = (iso: string) =>
    new Date(iso).toLocaleString('es-MX', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });

const statusClass: Record<string, string> = {
    pending: 'bg-warning/10 text-warning',
    confirmed: 'bg-success/10 text-success',
    checked_in: 'bg-primary/10 text-primary',
    completed: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    cancelled: 'bg-danger/10 text-danger',
    no_show: 'bg-danger/10 text-danger',
};

const liveRooms = computed(() =>
    props.group.reservations_detail.filter((r) => r.status === 'pending' || r.status === 'confirmed'),
);

function reload() {
    router.reload();
}

// ── Editar responsable y notas ──
const editingInfo = ref(false);
const infoForm = reactive({ guest_name: '', notes: '' });
const infoBusy = ref(false);

function openInfo() {
    infoForm.guest_name = props.group.guest_name ?? '';
    infoForm.notes = props.group.notes ?? '';
    editingInfo.value = true;
}

async function submitInfo() {
    infoBusy.value = true;
    try {
        await axios.patch(`/api/group-reservations/${props.group.id}`, {
            guest_name: infoForm.guest_name,
            notes: infoForm.notes || null,
        });
        toast.success('Grupo actualizado');
        editingInfo.value = false;
        reload();
    } catch (e: any) {
        toast.error('Error', e.response?.data?.message ?? 'No se pudo actualizar.');
    } finally {
        infoBusy.value = false;
    }
}

// ── Agregar habitaciones (mismas fechas del grupo) ──
const addingRooms = ref(false);
const roomsForm = reactive({ room_type_id: '' as string | number, rooms: 1, adults: 2, children: 0 });
const roomsBusy = ref(false);

async function submitRooms() {
    roomsBusy.value = true;
    try {
        await axios.post(`/api/group-reservations/${props.group.id}/rooms`, {
            room_type_id: Number(roomsForm.room_type_id),
            rooms: roomsForm.rooms,
            adults: roomsForm.adults,
            children: roomsForm.children,
        });
        toast.success('Habitaciones agregadas', 'El total del grupo se actualizó; vuelve a generar el cobro si había uno.');
        addingRooms.value = false;
        roomsForm.room_type_id = '';
        roomsForm.rooms = 1;
        reload();
    } catch (e: any) {
        toast.error('No se pudo agregar', e.response?.data?.message ?? 'Revisa la disponibilidad.');
    } finally {
        roomsBusy.value = false;
    }
}

// ── Editar personas de una habitación ──
const editingPeople = ref<ReservationDetail | null>(null);
const peopleForm = reactive({ adults: 1, children: 0 });
const peopleBusy = ref(false);

function openPeople(row: ReservationDetail) {
    editingPeople.value = row;
    peopleForm.adults = row.adults;
    peopleForm.children = row.children;
}

async function submitPeople() {
    if (!editingPeople.value) return;
    peopleBusy.value = true;
    try {
        await axios.patch(`/api/reservations/${editingPeople.value.id}`, {
            rate_plan_id: editingPeople.value.rate_plan_id,
            starts_at: editingPeople.value.starts_at,
            ends_at: editingPeople.value.ends_at,
            adults: peopleForm.adults,
            children: peopleForm.children,
        });
        toast.success('Personas actualizadas', 'El total se recalculó con los cargos que apliquen.');
        editingPeople.value = null;
        reload();
    } catch (e: any) {
        toast.error('No se pudo actualizar', e.response?.data?.message ?? 'Revisa la capacidad de la habitación.');
    } finally {
        peopleBusy.value = false;
    }
}

// ── Cancelar una habitación del grupo ──
const cancellingRoom = ref<ReservationDetail | null>(null);
const cancelRoomBusy = ref(false);

async function cancelRoom() {
    if (!cancellingRoom.value) return;
    cancelRoomBusy.value = true;
    try {
        await axios.patch(`/api/reservations/${cancellingRoom.value.id}/cancel`, {
            reason: `Ajuste del grupo ${props.group.code}.`,
        });
        toast.success('Habitación cancelada', 'Se liberó del grupo; el folio queda como rastro.');
        cancellingRoom.value = null;
        reload();
    } catch (e: any) {
        toast.error('Error', e.response?.data?.message ?? 'No se pudo cancelar.');
    } finally {
        cancelRoomBusy.value = false;
    }
}

// ── Recorridos: agregar y cancelar ──
const addingExperience = ref(false);
const expCatalog = ref<ExperienceOption[]>([]);
const expLoading = ref(false);
const expForm = reactive({ experience_id: '' as string | number, session_id: '' as string | number, people: 1 });
const expBusy = ref(false);

const expSessions = computed(() => {
    const exp = expCatalog.value.find((e) => e.id === Number(expForm.experience_id));
    return exp?.sessions ?? [];
});

async function openAddExperience() {
    addingExperience.value = true;
    expForm.experience_id = '';
    expForm.session_id = '';
    expForm.people = 1;
    expLoading.value = true;
    try {
        const start = props.group.starts_at?.slice(0, 10);
        const end = props.group.ends_at?.slice(0, 10) ?? start;
        const { data } = await axios.get('/api/grupos/experiences', { params: { start, end } });
        expCatalog.value = data.experiences ?? [];
    } catch {
        expCatalog.value = [];
    } finally {
        expLoading.value = false;
    }
}

async function submitExperience() {
    expBusy.value = true;
    try {
        await axios.post(`/api/group-reservations/${props.group.id}/experiences`, {
            session_id: Number(expForm.session_id),
            people: expForm.people,
        });
        toast.success('Recorrido agregado', 'Suma al total del grupo; vuelve a generar el cobro si había uno.');
        addingExperience.value = false;
        reload();
    } catch (e: any) {
        toast.error('No se pudo agregar', e.response?.data?.message ?? 'Revisa el cupo de la sesión.');
    } finally {
        expBusy.value = false;
    }
}

async function cancelExperience(exp: GroupExperienceRow) {
    try {
        await axios.patch(`/api/experience-bookings/${exp.id}/status`, { status: 'cancelled' });
        toast.success('Recorrido cancelado', 'Su cupo quedó libre.');
        reload();
    } catch (e: any) {
        toast.error('Error', e.response?.data?.message ?? 'No se pudo cancelar el recorrido.');
    }
}

// ── Cobro consolidado desde el panel ──
const chargeBusy = ref(false);

async function issueCharge(method: 'gateway' | 'transfer') {
    chargeBusy.value = true;
    try {
        const { data } = await axios.post(`/api/group-reservations/${props.group.id}/payment-request`, { method });
        if (data.checkout_url) {
            try {
                await navigator.clipboard.writeText(data.checkout_url);
                toast.success('Link de pago copiado', `${data.amount_label} — compártelo con el responsable.`);
            } catch {
                toast.success('Cobro generado', `${data.amount_label} — copia el link desde la lista de cobros.`);
            }
        } else {
            toast.success('Cobro por transferencia emitido', `${data.amount_label} — verifícalo en Pagos cuando llegue el comprobante.`);
        }
        reload();
    } catch (e: any) {
        toast.error('No se pudo generar el cobro', e.response?.data?.message ?? 'Revisa Métodos de pago.');
    } finally {
        chargeBusy.value = false;
    }
}

async function copyRequestLink(pr: PaymentRequestRow) {
    if (!pr.checkout_url) return;
    try {
        await navigator.clipboard.writeText(pr.checkout_url);
        toast.success('Link copiado', pr.amount_label);
    } catch {
        toast.error('No se pudo copiar', pr.checkout_url);
    }
}

const requestStatusLabel: Record<string, string> = {
    pending: 'Vigente',
    paid: 'Pagado',
    expired: 'Vencido',
    canceled: 'Cancelado',
    rejected: 'Rechazado',
};
const requestStatusClass: Record<string, string> = {
    pending: 'bg-warning/10 text-warning',
    paid: 'bg-success/10 text-success',
    expired: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    canceled: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    rejected: 'bg-danger/10 text-danger',
};
</script>

<template>
    <RazeLayout :title="`Grupo ${group.code}`">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link href="/grupos" class="flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700">
                        <Lucide icon="ArrowLeft" class="h-4 w-4" /> Todos los grupos
                    </Link>
                    <h1 class="mt-1 text-lg font-medium">Grupo {{ group.code }}</h1>
                    <p class="mt-0.5 text-sm text-slate-500">
                        {{ group.reservations_detail.length }} habitaciones
                        <template v-if="group.starts_at">
                            · {{ formatDateTime(group.starts_at) }}<template v-if="group.ends_at"> → {{ formatDateTime(group.ends_at) }}</template>
                        </template>
                    </p>
                </div>
                <div v-if="canManage" class="flex flex-wrap gap-2">
                    <Button variant="outline-secondary" class="rounded-[0.5rem] bg-white" @click="openInfo">
                        <Lucide icon="Pencil" class="mr-2 h-4 w-4" /> Editar datos
                    </Button>
                    <Button variant="primary" class="rounded-[0.5rem] shadow-md shadow-primary/20" :disabled="chargeBusy || group.pending_balance <= 0" @click="issueCharge('gateway')">
                        <Lucide icon="Link" class="mr-2 h-4 w-4" /> Cobrar por link
                    </Button>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-5">
                <!-- Habitaciones y recorridos -->
                <div class="col-span-12 xl:col-span-8">
                    <div class="box box--stacked">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-dashed border-slate-300/70 px-5 py-4 dark:border-darkmode-400">
                            <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="BedDouble" class="h-3.5 w-3.5" /> Habitaciones
                            </div>
                            <Button v-if="canManage" variant="outline-secondary" size="sm" class="rounded-[0.5rem] bg-white" @click="addingRooms = true">
                                <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" /> Agregar habitación
                            </Button>
                        </div>
                        <div class="divide-y divide-dashed divide-slate-300/70">
                            <div v-for="row in group.reservations_detail" :key="row.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-medium">{{ row.code }}</span>
                                        <span class="text-sm text-slate-500">{{ row.room_type }}<template v-if="row.room"> · Hab. {{ row.room }}</template></span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        {{ row.adults }} adulto(s){{ row.children ? ` + ${row.children} niño(s)` : '' }} · {{ formatDateTime(row.starts_at) }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span class="text-sm font-medium">{{ money(row.total) }}</span>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass[row.status] ?? 'bg-slate-100 text-slate-500'">
                                        {{ row.status_label }}
                                    </span>
                                    <template v-if="canManage && (row.status === 'pending' || row.status === 'confirmed')">
                                        <button
                                            type="button"
                                            class="rounded p-1.5 text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                            title="Editar personas"
                                            @click="openPeople(row)"
                                        >
                                            <Lucide icon="UsersRound" class="h-4 w-4" />
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                            title="Cancelar esta habitación"
                                            @click="cancellingRoom = row"
                                        >
                                            <Lucide icon="Ban" class="h-4 w-4" />
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="hasExperiencesModule" class="mt-5 box box--stacked">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-dashed border-slate-300/70 px-5 py-4 dark:border-darkmode-400">
                            <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="Compass" class="h-3.5 w-3.5" /> Recorridos y experiencias
                            </div>
                            <Button v-if="canManage" variant="outline-secondary" size="sm" class="rounded-[0.5rem] bg-white" @click="openAddExperience">
                                <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" /> Agregar recorrido
                            </Button>
                        </div>
                        <div v-if="group.experiences.length" class="divide-y divide-dashed divide-slate-300/70">
                            <div v-for="exp in group.experiences" :key="exp.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-medium">{{ exp.code }}</span>
                                        <span class="text-sm text-slate-500">{{ exp.name }}</span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        {{ exp.people }} persona(s)<template v-if="exp.starts_at"> · {{ formatDateTime(exp.starts_at) }}</template>
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span class="text-sm font-medium">{{ money(exp.total) }}</span>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass[exp.status] ?? 'bg-slate-100 text-slate-500'">
                                        {{ exp.status_label }}
                                    </span>
                                    <button
                                        v-if="canManage && (exp.status === 'pending' || exp.status === 'confirmed')"
                                        type="button"
                                        class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                        title="Cancelar el recorrido (libera su cupo)"
                                        @click="cancelExperience(exp)"
                                    >
                                        <Lucide icon="Ban" class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div v-else class="px-5 py-6 text-center text-xs text-slate-500">
                            Sin recorridos: agrégales un tour y viaja en el mismo cobro del grupo.
                        </div>
                    </div>
                </div>

                <!-- Dinero y responsable -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked p-5">
                        <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="Wallet" class="h-3.5 w-3.5" /> Dinero del grupo
                        </div>
                        <div class="mt-3 space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500">Total</span>
                                <span class="font-semibold">{{ money(group.total) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500">Pagado</span>
                                <span class="font-medium text-success">{{ money(group.paid_total) }}</span>
                            </div>
                            <div class="flex items-center justify-between border-t border-dashed border-slate-300/70 pt-2 dark:border-darkmode-400">
                                <span class="text-slate-500">Pendiente</span>
                                <span class="font-semibold" :class="group.pending_balance > 0 ? 'text-warning' : 'text-success'">
                                    {{ money(group.pending_balance) }}
                                </span>
                            </div>
                        </div>
                        <div v-if="canManage && group.pending_balance > 0" class="mt-4 flex flex-col gap-2">
                            <Button variant="primary" class="rounded-[0.5rem]" :disabled="chargeBusy" @click="issueCharge('gateway')">
                                <Lucide icon="Link" class="mr-2 h-4 w-4" /> {{ chargeBusy ? 'Generando…' : 'Generar link de pago' }}
                            </Button>
                            <Button variant="outline-secondary" class="rounded-[0.5rem] bg-white" :disabled="chargeBusy" @click="issueCharge('transfer')">
                                <Lucide icon="Landmark" class="mr-2 h-4 w-4" /> Cobro por transferencia
                            </Button>
                        </div>

                        <div v-if="group.payment_requests.length" class="mt-4 border-t border-dashed border-slate-300/70 pt-3 dark:border-darkmode-400">
                            <div class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">Cobros emitidos</div>
                            <div class="space-y-2">
                                <div v-for="pr in group.payment_requests" :key="pr.id" class="flex items-center justify-between gap-2 text-sm">
                                    <div class="min-w-0">
                                        <span class="font-medium">{{ pr.amount_label }}</span>
                                        <span class="ml-1 text-xs text-slate-400">{{ pr.method === 'transfer' ? 'transferencia' : 'link' }}</span>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1.5">
                                        <span class="rounded-full px-2 py-0.5 text-[10px] font-medium" :class="requestStatusClass[pr.status] ?? 'bg-slate-100 text-slate-500'">
                                            {{ requestStatusLabel[pr.status] ?? pr.status }}
                                        </span>
                                        <button
                                            v-if="pr.status === 'pending' && pr.checkout_url"
                                            type="button"
                                            class="rounded p-1 text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                            title="Copiar link de pago"
                                            @click="copyRequestLink(pr)"
                                        >
                                            <Lucide icon="Copy" class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 box box--stacked p-5">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="UserRound" class="h-3.5 w-3.5" /> Responsable
                            </div>
                            <button v-if="canManage" type="button" class="rounded p-1.5 text-slate-400 transition hover:bg-primary/10 hover:text-primary" title="Editar responsable y notas" @click="openInfo">
                                <Lucide icon="Pencil" class="h-4 w-4" />
                            </button>
                        </div>
                        <div class="mt-3 space-y-1.5 text-sm">
                            <div class="font-medium">{{ group.guest_name ?? 'Sin nombre' }}</div>
                            <div v-if="group.guest_phone" class="text-slate-500">{{ group.guest_phone }}</div>
                            <div v-if="group.guest_email" class="text-slate-500">{{ group.guest_email }}</div>
                            <p v-if="group.notes" class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-darkmode-700">{{ group.notes }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal editar responsable/notas -->
        <Dialog :open="editingInfo" @close="editingInfo = false">
            <Dialog.Panel>
                <form class="p-5" @submit.prevent="submitInfo">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="Pencil" class="h-5 w-5 text-primary" />
                        </div>
                        <h2 class="text-base font-medium">Editar grupo {{ group.code }}</h2>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm">Responsable del grupo</label>
                            <FormInput v-model="infoForm.guest_name" type="text" placeholder="Quien responde por el grupo" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Notas</label>
                            <FormTextarea v-model="infoForm.notes" rows="2" placeholder="Boda, evento, hora de llegada del grupo…" />
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="outline-secondary" @click="editingInfo = false">Cancelar</Button>
                        <Button type="submit" variant="primary" :disabled="infoBusy || !infoForm.guest_name.trim()">
                            {{ infoBusy ? 'Guardando…' : 'Guardar cambios' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal agregar habitaciones -->
        <Dialog :open="addingRooms" @close="addingRooms = false">
            <Dialog.Panel>
                <form class="p-5" @submit.prevent="submitRooms">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="BedDouble" class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">Agregar habitaciones al grupo</h2>
                            <p class="text-xs text-slate-500">Mismas fechas del grupo. Si no hay disponibilidad, no se agrega ninguna.</p>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm">Tipo de habitación</label>
                            <FormSelect v-model="roomsForm.room_type_id">
                                <option value="" disabled>Elige un tipo</option>
                                <option v-for="type in roomTypes" :key="type.id" :value="type.id">
                                    {{ type.name }} ({{ type.rooms_count }} física(s))
                                </option>
                            </FormSelect>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="mb-1 block text-sm">Habitaciones</label>
                                <FormInput v-model.number="roomsForm.rooms" type="number" min="1" max="10" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Adultos c/u</label>
                                <FormInput v-model.number="roomsForm.adults" type="number" min="1" max="20" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Niños c/u</label>
                                <FormInput v-model.number="roomsForm.children" type="number" min="0" max="20" />
                            </div>
                        </div>
                        <FormHelp>Si el grupo ya está confirmado, lo agregado nace confirmado; si hay un cobro vivo se cancela para emitir el correcto.</FormHelp>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="outline-secondary" @click="addingRooms = false">Cancelar</Button>
                        <Button type="submit" variant="primary" :disabled="roomsBusy || roomsForm.room_type_id === ''">
                            {{ roomsBusy ? 'Agregando…' : 'Agregar' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal editar personas -->
        <Dialog :open="editingPeople !== null" @close="editingPeople = null">
            <Dialog.Panel>
                <form class="p-5" @submit.prevent="submitPeople">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="UsersRound" class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">Personas en {{ editingPeople?.code }}</h2>
                            <p class="text-xs text-slate-500">{{ editingPeople?.room_type }} — el total se recalcula con los cargos por persona extra que apliquen.</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm">Adultos</label>
                            <FormInput v-model.number="peopleForm.adults" type="number" min="1" max="20" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Niños</label>
                            <FormInput v-model.number="peopleForm.children" type="number" min="0" max="20" />
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="outline-secondary" @click="editingPeople = null">Cancelar</Button>
                        <Button type="submit" variant="primary" :disabled="peopleBusy">
                            {{ peopleBusy ? 'Guardando…' : 'Guardar' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal confirmar cancelación de habitación -->
        <Dialog :open="cancellingRoom !== null" @close="cancellingRoom = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide icon="AlertTriangle" class="mx-auto mb-3 h-12 w-12 text-danger" />
                    <h2 class="text-base font-medium">¿Cancelar {{ cancellingRoom?.code }}?</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Se libera la habitación {{ cancellingRoom?.room_type }} de este grupo; el folio queda como rastro en Reservas.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button variant="outline-secondary" @click="cancellingRoom = null">Conservar</Button>
                        <Button variant="danger" :disabled="cancelRoomBusy" @click="cancelRoom">
                            {{ cancelRoomBusy ? 'Cancelando…' : 'Sí, cancelar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal agregar recorrido -->
        <Dialog :open="addingExperience" @close="addingExperience = false">
            <Dialog.Panel>
                <form class="p-5" @submit.prevent="submitExperience">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="Compass" class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">Agregar recorrido al grupo</h2>
                            <p class="text-xs text-slate-500">Sesiones con cupo durante la estancia del grupo; viaja en el mismo cobro.</p>
                        </div>
                    </div>
                    <div v-if="expLoading" class="py-6 text-center">
                        <Lucide icon="RefreshCw" class="mx-auto h-6 w-6 animate-spin text-primary" />
                    </div>
                    <div v-else-if="!expCatalog.length" class="rounded-lg border border-dashed border-slate-300/70 px-4 py-5 text-center text-xs text-slate-500 dark:border-darkmode-400">
                        No hay experiencias con sesiones disponibles en las fechas del grupo.
                    </div>
                    <div v-else class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm">Experiencia</label>
                            <FormSelect v-model="expForm.experience_id" @change="expForm.session_id = ''">
                                <option value="" disabled>Elige una experiencia</option>
                                <option v-for="exp in expCatalog" :key="exp.id" :value="exp.id">
                                    {{ exp.name }} — {{ exp.price_label }}
                                </option>
                            </FormSelect>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Sesión</label>
                            <FormSelect v-model="expForm.session_id" :disabled="expForm.experience_id === ''">
                                <option value="" disabled>Elige fecha y horario</option>
                                <option v-for="session in expSessions" :key="session.id" :value="session.id">
                                    {{ formatDateTime(session.starts_at) }} · {{ session.remaining }} lugar(es)
                                </option>
                            </FormSelect>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Personas</label>
                            <FormInput v-model.number="expForm.people" type="number" min="1" max="100" />
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="outline-secondary" @click="addingExperience = false">Cancelar</Button>
                        <Button type="submit" variant="primary" :disabled="expBusy || expForm.session_id === ''">
                            {{ expBusy ? 'Agregando…' : 'Agregar' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
