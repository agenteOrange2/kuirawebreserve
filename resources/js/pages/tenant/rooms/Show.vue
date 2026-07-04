<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface UsageStat { key: string; label: string; count: number; revenue: number }

const props = defineProps<{
    room: {
        id: number;
        number: string;
        name: string | null;
        description: string | null;
        room_type: string;
        base_price: number;
        zone: string | null;
        zone_color: string | null;
        status: string;
        status_label: string;
        status_color: string;
        beds_label: string | null;
        capacity: number | null;
        size_m2: number | null;
        view: string | null;
        amenities: string[];
        smoking: boolean;
        accessible: boolean;
        price_modifier: number | null;
        notes: string | null;
        maintenance_notes: string | null;
    };
    usage: UsageStat[];
    totals: { stays: number; revenue: number; last_stay_at: string | null };
    canManage: boolean;
}>();

const money = (n: number) => '$' + new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 }).format(n ?? 0);

const dotColor: Record<string, string> = {
    green: 'bg-success', cyan: 'bg-info', red: 'bg-primary', orange: 'bg-pending', blue: 'bg-warning', gray: 'bg-dark',
};
const tint: Record<string, string> = {
    green: 'border-success/10 bg-success/10 text-success',
    cyan: 'border-info/10 bg-info/10 text-info',
    red: 'border-primary/10 bg-primary/10 text-primary',
    orange: 'border-pending/10 bg-pending/10 text-pending',
    blue: 'border-warning/10 bg-warning/10 text-warning',
    gray: 'border-dark/10 bg-dark/10 text-dark',
};
const usageIcons: Record<string, Icon> = { week: 'CalendarDays', month: 'Calendar', quarter: 'CalendarRange', year: 'CalendarClock' };

const priceModifierLabel = computed(() => {
    const m = props.room.price_modifier;
    if (!m) return null;
    return (m > 0 ? '+$' : '−$') + Math.round(Math.abs(m));
});
</script>

