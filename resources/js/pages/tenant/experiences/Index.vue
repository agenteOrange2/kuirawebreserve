<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSelect, FormSwitch, FormTextarea } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface Photo {
    id: number;
    url: string;
    thumb_url: string;
}

interface SessionRow {
    id: number;
    starts_at: string;
    capacity: number;
    people_booked: number;
    remaining: number;
    status: string;
    from_schedule: boolean;
}

interface VehicleRow {
    id: number;
    name: string;
    capacity: number;
    active: boolean;
    sort_order: number;
}

interface SlotRow {
    id: number;
    experience_id: number;
    start_time: string;
    vehicle_ids: number[];
    capacity: number | null;
    effective_capacity: number;
    active: boolean;
}

interface ExperienceRow {
    id: number;
    name: string;
    description: string | null;
    includes: string[];
    duration_minutes: number | null;
    duration_label: string | null;
    pricing_mode: string;
    price: number;
    price_label: string;
    min_people: number;
    max_people: number | null;
    operating_days: number[];
    active: boolean;
    sort_order: number;
    photos: Photo[];
    slots: SlotRow[];
    sessions: SessionRow[];
}

interface BookingRow {
    id: number;
    code: string;
    experience: string | null;
    session_starts_at: string | null;
    guest_name: string | null;
    guest_phone: string | null;
    people: number;
    total: number;
    status: string;
    status_label: string;
    linked_to: string | null;
    notes: string | null;
    created_at: string;
    paid: boolean;
    pending_payment: { method: string; amount_label: string; checkout_url: string | null } | null;
}

const props = defineProps<{
    experiences: ExperienceRow[];
    vehicles: VehicleRow[];
    bookings: BookingRow[];
    pricingModes: Record<string, string>;
    publicUrl: string;
    canManage: boolean;
}>();

