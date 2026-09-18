<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';

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
        | 'settlements';
    /** Lo que pide atención, para no entrar a ver si hay algo. */
    badges?: Partial<
        Record<'upcoming' | 'in-house' | 'pending' | 'settlements', number>
    >;
}>();

interface NavItem {
    key: string;
    label: string;
    icon: Icon;
    href: string;
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
    },
    {
        key: 'operation',
        label: 'Operación',
        icon: 'ClipboardList' as Icon,
        href: route('tenant.reservations.operation'),
    },
    {
        key: 'calendar',
        label: 'Calendario',
        icon: 'CalendarRange' as Icon,
        href: route('tenant.reservations.calendar'),
    },
    {
        key: 'upcoming',
        label: 'Próximas',
        icon: 'CalendarDays' as Icon,
        href: route('tenant.reservations.upcoming'),
        badge: props.badges?.upcoming,
    },
    {
        key: 'in-house',
        label: 'En casa',
        icon: 'DoorOpen' as Icon,
        href: route('tenant.reservations.in-house'),
        badge: props.badges?.['in-house'],
    },
    {
        key: 'pending',
        label: 'Pendientes',
        icon: 'AlarmClock' as Icon,
        href: route('tenant.reservations.pending'),
        badge: props.badges?.pending,
        tone: 'pending' as const,
    },
    {
        key: 'history',
        label: 'Historial',
        icon: 'History' as Icon,
        href: route('tenant.reservations.history'),
    },
    {
        key: 'settlements',
        label: 'Cuentas',
        icon: 'ReceiptText' as Icon,
        href: route('tenant.reservations.settlements'),
        badge: props.badges?.settlements,
        tone: 'danger' as const,
    },
]);
</script>

<template>
    <div
        class="box box--stacked mt-4 flex flex-wrap items-center gap-1.5 p-1.5"
    >
        <Link
            v-for="item in items"
            :key="item.key"
            :href="item.href"
            class="inline-flex h-8 items-center gap-1.5 rounded-[0.5rem] px-3 text-xs font-medium transition"
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
    </div>
</template>
