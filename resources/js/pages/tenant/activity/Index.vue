<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormDate, FormLabel, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ActivityRow {
    id: string;
    at: string | null;
    date: string | null;
    time: string | null;
    day_label: string;
    by: string;
    type: string;
    type_label: string;
    subject: string;
    message: string;
}
interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}
interface TypeOption {
    value: string;
    label: string;
}

const props = defineProps<{
    property: { id: number; name: string };
    staff: { id: number; name: string }[];
    filters: { user: string; type: string; from: string; to: string };
    types: TypeOption[];
    activities: {
        data: ActivityRow[];
        links: PaginationLink[];
        total: number;
    };
}>();

const user = ref(props.filters.user);
const type = ref(props.filters.type);
const from = ref(props.filters.from);
const to = ref(props.filters.to);

function applyFilters() {
    router.get(
        route('tenant.activity'),
        {
            user: user.value || undefined,
            type: type.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
}

function clearFilters() {
    user.value = '';
    type.value = '';
    from.value = '';
    to.value = '';
    applyFilters();
}

/** Los movimientos se agrupan por día: la fecha se dice una sola vez. */
const groupedByDay = computed(() => {
    const days: { date: string; label: string; rows: ActivityRow[] }[] = [];

    props.activities.data.forEach((row) => {
        const date = row.date ?? 'Sin fecha';
        const last = days[days.length - 1];

        if (last?.date === date) {
            last.rows.push(row);
            return;
        }

        days.push({ date, label: row.day_label, rows: [row] });
    });

    return days;
});

const filtersActive = computed(
    () =>
        !!props.filters.user ||
        !!props.filters.type ||
        !!props.filters.from ||
        !!props.filters.to,
);

const typeBadge: Record<string, string> = {
    reservation: 'bg-primary/10 text-primary',
    stay: 'bg-info/10 text-info',
    room: 'bg-success/10 text-success',
    incident: 'bg-warning/10 text-warning',
    payment: 'bg-pending/10 text-pending',
    other: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
};

const typeIcon: Record<string, Icon> = {
    reservation: 'CalendarCheck',
    stay: 'DoorOpen',
    room: 'BedDouble',
    incident: 'Wrench',
    payment: 'Wallet',
    other: 'CircleDot',
};
</script>

<template>
    <RazeLayout title="Actividad">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="History" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Bitácora de actividad
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ property.name }} · quién hizo qué y cuándo:
                            reservas, pagos, llegadas y salidas, semáforo,
                            incidencias y cupones.
                        </p>
                    </div>
                </div>
                <Button
                    :as="Link"
                    :href="route('tenant.users')"
                    variant="outline-secondary"
                    class="h-9 shrink-0 rounded-[0.5rem] bg-white text-xs"
                >
                    <Lucide icon="UserCog" class="mr-1.5 h-3.5 w-3.5" />
                    Usuarios
                </Button>
            </div>

            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <Lucide
                            icon="ClipboardList"
                            class="h-4 w-4 text-slate-400"
                        />
                        Movimientos
                        <span
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                        >
                            {{ activities.total }}
                        </span>
                    </div>
                    <button
                        v-if="filtersActive"
                        type="button"
                        class="ml-auto text-xs font-medium text-primary hover:underline"
                        @click="clearFilters"
                    >
                        Limpiar filtros
                    </button>
                </div>

                <!-- Filtros pegados a lo que filtran -->
                <div
                    class="border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div
                        class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 xl:grid-cols-[13rem_14rem_10rem_10rem_auto]"
                    >
                        <div>
                            <FormLabel htmlFor="act-user">Usuario</FormLabel>
                            <FormSelect
                                id="act-user"
                                v-model="user"
                                class="h-9 text-xs"
                            >
                                <option value="">Todos</option>
                                <option value="system">
                                    Sistema (automático o huésped web)
                                </option>
                                <option
                                    v-for="s in staff"
                                    :key="s.id"
                                    :value="String(s.id)"
                                >
                                    {{ s.name }}
                                </option>
                            </FormSelect>
                        </div>
                        <div>
                            <FormLabel htmlFor="act-type">Tipo</FormLabel>
                            <FormSelect
                                id="act-type"
                                v-model="type"
                                class="h-9 text-xs"
                            >
                                <option value="">Todo</option>
                                <option
                                    v-for="t in types"
                                    :key="t.value"
                                    :value="t.value"
                                >
                                    {{ t.label }}
                                </option>
                            </FormSelect>
                        </div>
                        <div>
                            <FormLabel htmlFor="act-from">Desde</FormLabel>
                            <FormDate
                                id="act-from"
                                v-model="from"
                                input-class="h-9 text-xs"
                            />
                        </div>
                        <div>
                            <FormLabel htmlFor="act-to">Hasta</FormLabel>
                            <FormDate
                                id="act-to"
                                v-model="to"
                                input-class="h-9 text-xs"
                            />
                        </div>
                        <div class="flex items-end">
                            <Button
                                variant="primary"
                                class="h-9 w-full rounded-[0.5rem] text-xs whitespace-nowrap xl:w-auto"
                                @click="applyFilters"
                            >
                                <Lucide
                                    icon="Search"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Filtrar
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Línea de tiempo agrupada por día: la fecha se dice una
                     vez y cada renglón solo lleva su hora. -->
                <template v-if="activities.data.length">
                    <div
                        v-for="day in groupedByDay"
                        :key="day.date"
                        class="border-b border-slate-200/60 last:border-b-0 dark:border-darkmode-400"
                    >
                        <div
                            class="flex flex-wrap items-center gap-2 bg-slate-50/70 px-4 py-2 sm:px-5 dark:bg-darkmode-600/40"
                        >
                            <Lucide
                                icon="CalendarDays"
                                class="h-3.5 w-3.5 text-slate-400"
                            />
                            <span class="text-xs font-medium">
                                {{ day.label }}
                            </span>
                            <span class="text-[11px] text-slate-400">
                                {{ day.date }}
                            </span>
                            <span
                                class="ml-auto rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                            >
                                {{ day.rows.length }}
                                {{
                                    day.rows.length === 1
                                        ? 'movimiento'
                                        : 'movimientos'
                                }}
                            </span>
                        </div>
                        <div
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="a in day.rows"
                                :key="a.id"
                                class="flex items-start gap-2.5 px-4 py-2.5 sm:px-5"
                            >
                                <span
                                    class="w-10 shrink-0 pt-1 text-[11px] text-slate-400 tabular-nums"
                                >
                                    {{ a.time }}
                                </span>
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                    :class="
                                        typeBadge[a.type] ?? typeBadge.other
                                    "
                                >
                                    <Lucide
                                        :icon="
                                            typeIcon[a.type] ?? typeIcon.other
                                        "
                                        class="h-4 w-4"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <span class="text-xs font-medium">
                                            {{ a.message }}
                                        </span>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                            :class="
                                                typeBadge[a.type] ??
                                                typeBadge.other
                                            "
                                        >
                                            {{ a.type_label }}
                                        </span>
                                    </div>
                                    <div
                                        class="truncate text-xs text-slate-500"
                                        :title="a.subject"
                                    >
                                        {{ a.subject }}
                                    </div>
                                </div>
                                <span
                                    class="shrink-0 text-[11px] whitespace-nowrap"
                                    :class="
                                        a.by === 'Sistema'
                                            ? 'text-slate-400'
                                            : 'font-medium text-slate-600 dark:text-slate-300'
                                    "
                                >
                                    {{ a.by }}
                                </span>
                            </div>
                        </div>
                    </div>
                </template>

                <div
                    v-else
                    class="flex flex-col items-center gap-2.5 px-4 py-10 text-center"
                >
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="History" class="h-5 w-5" />
                    </div>
                    <p class="text-xs text-slate-500">
                        No hay actividad con los filtros elegidos.
                    </p>
                    <button
                        v-if="filtersActive"
                        type="button"
                        class="text-xs font-medium text-primary hover:underline"
                        @click="clearFilters"
                    >
                        Limpiar filtros
                    </button>
                </div>

                <!-- Paginación -->
                <div
                    v-if="activities.links.length > 3"
                    class="flex flex-wrap justify-center gap-1 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <template v-for="(link, i) in activities.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-state
                            preserve-scroll
                            class="rounded-md px-2.5 py-1 text-xs transition"
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
    </RazeLayout>
</template>
