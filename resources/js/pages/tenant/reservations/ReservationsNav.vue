<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import { useModules } from '@/composables/useModules';

/**
 * Las superficies de reservas, siempre a la vista.
 *
 * La sección se partió en ocho pantallas (tablero, operación, calendario,
 * próximas, en casa, pendientes, historial y cuentas) y saltar de una a
 * otra obligaba a volver al menú lateral. Esta franja va en todas y marca
 * dónde estás parado, con lo atorado en la pestaña que le toca.
 */
const props = defineProps<{
    current:
        | 'hub'
        | 'operation'
        | 'calendar'
        | 'upcoming'
        | 'in-house'
        | 'pending'
        | 'history'
        | 'settlements'
        | 'courtesies';
    /** Lo que pide atención, para no entrar a ver si hay algo. */
    badges?: Partial<
        Record<'upcoming' | 'in-house' | 'pending' | 'settlements', number>
    >;
}>();

const page = usePage();
const { hasModule } = useModules();
const shared = computed(
    () =>
        page.props.panelTenant as
            | { permissions?: string[]; courtesies_pending?: number }
            | undefined,
);
const showCourtesies = computed(
    () =>
        hasModule('cortesias') &&
        !!shared.value?.permissions?.includes('courtesies.request'),
);

interface NavItem {
    key: string;
    label: string;
    icon: Icon;
    href: string;
    /** A qué parte del día pertenece: así se agrupa la franja. */
    group: 'start' | 'today' | 'next' | 'past';
    /** Para qué sirve, al pasar el cursor (y para lectores de pantalla). */
    hint: string;
    badge?: number;
    /** El número que pesa: se pinta en ámbar, no en gris. */
    tone?: 'pending' | 'danger';
}

const items = computed<NavItem[]>(() => [
    {
        key: 'hub',
        label: 'Resumen',
        icon: 'LayoutGrid' as Icon,
        href: route('tenant.reservations'),
        group: 'start' as const,
        hint: 'El tablero: cuántas hay en cada lista y lo que entró hoy',
    },
    {
        key: 'operation',
        label: 'Operación',
        icon: 'ClipboardList' as Icon,
        href: route('tenant.reservations.operation'),
        group: 'today' as const,
        hint: 'El día en el mostrador: vender, recibir, cobrar y dar salidas',
    },
    {
        key: 'calendar',
        label: 'Calendario',
        icon: 'CalendarRange' as Icon,
        href: route('tenant.reservations.calendar'),
        group: 'next' as const,
        hint: 'Qué habitación está ocupada cada día',
    },
    {
        key: 'upcoming',
        label: 'Próximas',
        icon: 'CalendarDays' as Icon,
        href: route('tenant.reservations.upcoming'),
        group: 'next' as const,
        hint: 'Todo lo apartado de hoy en adelante: confirmar, cobrar y recibir',
        badge: props.badges?.upcoming,
    },
    {
        key: 'in-house',
        label: 'En casa',
        icon: 'DoorOpen' as Icon,
        href: route('tenant.reservations.in-house'),
        group: 'today' as const,
        hint: 'Quién está dentro, cuándo sale y cuánto debe',
        badge: props.badges?.['in-house'],
    },
    {
        key: 'pending',
        label: 'Pendientes',
        icon: 'AlarmClock' as Icon,
        href: route('tenant.reservations.pending'),
        group: 'next' as const,
        hint: 'Apartados que esperan confirmación y cuentas sin cobrar',
        badge: props.badges?.pending,
        tone: 'pending' as const,
    },
    {
        key: 'history',
        label: 'Historial',
        icon: 'History' as Icon,
        href: route('tenant.reservations.history'),
        group: 'past' as const,
        hint: 'Reservas terminadas, canceladas o que no llegaron',
    },
    {
        key: 'settlements',
        label: 'Cuentas',
        icon: 'ReceiptText' as Icon,
        href: route('tenant.reservations.settlements'),
        group: 'past' as const,
        hint: 'Estancias que se cerraron con saldo: cobrarlas o cerrarlas',
        badge: props.badges?.settlements,
        tone: 'danger' as const,
    },
    // Cortesías (módulo cortesias): con lo que espera al propietario.
    ...(showCourtesies.value
        ? [
              {
                  key: 'courtesies',
                  label: 'Cortesías',
                  icon: 'HandHeart' as Icon,
                  href: route('tenant.reservations.courtesies'),
                  group: 'past' as const,
                  hint: 'Cortesías pedidas y aprobadas por el propietario',
                  badge: shared.value?.courtesies_pending || undefined,
                  tone: 'pending' as const,
              },
          ]
        : []),
]);
const groupLabel: Record<NavItem['group'], string> = {
    start: '',
    today: 'Hoy',
    next: 'Lo que viene',
    past: 'Lo que pasó',
};

// Dentro de cada grupo, primero lo que más se usa.
const order = [
    'hub',
    'operation',
    'in-house',
    'upcoming',
    'pending',
    'calendar',
    'history',
    'settlements',
    'courtesies',
];

const groups = computed(() =>
    (['start', 'today', 'next', 'past'] as const)
        .map((key) => ({
            key,
            label: groupLabel[key],
            items: items.value
                .filter((item) => item.group === key)
                .sort((x, y) => order.indexOf(x.key) - order.indexOf(y.key)),
        }))
        .filter((group) => group.items.length),
);
</script>

<template>
    <!-- Agrupada por momento del día: en la capacitación nadie sabía si
         "Próximas" y "Pendientes" eran lo mismo, ni dónde se cobra. -->
    <nav
        class="box box--stacked mt-4 flex flex-wrap items-center gap-x-1 gap-y-1.5 p-1.5"
        aria-label="Secciones de reservas"
    >
        <template v-for="(group, g) in groups" :key="group.key">
            <span
                v-if="g > 0"
                class="mx-1 hidden h-5 w-px bg-slate-200 sm:block dark:bg-darkmode-400"
                aria-hidden="true"
            />
            <span
                v-if="group.label"
                class="hidden px-1.5 text-[11px] font-medium tracking-wide text-slate-400 uppercase 2xl:inline"
                >{{ group.label }}</span
            >
            <Link
                v-for="item in group.items"
                :key="item.key"
                :href="item.href"
                :title="item.hint"
                :aria-current="current === item.key ? 'page' : undefined"
                class="inline-flex h-8 items-center gap-1.5 rounded-[0.5rem] px-2.5 text-xs font-medium transition"
                :class="
                    current === item.key
                        ? 'bg-primary/10 text-primary'
                        : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-darkmode-400 dark:hover:text-slate-300'
                "
            >
                <Lucide :icon="item.icon" class="h-3.5 w-3.5" />
                {{ item.label }}
                <span
                    v-if="item.badge"
                    class="rounded-full px-1.5 text-[11px] font-medium"
                    :class="
                        item.tone === 'danger'
                            ? 'bg-danger/10 text-danger'
                            : item.tone === 'pending'
                              ? 'bg-pending/10 text-pending'
                              : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                    "
                    >{{ item.badge }}</span
                >
            </Link>
        </template>
    </nav>
</template>