const toast = useToasts();
const money = (n: number) => `$${n.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;
const formatDateTime = (iso: string) =>
    new Date(iso).toLocaleString('es-MX', { weekday: 'short', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });

function reload() {
    router.reload({ only: ['experiences', 'bookings'] });
}

// ── Modal experiencia (crear/editar + fotos) ──
const showForm = ref(false);
const editing = ref<ExperienceRow | null>(null);
const saving = ref(false);
const errors = reactive<Record<string, string>>({});
const form = reactive({
    name: '',
    description: '',
    includes: [] as string[],
    duration_minutes: '' as string | number,
    pricing_mode: 'per_person',
    price: '' as string | number,
    min_people: 1 as string | number,
    max_people: '' as string | number,
    active: true,
    sort_order: 0 as string | number,
});
const includeInput = ref('');

function openForm(experience: ExperienceRow | null = null) {
    editing.value = experience;
    form.name = experience?.name ?? '';
    form.description = experience?.description ?? '';
    form.includes = [...(experience?.includes ?? [])];
    form.duration_minutes = experience?.duration_minutes ?? '';
    form.pricing_mode = experience?.pricing_mode ?? 'per_person';
    form.price = experience?.price ?? '';
    form.min_people = experience?.min_people ?? 1;
    form.max_people = experience?.max_people ?? '';
    form.active = experience?.active ?? true;
    form.sort_order = experience?.sort_order ?? props.experiences.length;
    includeInput.value = '';
    photos.value = [...(experience?.photos ?? [])];
    Object.keys(errors).forEach((k) => delete errors[k]);
    showForm.value = true;
}

function addInclude() {
    const value = includeInput.value.trim();
    if (value && !form.includes.includes(value)) form.includes.push(value);
    includeInput.value = '';
}

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    const payload = {
        name: form.name,
        description: form.description.trim() === '' ? null : form.description,
        includes: form.includes,
        duration_minutes: form.duration_minutes === '' ? null : Number(form.duration_minutes),
        pricing_mode: form.pricing_mode,
        price: form.price,
        min_people: form.min_people === '' ? 1 : Number(form.min_people),
        max_people: form.max_people === '' ? null : Number(form.max_people),
        active: form.active,
        sort_order: form.sort_order === '' ? 0 : Number(form.sort_order),
    };
    try {
        if (editing.value) {
            await axios.patch(`/api/experiences/${editing.value.id}`, payload);
            toast.success('Experiencia actualizada', 'Las reservas ya hechas conservan su total congelado.');
        } else {
            await axios.post('/api/experiences', payload);
            toast.success('Experiencia creada', 'Agrega sesiones con cupo para que se pueda reservar.');
        }
        showForm.value = false;
        reload();
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(([key, msgs]) => (errors[key] = (msgs as string[])[0]));
        } else {
            toast.error('Error', data?.message ?? 'No se pudo guardar.');
        }
    } finally {
        saving.value = false;
    }
}

const deleting = ref<ExperienceRow | null>(null);

async function destroy() {
    if (!deleting.value) return;
    try {
        await axios.delete(`/api/experiences/${deleting.value.id}`);
        toast.success('Experiencia eliminada', 'Sus sesiones y reservas se eliminaron con ella.');
        deleting.value = null;
        reload();
    } catch {
        toast.error('Error', 'No se pudo eliminar.');
    }
}

// ── Fotos (mismo patrón que tipos de habitación) ──
const photos = ref<Photo[]>([]);
const photoBusy = ref(false);
const photoInput = ref<HTMLInputElement | null>(null);

async function uploadPhotos(event: Event) {
    const files = (event.target as HTMLInputElement).files;
    if (!files?.length || !editing.value) return;
    const formData = new FormData();
    [...files].forEach((file) => formData.append('photos[]', file));
    photoBusy.value = true;
    try {
        const { data } = await axios.post<{ photos: Photo[] }>(`/api/experiences/${editing.value.id}/photos`, formData);
        photos.value = data.photos;
        toast.success('Fotos subidas', 'La página pública ya las muestra.');
        reload();
    } catch (e: any) {
        const errs = e.response?.data?.errors as Record<string, string[]> | undefined;
        toast.error('No se pudieron subir', e.response?.data?.message ?? (errs ? Object.values(errs)[0]?.[0] : 'Revisa formato y peso (máx. 6 MB).'));
    } finally {
        photoBusy.value = false;
        if (photoInput.value) photoInput.value.value = '';
    }
}

async function removePhoto(photo: Photo) {
    if (!editing.value) return;
    photoBusy.value = true;
    try {
        const { data } = await axios.delete<{ photos: Photo[] }>(`/api/experiences/${editing.value.id}/photos/${photo.id}`);
        photos.value = data.photos;
        reload();
    } catch {
        toast.error('Error', 'No se pudo quitar la foto.');
    } finally {
        photoBusy.value = false;
    }
}

async function makeCover(photo: Photo) {
    if (!editing.value) return;
    photoBusy.value = true;
    try {
        const order = [photo.id, ...photos.value.filter((p) => p.id !== photo.id).map((p) => p.id)];
        const { data } = await axios.patch<{ photos: Photo[] }>(`/api/experiences/${editing.value.id}/photos/order`, { order });
        photos.value = data.photos;
        reload();
    } catch {
        toast.error('Error', 'No se pudo cambiar la portada.');
    } finally {
        photoBusy.value = false;
    }
}

// ── Vehículos (flota de la propiedad) ──
const showVehicles = ref(false);
const vehicles = ref<VehicleRow[]>([...props.vehicles]);
const vehicleForm = reactive({ name: '', capacity: 4 as string | number });
const vehicleSaving = ref(false);

async function addVehicle() {
    vehicleSaving.value = true;
    try {
        const { data } = await axios.post<VehicleRow>('/api/experience-vehicles', {
            name: vehicleForm.name,
            capacity: vehicleForm.capacity,
        });
        vehicles.value = [...vehicles.value, data];
        vehicleForm.name = '';
        vehicleForm.capacity = 4;
        toast.success('Vehículo agregado', 'Asígnalo a los horarios de tus experiencias.');
        reload();
    } catch (e: any) {
        toast.error('No se pudo agregar', e.response?.data?.message ?? 'Revisa nombre y capacidad.');
    } finally {
        vehicleSaving.value = false;
    }
}

async function toggleVehicle(vehicle: VehicleRow) {
    try {
        const { data } = await axios.patch<VehicleRow>(`/api/experience-vehicles/${vehicle.id}`, { active: !vehicle.active });
        vehicles.value = vehicles.value.map((v) => (v.id === data.id ? data : v));
        reload();
    } catch (e: any) {
        toast.error('Error', e.response?.data?.message ?? 'No se pudo actualizar el vehículo.');
    }
}

async function deleteVehicle(vehicle: VehicleRow) {
    try {
        await axios.delete(`/api/experience-vehicles/${vehicle.id}`);
        vehicles.value = vehicles.value.filter((v) => v.id !== vehicle.id);
        toast.success('Vehículo eliminado', 'Los horarios que lo usaban ajustaron su cupo.');
        reload();
    } catch (e: any) {
        toast.error('No se pudo eliminar', e.response?.data?.message ?? 'Intenta desactivarlo en su lugar.');
    }
}

// ── Programación semanal (días + horarios) ──
const WEEKDAYS = [
    { value: 1, label: 'Lun' },
    { value: 2, label: 'Mar' },
    { value: 3, label: 'Mié' },
    { value: 4, label: 'Jue' },
    { value: 5, label: 'Vie' },
    { value: 6, label: 'Sáb' },
    { value: 7, label: 'Dom' },
];
const scheduleFor = ref<ExperienceRow | null>(null);
const scheduleDaysSaving = ref(false);
const slotForm = reactive({ start_time: '', vehicle_ids: [] as number[], capacity: '' as string | number });
const slotSaving = ref(false);

function openSchedule(experience: ExperienceRow) {
    scheduleFor.value = experience;
    slotForm.start_time = '';
    slotForm.vehicle_ids = [];
    slotForm.capacity = '';
}

function scheduleSummary(experience: ExperienceRow): string {
    const liveSlots = experience.slots.filter((s) => s.active).length;
    if (!experience.operating_days.length || !liveSlots) return 'Sin programación semanal';
    const days = WEEKDAYS.filter((d) => experience.operating_days.includes(d.value))
        .map((d) => d.label)
        .join(', ');
    return `${days} · ${liveSlots} horario(s)`;
}

async function toggleDay(day: number) {
    if (!scheduleFor.value) return;
    const current = scheduleFor.value.operating_days;
    const next = current.includes(day) ? current.filter((d) => d !== day) : [...current, day].sort((a, b) => a - b);
    scheduleDaysSaving.value = true;
    try {
        await axios.patch(`/api/experiences/${scheduleFor.value.id}`, { operating_days: next });
        scheduleFor.value.operating_days = next;
        reload();
    } catch (e: any) {
        toast.error('Error', e.response?.data?.message ?? 'No se pudieron guardar los días.');
    } finally {
        scheduleDaysSaving.value = false;
    }
}

function toggleSlotVehicle(id: number) {
    slotForm.vehicle_ids = slotForm.vehicle_ids.includes(id)
        ? slotForm.vehicle_ids.filter((v) => v !== id)
        : [...slotForm.vehicle_ids, id];
}

async function addSlot() {
    if (!scheduleFor.value) return;
    slotSaving.value = true;
    try {
        const { data } = await axios.post<SlotRow>(`/api/experiences/${scheduleFor.value.id}/slots`, {
            start_time: slotForm.start_time,
            vehicle_ids: slotForm.vehicle_ids,
            capacity: slotForm.capacity === '' ? null : Number(slotForm.capacity),
        });
        scheduleFor.value.slots = [...scheduleFor.value.slots, data].sort((a, b) => a.start_time.localeCompare(b.start_time));
        slotForm.start_time = '';
        slotForm.vehicle_ids = [];
        slotForm.capacity = '';
        toast.success('Horario agregado', 'Las sesiones del horizonte de venta ya se generaron.');
        reload();
    } catch (e: any) {
        const data = e.response?.data;
        toast.error('No se pudo agregar', data?.message ?? Object.values(data?.errors ?? {}).flat()[0] ?? 'Revisa la hora y el cupo.');
    } finally {
        slotSaving.value = false;
    }
}

async function toggleSlot(slot: SlotRow) {
    if (!scheduleFor.value) return;
    try {
        const { data } = await axios.patch<SlotRow>(`/api/experiences/${scheduleFor.value.id}/slots/${slot.id}`, { active: !slot.active });
        scheduleFor.value.slots = scheduleFor.value.slots.map((s) => (s.id === data.id ? data : s));
        reload();
    } catch (e: any) {
        toast.error('Error', e.response?.data?.message ?? 'No se pudo actualizar el horario.');
    }
}

async function deleteSlot(slot: SlotRow) {
    if (!scheduleFor.value) return;
    try {
        await axios.delete(`/api/experiences/${scheduleFor.value.id}/slots/${slot.id}`);
        scheduleFor.value.slots = scheduleFor.value.slots.filter((s) => s.id !== slot.id);
        toast.success('Horario eliminado', 'Sus sesiones futuras sin reservas se quitaron de la venta.');
        reload();
    } catch (e: any) {
        toast.error('Error', e.response?.data?.message ?? 'No se pudo eliminar el horario.');
    }
}

function vehicleNames(slot: SlotRow): string {
    const names = vehicles.value.filter((v) => slot.vehicle_ids.includes(v.id)).map((v) => v.name);
    return names.length ? names.join(', ') : 'Sin vehículos';
}

// ── Sesiones ──
const sessionsFor = ref<ExperienceRow | null>(null);
const sessionForm = reactive({ starts_at: '', capacity: 10 as string | number });
const sessionSaving = ref(false);
const sessionErrors = reactive<Record<string, string>>({});

function openSessions(experience: ExperienceRow) {
    sessionsFor.value = experience;
    sessionForm.starts_at = '';
    sessionForm.capacity = 10;
    Object.keys(sessionErrors).forEach((k) => delete sessionErrors[k]);
}

async function addSession() {
    if (!sessionsFor.value) return;
    sessionSaving.value = true;
    Object.keys(sessionErrors).forEach((k) => delete sessionErrors[k]);
    try {
        const { data } = await axios.post<SessionRow>(`/api/experiences/${sessionsFor.value.id}/sessions`, {
            starts_at: sessionForm.starts_at,
            capacity: sessionForm.capacity,
        });
        sessionsFor.value.sessions = [...sessionsFor.value.sessions, data].sort((a, b) => a.starts_at.localeCompare(b.starts_at));
        sessionForm.starts_at = '';
        toast.success('Sesión creada', 'Ya se puede reservar en la página pública.');
        reload();
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(([key, msgs]) => (sessionErrors[key] = (msgs as string[])[0]));
        } else {
            toast.error('Error', data?.message ?? 'No se pudo crear la sesión.');
        }
    } finally {
        sessionSaving.value = false;
    }
}

async function cancelSession(session: SessionRow) {
    if (!sessionsFor.value) return;
    try {
        const { data } = await axios.patch(`/api/experiences/${sessionsFor.value.id}/sessions/${session.id}`, { status: 'cancelled' });
        session.status = 'cancelled';
        toast.success(
            'Sesión cancelada',
            data.cancelled_bookings ? `Se cancelaron ${data.cancelled_bookings} reserva(s); avisa a los inscritos.` : undefined,
        );
        reload();
    } catch (e: any) {
        toast.error('Error', e.response?.data?.message ?? 'No se pudo cancelar.');
    }
}

async function deleteSession(session: SessionRow) {
    if (!sessionsFor.value) return;
    try {
        await axios.delete(`/api/experiences/${sessionsFor.value.id}/sessions/${session.id}`);
        sessionsFor.value.sessions = sessionsFor.value.sessions.filter((s) => s.id !== session.id);
        reload();
    } catch (e: any) {
        toast.error('No se pudo borrar', e.response?.data?.message ?? 'Intenta cancelarla en su lugar.');
    }
}

// ── Reservas ──
const bookings = ref<BookingRow[]>([...props.bookings]);
const showBookingForm = ref(false);
const bookingSaving = ref(false);
const bookingErrors = reactive<Record<string, string>>({});
const bookingForm = reactive({
    experience_id: '' as string | number,
    experience_session_id: '' as string | number,
    people: 1,
    guest_name: '',
    guest_phone: '',
    notes: '',
    confirmed: true,
});

const bookableSessions = computed(() => {
    const experience = props.experiences.find((e) => e.id === Number(bookingForm.experience_id));
    return (experience?.sessions ?? []).filter((s) => s.status === 'scheduled' && s.remaining > 0);
});

function openBookingForm() {
    bookingForm.experience_id = '';
    bookingForm.experience_session_id = '';
    bookingForm.people = 1;
    bookingForm.guest_name = '';
    bookingForm.guest_phone = '';
    bookingForm.notes = '';
    bookingForm.confirmed = true;
    Object.keys(bookingErrors).forEach((k) => delete bookingErrors[k]);
    showBookingForm.value = true;
}

async function submitBooking() {
    bookingSaving.value = true;
    Object.keys(bookingErrors).forEach((k) => delete bookingErrors[k]);
    try {
        const { data } = await axios.post<BookingRow>('/api/experience-bookings', {
            experience_session_id: bookingForm.experience_session_id,
            people: bookingForm.people,
            guest_name: bookingForm.guest_name,
            guest_phone: bookingForm.guest_phone || null,
            notes: bookingForm.notes || null,
            confirmed: bookingForm.confirmed,
        });
        bookings.value = [data, ...bookings.value];
        showBookingForm.value = false;
        toast.success('Reserva registrada', `Folio ${data.code} · total ${money(data.total)}.`);
        reload();
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(([key, msgs]) => (bookingErrors[key] = (msgs as string[])[0]));
        } else {
            toast.error('No se pudo registrar', data?.message ?? 'Revisa el cupo de la sesión.');
        }
    } finally {
        bookingSaving.value = false;
    }
}

// Genera el cobro (link de pasarela o transferencia) y deja el link listo
// para compartir: el webhook o la verificación humana lo cierran.
const chargingBooking = ref<number | null>(null);

async function issuePayment(booking: BookingRow) {
    chargingBooking.value = booking.id;
    try {
        const { data } = await axios.post<BookingRow & { payment: { method: string; amount_label: string; checkout_url: string | null } }>(
            `/api/experience-bookings/${booking.id}/payment-request`,
        );
        bookings.value = bookings.value.map((b) => (b.id === data.id ? data : b));
        if (data.payment.checkout_url) {
            try {
                await navigator.clipboard.writeText(data.payment.checkout_url);
                toast.success('Link de pago copiado', `${data.payment.amount_label} — pégalo en el chat con el huésped.`);
            } catch {
                toast.success('Cobro generado', `${data.payment.amount_label} — copia el link desde la fila.`);
            }
        } else {
            toast.success('Cobro por transferencia emitido', `${data.payment.amount_label} — verifícalo en la cola de pagos cuando llegue el comprobante.`);
        }
    } catch (e: any) {
        toast.error('No se pudo generar el cobro', e.response?.data?.message ?? 'Revisa las pasarelas o cuentas en Métodos de pago.');
    } finally {
        chargingBooking.value = null;
    }
}

async function copyCheckout(booking: BookingRow) {
    if (!booking.pending_payment?.checkout_url) return;
    try {
        await navigator.clipboard.writeText(booking.pending_payment.checkout_url);
        toast.success('Link copiado', booking.pending_payment.amount_label);
    } catch {
        toast.error('No se pudo copiar', booking.pending_payment.checkout_url);
    }
}

async function setBookingStatus(booking: BookingRow, status: string) {
    try {
        const { data } = await axios.patch<BookingRow>(`/api/experience-bookings/${booking.id}/status`, { status });
        bookings.value = bookings.value.map((b) => (b.id === data.id ? data : b));
        reload();
    } catch (e: any) {
        toast.error('Error', e.response?.data?.message ?? 'No se pudo actualizar la reserva.');
    }
}

const bookingStatusClass: Record<string, string> = {
    pending: 'bg-warning/10 text-warning',
    confirmed: 'bg-success/10 text-success',
    cancelled: 'bg-danger/10 text-danger',
    completed: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
};
</script>

<template>
    <RazeLayout title="Experiencias">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Experiencias</h1>
                    <p class="mt-0.5 text-sm text-slate-500">
                        Tours y recorridos con horario y cupo propios. Se reservan solos, con o sin habitación.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button as="a" :href="publicUrl" target="_blank" variant="outline-primary" class="rounded-[0.5rem] bg-white">
                        <Lucide icon="ExternalLink" class="mr-2 h-4 w-4 stroke-[1.3]" /> Ver página pública
                    </Button>
                    <Button v-if="canManage" variant="outline-secondary" class="rounded-[0.5rem] bg-white" @click="showVehicles = true">
                        <Lucide icon="Truck" class="mr-2 h-4 w-4 stroke-[1.3]" /> Vehículos
                    </Button>
                    <Button v-if="canManage" variant="primary" class="rounded-[0.5rem] shadow-md shadow-primary/20" @click="openForm()">
                        <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Nueva experiencia
                    </Button>
                </div>
            </div>

            <!-- Catálogo -->
            <div v-if="experiences.length" class="mt-5 grid grid-cols-12 gap-5">
                <div v-for="experience in experiences" :key="experience.id" class="col-span-12 md:col-span-6 xl:col-span-4">
                    <div class="box box--stacked flex h-full flex-col overflow-hidden">
                        <div class="relative h-40 w-full bg-slate-100 dark:bg-darkmode-400">
                            <img
                                v-if="experience.photos.length"
                                :src="experience.photos[0].thumb_url"
                                :alt="experience.name"
                                class="h-full w-full object-cover"
                                loading="lazy"
                            />
                            <div v-else class="flex h-full items-center justify-center">
                                <Lucide icon="Compass" class="h-10 w-10 text-slate-300" />
                            </div>
                            <span
                                v-if="!experience.active"
                                class="absolute left-2 top-2 rounded-full bg-slate-800/70 px-2 py-0.5 text-[10px] font-medium text-white"
                            >
                                Pausada
                            </span>
                        </div>
                        <div class="flex flex-1 flex-col p-4">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-medium">{{ experience.name }}</div>
                                    <div class="mt-0.5 text-xs text-slate-500">
                                        {{ experience.price_label }}<template v-if="experience.duration_label"> · {{ experience.duration_label }}</template>
                                    </div>
                                </div>
                            </div>
                            <p v-if="experience.description" class="mt-2 line-clamp-2 text-xs text-slate-500">{{ experience.description }}</p>
                            <div class="mt-2 text-xs text-slate-400">
                                {{ experience.sessions.filter((s) => s.status === 'scheduled').length }} sesión(es) en los próximos 60 días
                            </div>
                            <div class="mt-1 flex items-center gap-1.5 text-xs text-slate-400">
                                <Lucide icon="CalendarClock" class="h-3.5 w-3.5 shrink-0" />
                                {{ scheduleSummary(experience) }}
                            </div>
                            <div class="mt-auto flex items-center gap-2 pt-3">
                                <Button v-if="canManage" variant="outline-secondary" size="sm" class="rounded-[0.5rem] bg-white" @click="openSchedule(experience)">
                                    <Lucide icon="CalendarClock" class="mr-1.5 h-3.5 w-3.5" /> Programación
                                </Button>
                                <Button variant="outline-secondary" size="sm" class="rounded-[0.5rem] bg-white" @click="openSessions(experience)">
                                    <Lucide icon="CalendarDays" class="mr-1.5 h-3.5 w-3.5" /> Sesiones
                                </Button>
                                <Button v-if="canManage" variant="outline-secondary" size="sm" class="rounded-[0.5rem] bg-white" @click="openForm(experience)">
                                    <Lucide icon="Pencil" class="mr-1.5 h-3.5 w-3.5" /> Editar
                                </Button>
                                <button
                                    v-if="canManage"
                                    type="button"
                                    class="ml-auto rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                    title="Eliminar experiencia"
                                    @click="deleting = experience"
                                >
                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else class="mt-5 box box--stacked flex flex-col items-center gap-3 px-5 py-12 text-center">
                <Lucide icon="Compass" class="h-10 w-10 text-slate-300" />
                <div>
                    <p class="text-sm font-medium text-slate-600">Aún no tienes experiencias</p>
                    <p class="mt-0.5 text-xs text-slate-500">Crea la primera — un tour, un recorrido, una actividad — y agrégale sesiones con cupo.</p>
                </div>
                <Button v-if="canManage" variant="primary" class="rounded-[0.5rem]" @click="openForm()">
                    <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Nueva experiencia
                </Button>
            </div>

            <!-- Reservas -->
            <div class="mt-6 box box--stacked">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-dashed border-slate-300/70 px-5 py-4 dark:border-darkmode-400">
                    <div>
                        <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="TicketCheck" class="h-3.5 w-3.5" /> Reservas de experiencias
                        </div>
                        <p class="mt-1 text-xs text-slate-500">Las pendientes van primero: confírmalas cuando el huésped pague o llegue.</p>
                    </div>
                    <Button v-if="experiences.length" variant="primary" class="rounded-[0.5rem]" @click="openBookingForm">
                        <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Registrar reserva
                    </Button>
                </div>
                <div v-if="bookings.length" class="overflow-auto p-5 lg:overflow-visible">
                    <Table>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="whitespace-nowrap">Folio</Table.Th>
                                <Table.Th class="whitespace-nowrap">Experiencia / sesión</Table.Th>
                                <Table.Th class="whitespace-nowrap">Huésped</Table.Th>
                                <Table.Th class="whitespace-nowrap">Personas</Table.Th>
                                <Table.Th class="whitespace-nowrap">Total</Table.Th>
                                <Table.Th class="whitespace-nowrap">Estado</Table.Th>
                                <Table.Th class="whitespace-nowrap text-right">Acciones</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="booking in bookings" :key="booking.id">
                                <Table.Td class="font-medium">
                                    {{ booking.code }}
                                    <div v-if="booking.linked_to" class="mt-0.5 text-[11px] font-normal text-slate-400">Plus de {{ booking.linked_to }}</div>
                                </Table.Td>
                                <Table.Td>
                                    <div class="text-sm">{{ booking.experience }}</div>
                                    <div class="text-xs text-slate-500">{{ booking.session_starts_at ? formatDateTime(booking.session_starts_at) : '' }}</div>
                                </Table.Td>
                                <Table.Td>
                                    <div class="text-sm">{{ booking.guest_name ?? 'Sin nombre' }}</div>
                                    <div v-if="booking.guest_phone" class="text-xs text-slate-500">{{ booking.guest_phone }}</div>
                                </Table.Td>
                                <Table.Td>{{ booking.people }}</Table.Td>
                                <Table.Td>
                                    <div class="font-medium">{{ money(booking.total) }}</div>
                                    <span v-if="booking.paid" class="mt-0.5 inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-[10px] font-medium text-success">
                                        <Lucide icon="CircleCheck" class="h-3 w-3" /> Pagada
                                    </span>
                                    <button
                                        v-else-if="booking.pending_payment?.checkout_url"
                                        type="button"
                                        class="mt-0.5 inline-flex items-center gap-1 rounded-full bg-warning/10 px-2 py-0.5 text-[10px] font-medium text-warning transition hover:bg-warning/20"
                                        title="Copiar link de pago vigente"
                                        @click="copyCheckout(booking)"
                                    >
                                        <Lucide icon="Copy" class="h-3 w-3" /> Cobro vigente
                                    </button>
                                    <span v-else-if="booking.pending_payment" class="mt-0.5 inline-flex items-center gap-1 rounded-full bg-warning/10 px-2 py-0.5 text-[10px] font-medium text-warning">
                                        Transferencia por verificar
                                    </span>
                                </Table.Td>
                                <Table.Td>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="bookingStatusClass[booking.status] ?? 'bg-slate-100 text-slate-500'">
                                        {{ booking.status_label }}
                                    </span>
                                </Table.Td>
                                <Table.Td>
                                    <div class="flex items-center justify-end gap-2">
                                        <Button
                                            v-if="!booking.paid && (booking.status === 'pending' || booking.status === 'confirmed')"
                                            variant="outline-secondary"
                                            size="sm"
                                            class="rounded-[0.5rem] bg-white !px-2.5 text-xs"
                                            title="Genera el link de pago o el cobro por transferencia"
                                            :disabled="chargingBooking === booking.id"
                                            @click="issuePayment(booking)"
                                        >
                                            <Lucide icon="CreditCard" class="mr-1 h-3.5 w-3.5" /> {{ chargingBooking === booking.id ? 'Generando…' : 'Cobrar' }}
                                        </Button>
                                        <Button
                                            v-if="booking.status === 'pending'"
                                            variant="outline-secondary"
                                            size="sm"
                                            class="rounded-[0.5rem] bg-white !px-2.5 text-xs"
                                            @click="setBookingStatus(booking, 'confirmed')"
                                        >
                                            Confirmar
                                        </Button>
                                        <Button
                                            v-if="booking.status === 'confirmed'"
                                            variant="outline-secondary"
                                            size="sm"
                                            class="rounded-[0.5rem] bg-white !px-2.5 text-xs"
                                            @click="setBookingStatus(booking, 'completed')"
                                        >
                                            Completar
                                        </Button>
                                        <button
                                            v-if="booking.status === 'pending' || booking.status === 'confirmed'"
                                            type="button"
                                            class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                            title="Cancelar reserva"
                                            @click="setBookingStatus(booking, 'cancelled')"
                                        >
                                            <Lucide icon="X" class="h-4 w-4" />
                                        </button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>
                <div v-else class="px-5 py-8 text-center text-sm text-slate-500">
                    Sin reservas próximas. Comparte la página pública o registra una desde aquí.
                </div>
            </div>
        </div>

        <!-- Modal experiencia -->
        <Dialog :open="showForm" size="lg" @close="showForm = false">
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="submit">
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-7 py-5 dark:border-darkmode-400">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="Compass" class="h-5 w-5 text-primary" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">{{ editing ? `Editar ${editing.name}` : 'Nueva experiencia' }}</h2>
                            <p class="mt-0.5 text-xs text-slate-500">La ficha que ve el huésped en la página pública de experiencias.</p>
                        </div>
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400" @click="showForm = false">
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="max-h-[70vh] space-y-6 overflow-y-auto px-7 py-6">
                        <section>
                            <div class="grid grid-cols-12 gap-5">
                                <div class="col-span-12">
                                    <label class="mb-1 block text-sm">Nombre</label>
                                    <FormInput v-model="form.name" type="text" placeholder="Recorrido en cuatrimoto" />
                                    <FormHelp v-if="errors.name" class="text-danger">{{ errors.name }}</FormHelp>
                                </div>
                                <div class="col-span-12">
                                    <label class="mb-1 block text-sm">Descripción</label>
                                    <FormTextarea v-model="form.description" rows="3" placeholder="Recorrido guiado por la sierra, 12 km entre pinos…" />
                                </div>
                                <div class="col-span-12">
                                    <label class="mb-1 block text-sm">Qué incluye</label>
                                    <div class="flex gap-2">
                                        <FormInput v-model="includeInput" type="text" placeholder="Equipo de seguridad" @keyup.enter.prevent="addInclude" />
                                        <Button type="button" variant="outline-secondary" class="shrink-0 rounded-[0.5rem] bg-white" @click="addInclude">
                                            <Lucide icon="Plus" class="h-4 w-4" />
                                        </Button>
                                    </div>
                                    <div v-if="form.includes.length" class="mt-2 flex flex-wrap gap-1.5">
                                        <span v-for="(item, index) in form.includes" :key="item" class="flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-darkmode-400 dark:text-slate-300">
                                            {{ item }}
                                            <button type="button" class="text-slate-400 hover:text-danger" @click="form.includes.splice(index, 1)">
                                                <Lucide icon="X" class="h-3 w-3" />
                                            </button>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="border-t border-dashed border-slate-300/70 pt-5">
                            <div class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="Tag" class="h-3.5 w-3.5" /> Precio y capacidad
                            </div>
                            <div class="grid grid-cols-12 gap-5">
                                <div class="col-span-12 sm:col-span-6">
                                    <label class="mb-1 block text-sm">Modalidad de precio</label>
                                    <FormSelect v-model="form.pricing_mode">
                                        <option v-for="(label, key) in pricingModes" :key="key" :value="key">{{ label }}</option>
                                    </FormSelect>
                                    <FormHelp>"Por grupo": un precio fijo sin importar cuántos van (ej. la cuatrimoto).</FormHelp>
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <label class="mb-1 block text-sm">Precio ($)</label>
                                    <FormInput v-model="form.price" type="number" step="0.01" min="0.01" placeholder="450.00" />
                                    <FormHelp v-if="errors.price" class="text-danger">{{ errors.price }}</FormHelp>
                                </div>
                                <div class="col-span-6 sm:col-span-4">
                                    <label class="mb-1 block text-sm">Mín. personas</label>
                                    <FormInput v-model.number="form.min_people" type="number" min="1" />
                                </div>
                                <div class="col-span-6 sm:col-span-4">
                                    <label class="mb-1 block text-sm">Máx. por reserva</label>
                                    <FormInput v-model="form.max_people" type="number" min="1" placeholder="Sin límite" />
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <label class="mb-1 block text-sm">Duración (minutos)</label>
                                    <FormInput v-model="form.duration_minutes" type="number" min="5" placeholder="120" />
                                </div>
                            </div>
                        </section>

                        <!-- Fotos -->
                        <section class="border-t border-dashed border-slate-300/70 pt-5">
                            <div class="mb-1 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                                <Lucide icon="Image" class="h-3.5 w-3.5" /> Fotos
                            </div>
                            <p class="mb-4 text-xs text-slate-500">La primera es la portada en la página pública.</p>
                            <div v-if="!editing" class="rounded-lg border border-dashed border-slate-300/70 px-4 py-5 text-center text-xs text-slate-500 dark:border-darkmode-400">
                                Guarda la experiencia primero; después podrás subir sus fotos desde aquí.
                            </div>
                            <template v-else>
                                <div v-if="photos.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                    <div v-for="(photo, index) in photos" :key="photo.id" class="group relative aspect-[4/3] overflow-hidden rounded-lg border border-slate-200/70 dark:border-darkmode-400">
                                        <img :src="photo.thumb_url" alt="" class="h-full w-full object-cover" loading="lazy" />
                                        <span v-if="index === 0" class="absolute left-1.5 top-1.5 rounded-full bg-primary px-2 py-0.5 text-[10px] font-medium text-white">Portada</span>
                                        <div class="absolute inset-x-0 bottom-0 flex justify-end gap-1 bg-gradient-to-t from-black/60 to-transparent p-1.5 opacity-0 transition group-hover:opacity-100">
                                            <button v-if="index !== 0" type="button" class="rounded bg-white/90 px-1.5 py-1 text-[10px] font-medium text-slate-700 transition hover:bg-white" :disabled="photoBusy" @click="makeCover(photo)">Portada</button>
                                            <button type="button" class="rounded bg-white/90 p-1 text-danger transition hover:bg-white" :disabled="photoBusy" @click="removePhoto(photo)">
                                                <Lucide icon="Trash2" class="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="rounded-lg border border-dashed border-slate-300/70 px-4 py-5 text-center text-xs text-slate-500 dark:border-darkmode-400">
                                    Sin fotos, la experiencia se muestra solo con texto.
                                </div>
                                <div class="mt-3 flex items-center gap-3">
                                    <input ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" @change="uploadPhotos" />
                                    <Button type="button" variant="outline-secondary" class="rounded-[0.5rem] bg-white" :disabled="photoBusy || photos.length >= 12" @click="photoInput?.click()">
                                        <Lucide :icon="photoBusy ? 'RefreshCw' : 'ImagePlus'" class="mr-2 h-4 w-4" :class="photoBusy && 'animate-spin'" />
                                        {{ photoBusy ? 'Subiendo…' : 'Agregar fotos' }}
                                    </Button>
                                    <span class="text-xs text-slate-400">{{ photos.length }} de 12</span>
                                </div>
                            </template>
                        </section>

                        <section class="border-t border-dashed border-slate-300/70 pt-5">
                            <div class="flex items-center justify-between rounded-lg border border-dashed border-slate-300/70 px-3 py-2.5 dark:border-darkmode-400">
                                <div class="text-sm">
                                    <div class="font-medium">Visible en la página pública</div>
                                    <p class="mt-0.5 text-xs text-slate-500">Pausada, no aparece ni se puede reservar; sus reservas existentes no cambian.</p>
                                </div>
                                <FormSwitch>
                                    <FormSwitch.Input :checked="form.active" type="checkbox" @change="form.active = !form.active" />
                                </FormSwitch>
                            </div>
                        </section>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-200/70 px-7 py-4 dark:border-darkmode-400">
                        <Button type="button" variant="outline-secondary" @click="showForm = false">Cancelar</Button>
                        <Button type="submit" variant="primary" :disabled="saving">
                            {{ saving ? 'Guardando…' : editing ? 'Guardar cambios' : 'Crear experiencia' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal sesiones -->
        <Dialog :open="sessionsFor !== null" @close="sessionsFor = null">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="CalendarDays" class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">Sesiones de {{ sessionsFor?.name }}</h2>
                            <p class="text-xs text-slate-500">Cada sesión tiene su propio cupo; el cupo es duro, nunca se sobrevende.</p>
                        </div>
                    </div>

                    <p v-if="sessionsFor?.sessions.length" class="mb-2 text-xs text-slate-400">
                        Se muestran los próximos 60 días; la venta está abierta todo el año.
                    </p>
                    <div v-if="sessionsFor?.sessions.length" class="max-h-64 space-y-2 overflow-y-auto">
                        <div
                            v-for="session in sessionsFor.sessions"
                            :key="session.id"
                            class="flex items-center justify-between gap-3 rounded-lg border border-slate-200/70 px-3 py-2.5 text-sm dark:border-darkmode-400"
                        >
                            <div class="min-w-0">
                                <div class="font-medium" :class="{ 'text-slate-400 line-through': session.status === 'cancelled' }">
                                    {{ formatDateTime(session.starts_at) }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ session.people_booked }} de {{ session.capacity }} lugares vendidos
                                    <span v-if="session.from_schedule" class="text-slate-400">· de programación</span>
                                    <span v-if="session.status === 'cancelled'" class="text-danger">· cancelada</span>
                                </div>
                            </div>
                            <div v-if="canManage && session.status === 'scheduled'" class="flex shrink-0 items-center gap-2">
                                <button type="button" class="rounded p-1.5 text-slate-400 transition hover:bg-warning/10 hover:text-warning" title="Cancelar sesión (cancela sus reservas vivas)" @click="cancelSession(session)">
                                    <Lucide icon="Ban" class="h-4 w-4" />
                                </button>
                                <button v-if="session.people_booked === 0" type="button" class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger" title="Borrar sesión" @click="deleteSession(session)">
                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-else class="rounded-lg border border-dashed border-slate-300/70 px-4 py-5 text-center text-xs text-slate-500 dark:border-darkmode-400">
                        Sin sesiones: la experiencia no aparece en la página pública hasta que agregues una con fecha futura.
                    </div>

                    <div v-if="canManage" class="mt-4 border-t border-dashed border-slate-300/70 pt-4 dark:border-darkmode-400">
                        <div class="grid grid-cols-12 items-end gap-3">
                            <div class="col-span-12 sm:col-span-6">
                                <label class="mb-1 block text-sm">Fecha y hora</label>
                                <FormInput v-model="sessionForm.starts_at" type="datetime-local" />
                                <FormHelp v-if="sessionErrors.starts_at" class="text-danger">{{ sessionErrors.starts_at }}</FormHelp>
                            </div>
                            <div class="col-span-6 sm:col-span-3">
                                <label class="mb-1 block text-sm">Cupo</label>
                                <FormInput v-model.number="sessionForm.capacity" type="number" min="1" max="500" />
                                <FormHelp v-if="sessionErrors.capacity" class="text-danger">{{ sessionErrors.capacity }}</FormHelp>
                            </div>
                            <div class="col-span-6 sm:col-span-3">
                                <Button type="button" variant="primary" class="w-full rounded-[0.5rem]" :disabled="sessionSaving || !sessionForm.starts_at" @click="addSession">
                                    {{ sessionSaving ? 'Creando…' : 'Agregar' }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal vehículos (flota) -->
        <Dialog :open="showVehicles" @close="showVehicles = false">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="Truck" class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">Vehículos para experiencias</h2>
                            <p class="text-xs text-slate-500">La flota es del hotel: el mismo vehículo puede servir a varias experiencias en horarios distintos.</p>
                        </div>
                    </div>

                    <div v-if="vehicles.length" class="max-h-64 space-y-2 overflow-y-auto">
                        <div
                            v-for="vehicle in vehicles"
                            :key="vehicle.id"
                            class="flex items-center justify-between gap-3 rounded-lg border border-slate-200/70 px-3 py-2.5 text-sm dark:border-darkmode-400"
                        >
                            <div class="min-w-0">
                                <div class="font-medium" :class="{ 'text-slate-400': !vehicle.active }">{{ vehicle.name }}</div>
                                <div class="text-xs text-slate-500">
                                    {{ vehicle.capacity }} persona(s)
                                    <span v-if="!vehicle.active" class="text-warning">· fuera de servicio</span>
                                </div>
                            </div>
                            <div v-if="canManage" class="flex shrink-0 items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded p-1.5 text-slate-400 transition hover:bg-warning/10 hover:text-warning"
                                    :title="vehicle.active ? 'Sacar de servicio (los horarios que lo usan bajan su cupo)' : 'Regresar a servicio'"
                                    @click="toggleVehicle(vehicle)"
                                >
                                    <Lucide :icon="vehicle.active ? 'Ban' : 'RotateCcw'" class="h-4 w-4" />
                                </button>
                                <button type="button" class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger" title="Eliminar vehículo" @click="deleteVehicle(vehicle)">
                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-else class="rounded-lg border border-dashed border-slate-300/70 px-4 py-5 text-center text-xs text-slate-500 dark:border-darkmode-400">
                        Sin vehículos: agrega el primero (razer, camioneta, cuatrimoto...) con cuántas personas lleva.
                    </div>

                    <div v-if="canManage" class="mt-4 border-t border-dashed border-slate-300/70 pt-4 dark:border-darkmode-400">
                        <div class="grid grid-cols-12 items-end gap-3">
                            <div class="col-span-12 sm:col-span-6">
                                <label class="mb-1 block text-sm">Nombre</label>
                                <FormInput v-model="vehicleForm.name" type="text" placeholder="Razer 1, camioneta roja…" />
                            </div>
                            <div class="col-span-6 sm:col-span-3">
                                <label class="mb-1 block text-sm">Personas</label>
                                <FormInput v-model.number="vehicleForm.capacity" type="number" min="1" max="100" />
                            </div>
                            <div class="col-span-6 sm:col-span-3">
                                <Button type="button" variant="primary" class="w-full rounded-[0.5rem]" :disabled="vehicleSaving || !vehicleForm.name.trim()" @click="addVehicle">
                                    {{ vehicleSaving ? 'Agregando…' : 'Agregar' }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal programación semanal -->
        <Dialog :open="scheduleFor !== null" size="lg" @close="scheduleFor = null">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="CalendarClock" class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">Programación de {{ scheduleFor?.name }}</h2>
                            <p class="text-xs text-slate-500">
                                Días que opera y horarios con sus vehículos. Las sesiones de todo el año se generan y actualizan solas — abierto a la venta como las habitaciones.
                            </p>
                        </div>
                    </div>

                    <div class="max-h-[70vh] overflow-y-auto">
                        <section>
                            <div class="text-xs font-medium uppercase tracking-wide text-slate-400">Días que opera</div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button
                                    v-for="day in WEEKDAYS"
                                    :key="day.value"
                                    type="button"
                                    class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
                                    :class="scheduleFor?.operating_days.includes(day.value)
                                        ? 'border-primary/20 bg-primary/10 text-primary'
                                        : 'border-slate-200/80 bg-white text-slate-500 hover:border-slate-300 dark:border-darkmode-400 dark:bg-darkmode-600'"
                                    :disabled="scheduleDaysSaving"
                                    @click="toggleDay(day.value)"
                                >
                                    {{ day.label }}
                                </button>
                            </div>
                            <FormHelp>Ejemplo: solo viernes a domingo = 3 días. Sin días marcados no se genera ninguna sesión.</FormHelp>
                        </section>

                        <section class="mt-5">
                            <div class="text-xs font-medium uppercase tracking-wide text-slate-400">Horarios</div>

                            <div v-if="scheduleFor?.slots.length" class="mt-2 space-y-2">
                                <div
                                    v-for="slot in scheduleFor.slots"
                                    :key="slot.id"
                                    class="flex items-center justify-between gap-3 rounded-lg border border-slate-200/70 px-3 py-2.5 text-sm dark:border-darkmode-400"
                                >
                                    <div class="min-w-0">
                                        <div class="font-medium" :class="{ 'text-slate-400': !slot.active }">
                                            {{ slot.start_time }}
                                            <span v-if="!slot.active" class="text-xs font-normal text-warning">· pausado</span>
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            {{ vehicleNames(slot) }} · cupo {{ slot.capacity ?? slot.effective_capacity }}
                                            <span v-if="slot.capacity === null" class="text-slate-400">(suma de vehículos)</span>
                                        </div>
                                    </div>
                                    <div v-if="canManage" class="flex shrink-0 items-center gap-2">
                                        <button
                                            type="button"
                                            class="rounded p-1.5 text-slate-400 transition hover:bg-warning/10 hover:text-warning"
                                            :title="slot.active ? 'Pausar horario (poda sus sesiones futuras sin reservas)' : 'Reactivar horario'"
                                            @click="toggleSlot(slot)"
                                        >
                                            <Lucide :icon="slot.active ? 'Ban' : 'RotateCcw'" class="h-4 w-4" />
                                        </button>
                                        <button type="button" class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger" title="Eliminar horario" @click="deleteSlot(slot)">
                                            <Lucide icon="Trash2" class="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="mt-2 rounded-lg border border-dashed border-slate-300/70 px-4 py-5 text-center text-xs text-slate-500 dark:border-darkmode-400">
                                Sin horarios: agrega el primero (por ejemplo 10:00 con el razer y la camioneta).
                            </div>

                            <div v-if="canManage" class="mt-4 border-t border-dashed border-slate-300/70 pt-4 dark:border-darkmode-400">
                                <div class="grid grid-cols-12 gap-3">
                                    <div class="col-span-6 sm:col-span-3">
                                        <label class="mb-1 block text-sm">Hora</label>
                                        <FormInput v-model="slotForm.start_time" type="time" />
                                    </div>
                                    <div class="col-span-6 sm:col-span-3">
                                        <label class="mb-1 block text-sm">Cupo manual</label>
                                        <FormInput v-model="slotForm.capacity" type="number" min="1" max="500" placeholder="Automático" />
                                    </div>
                                    <div class="col-span-12 sm:col-span-6">
                                        <label class="mb-1 block text-sm">Vehículos de este horario</label>
                                        <div v-if="vehicles.filter((v) => v.active).length" class="flex flex-wrap gap-2">
                                            <button
                                                v-for="vehicle in vehicles.filter((v) => v.active)"
                                                :key="vehicle.id"
                                                type="button"
                                                class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
                                                :class="slotForm.vehicle_ids.includes(vehicle.id)
                                                    ? 'border-primary/20 bg-primary/10 text-primary'
                                                    : 'border-slate-200/80 bg-white text-slate-500 hover:border-slate-300 dark:border-darkmode-400 dark:bg-darkmode-600'"
                                                @click="toggleSlotVehicle(vehicle.id)"
                                            >
                                                {{ vehicle.name }} ({{ vehicle.capacity }})
                                            </button>
                                        </div>
                                        <FormHelp v-else>Sin vehículos activos: usa el cupo manual o agrega vehículos primero.</FormHelp>
                                    </div>
                                </div>
                                <FormHelp>Sin cupo manual, el cupo es la suma de los vehículos marcados.</FormHelp>
                                <div class="mt-3 flex justify-end">
                                    <Button
                                        type="button"
                                        variant="primary"
                                        class="rounded-[0.5rem]"
                                        :disabled="slotSaving || !slotForm.start_time || (slotForm.vehicle_ids.length === 0 && slotForm.capacity === '')"
                                        @click="addSlot"
                                    >
                                        {{ slotSaving ? 'Agregando…' : 'Agregar horario' }}
                                    </Button>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal registrar reserva -->
        <Dialog :open="showBookingForm" @close="showBookingForm = false">
            <Dialog.Panel>
                <form class="p-5" @submit.prevent="submitBooking">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="TicketCheck" class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">Registrar reserva de experiencia</h2>
                            <p class="text-xs text-slate-500">Para huéspedes que llaman o llegan a recepción. El total lo calcula el sistema.</p>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm">Experiencia</label>
                            <FormSelect v-model="bookingForm.experience_id" @change="bookingForm.experience_session_id = ''">
                                <option value="" disabled>Elige una experiencia</option>
                                <option v-for="experience in experiences.filter((e) => e.active)" :key="experience.id" :value="experience.id">
                                    {{ experience.name }} — {{ experience.price_label }}
                                </option>
                            </FormSelect>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Sesión</label>
                            <FormSelect v-model="bookingForm.experience_session_id" :disabled="!bookingForm.experience_id">
                                <option value="" disabled>Elige fecha y horario</option>
                                <option v-for="session in bookableSessions" :key="session.id" :value="session.id">
                                    {{ formatDateTime(session.starts_at) }} · {{ session.remaining }} lugar(es)
                                </option>
                            </FormSelect>
                            <FormHelp v-if="bookingForm.experience_id && !bookableSessions.length">Esa experiencia no tiene sesiones con cupo; crea una primero.</FormHelp>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="mb-1 block text-sm">Personas</label>
                                <FormInput v-model.number="bookingForm.people" type="number" min="1" max="100" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Teléfono (opcional)</label>
                                <FormInput v-model="bookingForm.guest_phone" type="tel" placeholder="10 dígitos" />
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Nombre del huésped</label>
                            <FormInput v-model="bookingForm.guest_name" type="text" placeholder="Como se presenta" />
                            <FormHelp v-if="bookingErrors.guest_name" class="text-danger">{{ bookingErrors.guest_name }}</FormHelp>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Notas (opcional)</label>
                            <FormInput v-model="bookingForm.notes" type="text" placeholder="Pagó en efectivo, llega 10 min antes…" />
                        </div>
                        <div class="flex items-center justify-between rounded-lg border border-dashed border-slate-300/70 px-3 py-2.5 dark:border-darkmode-400">
                            <span class="text-sm">Confirmada de una vez</span>
                            <FormSwitch>
                                <FormSwitch.Input :checked="bookingForm.confirmed" type="checkbox" @change="bookingForm.confirmed = !bookingForm.confirmed" />
                            </FormSwitch>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="outline-secondary" @click="showBookingForm = false">Cancelar</Button>
                        <Button type="submit" variant="primary" :disabled="bookingSaving || !bookingForm.experience_session_id || !bookingForm.guest_name.trim()">
                            {{ bookingSaving ? 'Registrando…' : 'Registrar' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmar eliminación -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide icon="AlertTriangle" class="mx-auto mb-3 h-12 w-12 text-danger" />
                    <h2 class="text-base font-medium">¿Eliminar "{{ deleting?.name }}"?</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Se eliminan también sus sesiones y reservas. Si solo quieres dejar de venderla, pausa la experiencia con el switch del editor.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button variant="outline-secondary" @click="deleting = null">Cancelar</Button>
                        <Button variant="danger" @click="destroy">Sí, eliminar</Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