<template>
    <RazeLayout :title="`Habitación ${room.number}`">
        <div class="grid grid-cols-12 gap-y-8 gap-x-6">
            <!-- Encabezado estilo reportes -->
            <div class="col-span-12">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <h1 class="text-lg font-medium">Habitación {{ room.number }}</h1>
                            <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium" :class="tint[room.status_color]">
                                <span class="h-2 w-2 rounded-full" :class="dotColor[room.status_color]" />
                                {{ room.status_label }}
                            </span>
                        </div>
                        <p class="text-sm text-slate-500">
                            {{ room.name ? `${room.name} · ` : '' }}{{ room.room_type }}<span v-if="room.zone"> · {{ room.zone }}</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button as="a" :href="route('tenant.rooms')" variant="outline-secondary" class="rounded-[0.5rem] bg-white">
                            <Lucide icon="ArrowLeft" class="mr-2 h-4 w-4 stroke-[1.3]" /> Habitaciones
                        </Button>
                        <Button as="a" :href="route('tenant.rooms.history', room.id)" variant="outline-primary" class="rounded-[0.5rem] bg-white">
                            <Lucide icon="History" class="mr-2 h-4 w-4 stroke-[1.3]" /> Ver historial
                        </Button>
                        <Button v-if="canManage" as="a" :href="`${route('tenant.rooms')}?edit=${room.id}`" variant="primary" class="rounded-[0.5rem] shadow-md shadow-primary/20">
                            <Lucide icon="Pencil" class="mr-2 h-4 w-4 stroke-[1.3]" /> Editar
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Uso por periodo (glance) -->
            <div class="col-span-12">
                <div class="grid grid-cols-12 gap-5">
                    <Link
                        v-for="stat in usage"
                        :key="stat.key"
                        :href="route('tenant.rooms.history', room.id)"
                        class="col-span-6 p-5 xl:col-span-3 box box--stacked transition hover:-translate-y-0.5"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex h-11 w-11 items-center justify-center rounded-full border" :class="tint[room.status_color]">
                                <Lucide :icon="usageIcons[stat.key] ?? 'Calendar'" class="h-5 w-5" />
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-medium leading-none">{{ stat.count }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ stat.count === 1 ? 'uso' : 'usos' }}</div>
                            </div>
                        </div>
                        <div class="mt-4 text-sm font-medium">{{ stat.label }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ money(stat.revenue) }} generados</div>
                    </Link>
                </div>
            </div>

            <!-- Ficha -->
            <div class="col-span-12 flex flex-col xl:col-span-8">
                <div class="flex items-center md:h-10"><div class="text-base font-medium">Ficha de la habitación</div></div>
                <div class="mt-3.5 box box--stacked flex flex-1 flex-col p-5">
                    <dl class="grid grid-cols-1 gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
                        <div class="flex items-center justify-between border-b border-dashed border-slate-200/70 pb-3 dark:border-darkmode-400">
                            <dt class="flex items-center gap-2 text-slate-500"><Lucide icon="Tag" class="h-4 w-4" /> Tipo</dt>
                            <dd class="font-medium">{{ room.room_type }}</dd>
                        </div>
                        <div class="flex items-center justify-between border-b border-dashed border-slate-200/70 pb-3 dark:border-darkmode-400">
                            <dt class="flex items-center gap-2 text-slate-500"><Lucide icon="DollarSign" class="h-4 w-4" /> Precio base + ajuste</dt>
                            <dd class="font-medium">
                                {{ money(room.base_price) }}
                                <span v-if="priceModifierLabel" class="ml-1 rounded-full px-1.5 py-0.5 text-xs" :class="room.price_modifier! > 0 ? 'bg-warning/10 text-warning' : 'bg-success/10 text-success'">{{ priceModifierLabel }}</span>
                            </dd>
                        </div>
                        <div class="flex items-center justify-between border-b border-dashed border-slate-200/70 pb-3 dark:border-darkmode-400">
                            <dt class="flex items-center gap-2 text-slate-500"><Lucide icon="Map" class="h-4 w-4" /> Zona</dt>
                            <dd class="flex items-center gap-1.5 font-medium">
                                <span v-if="room.zone_color" class="h-2 w-2 rounded-full" :style="{ backgroundColor: room.zone_color }" />
                                {{ room.zone ?? '—' }}
                            </dd>
                        </div>
                        <div class="flex items-center justify-between border-b border-dashed border-slate-200/70 pb-3 dark:border-darkmode-400">
                            <dt class="flex items-center gap-2 text-slate-500"><Lucide icon="BedDouble" class="h-4 w-4" /> Camas</dt>
                            <dd class="font-medium">{{ room.beds_label ?? '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between border-b border-dashed border-slate-200/70 pb-3 dark:border-darkmode-400">
                            <dt class="flex items-center gap-2 text-slate-500"><Lucide icon="Users" class="h-4 w-4" /> Capacidad</dt>
                            <dd class="font-medium">{{ room.capacity ? `${room.capacity} pers` : '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between border-b border-dashed border-slate-200/70 pb-3 dark:border-darkmode-400">
                            <dt class="flex items-center gap-2 text-slate-500"><Lucide icon="Ruler" class="h-4 w-4" /> Superficie</dt>
                            <dd class="font-medium">{{ room.size_m2 ? `${room.size_m2} m²` : '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between pb-1">
                            <dt class="flex items-center gap-2 text-slate-500"><Lucide icon="Eye" class="h-4 w-4" /> Vista</dt>
                            <dd class="font-medium capitalize">{{ room.view ?? '—' }}</dd>
                        </div>
                    </dl>

                    <div v-if="room.amenities.length" class="mt-4 border-t border-dashed border-slate-300/70 pt-4 dark:border-darkmode-400">
                        <div class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">Amenidades</div>
                        <div class="flex flex-wrap gap-1.5">
                            <span v-for="a in room.amenities" :key="a" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs dark:bg-darkmode-400">{{ a }}</span>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 border-t border-dashed border-slate-300/70 pt-4 text-sm dark:border-darkmode-400">
                        <span class="flex items-center gap-1.5" :class="room.smoking ? 'text-slate-700 dark:text-slate-200' : 'text-slate-400'">
                            <Lucide icon="Cigarette" class="h-4 w-4" /> {{ room.smoking ? 'Se permite fumar' : 'No fumar' }}
                        </span>
                        <span class="flex items-center gap-1.5" :class="room.accessible ? 'text-slate-700 dark:text-slate-200' : 'text-slate-400'">
                            <Lucide icon="Accessibility" class="h-4 w-4" /> {{ room.accessible ? 'Accesible' : 'No accesible' }}
                        </span>
                    </div>

                    <div v-if="room.description || room.notes || room.maintenance_notes" class="mt-4 space-y-2 border-t border-dashed border-slate-300/70 pt-4 text-sm dark:border-darkmode-400">
                        <p v-if="room.description"><span class="text-slate-400">Descripción:</span> {{ room.description }}</p>
                        <p v-if="room.notes"><span class="text-slate-400">Notas:</span> {{ room.notes }}</p>
                        <p v-if="room.maintenance_notes" class="text-pending"><Lucide icon="Wrench" class="mr-1 inline h-3.5 w-3.5" />{{ room.maintenance_notes }}</p>
                    </div>
                </div>
            </div>

            <!-- Resumen + CTA a historial -->
            <div class="col-span-12 flex flex-col xl:col-span-4">
                <div class="flex items-center md:h-10"><div class="text-base font-medium">Resumen de uso</div></div>
                <div class="mt-3.5 box box--stacked flex flex-1 flex-col p-5">
                    <div class="space-y-3 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-2 text-slate-500"><Lucide icon="BedDouble" class="h-4 w-4" /> Estancias totales</span>
                            <span class="text-lg font-medium">{{ totals.stays }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-2 text-slate-500"><Lucide icon="DollarSign" class="h-4 w-4" /> Ingresos generados</span>
                            <span class="text-lg font-medium text-success">{{ money(totals.revenue) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-2 text-slate-500"><Lucide icon="Clock" class="h-4 w-4" /> Última estancia</span>
                            <span class="font-medium">{{ totals.last_stay_at ?? '—' }}</span>
                        </div>
                    </div>
                    <div class="mt-auto pt-5">
                        <Button as="a" :href="route('tenant.rooms.history', room.id)" variant="primary" class="w-full rounded-[0.5rem]">
                            <Lucide icon="History" class="mr-2 h-4 w-4" /> Ver historial completo
                        </Button>
                        <p class="mt-2 text-center text-xs text-slate-400">Uso por semana/mes/año, estancias y cambios de estado.</p>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
