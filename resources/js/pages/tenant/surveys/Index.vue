<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormDate,
    FormInput,
    FormLabel,
    FormSelect,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useModules } from '@/composables/useModules';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface AspectAverage {
    key: string;
    label: string;
    average: number | null;
}

interface ResponseAnswer {
    label: string;
    value: number;
}

interface SurveyResponse {
    id: number;
    guest: string | null;
    room: string | null;
    room_id: number | null;
    stay_id: number | null;
    guest_phone: string | null;
    guest_email: string | null;
    rating: number;
    answers: ResponseAnswer[];
    comment: string | null;
    submitted_at: string;
    handled_at: string | null;
    handled_by: string | null;
    handled_notes: string | null;
    incident_id: number | null;
    needs_follow_up: boolean;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    filters: {
        period: string;
        from: string;
        to: string;
        show: string;
        q: string;
    };
    period: { label: string; from: string | null; to: string | null };
    kpis: {
        sent: number;
        answered: number;
        response_rate: number;
        avg_rating: number | null;
        low: number;
        pending: number;
    };
    aspectAverages: AspectAverage[];
    distribution: Array<{ stars: number; count: number }>;
    responses: Paginated<SurveyResponse>;
    matching: number;
    incidentCategories: Array<{ value: string; label: string }>;
    canManage: boolean;
}>();

const toast = useToasts();
const { hasModule } = useModules();

const maxDistribution = computed(() =>
    Math.max(1, ...props.distribution.map((row) => row.count)),
);

// ── Periodo ──
const period = ref(props.filters.period);
const customFrom = ref(props.filters.from);
const customTo = ref(props.filters.to);

// ── Filtros de la lista (no tocan los promedios del periodo) ──
const show = ref(props.filters.show);
const q = ref(props.filters.q);

function query(extra: Record<string, unknown> = {}) {
    return {
        period: period.value,
        from: period.value === 'custom' ? customFrom.value : undefined,
        to: period.value === 'custom' ? customTo.value : undefined,
        show: show.value === 'all' ? undefined : show.value,
        q: q.value || undefined,
        ...extra,
    };
}

function applyFilters() {
    router.get(route('tenant.surveys'), query(), {
        preserveScroll: true,
        preserveState: true,
        // Sin esto cada tecla del buscador deja una entrada en el historial.
        replace: true,
    });
}

// Un solo temporizador para los tres filtros: así, limpiar la lista (que
// toca dos a la vez) manda una consulta, no dos.
let timer: ReturnType<typeof setTimeout> | null = null;

function scheduleApply(delay = 350) {
    if (timer) clearTimeout(timer);
    timer = setTimeout(applyFilters, delay);
}

watch(q, () => scheduleApply());
watch([show, period], () => scheduleApply(0));

function pdfUrl(): string {
    const params = new URLSearchParams({ period: period.value });
    if (period.value === 'custom') {
        params.set('from', customFrom.value);
        params.set('to', customTo.value);
    }
    return route('tenant.surveys.pdf') + '?' + params.toString();
}

const showOptions = [
    { value: 'all', label: 'Todas las respuestas' },
    { value: 'pending', label: 'Por atender' },
    { value: 'low', label: 'Calificaron 3 o menos' },
    { value: 'commented', label: 'Con comentario' },
    { value: 'handled', label: 'Ya atendidas' },
];

// ── Detalle y seguimiento ──
const detail = ref<SurveyResponse | null>(null);
const busy = ref(false);
const handleNotes = ref('');

function openDetail(response: SurveyResponse) {
    detail.value = response;
    handleNotes.value = response.handled_notes ?? '';
    replyText.value = '';
    incidentForm.value = null;
}

async function toggleHandled(handled: boolean) {
    if (!detail.value) return;
    busy.value = true;
    try {
        const { data } = await axios.patch(
            `/api/stay-surveys/${detail.value.id}/handle`,
            { handled, notes: handleNotes.value || null },
        );
        Object.assign(detail.value, {
            handled_at: data.handled_at,
            handled_by: data.handled_by,
            handled_notes: data.handled_notes,
            needs_follow_up: !data.handled_at && detail.value.needs_follow_up,
        });
        toast.success(
            handled ? 'Caso cerrado' : 'Caso reabierto',
            handled
                ? 'Queda constancia de quién lo atendió.'
                : 'Vuelve a la lista de pendientes.',
        );
        router.reload({ only: ['kpis', 'responses', 'matching'] });
    } catch (e: any) {
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Ocurrió un error inesperado.',
        );
    } finally {
        busy.value = false;
    }
}

