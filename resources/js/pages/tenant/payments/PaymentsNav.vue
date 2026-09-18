<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';

/**
 * Las áreas de caja y pagos, siempre a la vista.
 *
 * El dinero vivía en seis rutas de dos grupos distintos del menú y no había
 * forma de saltar de una a otra sin volver al menú lateral: "está todo
 * desorganizado y se pierde uno". Esta franja va en las cuatro pantallas y
 * marca dónde estás parado.
 */
const props = defineProps<{
    current: 'hub' | 'verify' | 'collect' | 'movements';
    canManage: boolean;
    canCashCuts: boolean;
    /** Lo que pide atención, para no entrar a ver si hay algo. */
    badges?: Partial<Record<'verify' | 'collect', number>>;
}>();

interface NavItem {
    key: string;
    label: string;
    icon: Icon;
    href: string;
    show: boolean;
    badge?: number;
}

const items = computed<NavItem[]>(() =>
    [
        {
            key: 'hub',
            label: 'Resumen',
            icon: 'LayoutGrid' as Icon,
            href: route('tenant.payments'),
            show: true,
        },
        {
            key: 'verify',
            label: 'Por verificar',
            icon: 'Landmark' as Icon,
            href: route('tenant.payments.verify'),
            show: props.canManage,
            badge: props.badges?.verify,
        },
        {
            key: 'collect',
            label: 'Por cobrar',
            icon: 'TriangleAlert' as Icon,
            href: route('tenant.payments.collect'),
            show: true,
            badge: props.badges?.collect,
        },
        {
            key: 'movements',
            label: 'Movimientos',
            icon: 'ArrowLeftRight' as Icon,
            href: route('tenant.payments.movements'),
            show: true,
        },
        {
            key: 'cashcuts',
            label: 'Cortes de caja',
            icon: 'Calculator' as Icon,
            href: props.canCashCuts ? route('tenant.cashcuts') : '',
            show: props.canCashCuts,
        },
    ].filter((item) => item.show),
);
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
                class="rounded-full bg-pending/10 px-1.5 text-[11px] font-medium text-pending"
                >{{ item.badge }}</span
            >
        </Link>
    </div>
</template>
