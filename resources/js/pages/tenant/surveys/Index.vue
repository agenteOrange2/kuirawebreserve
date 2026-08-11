<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useModules } from '@/composables/useModules';
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
    rating: number;
    answers: ResponseAnswer[];
    comment: string | null;
    submitted_at: string;
}

const props = defineProps<{
    filters: { period: string; from: string; to: string };
    period: { label: string; from: string | null; to: string | null };
    kpis: {
        sent: number;
        answered: number;
        response_rate: number;
        avg_rating: number | null;
        low: number;
    };
    aspectAverages: AspectAverage[];
    distribution: Array<{ stars: number; count: number }>;
    responses: SurveyResponse[];
    canManage: boolean;
}>();

const maxDistribution = Math.max(
    1,
    ...props.distribution.map((row) => row.count),
);

// Satisfacción avanzada (Empresarial): el PDF del reporte.
const { hasModule } = useModules();

// ── Periodo ──
const period = ref(props.filters.period);
const customFrom = ref(props.filters.from);
const customTo = ref(props.filters.to);

function applyPeriod() {
    router.get(
        route('tenant.surveys'),
        {
            period: period.value,
            from: period.value === 'custom' ? customFrom.value : undefined,
            to: period.value === 'custom' ? customTo.value : undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
}

function pdfUrl(): string {
    const params = new URLSearchParams({ period: period.value });
    if (period.value === 'custom') {
        params.set('from', customFrom.value);
        params.set('to', customTo.value);
    }
    return route('tenant.surveys.pdf') + '?' + params.toString();
}
</script>

<template>
    <RazeLayout title="Encuestas">
        <div class="mt-2 grid grid-cols-12 gap-5">
            <!-- Encabezado -->
            <div class="col-span-12">
                <div
                    class="box box--stacked flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                        >
                            <Lucide
                                icon="Smile"
                                class="h-5 w-5 sm:h-7 sm:w-7"
                            />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-lg font-medium sm:text-xl">
                                Experiencia del huésped
                            </h1>
                            <p class="mt-1 text-sm text-slate-500">
                                Respuestas del cuestionario que se envía con el
                                agradecimiento al terminar cada estancia.
                            </p>
                        </div>
                    </div>
                    <div
                        class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center"
                    >
                        <Button
                            v-if="hasModule('encuestas-avanzado')"
                            as="a"
                            :href="pdfUrl()"
                            variant="outline-secondary"
                            class="min-h-11 rounded-[0.5rem] bg-white"
                        >
                            <Lucide icon="Download" class="mr-2 h-4 w-4" />
                            PDF
                        </Button>
                        <Button
                            v-if="canManage"
                            as="a"
                            :href="route('tenant.survey-settings')"
                            variant="outline-primary"
                            class="min-h-11 rounded-[0.5rem] bg-white"
                        >
                            <Lucide icon="ListChecks" class="mr-2 h-4 w-4" />
                            Personalizar preguntas
                        </Button>
                    </div>
                </div>

                <!-- Periodo -->
                <div
                    class="box box--stacked mt-5 flex flex-wrap items-end gap-3 p-3"
                >
                    <div>
                        <label class="mb-1 block text-xs text-slate-500"
                            >Periodo</label
                        >
                        <div class="relative">
                            <Lucide
                                icon="CalendarRange"
                                class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                            />
                            <FormSelect
                                v-model="period"
                                class="w-52 pl-9"
                                @change="applyPeriod"
                            >
                                <option value="all">Histórico completo</option>
                                <option value="day">Hoy</option>
                                <option value="week">Esta semana</option>
                                <option value="month">Este mes</option>
                                <option value="year">Este año</option>
                                <option value="custom">Personalizado</option>
                            </FormSelect>
                        </div>
                    </div>
                    <template v-if="period === 'custom'">
                        <div>
                            <label class="mb-1 block text-xs text-slate-500"
                                >Desde</label
                            >
                            <FormInput
                                v-model="customFrom"
                                type="date"
                                class="w-40"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-slate-500"
                                >Hasta</label
                            >
                            <FormInput
                                v-model="customTo"
                                type="date"
                                class="w-40"
                            />
                        </div>
                        <Button
                            variant="outline-primary"
                            class="rounded-[0.5rem] bg-white"
                            @click="applyPeriod"
                        >
                            <Lucide icon="RefreshCw" class="mr-2 h-4 w-4" />
                            Aplicar
                        </Button>
                    </template>
                    <div
                        class="ml-auto flex items-center gap-2 rounded-[0.5rem] border border-dashed border-slate-300/70 px-3 py-2 text-xs text-slate-500 dark:border-darkmode-400"
                    >
                        <Lucide
                            icon="CalendarCheck"
                            class="h-4 w-4 text-primary"
                        />
                        {{ props.period.label
                        }}<template v-if="props.period.from">
                            · {{ props.period.from }} →
                            {{ props.period.to }}</template
                        >
                        <span
                            v-if="kpis.low > 0"
                            class="rounded-full bg-danger/10 px-2 py-0.5 font-medium text-danger"
                        >
                            {{ kpis.low }} evaluación(es) baja(s)
                        </span>
                    </div>
                </div>
            </div>

            <!-- KPIs -->
            <div class="col-span-12 sm:col-span-4">
                <div
                    class="box box--stacked flex h-full items-center gap-4 p-5"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="Star" class="h-5 w-5" />
                    </div>
                    <div>
                        <div class="text-xl font-medium">
                            {{ kpis.avg_rating ?? '—' }}
                            <span
                                v-if="kpis.avg_rating !== null"
                                class="text-sm font-normal text-slate-400"
                                >de 5</span
                            >
                        </div>
                        <div class="text-sm text-slate-500">
                            Calificación general
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-span-12 sm:col-span-4">
                <div
                    class="box box--stacked flex h-full items-center gap-4 p-5"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="MessagesSquare" class="h-5 w-5" />
                    </div>
                    <div>
                        <div class="text-xl font-medium">
                            {{ kpis.answered }}
                        </div>
                        <div class="text-sm text-slate-500">
                            Respuestas ({{ kpis.response_rate }}% de
                            {{ kpis.sent }} enviadas)
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-span-12 sm:col-span-4">
                <div class="box box--stacked h-full p-5">
                    <template v-if="aspectAverages.length">
                        <div
                            v-for="aspect in aspectAverages"
                            :key="aspect.key"
                            class="flex items-center justify-between py-0.5 text-sm"
                        >
                            <span class="truncate pr-2 text-slate-500">{{
                                aspect.label
                            }}</span>
                            <span
                                class="flex shrink-0 items-center gap-1 font-medium"
                            >
                                <Lucide
                                    icon="Star"
                                    class="h-3.5 w-3.5 fill-current text-warning"
                                />
                                {{ aspect.average ?? '—' }}
                            </span>
                        </div>
                    </template>
                    <p v-else class="text-sm text-slate-400">
                        Sin aspectos configurados: la encuesta pregunta solo la
                        calificación general y el comentario.
                    </p>
                </div>
            </div>

            <!-- Distribución -->
            <div class="col-span-12 lg:col-span-4">
                <div class="box box--stacked h-full p-5">
                    <div class="text-base font-medium">
                        Distribución de calificaciones
                    </div>
                    <div class="mt-4 space-y-2.5">
                        <div
                            v-for="row in distribution"
                            :key="row.stars"
                            class="flex items-center gap-3"
                        >
                            <span
                                class="flex w-8 shrink-0 items-center gap-0.5 text-sm text-slate-600"
                            >
                                {{ row.stars }}
                                <Lucide
                                    icon="Star"
                                    class="h-3.5 w-3.5 fill-current text-warning"
                                />
                            </span>
                            <div
                                class="h-2.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                            >
                                <div
                                    class="h-full rounded-full bg-warning"
                                    :style="{
                                        width: `${(row.count / maxDistribution) * 100}%`,
                                    }"
                                />
                            </div>
                            <span
                                class="w-8 shrink-0 text-right text-sm text-slate-500"
                                >{{ row.count }}</span
                            >
                        </div>
                    </div>
                </div>
            </div>

            <!-- Respuestas -->
            <div class="col-span-12 lg:col-span-8">
                <div
                    class="box box--stacked h-full overflow-auto p-5 lg:overflow-visible"
                >
                    <div class="text-base font-medium">
                        Respuestas recientes
                    </div>
                    <Table v-if="responses.length" class="mt-3">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Huésped</Table.Th>
                                <Table.Th class="whitespace-nowrap"
                                    >Calificación</Table.Th
                                >
                                <Table.Th>Comentario</Table.Th>
                                <Table.Th class="whitespace-nowrap"
                                    >Fecha</Table.Th
                                >
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr
                                v-for="response in responses"
                                :key="response.id"
                            >
                                <Table.Td>
                                    <div class="font-medium">
                                        {{ response.guest ?? 'Huésped' }}
                                    </div>
                                    <div
                                        v-if="response.room"
                                        class="text-xs text-slate-500"
                                    >
                                        Habitación {{ response.room }}
                                    </div>
                                </Table.Td>
                                <Table.Td>
                                    <div class="flex items-center gap-0.5">
                                        <Lucide
                                            v-for="n in 5"
                                            :key="n"
                                            icon="Star"
                                            class="h-3.5 w-3.5"
                                            :class="
                                                n <= response.rating
                                                    ? 'fill-current text-warning'
                                                    : 'text-slate-300'
                                            "
                                        />
                                    </div>
                                    <div
                                        v-if="response.answers.length"
                                        class="mt-1 text-xs text-slate-400"
                                    >
                                        {{
                                            response.answers
                                                .map(
                                                    (a) =>
                                                        `${a.label} ${a.value}`,
                                                )
                                                .join(' · ')
                                        }}
                                    </div>
                                </Table.Td>
                                <Table.Td>
                                    <p class="max-w-80 text-sm text-slate-600">
                                        {{ response.comment ?? '—' }}
                                    </p>
                                </Table.Td>
                                <Table.Td
                                    class="text-xs whitespace-nowrap text-slate-500"
                                >
                                    {{ response.submitted_at }}
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div
                        v-else
                        class="flex flex-col items-center gap-3 py-10 text-center text-slate-400"
                    >
                        <Lucide icon="Smile" class="h-8 w-8" />
                        <p class="text-sm">
                            Aún no hay respuestas. El cuestionario se envía
                            solo, con el agradecimiento del check-out.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