// ── Responderle al huésped ──
const replyText = ref('');
const sendingReply = ref(false);

async function sendReply() {
    if (!detail.value || !replyText.value.trim()) return;
    sendingReply.value = true;
    try {
        await axios.post(`/api/stay-surveys/${detail.value.id}/reply`, {
            message: replyText.value.trim(),
        });
        replyText.value = '';
        toast.success('Mensaje enviado', 'Salió por WhatsApp y correo.');
    } catch (e: any) {
        toast.error(
            'No se pudo enviar',
            e.response?.data?.message ??
                'Revisa el WhatsApp del hotel y el correo.',
        );
    } finally {
        sendingReply.value = false;
    }
}

const waLink = computed(() => {
    const digits = (detail.value?.guest_phone ?? '').replace(/\D+/g, '');
    if (!digits) return null;
    const phone = digits.length === 10 ? `52${digits}` : digits;
    return `https://wa.me/${phone}`;
});

// ── Levantar la incidencia que destapó la queja ──
const incidentForm = ref<{
    title: string;
    category: string;
    priority: string;
} | null>(null);

function openIncidentForm() {
    if (!detail.value) return;
    incidentForm.value = {
        title:
            (detail.value.comment ?? '').slice(0, 110) ||
            'Queja de la encuesta',
        category: '',
        priority: (detail.value.rating ?? 5) <= 2 ? 'high' : 'medium',
    };
}

async function createIncident() {
    if (!detail.value || !incidentForm.value) return;
    busy.value = true;
    try {
        const { data } = await axios.post(
            `/api/stay-surveys/${detail.value.id}/incident`,
            incidentForm.value,
        );
        detail.value.incident_id = data.incident_id;
        incidentForm.value = null;
        toast.success(
            'Incidencia levantada',
            'Quedó ligada a esta respuesta, con su tiempo objetivo.',
        );
        router.reload({ only: ['responses'] });
    } catch (e: any) {
        toast.error(
            'No se pudo levantar',
            e.response?.data?.message ?? 'Ocurrió un error inesperado.',
        );
    } finally {
        busy.value = false;
    }
}

// ── Eliminar (respuestas de prueba o spam) ──
const deleting = ref<SurveyResponse | null>(null);

async function destroy() {
    if (!deleting.value) return;
    try {
        await axios.delete(`/api/stay-surveys/${deleting.value.id}`);
        toast.success('Respuesta eliminada');
        if (detail.value?.id === deleting.value.id) detail.value = null;
        deleting.value = null;
        router.reload({
            only: [
                'kpis',
                'responses',
                'matching',
                'distribution',
                'aspectAverages',
            ],
        });
    } catch (e: any) {
        toast.error(
            'No se pudo eliminar',
            e.response?.data?.message ?? 'Ocurrió un error inesperado.',
        );
    }
}

/** Filtros de la lista activos (el periodo tiene su propia franja). */
const listFiltersActive = computed(
    () => show.value !== 'all' || q.value.trim() !== '',
);

function clearListFilters() {
    show.value = 'all';
    q.value = '';
}

const ratingTone = (rating: number) =>
    rating <= 2
        ? 'text-danger'
        : rating === 3
          ? 'text-pending'
          : 'text-warning';
</script>

<template>
    <RazeLayout title="Encuestas">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Smile" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Experiencia del huésped
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Respuestas del cuestionario que sale con el
                            agradecimiento del check-out. Cada queja se cierra
                            aquí, con nombre y fecha.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2.5"
                >
                    <Button
                        v-if="hasModule('encuestas-avanzado')"
                        as="a"
                        :href="pdfUrl()"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide icon="Download" class="mr-1.5 h-3.5 w-3.5" />
                        Descargar PDF
                    </Button>
                    <Button
                        v-if="canManage"
                        :as="Link"
                        :href="route('tenant.survey-settings')"
                        variant="outline-primary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide icon="ListChecks" class="mr-1.5 h-3.5 w-3.5" />
                        Personalizar preguntas
                    </Button>
                </div>
            </div>

            <!-- Periodo: manda sobre TODO el reporte, no solo sobre la lista -->
            <div
                class="box box--stacked mt-4 bg-slate-50/70 px-4 py-3 dark:bg-darkmode-600/40"
            >
                <div class="mb-3 flex flex-wrap items-center gap-2.5">
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="CalendarRange" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            Periodo del reporte
                        </div>
                        <div class="text-xs text-slate-500">
                            Los indicadores, la distribución y los aspectos se
                            calculan sobre este rango.
                        </div>
                    </div>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 text-[11px] font-medium text-primary md:ml-auto"
                    >
                        <Lucide icon="CalendarCheck" class="h-3.5 w-3.5" />
                        {{ props.period.label }}
                        <template v-if="props.period.from">
                            · {{ props.period.from }} → {{ props.period.to }}
                        </template>
                    </span>
                </div>
                <div
                    class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-[14rem_12rem_12rem_auto]"
                >
                    <div>
                        <FormLabel htmlFor="survey-period">Periodo</FormLabel>
                        <FormSelect
                            id="survey-period"
                            v-model="period"
                            class="h-9 text-xs"
                        >
                            <option value="all">Histórico completo</option>
                            <option value="day">Hoy</option>
                            <option value="week">Esta semana</option>
                            <option value="month">Este mes</option>
                            <option value="year">Este año</option>
                            <option value="custom">Personalizado</option>
                        </FormSelect>
                    </div>
                    <template v-if="period === 'custom'">
                        <div>
                            <FormLabel htmlFor="survey-from">Desde</FormLabel>
                            <FormDate
                                id="survey-from"
                                v-model="customFrom"
                                input-class="h-9 text-xs"
                            />
                        </div>
                        <div>
                            <FormLabel htmlFor="survey-to">Hasta</FormLabel>
                            <FormDate
                                id="survey-to"
                                v-model="customTo"
                                input-class="h-9 text-xs"
                            />
                        </div>
                        <div class="flex items-end">
                            <Button
                                variant="outline-primary"
                                class="h-9 w-full bg-white text-xs whitespace-nowrap xl:w-auto"
                                @click="applyFilters"
                            >
                                <Lucide
                                    icon="RefreshCw"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Aplicar
                            </Button>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Indicadores del periodo -->
            <div class="mt-4 grid grid-cols-12 gap-4">
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="Star" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{
                                kpis.avg_rating !== null
                                    ? `${kpis.avg_rating} de 5`
                                    : 'Sin dato'
                            }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Calificación general
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="MessagesSquare" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ kpis.answered }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Respuestas · {{ kpis.response_rate }}% de
                            {{ kpis.sent }} enviadas
                        </div>
                    </div>
                </div>
                <!-- El número que manda la operación: lo que nadie ha cerrado -->
                <button
                    type="button"
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 text-left transition sm:col-span-6 xl:col-span-3"
                    :class="
                        show === 'pending'
                            ? 'ring-2 ring-primary/60'
                            : 'hover:border-primary/30'
                    "
                    title="Ver solo las que faltan por atender"
                    @click="show = show === 'pending' ? 'all' : 'pending'"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                        :class="
                            kpis.pending > 0
                                ? 'border-pending/10 bg-pending/10 text-pending'
                                : 'border-success/10 bg-success/10 text-success'
                        "
                    >
                        <Lucide
                            :icon="
                                kpis.pending > 0 ? 'CircleAlert' : 'CircleCheck'
                            "
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ kpis.pending }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Por atender · quejas sin cerrar
                        </div>
                    </div>
                </button>
                <button
                    type="button"
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 text-left transition sm:col-span-6 xl:col-span-3"
                    :class="
                        show === 'low'
                            ? 'ring-2 ring-primary/60'
                            : 'hover:border-primary/30'
                    "
                    title="Ver solo las de 3 estrellas o menos"
                    @click="show = show === 'low' ? 'all' : 'low'"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                        :class="
                            kpis.low > 0
                                ? 'border-danger/10 bg-danger/10 text-danger'
                                : 'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400 dark:text-slate-300'
                        "
                    >
                        <Lucide icon="ThumbsDown" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">{{ kpis.low }}</div>
                        <div class="truncate text-xs text-slate-500">
                            Bajas · calificaron 1 o 2
                        </div>
                    </div>
                </button>
            </div>

            <div class="mt-4 grid grid-cols-12 items-start gap-5">
                <!-- Distribución -->
                <div class="col-span-12 lg:col-span-5">
                    <div class="box box--stacked">
                        <div
                            class="flex items-center gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-warning/10 text-warning"
                            >
                                <Lucide icon="ChartColumn" class="h-4 w-4" />
                            </div>
                            <div>
                                <div class="font-medium">
                                    Distribución de calificaciones
                                </div>
                                <div class="text-xs text-slate-500">
                                    Cuántas de cada estrella en el periodo.
                                </div>
                            </div>
                        </div>
                        <div class="space-y-2 p-4">
                            <div
                                v-for="row in distribution"
                                :key="row.stars"
                                class="flex items-center gap-3"
                            >
                                <span
                                    class="flex w-7 shrink-0 items-center gap-0.5 text-xs text-slate-600 dark:text-slate-300"
                                >
                                    {{ row.stars }}
                                    <Lucide
                                        icon="Star"
                                        class="h-3 w-3 fill-current text-warning"
                                    />
                                </span>
                                <div
                                    class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                >
                                    <div
                                        class="h-full rounded-full bg-warning transition-all"
                                        :style="{
                                            width: `${(row.count / maxDistribution) * 100}%`,
                                        }"
                                    />
                                </div>
                                <span
                                    class="w-7 shrink-0 text-right text-xs text-slate-500"
                                >
                                    {{ row.count }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Promedio por aspecto -->
                <div class="col-span-12 lg:col-span-7">
                    <div class="box box--stacked">
                        <div
                            class="flex items-center gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Lucide icon="Gauge" class="h-4 w-4" />
                            </div>
                            <div>
                                <div class="font-medium">
                                    Promedio por aspecto
                                </div>
                                <div class="text-xs text-slate-500">
                                    Las preguntas que el hotel eligió en
                                    Personalizar preguntas.
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="aspectAverages.length"
                            class="grid grid-cols-1 gap-x-6 gap-y-2 p-4 sm:grid-cols-2"
                        >
                            <div
                                v-for="aspect in aspectAverages"
                                :key="aspect.key"
                                class="flex items-center gap-3"
                            >
                                <span
                                    class="min-w-0 flex-1 truncate text-xs text-slate-500"
                                    :title="aspect.label"
                                >
                                    {{ aspect.label }}
                                </span>
                                <div
                                    class="h-2 w-20 shrink-0 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                >
                                    <div
                                        class="h-full rounded-full bg-primary transition-all"
                                        :style="{
                                            width: `${((aspect.average ?? 0) / 5) * 100}%`,
                                        }"
                                    />
                                </div>
                                <span
                                    class="w-7 shrink-0 text-right text-xs font-medium"
                                >
                                    {{ aspect.average ?? 'n/d' }}
                                </span>
                            </div>
                        </div>
                        <p
                            v-else
                            class="px-4 py-6 text-center text-xs text-slate-500"
                        >
                            Sin aspectos configurados: la encuesta pregunta solo
                            la calificación general y el comentario.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Respuestas -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <Lucide
                            icon="MessagesSquare"
                            class="h-4 w-4 text-slate-400"
                        />
                        Respuestas recibidas
                        <span
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                        >
                            {{ responses.total }}
                        </span>
                    </div>
                    <div
                        class="ml-auto flex items-center gap-2 text-xs text-slate-500"
                    >
                        <span v-if="responses.data.length">
                            Mostrando {{ responses.from }}-{{ responses.to }} de
                            {{ responses.total }}
                        </span>
                        <button
                            v-if="listFiltersActive"
                            type="button"
                            class="font-medium text-primary hover:underline"
                            @click="clearListFilters"
                        >
                            Limpiar filtros
                        </button>
                    </div>
                </div>

                <div
                    class="border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="mb-3 flex items-center gap-2.5">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                        >
                            <Lucide icon="Filter" class="h-4 w-4" />
                        </div>
                        <div>
                            <div class="text-sm font-medium">
                                Encuentra una respuesta
                            </div>
                            <div class="text-xs text-slate-500">
                                Estos filtros acotan la lista; los indicadores
                                de arriba siguen siendo del periodo completo.
                            </div>
                        </div>
                    </div>
                    <div
                        class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-[minmax(15rem,1.5fr)_14rem_auto]"
                    >
                        <div>
                            <FormLabel htmlFor="survey-search">
                                Búsqueda rápida
                            </FormLabel>
                            <div class="relative">
                                <Lucide
                                    icon="Search"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                />
                                <FormInput
                                    id="survey-search"
                                    v-model="q"
                                    type="search"
                                    class="h-9 pl-9 text-xs"
                                    placeholder="Huésped, habitación o comentario"
                                />
                            </div>
                        </div>
                        <div>
                            <FormLabel htmlFor="survey-show">Mostrar</FormLabel>
                            <FormSelect
                                id="survey-show"
                                v-model="show"
                                class="h-9 text-xs"
                            >
                                <option
                                    v-for="option in showOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </FormSelect>
                        </div>
                        <div class="flex items-end">
                            <Button
                                v-if="listFiltersActive"
                                type="button"
                                variant="outline-secondary"
                                class="h-9 w-full text-xs whitespace-nowrap xl:w-auto"
                                @click="clearListFilters"
                            >
                                <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                                Limpiar
                            </Button>
                        </div>
                    </div>
                </div>

                <div v-if="responses.data.length" class="overflow-auto">
                    <Table sm hover class="text-xs">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="whitespace-nowrap">
                                    Huésped
                                </Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Calificación
                                </Table.Th>
                                <Table.Th>Comentario</Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Seguimiento
                                </Table.Th>
                                <Table.Th class="text-right whitespace-nowrap">
                                    Acciones
                                </Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr
                                v-for="response in responses.data"
                                :key="response.id"
                            >
                                <Table.Td>
                                    <div class="text-sm font-medium">
                                        {{ response.guest ?? 'Huésped' }}
                                    </div>
                                    <div class="text-slate-500">
                                        <template v-if="response.room">
                                            Habitación {{ response.room }} ·
                                        </template>
                                        {{ response.submitted_at }}
                                    </div>
                                </Table.Td>
                                <Table.Td class="whitespace-nowrap">
                                    <div class="flex items-center gap-0.5">
                                        <Lucide
                                            v-for="n in 5"
                                            :key="n"
                                            icon="Star"
                                            class="h-3.5 w-3.5"
                                            :class="
                                                n <= response.rating
                                                    ? `fill-current ${ratingTone(response.rating)}`
                                                    : 'text-slate-300 dark:text-darkmode-400'
                                            "
                                        />
                                    </div>
                                    <div
                                        v-if="response.answers.length"
                                        class="mt-0.5 text-slate-400"
                                    >
                                        {{ response.answers.length }}
                                        {{
                                            response.answers.length === 1
                                                ? 'aspecto'
                                                : 'aspectos'
                                        }}
                                    </div>
                                </Table.Td>
                                <Table.Td>
                                    <p
                                        class="line-clamp-2 max-w-80 text-slate-600 dark:text-slate-300"
                                        :title="response.comment ?? undefined"
                                    >
                                        {{
                                            response.comment ?? 'Sin comentario'
                                        }}
                                    </p>
                                </Table.Td>
                                <Table.Td>
                                    <span
                                        v-if="response.handled_at"
                                        class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success"
                                    >
                                        <Lucide
                                            icon="CircleCheck"
                                            class="h-3.5 w-3.5"
                                        />
                                        Atendida
                                    </span>
                                    <span
                                        v-else-if="response.needs_follow_up"
                                        class="inline-flex items-center gap-1 rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                    >
                                        <Lucide
                                            icon="CircleAlert"
                                            class="h-3.5 w-3.5"
                                        />
                                        Por atender
                                    </span>
                                    <span v-else class="text-slate-400">
                                        Sin pendientes
                                    </span>
                                    <div
                                        v-if="response.handled_by"
                                        class="mt-0.5 text-slate-400"
                                    >
                                        {{ response.handled_by }} ·
                                        {{ response.handled_at }}
                                    </div>
                                    <Link
                                        v-if="response.incident_id"
                                        :href="
                                            route(
                                                'tenant.incidents.show',
                                                response.incident_id,
                                            )
                                        "
                                        class="mt-0.5 flex items-center gap-1 text-primary hover:underline"
                                    >
                                        <Lucide
                                            icon="Wrench"
                                            class="h-3.5 w-3.5"
                                        />
                                        Incidencia levantada
                                    </Link>
                                </Table.Td>
                                <Table.Td class="text-right whitespace-nowrap">
                                    <div
                                        class="flex items-center justify-end gap-1.5"
                                    >
                                        <Button
                                            variant="outline-primary"
                                            class="h-8 rounded-[0.5rem] text-xs"
                                            @click="openDetail(response)"
                                        >
                                            <Lucide
                                                icon="Eye"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Ver
                                        </Button>
                                        <button
                                            v-if="canManage"
                                            type="button"
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-danger dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                            title="Eliminar respuesta"
                                            @click="deleting = response"
                                        >
                                            <Lucide
                                                icon="Trash2"
                                                class="h-3.5 w-3.5"
                                            />
                                        </button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>

                <div
                    v-else-if="listFiltersActive"
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="SearchX" class="h-7 w-7" />
                    </div>
                    <div>
                        <p class="text-sm font-medium">
                            Nada coincide con los filtros
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cambia la búsqueda o vuelve a ver todas las
                            respuestas.
                        </p>
                    </div>
                    <Button
                        variant="outline-secondary"
                        @click="clearListFilters"
                    >
                        <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                        Limpiar filtros
                    </Button>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary"
                    >
                        <Lucide icon="Smile" class="h-7 w-7" />
                    </div>
                    <div>
                        <p class="text-sm font-medium">
                            Todavía no hay respuestas en este periodo
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            El cuestionario se envía solo, con el agradecimiento
                            del check-out.
                        </p>
                    </div>
                </div>

                <div
                    v-if="responses.links.length > 3"
                    class="flex flex-wrap justify-center gap-1 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <template v-for="(link, i) in responses.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-state
                            preserve-scroll
                            class="rounded-md px-2.5 py-1 text-xs"
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
                            class="px-2.5 py-1 text-xs text-slate-400"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>

        <!-- Detalle de la respuesta y su seguimiento -->
        <Dialog :open="detail !== null" size="lg" @close="detail = null">
            <Dialog.Panel v-if="detail">
                <div class="flex max-h-[85vh] flex-col">
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                        >
                            <Lucide icon="Star" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                {{ detail.guest ?? 'Huésped' }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                <template v-if="detail.room">
                                    Habitación {{ detail.room }} ·
                                </template>
                                {{ detail.submitted_at }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-0.5">
                            <Lucide
                                v-for="n in 5"
                                :key="n"
                                icon="Star"
                                class="h-3.5 w-3.5"
                                :class="
                                    n <= detail.rating
                                        ? `fill-current ${ratingTone(detail.rating)}`
                                        : 'text-slate-300 dark:text-darkmode-400'
                                "
                            />
                        </div>
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="!h-9 !w-9 shrink-0 rounded-full !p-0"
                            title="Cerrar"
                            @click="detail = null"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </Button>
                    </div>

                    <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                        <!-- Lo que calificó -->
                        <div v-if="detail.answers.length">
                            <div
                                class="mb-2 text-[11px] font-medium text-slate-400"
                            >
                                ASPECTOS
                            </div>
                            <div
                                class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2"
                            >
                                <div
                                    v-for="answer in detail.answers"
                                    :key="answer.label"
                                    class="flex items-center justify-between gap-3 text-sm"
                                >
                                    <span
                                        class="min-w-0 truncate text-slate-500"
                                        >{{ answer.label }}</span
                                    >
                                    <span class="flex shrink-0 gap-0.5">
                                        <Lucide
                                            v-for="n in 5"
                                            :key="n"
                                            icon="Star"
                                            class="h-3.5 w-3.5"
                                            :class="
                                                n <= answer.value
                                                    ? 'fill-current text-warning'
                                                    : 'text-slate-300 dark:text-darkmode-400'
                                            "
                                        />
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Lo que escribió -->
                        <div v-if="detail.comment">
                            <div
                                class="mb-2 text-[11px] font-medium text-slate-400"
                            >
                                COMENTARIO
                            </div>
                            <p
                                class="rounded-lg bg-slate-50 px-3.5 py-3 text-sm whitespace-pre-line text-slate-600 dark:bg-darkmode-600/50 dark:text-slate-300"
                            >
                                {{ detail.comment }}
                            </p>
                        </div>

                        <!-- Contacto -->
                        <div
                            v-if="detail.guest_phone || detail.guest_email"
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500"
                        >
                            <span
                                v-if="detail.guest_phone"
                                class="flex items-center gap-1.5"
                            >
                                <Lucide icon="Phone" class="h-3.5 w-3.5" />
                                {{ detail.guest_phone }}
                            </span>
                            <span
                                v-if="detail.guest_email"
                                class="flex items-center gap-1.5"
                            >
                                <Lucide icon="Mail" class="h-3.5 w-3.5" />
                                {{ detail.guest_email }}
                            </span>
                            <a
                                v-if="waLink"
                                :href="waLink"
                                target="_blank"
                                rel="noopener"
                                class="flex items-center gap-1 text-success hover:underline"
                            >
                                <Lucide
                                    icon="MessageCircle"
                                    class="h-3.5 w-3.5"
                                />
                                Abrir WhatsApp
                            </a>
                        </div>

                        <template v-if="canManage">
                            <!-- Responderle -->
                            <div
                                class="rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                            >
                                <div class="text-xs font-medium">
                                    Responderle al huésped
                                </div>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Sale por su WhatsApp y su correo. Escríbelo
                                    tú: una disculpa de plantilla se nota.
                                </p>
                                <FormTextarea
                                    v-model="replyText"
                                    rows="2"
                                    maxlength="1000"
                                    class="mt-2 text-xs"
                                    placeholder="Gracias por decírnoslo, ya lo estamos corrigiendo…"
                                />
                                <div class="mt-2 flex justify-end">
                                    <Button
                                        variant="outline-primary"
                                        class="h-8 rounded-[0.5rem] bg-white text-xs"
                                        :disabled="
                                            !replyText.trim() || sendingReply
                                        "
                                        @click="sendReply"
                                    >
                                        <Lucide
                                            icon="Send"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        {{
                                            sendingReply
                                                ? 'Enviando…'
                                                : 'Enviar respuesta'
                                        }}
                                    </Button>
                                </div>
                            </div>

                            <!-- La queja que era una falla real -->
                            <div
                                v-if="hasModule('incidencias')"
                                class="rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                            >
                                <div class="text-xs font-medium">
                                    Incidencia de mantenimiento
                                </div>
                                <Link
                                    v-if="detail.incident_id"
                                    :href="
                                        route(
                                            'tenant.incidents.show',
                                            detail.incident_id,
                                        )
                                    "
                                    class="mt-1 flex items-center gap-1.5 text-xs text-primary hover:underline"
                                >
                                    <Lucide icon="Wrench" class="h-3.5 w-3.5" />
                                    Ya se levantó una: abrir el ticket
                                </Link>
                                <template v-else-if="incidentForm">
                                    <FormInput
                                        v-model="incidentForm.title"
                                        type="text"
                                        maxlength="120"
                                        class="mt-2 h-9 text-xs"
                                        placeholder="Qué hay que arreglar"
                                    />
                                    <div class="mt-2 grid grid-cols-2 gap-2">
                                        <FormSelect
                                            v-model="incidentForm.category"
                                            class="h-9 text-xs"
                                        >
                                            <option value="">
                                                Sin clasificar
                                            </option>
                                            <option
                                                v-for="option in incidentCategories"
                                                :key="option.value"
                                                :value="option.value"
                                            >
                                                {{ option.label }}
                                            </option>
                                        </FormSelect>
                                        <FormSelect
                                            v-model="incidentForm.priority"
                                            class="h-9 text-xs"
                                        >
                                            <option value="high">
                                                Prioridad alta
                                            </option>
                                            <option value="medium">
                                                Prioridad media
                                            </option>
                                            <option value="low">
                                                Prioridad baja
                                            </option>
                                        </FormSelect>
                                    </div>
                                    <div class="mt-2 flex justify-end gap-2">
                                        <Button
                                            variant="outline-secondary"
                                            class="h-8 rounded-[0.5rem] bg-white text-xs"
                                            @click="incidentForm = null"
                                        >
                                            Cancelar
                                        </Button>
                                        <Button
                                            variant="primary"
                                            class="h-8 rounded-[0.5rem] text-xs"
                                            :disabled="
                                                busy ||
                                                !incidentForm.title.trim()
                                            "
                                            @click="createIncident"
                                        >
                                            Levantar incidencia
                                        </Button>
                                    </div>
                                </template>
                                <template v-else>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        Si la queja era una falla real, aquí se
                                        convierte en trabajo con responsable y
                                        tiempo objetivo.
                                    </p>
                                    <Button
                                        variant="outline-primary"
                                        class="mt-2 h-8 rounded-[0.5rem] bg-white text-xs"
                                        @click="openIncidentForm"
                                    >
                                        <Lucide
                                            icon="Wrench"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Levantar incidencia
                                    </Button>
                                </template>
                            </div>

                            <!-- Cerrar el caso -->
                            <div
                                class="rounded-lg border p-3.5"
                                :class="
                                    detail.handled_at
                                        ? 'border-success/40 bg-success/5'
                                        : 'border-slate-200/70 dark:border-darkmode-400'
                                "
                            >
                                <div class="text-xs font-medium">
                                    Seguimiento
                                </div>
                                <p
                                    v-if="detail.handled_at"
                                    class="mt-0.5 text-xs text-success"
                                >
                                    Atendida por
                                    {{
                                        detail.handled_by ??
                                        'alguien del equipo'
                                    }}
                                    el {{ detail.handled_at }}.
                                </p>
                                <FormTextarea
                                    v-model="handleNotes"
                                    rows="2"
                                    maxlength="1000"
                                    class="mt-2 text-xs"
                                    placeholder="Qué se hizo: se le llamó, se cambió de habitación, se le dio cortesía…"
                                />
                                <div class="mt-2 flex justify-end gap-2">
                                    <Button
                                        v-if="detail.handled_at"
                                        variant="outline-secondary"
                                        class="h-8 rounded-[0.5rem] bg-white text-xs"
                                        :disabled="busy"
                                        @click="toggleHandled(false)"
                                    >
                                        Reabrir
                                    </Button>
                                    <Button
                                        variant="primary"
                                        class="h-8 rounded-[0.5rem] text-xs"
                                        :disabled="busy"
                                        @click="toggleHandled(true)"
                                    >
                                        <Lucide
                                            icon="CircleCheck"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        {{
                                            detail.handled_at
                                                ? 'Actualizar nota'
                                                : 'Marcar atendida'
                                        }}
                                    </Button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div
                        class="flex justify-between gap-2 border-t border-slate-200/60 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            v-if="canManage"
                            variant="outline-danger"
                            class="h-9 rounded-[0.5rem] bg-white px-4 text-xs"
                            @click="deleting = detail"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            Eliminar
                        </Button>
                        <Button
                            variant="outline-secondary"
                            class="ml-auto h-9 rounded-[0.5rem] bg-white px-4 text-xs"
                            @click="detail = null"
                        >
                            Cerrar
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmar eliminación -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide
                        icon="TriangleAlert"
                        class="mx-auto mb-3 h-10 w-10 text-danger"
                    />
                    <h2 class="text-base font-medium">
                        ¿Eliminar esta respuesta?
                    </h2>
                    <p class="mt-2 text-xs text-slate-500">
                        Se borra del reporte y deja de contar en los promedios.
                        Úsalo solo para respuestas de prueba o spam.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                            @click="deleting = null"
                        >
                            Cancelar
                        </Button>
                        <Button
                            variant="danger"
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                            @click="destroy"
                        >
                            Sí, eliminar
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
