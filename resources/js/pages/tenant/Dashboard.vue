<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import type { ChartOptions, TooltipItem } from 'chart.js';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import Chart from '@/components/Base/Chart';
import { FormDate, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import { useModules } from '@/composables/useModules';
import { usePropertyMode } from '@/composables/usePropertyMode';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface Metric {
    key: string;
    title: string;
    value: string;
    change: number | null;
    desc: string;
}
interface RevenuePoint {
    label: string;
    value: number;
    lodging: number;
    pos: number;
}
interface SeriesPoint {
    label: string;
    value: number;
}
interface StatusCount {
    value: string;
    label: string;
    color: string;
    count: number;
}
interface ActivityRow {
    id: number;
    room: string | null;
    room_id: number | null;
    from: string | null;
    to: string;
    to_color: string;
    by: string;
    at: string;
    at_full: string;
}
interface ArrivalRow {
    id: number;
    code: string;
    guest_name: string;
    room: string | null;
    eta: string | null;
    time: string;
    people: number | null;
    checked_in: boolean;
    pending: boolean;
}
interface DepartureRow {
    id: number;
    reservation_id: number | null;
    room_id: number | null;
    code: string | null;
    guest_name: string;
    room: string | null;
    time: string;
    overdue: boolean;
    balance: number;
}

const props = defineProps<{
    filters: { range: string; from: string | null; to: string | null };
    expiringHolds: {
        id: number;
        code: string;
        guest_name: string | null;
        room: string | null;
        expires_at: string;
    }[];
    hero: { revenue: string; change: number | null; period: string };
    metrics: Metric[];
    series: {
        label: string;
        revenue: RevenuePoint[];
        occupancy: SeriesPoint[];
        revenue_total: number;
        revenue_change: number | null;
        occupancy_avg: number;
        occupancy_change: number | null;
    };
    guestStatus: { in_house: number; checked_out: number; pending: number };
    roomTypeDistribution: { label: string; count: number }[];
    // Mantenimiento pendiente (null sin el módulo incidencias).
    maintenance: {
        open: number;
        overdue: number;
        high: number;
        items: {
            id: number;
            title: string;
            room: string | null;
            priority: string;
            priority_label: string;
            overdue: boolean;
            age_hours: number;
        }[];
    } | null;
    statuses: StatusCount[];
    occupancy: {
        occupied: number;
        total: number;
        percent: number;
        reserved: number;
        available: number;
    };
    arrivals: ArrivalRow[];
    departures: DepartureRow[];
    today: {
        arrivals: number;
        arrivals_pending: number;
        departures: number;
        overdue: number;
        settlements: number;
    };
    channels: { channel: string; label: string; count: number }[];
    byMethod: {
        method: string;
        label: string;
        amount: number;
        count: number;
    }[];
    totals: {
        rooms: number;
        zones: number;
        roomTypes: number;
        staff: number;
        properties: number;
    };
    plan: { name: string; max_rooms: number | null; max_users: number | null };
    recentActivity: ActivityRow[];
}>();

// ── Permisos, módulos y modo: un botón que responde 403 es peor que
// ninguno, así que cada acceso se pinta solo si la ruta lo dejaría pasar. ──
const page = usePage();
const permissions = computed<string[]>(
    () =>
        (page.props.panelTenant as { permissions?: string[] } | undefined)
            ?.permissions ?? [],
);
const can = (permission: string) => permissions.value.includes(permission);
const { hasModule } = useModules();
const { isMotel } = usePropertyMode();

// ── Formato ──
const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 }).format(n ?? 0);
const compactMoney = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', { notation: 'compact' }).format(n ?? 0);
const plural = (n: number, one: string, many: string) =>
    `${n} ${n === 1 ? one : many}`;

// Antigüedad en palabras: "hace 3 días" pesa más que "454 h".
const ageLabel = (hours: number) => {
    if (hours < 1) return 'recién';
    if (hours < 24) return `hace ${hours} h`;
    const days = Math.floor(hours / 24);

    return `hace ${days} ${days === 1 ? 'día' : 'días'}`;
};

const todayLabel = (() => {
    const label = new Date().toLocaleDateString('es-MX', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });

    return label.charAt(0).toUpperCase() + label.slice(1);
})();

// ── Clases del canon ──
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const ghostLink =
    'inline-flex h-8 items-center gap-1 rounded-[0.5rem] border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600 dark:text-slate-300';
// Acción de cabecera de tarjeta: en el teléfono baja a su renglón a todo
// lo ancho en vez de aplastar el título.
const headerAction = `${ghostLink} max-sm:w-full max-sm:justify-center`;
const rowLink =
    'flex items-center gap-3 px-4 py-3 transition hover:bg-slate-50/80 sm:px-5 dark:hover:bg-darkmode-400/40';

type Tone = 'danger' | 'warning' | 'pending' | 'info' | 'primary' | 'success';
const toneTint: Record<Tone, string> = {
    danger: 'border-danger/10 bg-danger/10 text-danger',
    warning: 'border-warning/10 bg-warning/10 text-warning',
    pending: 'border-pending/10 bg-pending/10 text-pending',
    info: 'border-info/10 bg-info/10 text-info',
    primary: 'border-primary/10 bg-primary/10 text-primary',
    success: 'border-success/10 bg-success/10 text-success',
};
const toneChip: Record<Tone, string> = {
    danger: 'bg-danger/10 text-danger hover:bg-danger/20',
    warning: 'bg-warning/10 text-warning hover:bg-warning/20',
    pending: 'bg-pending/10 text-pending hover:bg-pending/20',
    info: 'bg-info/10 text-info hover:bg-info/20',
    primary: 'bg-primary/10 text-primary hover:bg-primary/20',
    success: 'bg-success/10 text-success hover:bg-success/20',
};

// ── Semáforo: mismos tokens que el plano (ocupada = danger, no primary:
// el primario lo elige cada hotel y con un azul oscuro se confundía con
// mantenimiento). ──
const statusHex: Record<string, string> = {
    available: '#0d9488',
    reserved: '#0891b2',
    occupied: '#b91c1c',
    dirty: '#78350f',
    cleaning: '#ca8a04',
    maintenance: '#1e293b',
};
const statusDot: Record<string, string> = {
    available: 'bg-success',
    reserved: 'bg-info',
    occupied: 'bg-danger',
    dirty: 'bg-dirty',
    cleaning: 'bg-warning',
    maintenance: 'bg-dark',
};
const statusTint: Record<string, string> = {
    available: 'border-success/10 bg-success/10 text-success',
    reserved: 'border-info/10 bg-info/10 text-info',
    occupied: 'border-danger/10 bg-danger/10 text-danger',
    dirty: 'border-dirty/10 bg-dirty/10 text-dirty dark:bg-dirty/30 dark:text-slate-200',
    cleaning: 'border-warning/10 bg-warning/10 text-warning',
    maintenance: 'border-dark/10 bg-dark/10 text-dark dark:text-slate-300',
};
const statusIcon: Record<string, Icon> = {
    available: 'BedDouble',
    reserved: 'CalendarClock',
    occupied: 'UserCheck',
    dirty: 'Trash2',
    cleaning: 'Sparkles',
    maintenance: 'Wrench',
};
// La bitácora trae el color del enum, no el estado.
const colorToStatus: Record<string, string> = {
    green: 'available',
    cyan: 'reserved',
    red: 'occupied',
    brown: 'dirty',
    blue: 'cleaning',
    gray: 'maintenance',
};

// El primario lo cambia cada hotel en Apariencia: las gráficas lo leen de
// la variable CSS para no quedarse con el azul de Kuira.
const themeHex = (token: string, fallback: string) => {
    if (typeof window === 'undefined') return fallback;
    const value = getComputedStyle(document.documentElement)
        .getPropertyValue(`--color-${token}`)
        .trim();

    return /^#[0-9a-f]{6}$/i.test(value) ? value : fallback;
};
const primaryHex = themeHex('primary', '#03045e');

// ── Filtro de periodo (hoy / semana / mes / personalizado) ──
// `range` es lo aplicado; `rangeChoice`, lo que muestra el selector. Elegir
// "Personalizado" abre el modal y solo recarga al aplicar: antes cada fecha
// tecleada disparaba una recarga y el periodo cambiaba a medias.
const range = ref(props.filters.range);
const rangeChoice = ref(props.filters.range);
const customFrom = ref(props.filters.from ?? '');
const customTo = ref(props.filters.to ?? '');
const customOpen = ref(false);

// El backend recorta el rango personalizado a este máximo; el campo lo
// impide antes para que nadie pida 6 meses y reciba 3 sin enterarse.
const MAX_CUSTOM_DAYS = 92;

const maxCustomTo = computed(() => {
    if (!customFrom.value) return undefined;
    const limit = new Date(`${customFrom.value}T00:00`);
    limit.setDate(limit.getDate() + MAX_CUSTOM_DAYS);

    return limit.toISOString().slice(0, 10);
});

function reload() {
    router.get(
        route('tenant.dashboard'),
        {
            range: range.value,
            from: range.value === 'custom' ? customFrom.value : undefined,
            to: range.value === 'custom' ? customTo.value : undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
}

function onRangeChange() {
    if (rangeChoice.value === 'custom') {
        customOpen.value = true;

        return;
    }
    range.value = rangeChoice.value;
    reload();
}

function closeCustom() {
    customOpen.value = false;
    rangeChoice.value = range.value;
}

function applyCustom() {
    if (!customFrom.value || !customTo.value) return;
    range.value = 'custom';
    rangeChoice.value = 'custom';
    customOpen.value = false;
    reload();
}

// ── Avisos: lo que hay que atender ahora, del más grave al más leve ──
interface NoticeChip {
    key: string | number;
    label: string;
    meta?: string;
    href: string;
}
interface Notice {
    key: string;
    tone: Tone;
    icon: Icon;
    title: string;
    detail: string;
    chips?: NoticeChip[];
    chipTone?: (chip: NoticeChip) => Tone;
    action?: { label: string; href: string };
}

const hasRooms = computed(() => props.totals.rooms > 0);
const dirtyRooms = computed(
    () => props.statuses.find((s) => s.value === 'dirty')?.count ?? 0,
);

const notices = computed<Notice[]>(() => {
    const list: Notice[] = [];

    if (!hasRooms.value) {
        list.push({
            key: 'onboarding',
            tone: 'primary',
            icon: 'Sparkles',
            title: 'Configura tu propiedad en dos pasos',
            detail: 'Define zonas y tipos, da de alta tus habitaciones y acomódalas en el plano.',
            chips: [
                {
                    key: 'catalog',
                    label: '1. Zonas y tipos',
                    href: route('tenant.catalog'),
                },
                {
                    key: 'rooms',
                    label: '2. Habitaciones',
                    href: route('tenant.rooms'),
                },
            ],
            chipTone: () => 'primary',
        });
    }

    // Cortesías esperando al propietario (módulo cortesias): mientras no
    // contesta, la cuenta sigue con su precio y el mostrador no sabe qué
    // cobrar. El conteo solo viaja para el propietario.
    const courtesiesPending =
        (page.props.panelTenant as { courtesies_pending?: number } | undefined)
            ?.courtesies_pending ?? 0;
    if (courtesiesPending > 0) {
        list.push({
            key: 'courtesies',
            tone: 'pending',
            icon: 'HandHeart',
            title: `${plural(courtesiesPending, 'cortesía espera', 'cortesías esperan')} tu aprobación`,
            detail: 'El personal pidió hospedaje gratis o con descuento. Mientras decides, la cuenta sigue con su precio.',
            action: {
                label: 'Revisar cortesías',
                href: route('tenant.reservations.courtesies', {
                    estado: 'pending',
                }),
            },
        });
    }

    if (props.today.overdue > 0) {
        list.push({
            key: 'overdue',
            tone: 'danger',
            icon: 'ClockAlert',
            title: `${plural(props.today.overdue, 'estancia pasó', 'estancias pasaron')} su hora de salida`,
            detail: 'Confirma si siguen dentro, cobra el tiempo extra o registra la salida.',
            chips: props.departures
                .filter((d) => d.overdue)
                .map((d) => ({
                    key: d.id,
                    label: d.room ? `Hab. ${d.room}` : d.guest_name,
                    meta: `salía ${d.time}`,
                    href: departureHref(d),
                })),
            chipTone: () => 'danger',
            action: can('rooms.view')
                ? { label: 'Abrir el plano', href: route('tenant.plano') }
                : undefined,
        });
    }

    if (props.maintenance && props.maintenance.open > 0) {
        const m = props.maintenance;
        list.push({
            key: 'maintenance',
            tone: m.overdue > 0 ? 'danger' : 'pending',
            icon: 'Wrench',
            title:
                m.overdue > 0
                    ? `${plural(m.overdue, 'falla', 'fallas')} sin atender a tiempo`
                    : `${plural(m.open, 'falla pendiente', 'fallas pendientes')} de mantenimiento`,
            detail:
                m.overdue > 0
                    ? `${plural(m.open, 'abierta', 'abiertas')} en total; estas ya pasaron su tiempo objetivo.`
                    : 'Ninguna se ha pasado de su tiempo objetivo todavía.',
            chips: m.items.map((item) => ({
                key: item.id,
                label: item.room
                    ? `Hab. ${item.room} · ${item.title}`
                    : item.title,
                meta: ageLabel(item.age_hours),
                href: route('tenant.incidents.show', item.id),
            })),
            chipTone: (chip) =>
                m.items.find((i) => i.id === chip.key)?.overdue
                    ? 'danger'
                    : 'pending',
            action: {
                label: 'Ver incidencias',
                href: route('tenant.incidents'),
            },
        });
    }

    if (props.expiringHolds.length) {
        list.push({
            key: 'holds',
            tone: 'warning',
            icon: 'AlarmClock',
            title: `${plural(props.expiringHolds.length, 'apartado vence', 'apartados vencen')} en menos de 30 minutos`,
            detail: 'Si no llega el pago, la habitación se libera sola al vencer.',
            chips: props.expiringHolds.map((hold) => ({
                key: hold.id,
                label: `${hold.code} · ${hold.guest_name ?? 'Sin nombre'}${hold.room ? ` · hab. ${hold.room}` : ''}`,
                meta: `vence ${hold.expires_at}`,
                href: route('tenant.reservations.detail', hold.id),
            })),
            chipTone: () => 'warning',
            action: can('reservations.view')
                ? {
                      label: 'Ver pendientes',
                      href: route('tenant.reservations.pending'),
                  }
                : undefined,
        });
    }

    if (props.today.settlements > 0 && can('reservations.view')) {
        list.push({
            key: 'settlements',
            tone: 'pending',
            icon: 'Receipt',
            title: `${plural(props.today.settlements, 'cuenta', 'cuentas')} por cerrar`,
            detail: 'Estancias que ya terminaron y se quedaron con dinero sin registrar.',
            action: {
                label: 'Revisar cuentas',
                href: route('tenant.reservations.settlements'),
            },
        });
    }

    if (props.guestStatus.pending > 0 && can('reservations.view')) {
        list.push({
            key: 'pending',
            tone: 'info',
            icon: 'CalendarClock',
            title: `${plural(props.guestStatus.pending, 'reserva espera', 'reservas esperan')} confirmación`,
            detail: 'Apartados sin pago confirmado: revisa comprobantes y confírmalos.',
            action: {
                label: 'Confirmar',
                href: route('tenant.reservations.pending'),
            },
        });
    }

    if (
        dirtyRooms.value > 0 &&
        hasModule('limpieza') &&
        can('rooms.update-status')
    ) {
        list.push({
            key: 'dirty',
            tone: 'pending',
            icon: 'Sparkles',
            title: `${plural(dirtyRooms.value, 'habitación espera', 'habitaciones esperan')} limpieza`,
            detail: 'No se pueden vender hasta que alguien las deje listas.',
            action: {
                label: 'Ir a limpieza',
                href: route('tenant.housekeeping'),
            },
        });
    }

    return list;
});

// ── Hoy: cuatro cifras que llevan a donde se trabajan ──
const departuresWithBalance = computed(
    () => props.departures.filter((d) => d.balance > 0).length,
);
const todayTiles = computed(() => [
    {
        key: 'in-house',
        label: 'En casa ahora',
        value: props.guestStatus.in_house,
        hint: `${props.occupancy.occupied} de ${props.occupancy.total} ocupadas`,
        icon: 'UserCheck' as Icon,
        tint: toneTint.danger,
        href: can('reservations.view')
            ? route('tenant.reservations.in-house')
            : null,
    },
    {
        key: 'arrivals',
        label: 'Llegadas hoy',
        value: props.today.arrivals,
        hint: props.today.arrivals_pending
            ? `${props.today.arrivals_pending} por registrar`
            : 'Todas registradas',
        icon: 'LogIn' as Icon,
        tint: toneTint.success,
        href: can('reservations.view')
            ? route('tenant.reservations.operation')
            : null,
    },
    {
        key: 'departures',
        label: 'Salidas hoy',
        value: props.today.departures,
        hint: props.today.overdue
            ? `${props.today.overdue} pasadas de hora`
            : departuresWithBalance.value
              ? `${departuresWithBalance.value} con saldo`
              : 'Sin saldos pendientes',
        icon: 'LogOut' as Icon,
        tint: toneTint.pending,
        href: can('reservations.view')
            ? route('tenant.reservations.in-house')
            : null,
    },
    {
        key: 'available',
        label: 'Libres ahora',
        value: props.occupancy.available,
        hint: `${props.occupancy.reserved} reservadas`,
        icon: 'BedDouble' as Icon,
        tint: toneTint.info,
        href: can('rooms.view') ? route('tenant.plano') : null,
    },
]);

// ── Indicadores del periodo ──
// En un motel la unidad es el uso, no la noche: el mismo número con la
// etiqueta de hotel no le decía nada al encargado.
const metricList = computed<Metric[]>(() =>
    props.metrics.map((m) =>
        m.key === 'nights' && isMotel.value
            ? {
                  ...m,
                  title: 'Usos vendidos',
                  desc: 'Habitaciones usadas por día en el periodo, con o sin reserva.',
              }
            : m,
    ),
);
const headlineKeys = ['revenue', 'occupancy', 'nights', 'created'];
const headline = computed(() =>
    headlineKeys
        .map((key) => metricList.value.find((m) => m.key === key))
        .filter((m): m is Metric => Boolean(m)),
);
const metricsOpen = ref(false);

const changeClass = (change: number | null) =>
    change === null || change === 0
        ? 'bg-slate-100 text-slate-500 dark:bg-darkmode-400 dark:text-slate-300'
        : change < 0
          ? 'bg-danger/10 text-danger'
          : 'bg-success/10 text-success';
const changeIcon = (change: number | null): Icon =>
    change !== null && change < 0 ? 'TrendingDown' : 'TrendingUp';

// ── Gráfica de ingresos (barras apiladas) con la ocupación encima ──
const hasPos = computed(() => props.series.revenue.some((p) => p.pos > 0));
const hasSeriesData = computed(
    () =>
        props.series.revenue.some((p) => p.value > 0) ||
        props.series.occupancy.some((p) => p.value > 0),
);

const revenueChart = computed(() => ({
    labels: props.series.revenue.map((p) => p.label),
    datasets: [
        {
            type: 'line' as const,
            label: 'Ocupación',
            data: props.series.occupancy.map((p) => p.value),
            yAxisID: 'occ',
            borderColor: '#0d9488',
            backgroundColor: '#0d9488',
            borderWidth: 2,
            tension: 0.35,
            pointRadius: props.series.occupancy.length > 31 ? 0 : 2.5,
            pointHoverRadius: 4,
            order: 0,
        },
        {
            type: 'bar' as const,
            label: 'Hospedaje',
            data: props.series.revenue.map((p) => p.lodging),
            yAxisID: 'y',
            backgroundColor: primaryHex,
            borderRadius: 3,
            maxBarThickness: 28,
            stack: 'money',
            order: 1,
        },
        ...(hasPos.value
            ? [
                  {
                      type: 'bar' as const,
                      label: 'Consumo y POS',
                      data: props.series.revenue.map((p) => p.pos),
                      yAxisID: 'y',
                      backgroundColor: '#0891b2',
                      borderRadius: 3,
                      maxBarThickness: 28,
                      stack: 'money',
                      order: 1,
                  },
              ]
            : []),
    ],
}));

const revenueOptions: ChartOptions<'bar'> = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx: TooltipItem<'bar'>) =>
                    ctx.dataset.yAxisID === 'occ'
                        ? `${ctx.dataset.label}: ${ctx.parsed.y}%`
                        : `${ctx.dataset.label}: ${money(Number(ctx.parsed.y))}`,
            },
        },
    },
    scales: {
        x: {
            stacked: true,
            grid: { display: false },
            ticks: {
                font: { size: 10 },
                maxRotation: 0,
                autoSkip: true,
                maxTicksLimit: 12,
            },
        },
        y: {
            stacked: true,
            beginAtZero: true,
            border: { display: false },
            grid: { color: 'rgba(148,163,184,0.18)' },
            ticks: {
                font: { size: 10 },
                maxTicksLimit: 5,
                callback: (value: number | string) =>
                    compactMoney(Number(value)),
            },
        },
        occ: {
            position: 'right',
            min: 0,
            max: 100,
            border: { display: false },
            grid: { display: false },
            ticks: {
                font: { size: 10 },
                stepSize: 25,
                callback: (value: number | string) => `${value}%`,
            },
        },
    },
};

const revenueLegend = computed(() => [
    { label: 'Hospedaje', color: primaryHex, bar: true },
    ...(hasPos.value
        ? [{ label: 'Consumo y POS', color: '#0891b2', bar: true }]
        : []),
    { label: 'Ocupación', color: '#0d9488', bar: false },
]);

// ── Dona de habitaciones: semáforo o por tipo ──
const roomsView = ref<'status' | 'type'>('status');

const statusSlices = computed(() => props.statuses.filter((s) => s.count > 0));

const typePalette = [
    primaryHex,
    '#0891b2',
    '#0d9488',
    '#ca8a04',
    '#c2410c',
    '#b91c1c',
    '#1e293b',
    '#0c4a6e',
    '#64748b',
    '#94a3b8',
];

const roomsDonut = computed(() =>
    roomsView.value === 'status'
        ? {
              labels: statusSlices.value.map((s) => s.label),
              datasets: [
                  {
                      data: statusSlices.value.map((s) => s.count),
                      backgroundColor: statusSlices.value.map(
                          (s) => statusHex[s.value] ?? '#94a3b8',
                      ),
                      borderWidth: 0,
                      hoverOffset: 4,
                  },
              ],
          }
        : {
              labels: props.roomTypeDistribution.map((t) => t.label),
              datasets: [
                  {
                      data: props.roomTypeDistribution.map((t) => t.count),
                      backgroundColor: props.roomTypeDistribution.map(
                          (_, i) => typePalette[i % typePalette.length],
                      ),
                      borderWidth: 0,
                      hoverOffset: 4,
                  },
              ],
          },
);

const roomsLegend = computed(() =>
    roomsView.value === 'status'
        ? props.statuses.map((s) => ({
              key: s.value,
              label: s.label,
              count: s.count,
              dot: statusDot[s.value],
              color: undefined as string | undefined,
          }))
        : props.roomTypeDistribution.map((t, i) => ({
              key: t.label,
              label: t.label,
              count: t.count,
              dot: '',
              color: typePalette[i % typePalette.length],
          })),
);

const donutOptions: ChartOptions<'doughnut'> = {
    cutout: '74%',
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
};

// ── Por dónde llegaron ──
const channelHex: Record<string, string> = {
    web: primaryHex,
    agent: '#0891b2',
    whatsapp: '#0d9488',
    front_desk: '#1e293b',
    walk_in: '#c2410c',
    phone: '#ca8a04',
};
const channelColor = (channel: string, i: number) =>
    channelHex[channel] ?? typePalette[(i + 6) % typePalette.length];
const channelsTotal = computed(() =>
    props.channels.reduce((sum, c) => sum + c.count, 0),
);
const channelsDonut = computed(() => ({
    labels: props.channels.map((c) => c.label),
    datasets: [
        {
            data: props.channels.map((c) => c.count),
            backgroundColor: props.channels.map((c, i) =>
                channelColor(c.channel, i),
            ),
            borderWidth: 0,
            hoverOffset: 4,
        },
    ],
}));

// ── Cómo se cobró: barras horizontales por método ──
const methodHex: Record<string, string> = {
    cash: '#0d9488',
    card: primaryHex,
    transfer: '#ca8a04',
    online: '#1e293b',
};
const methodsTotal = computed(() =>
    props.byMethod.reduce((sum, m) => sum + m.amount, 0),
);
const methodsMax = computed(() =>
    Math.max(0, ...props.byMethod.map((m) => m.amount)),
);

// ── Llegadas y salidas ──
const arrivalHref = (a: ArrivalRow) =>
    route('tenant.reservations.detail', a.id);
// Una salida de mostrador no tiene reserva: se atiende desde la ficha de
// la habitación.
function departureHref(d: DepartureRow): string {
    if (d.reservation_id) {
        return route('tenant.reservations.detail', d.reservation_id);
    }

    return d.room_id
        ? route('tenant.rooms.show', d.room_id)
        : route('tenant.plano');
}
</script>

<template>
    <RazeLayout title="Dashboard">
        <div class="mt-2">
            <!-- ===================== Encabezado ===================== -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="LayoutDashboard" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Resumen</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ todayLabel }} · cifras de {{ hero.period }}
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <div class="relative col-span-2 md:w-40">
                        <Lucide
                            icon="CalendarRange"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                        />
                        <FormSelect
                            v-model="rangeChoice"
                            class="h-9 pl-9 text-xs"
                            aria-label="Periodo"
                            @change="onRangeChange"
                        >
                            <option value="today">Hoy</option>
                            <option value="week">Esta semana</option>
                            <option value="month">Este mes</option>
                            <option value="custom">Personalizado</option>
                        </FormSelect>
                    </div>
                    <button
                        v-if="range === 'custom'"
                        type="button"
                        class="col-span-2 inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-primary/20 bg-primary/5 px-3.5 text-xs font-medium text-primary transition hover:bg-primary/10"
                        title="Cambiar las fechas"
                        @click="customOpen = true"
                    >
                        <Lucide icon="CalendarRange" class="h-3.5 w-3.5" />
                        {{ hero.period }}
                    </button>
                    <Button
                        v-if="can('rooms.view')"
                        as="a"
                        :href="route('tenant.plano')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                    >
                        <Lucide icon="Map" class="mr-1.5 h-3.5 w-3.5" />
                        Plano
                    </Button>
                    <Button
                        v-if="hasModule('pos') && can('orders.manage')"
                        as="a"
                        :href="route('tenant.pos')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                    >
                        <Lucide
                            icon="ShoppingCart"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        Punto de venta
                    </Button>
                    <Button
                        v-if="can('reservations.manage')"
                        as="a"
                        :href="`${route('tenant.reservations.operation')}?intent=reserve`"
                        variant="primary"
                        class="col-span-2 h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                    >
                        <Lucide
                            icon="CalendarPlus"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        Nueva reserva
                    </Button>
                </div>
            </div>

            <!-- ===================== Avisos ===================== -->
            <div class="box box--stacked mt-4 overflow-hidden">
                <div :class="cardHeader">
                    <div
                        :class="[
                            sectionIcon,
                            notices.length
                                ? toneTint[notices[0].tone]
                                : toneTint.success,
                        ]"
                    >
                        <Lucide
                            :icon="notices.length ? 'BellRing' : 'ShieldCheck'"
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0 flex-1 basis-48">
                        <div class="text-sm font-medium">Avisos</div>
                        <div class="text-xs text-slate-500">
                            {{
                                notices.length
                                    ? 'Lo que necesita atención, de lo más urgente a lo menos'
                                    : 'Nada que atender en este momento'
                            }}
                        </div>
                    </div>
                    <span
                        v-if="notices.length"
                        class="rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                        :class="toneChip[notices[0].tone]"
                    >
                        {{ plural(notices.length, 'aviso', 'avisos') }}
                    </span>
                </div>

                <div
                    v-if="notices.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="notice in notices"
                        :key="notice.key"
                        class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:px-5"
                    >
                        <div class="flex min-w-0 flex-1 items-start gap-3">
                            <div :class="[sectionIcon, toneTint[notice.tone]]">
                                <Lucide :icon="notice.icon" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium">
                                    {{ notice.title }}
                                </div>
                                <div class="mt-0.5 text-xs text-slate-500">
                                    {{ notice.detail }}
                                </div>
                                <div
                                    v-if="notice.chips?.length"
                                    class="mt-2 flex flex-wrap gap-1.5"
                                >
                                    <Link
                                        v-for="chip in notice.chips"
                                        :key="chip.key"
                                        :href="chip.href"
                                        class="inline-flex max-w-full items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-medium transition"
                                        :class="
                                            toneChip[
                                                notice.chipTone?.(chip) ??
                                                    notice.tone
                                            ]
                                        "
                                    >
                                        <span class="truncate">{{
                                            chip.label
                                        }}</span>
                                        <span
                                            v-if="chip.meta"
                                            class="shrink-0 font-normal opacity-80"
                                            >· {{ chip.meta }}</span
                                        >
                                    </Link>
                                </div>
                            </div>
                        </div>
                        <Link
                            v-if="notice.action"
                            :href="notice.action.href"
                            :class="[
                                ghostLink,
                                'shrink-0 self-start sm:self-center',
                            ]"
                        >
                            {{ notice.action.label }}
                            <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                        </Link>
                    </div>
                </div>
            </div>

            <!-- ===================== Hoy ===================== -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <component
                    :is="tile.href ? Link : 'div'"
                    v-for="tile in todayTiles"
                    :key="tile.key"
                    :href="tile.href ?? undefined"
                    class="box box--stacked group col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                    :class="
                        tile.href
                            ? 'transition hover:border-primary/30 hover:shadow-md'
                            : ''
                    "
                >
                    <div :class="[sectionIcon, tile.tint]">
                        <Lucide :icon="tile.icon" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">{{ tile.value }}</div>
                        <div class="truncate text-xs text-slate-500">
                            {{ tile.label }}
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            {{ tile.hint }}
                        </div>
                    </div>
                    <Lucide
                        v-if="tile.href"
                        icon="ChevronRight"
                        class="hidden h-4 w-4 shrink-0 text-slate-300 transition group-hover:text-primary sm:block"
                    />
                </component>
            </div>

            <!-- ===================== Periodo ===================== -->
            <div class="mt-4 grid grid-cols-12 items-stretch gap-5">
                <!-- Ingresos y ocupación -->
                <div class="col-span-12 flex flex-col xl:col-span-8">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div :class="[sectionIcon, toneTint.primary]">
                                <Lucide icon="ChartColumn" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1 basis-48">
                                <div class="text-sm font-medium">
                                    Ingresos y ocupación
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ series.label }} · comparado con el
                                    periodo anterior
                                </div>
                            </div>
                            <button
                                type="button"
                                :class="headerAction"
                                @click="metricsOpen = true"
                            >
                                <Lucide icon="ListChecks" class="h-3.5 w-3.5" />
                                Todos los indicadores
                            </button>
                        </div>

                        <!-- Cifras del periodo -->
                        <div
                            class="grid grid-cols-2 border-b border-slate-200/60 bg-slate-50/70 sm:grid-cols-4 dark:border-darkmode-400 dark:bg-darkmode-700/40"
                        >
                            <div
                                v-for="(m, i) in headline"
                                :key="m.key"
                                class="min-w-0 px-4 py-3"
                                :class="[
                                    i % 2 === 1
                                        ? 'border-l border-slate-200/60 dark:border-darkmode-400'
                                        : '',
                                    i >= 2
                                        ? 'border-t border-slate-200/60 sm:border-t-0 dark:border-darkmode-400'
                                        : '',
                                    i === 2
                                        ? 'sm:border-l sm:border-slate-200/60 dark:sm:border-darkmode-400'
                                        : '',
                                ]"
                                :title="m.desc"
                            >
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <span class="text-sm font-medium">{{
                                        m.value
                                    }}</span>
                                    <span
                                        v-if="m.change !== null"
                                        class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-px text-[11px] font-medium"
                                        :class="changeClass(m.change)"
                                    >
                                        <Lucide
                                            :icon="changeIcon(m.change)"
                                            class="h-3 w-3"
                                        />
                                        {{ Math.abs(m.change) }}%
                                    </span>
                                </div>
                                <div
                                    class="mt-0.5 truncate text-xs text-slate-500"
                                >
                                    {{ m.title }}
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-1 flex-col px-4 py-3">
                            <div
                                class="flex flex-wrap items-center gap-x-4 gap-y-1"
                            >
                                <span
                                    v-for="item in revenueLegend"
                                    :key="item.label"
                                    class="inline-flex items-center gap-1.5 text-[11px] text-slate-500"
                                >
                                    <span
                                        :class="
                                            item.bar
                                                ? 'h-2.5 w-2.5 rounded-sm'
                                                : 'h-0.5 w-3.5 rounded-full'
                                        "
                                        :style="{ backgroundColor: item.color }"
                                    />
                                    {{ item.label }}
                                </span>
                            </div>
                            <div class="mt-2 min-h-[240px] flex-1">
                                <Chart
                                    v-if="hasSeriesData"
                                    type="bar"
                                    :data="revenueChart"
                                    :options="revenueOptions"
                                    class="!h-[240px] w-full"
                                />
                                <div
                                    v-else
                                    class="flex h-[240px] flex-col items-center justify-center gap-1.5 rounded-[0.6rem] border border-dashed border-slate-300/70 text-center dark:border-darkmode-400"
                                >
                                    <Lucide
                                        icon="ChartColumn"
                                        class="h-5 w-5 text-slate-300"
                                    />
                                    <span class="text-xs text-slate-400">
                                        Sin cobros ni ocupación en el periodo
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Habitaciones ahora -->
                <div class="col-span-12 flex flex-col xl:col-span-4">
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div :class="[sectionIcon, toneTint.danger]">
                                <Lucide icon="ChartPie" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium">
                                    Habitaciones
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{
                                        roomsView === 'status'
                                            ? 'Ahora mismo'
                                            : 'Cuántas hay de cada tipo'
                                    }}
                                </div>
                            </div>
                            <div
                                class="flex rounded-full border border-slate-200 bg-slate-50 p-0.5 dark:border-darkmode-400 dark:bg-darkmode-700"
                                role="tablist"
                            >
                                <button
                                    v-for="opt in [
                                        { key: 'status', label: 'Estado' },
                                        { key: 'type', label: 'Por tipo' },
                                    ] as const"
                                    :key="opt.key"
                                    type="button"
                                    role="tab"
                                    :aria-selected="roomsView === opt.key"
                                    class="h-7 rounded-full px-3 text-[11px] font-medium transition"
                                    :class="
                                        roomsView === opt.key
                                            ? 'bg-white text-primary shadow-sm dark:bg-darkmode-400 dark:text-slate-200'
                                            : 'text-slate-500 hover:text-slate-700'
                                    "
                                    @click="roomsView = opt.key"
                                >
                                    {{ opt.label }}
                                </button>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col px-4 py-3">
                            <template v-if="hasRooms">
                                <div
                                    class="relative mx-auto w-full max-w-[180px]"
                                >
                                    <div class="h-[170px]">
                                        <Chart
                                            type="doughnut"
                                            :data="roomsDonut"
                                            :options="donutOptions"
                                            class="!h-[170px]"
                                        />
                                    </div>
                                    <div
                                        class="pointer-events-none absolute inset-0 flex items-center justify-center"
                                    >
                                        <div class="text-center">
                                            <div class="text-base font-medium">
                                                {{
                                                    roomsView === 'status'
                                                        ? `${occupancy.percent}%`
                                                        : totals.rooms
                                                }}
                                            </div>
                                            <div
                                                class="text-[11px] text-slate-500"
                                            >
                                                {{
                                                    roomsView === 'status'
                                                        ? 'ocupación'
                                                        : 'habitaciones'
                                                }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div
                                    class="mt-4 grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2"
                                >
                                    <div
                                        v-for="row in roomsLegend"
                                        :key="row.key"
                                        class="flex min-w-0 items-center text-xs"
                                        :class="row.count ? '' : 'opacity-50'"
                                    >
                                        <span
                                            class="mr-2 h-2 w-2 shrink-0 rounded-full"
                                            :class="row.dot"
                                            :style="
                                                row.color
                                                    ? {
                                                          backgroundColor:
                                                              row.color,
                                                      }
                                                    : undefined
                                            "
                                        />
                                        <span
                                            class="truncate text-slate-600 dark:text-slate-300"
                                            >{{ row.label }}</span
                                        >
                                        <span
                                            class="ml-auto pl-2 font-medium"
                                            >{{ row.count }}</span
                                        >
                                    </div>
                                </div>
                            </template>
                            <div
                                v-else
                                class="flex flex-1 flex-col items-center justify-center gap-1.5 py-8 text-center"
                            >
                                <Lucide
                                    icon="BedDouble"
                                    class="h-5 w-5 text-slate-300"
                                />
                                <span class="text-xs text-slate-400">
                                    Aún no hay habitaciones dadas de alta
                                </span>
                            </div>
                            <Link
                                v-if="can('rooms.view')"
                                :href="route('tenant.plano')"
                                :class="[
                                    ghostLink,
                                    'mt-4 w-full justify-center',
                                ]"
                            >
                                <Lucide icon="Map" class="h-3.5 w-3.5" />
                                Abrir el plano
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===================== Llegadas y salidas ===================== -->
            <div class="mt-4 grid grid-cols-12 items-stretch gap-5">
                <!-- Llegadas -->
                <div class="col-span-12 flex flex-col xl:col-span-6">
                    <div
                        class="box box--stacked flex flex-1 flex-col overflow-hidden"
                    >
                        <div :class="cardHeader">
                            <div :class="[sectionIcon, toneTint.success]">
                                <Lucide icon="LogIn" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1 basis-48">
                                <div class="text-sm font-medium">
                                    Llegadas de hoy
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{
                                        today.arrivals
                                            ? `${plural(today.arrivals, 'reserva', 'reservas')} · ${today.arrivals_pending} por registrar`
                                            : 'Reservas que llegan hoy'
                                    }}
                                </div>
                            </div>
                            <Link
                                v-if="can('reservations.view')"
                                :href="route('tenant.reservations.operation')"
                                :class="headerAction"
                            >
                                Operación del día
                                <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                            </Link>
                        </div>
                        <div
                            v-if="arrivals.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <Link
                                v-for="a in arrivals"
                                :key="a.id"
                                :href="arrivalHref(a)"
                                :class="rowLink"
                            >
                                <div
                                    :class="[
                                        sectionIcon,
                                        a.checked_in
                                            ? toneTint.success
                                            : a.pending
                                              ? toneTint.warning
                                              : toneTint.info,
                                        'text-[11px] font-medium',
                                    ]"
                                >
                                    <template v-if="a.room">{{
                                        a.room
                                    }}</template>
                                    <Lucide
                                        v-else
                                        icon="BedDouble"
                                        class="h-4 w-4"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">
                                        {{ a.guest_name }}
                                    </div>
                                    <div
                                        class="truncate text-xs text-slate-500"
                                    >
                                        {{ a.code }} ·
                                        {{
                                            a.room
                                                ? `Hab. ${a.room}`
                                                : 'Sin habitación asignada'
                                        }}
                                        <template v-if="a.people">
                                            ·
                                            {{
                                                plural(
                                                    a.people,
                                                    'persona',
                                                    'personas',
                                                )
                                            }}</template
                                        >
                                    </div>
                                </div>
                                <div
                                    class="flex shrink-0 flex-col items-end gap-1"
                                >
                                    <span
                                        class="inline-flex items-center gap-1 text-xs text-slate-500"
                                    >
                                        <Lucide
                                            icon="Clock"
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ a.eta || a.time }}
                                    </span>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap"
                                        :class="
                                            a.checked_in
                                                ? 'bg-success/10 text-success'
                                                : a.pending
                                                  ? 'bg-warning/10 text-warning'
                                                  : 'bg-info/10 text-info'
                                        "
                                    >
                                        {{
                                            a.checked_in
                                                ? 'En casa'
                                                : a.pending
                                                  ? 'Por confirmar'
                                                  : 'Por registrar'
                                        }}
                                    </span>
                                </div>
                                <Lucide
                                    icon="ChevronRight"
                                    class="hidden h-4 w-4 shrink-0 text-slate-300 sm:block"
                                />
                            </Link>
                        </div>
                        <div
                            v-else
                            class="flex flex-1 flex-col items-center justify-center gap-2 px-4 py-8 text-center"
                        >
                            <div :class="[sectionIcon, toneTint.success]">
                                <Lucide icon="CalendarCheck" class="h-4 w-4" />
                            </div>
                            <p class="text-xs text-slate-500">
                                Sin llegadas programadas para hoy.
                            </p>
                        </div>
                        <div
                            v-if="today.arrivals > arrivals.length"
                            class="mt-auto border-t border-slate-200/60 px-4 py-2.5 text-xs text-slate-500 dark:border-darkmode-400"
                        >
                            Mostrando {{ arrivals.length }} de
                            {{ today.arrivals }}.
                            <Link
                                :href="route('tenant.reservations.operation')"
                                class="font-medium text-primary hover:underline"
                                >Ver todas</Link
                            >
                        </div>
                    </div>
                </div>

                <!-- Salidas -->
                <div class="col-span-12 flex flex-col xl:col-span-6">
                    <div
                        class="box box--stacked flex flex-1 flex-col overflow-hidden"
                    >
                        <div :class="cardHeader">
                            <div :class="[sectionIcon, toneTint.pending]">
                                <Lucide icon="LogOut" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1 basis-48">
                                <div class="text-sm font-medium">
                                    Salidas de hoy
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{
                                        today.departures
                                            ? `${plural(today.departures, 'estancia', 'estancias')} · ${departuresWithBalance} con saldo`
                                            : 'Estancias que terminan hoy'
                                    }}
                                </div>
                            </div>
                            <Link
                                v-if="can('reservations.view')"
                                :href="route('tenant.reservations.in-house')"
                                :class="headerAction"
                            >
                                Huéspedes en casa
                                <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                            </Link>
                        </div>
                        <div
                            v-if="departures.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <Link
                                v-for="d in departures"
                                :key="d.id"
                                :href="departureHref(d)"
                                :class="rowLink"
                            >
                                <div
                                    :class="[
                                        sectionIcon,
                                        d.overdue
                                            ? toneTint.danger
                                            : toneTint.pending,
                                        'text-[11px] font-medium',
                                    ]"
                                >
                                    <template v-if="d.room">{{
                                        d.room
                                    }}</template>
                                    <Lucide
                                        v-else
                                        icon="DoorOpen"
                                        class="h-4 w-4"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">
                                        {{ d.guest_name }}
                                    </div>
                                    <div
                                        class="truncate text-xs text-slate-500"
                                    >
                                        {{ d.code ?? 'Sin reserva' }} ·
                                        {{
                                            d.room
                                                ? `Hab. ${d.room}`
                                                : 'Sin habitación'
                                        }}
                                    </div>
                                </div>
                                <div
                                    class="flex shrink-0 flex-col items-end gap-1"
                                >
                                    <span
                                        class="inline-flex items-center gap-1 text-xs"
                                        :class="
                                            d.overdue
                                                ? 'font-medium text-danger'
                                                : 'text-slate-500'
                                        "
                                        :title="
                                            d.overdue
                                                ? 'Ya pasó su hora de salida'
                                                : 'Hora de salida'
                                        "
                                    >
                                        <Lucide
                                            :icon="
                                                d.overdue
                                                    ? 'ClockAlert'
                                                    : 'Clock'
                                            "
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ d.time }}
                                    </span>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap"
                                        :class="
                                            d.balance > 0
                                                ? 'bg-danger/10 text-danger'
                                                : 'bg-success/10 text-success'
                                        "
                                    >
                                        {{
                                            d.balance > 0
                                                ? `Debe ${money(d.balance)}`
                                                : 'Pagada'
                                        }}
                                    </span>
                                </div>
                                <Lucide
                                    icon="ChevronRight"
                                    class="hidden h-4 w-4 shrink-0 text-slate-300 sm:block"
                                />
                            </Link>
                        </div>
                        <div
                            v-else
                            class="flex flex-1 flex-col items-center justify-center gap-2 px-4 py-8 text-center"
                        >
                            <div :class="[sectionIcon, toneTint.pending]">
                                <Lucide icon="DoorOpen" class="h-4 w-4" />
                            </div>
                            <p class="text-xs text-slate-500">
                                Sin salidas programadas para hoy.
                            </p>
                        </div>
                        <div
                            v-if="today.departures > departures.length"
                            class="mt-auto border-t border-slate-200/60 px-4 py-2.5 text-xs text-slate-500 dark:border-darkmode-400"
                        >
                            Mostrando {{ departures.length }} de
                            {{ today.departures }}.
                            <Link
                                v-if="can('reservations.view')"
                                :href="route('tenant.reservations.in-house')"
                                class="font-medium text-primary hover:underline"
                                >Ver todas</Link
                            >
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===================== Análisis del periodo ===================== -->
            <div class="mt-4 grid grid-cols-12 items-stretch gap-5">
                <!-- Por dónde llegaron -->
                <div
                    class="col-span-12 flex flex-col md:col-span-6 xl:col-span-4"
                >
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div :class="[sectionIcon, toneTint.info]">
                                <Lucide icon="Signpost" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1 basis-48">
                                <div class="text-sm font-medium">
                                    Por dónde llegaron
                                </div>
                                <div class="text-xs text-slate-500">
                                    Reservas y entradas sin reserva ·
                                    {{ hero.period }}
                                </div>
                            </div>
                        </div>
                        <div
                            class="flex flex-1 flex-col justify-center px-4 py-4"
                        >
                            <template v-if="channels.length">
                                <div
                                    class="relative mx-auto w-full max-w-[160px]"
                                >
                                    <div class="h-[150px]">
                                        <Chart
                                            type="doughnut"
                                            :data="channelsDonut"
                                            :options="donutOptions"
                                            class="!h-[150px]"
                                        />
                                    </div>
                                    <div
                                        class="pointer-events-none absolute inset-0 flex items-center justify-center"
                                    >
                                        <div class="text-center">
                                            <div class="text-base font-medium">
                                                {{ channelsTotal }}
                                            </div>
                                            <div
                                                class="text-[11px] text-slate-500"
                                            >
                                                {{
                                                    channelsTotal === 1
                                                        ? 'entrada'
                                                        : 'entradas'
                                                }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4 space-y-2">
                                    <div
                                        v-for="(c, i) in channels"
                                        :key="c.channel"
                                        class="flex items-center text-xs"
                                    >
                                        <span
                                            class="mr-2 h-2 w-2 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor: channelColor(
                                                    c.channel,
                                                    i,
                                                ),
                                            }"
                                        />
                                        <span
                                            class="truncate text-slate-600 dark:text-slate-300"
                                            >{{ c.label }}</span
                                        >
                                        <span
                                            class="ml-auto pl-2 font-medium"
                                            >{{ c.count }}</span
                                        >
                                        <span
                                            class="ml-2 w-9 text-right text-[11px] text-slate-400"
                                        >
                                            {{
                                                Math.round(
                                                    (c.count / channelsTotal) *
                                                        100,
                                                )
                                            }}%
                                        </span>
                                    </div>
                                </div>
                            </template>
                            <div
                                v-else
                                class="flex flex-1 flex-col items-center justify-center gap-1.5 py-8 text-center"
                            >
                                <Lucide
                                    icon="Signpost"
                                    class="h-5 w-5 text-slate-300"
                                />
                                <span class="text-xs text-slate-400">
                                    Sin reservas ni entradas en el periodo
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cómo se cobró -->
                <div
                    class="col-span-12 flex flex-col md:col-span-6 xl:col-span-4"
                >
                    <div class="box box--stacked flex flex-1 flex-col">
                        <div :class="cardHeader">
                            <div :class="[sectionIcon, toneTint.success]">
                                <Lucide icon="Wallet" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1 basis-48">
                                <div class="text-sm font-medium">
                                    Cómo se cobró
                                </div>
                                <div class="text-xs text-slate-500">
                                    Abonos por método · {{ hero.period }}
                                </div>
                            </div>
                        </div>
                        <div
                            class="flex flex-1 flex-col justify-center px-4 py-4"
                        >
                            <template v-if="byMethod.length">
                                <div class="flex items-baseline gap-1.5">
                                    <span class="text-sm font-medium">{{
                                        money(methodsTotal)
                                    }}</span>
                                    <span class="text-xs text-slate-500"
                                        >en
                                        {{
                                            plural(
                                                byMethod.reduce(
                                                    (s, m) => s + m.count,
                                                    0,
                                                ),
                                                'abono',
                                                'abonos',
                                            )
                                        }}</span
                                    >
                                </div>
                                <div class="mt-4 space-y-3.5">
                                    <div v-for="m in byMethod" :key="m.method">
                                        <div class="flex items-center text-xs">
                                            <span
                                                class="text-slate-600 dark:text-slate-300"
                                                >{{ m.label }}</span
                                            >
                                            <span
                                                class="ml-1.5 text-[11px] text-slate-400"
                                                >{{ m.count }}</span
                                            >
                                            <span class="ml-auto font-medium">{{
                                                money(m.amount)
                                            }}</span>
                                            <span
                                                class="ml-2 w-9 text-right text-[11px] text-slate-400"
                                            >
                                                {{
                                                    methodsTotal
                                                        ? Math.round(
                                                              (m.amount /
                                                                  methodsTotal) *
                                                                  100,
                                                          )
                                                        : 0
                                                }}%
                                            </span>
                                        </div>
                                        <div
                                            class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                                        >
                                            <div
                                                class="h-full rounded-full"
                                                :style="{
                                                    width: `${methodsMax ? Math.max(2, (m.amount / methodsMax) * 100) : 0}%`,
                                                    backgroundColor:
                                                        methodHex[m.method] ??
                                                        '#94a3b8',
                                                }"
                                            />
                                        </div>
                                    </div>
                                </div>
                                <p class="mt-4 text-[11px] text-slate-400">
                                    Las ventas de mostrador del POS no son
                                    abonos y no entran aquí; la fianza tampoco.
                                </p>
                            </template>
                            <div
                                v-else
                                class="flex flex-1 flex-col items-center justify-center gap-1.5 py-8 text-center"
                            >
                                <Lucide
                                    icon="Receipt"
                                    class="h-5 w-5 text-slate-300"
                                />
                                <span class="text-xs text-slate-400">
                                    Sin abonos registrados en el periodo
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actividad reciente -->
                <div class="col-span-12 flex flex-col xl:col-span-4">
                    <div
                        class="box box--stacked flex flex-1 flex-col overflow-hidden"
                    >
                        <div :class="cardHeader">
                            <div :class="[sectionIcon, toneTint.primary]">
                                <Lucide icon="History" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1 basis-48">
                                <div class="text-sm font-medium">
                                    Actividad reciente
                                </div>
                                <div class="text-xs text-slate-500">
                                    Últimos cambios del semáforo
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="recentActivity.length"
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <component
                                :is="
                                    log.room_id && can('rooms.view')
                                        ? Link
                                        : 'div'
                                "
                                v-for="log in recentActivity"
                                :key="log.id"
                                :href="
                                    log.room_id && can('rooms.view')
                                        ? route(
                                              'tenant.rooms.show',
                                              log.room_id,
                                          )
                                        : undefined
                                "
                                :class="rowLink"
                            >
                                <div
                                    :class="[
                                        sectionIcon,
                                        statusTint[
                                            colorToStatus[log.to_color]
                                        ] ?? toneTint.primary,
                                    ]"
                                >
                                    <Lucide
                                        :icon="
                                            statusIcon[
                                                colorToStatus[log.to_color]
                                            ] ?? 'Activity'
                                        "
                                        class="h-4 w-4"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">
                                        {{
                                            log.room
                                                ? `Hab. ${log.room}`
                                                : 'Habitación eliminada'
                                        }}
                                    </div>
                                    <div
                                        class="truncate text-xs text-slate-500"
                                        :title="`${log.from ?? 'Sin estado'} → ${log.to} · ${log.by}`"
                                    >
                                        {{ log.from ?? 'Sin estado' }} →
                                        {{ log.to }} · {{ log.by }}
                                    </div>
                                </div>
                                <span
                                    class="shrink-0 text-[11px] whitespace-nowrap text-slate-400"
                                    :title="log.at_full"
                                    >{{ log.at }}</span
                                >
                            </component>
                        </div>
                        <div
                            v-else
                            class="flex flex-1 flex-col items-center justify-center gap-1.5 py-8 text-center"
                        >
                            <Lucide
                                icon="History"
                                class="h-5 w-5 text-slate-300"
                            />
                            <span class="text-xs text-slate-400">
                                Sin movimientos todavía
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================== Modal: todos los indicadores ===================== -->
        <Dialog :open="metricsOpen" size="lg" @close="metricsOpen = false">
            <Dialog.Panel class="sm:w-[94vw] lg:w-[720px]">
                <div class="flex max-h-[calc(100dvh-6rem)] flex-col">
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="ListChecks" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                Indicadores de {{ hero.period }}
                            </h2>
                            <p class="text-xs text-slate-500">
                                El porcentaje compara contra el mismo avance del
                                periodo anterior.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            title="Cerrar"
                            @click="metricsOpen = false"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <div
                            class="grid grid-cols-1 divide-y divide-slate-200/60 sm:grid-cols-2 sm:divide-y-0 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="m in metricList"
                                :key="m.key"
                                class="flex items-start gap-3 border-slate-200/60 px-5 py-3 sm:border-b sm:odd:border-r dark:border-darkmode-400"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="text-xs font-medium">
                                        {{ m.title }}
                                    </div>
                                    <div
                                        class="mt-0.5 text-[11px] leading-snug text-slate-500"
                                    >
                                        {{ m.desc }}
                                    </div>
                                </div>
                                <div
                                    class="flex shrink-0 flex-col items-end gap-1"
                                >
                                    <span class="text-sm font-medium">{{
                                        m.value
                                    }}</span>
                                    <span
                                        class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-px text-[11px] font-medium"
                                        :class="changeClass(m.change)"
                                    >
                                        <template v-if="m.change !== null">
                                            <Lucide
                                                :icon="changeIcon(m.change)"
                                                class="h-3 w-3"
                                            />
                                            {{ Math.abs(m.change) }}%
                                        </template>
                                        <template v-else>Sin comparar</template>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            variant="outline-secondary"
                            class="h-9 px-5 text-xs"
                            @click="metricsOpen = false"
                            >Cerrar</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- ===================== Modal: periodo personalizado ===================== -->
        <Dialog :open="customOpen" @close="closeCustom">
            <Dialog.Panel class="sm:w-[94vw] lg:w-[480px]">
                <form
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                    @submit.prevent="applyCustom"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="CalendarRange" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                Periodo personalizado
                            </h2>
                            <p class="text-xs text-slate-500">
                                Hasta {{ MAX_CUSTOM_DAYS }} días por consulta.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            title="Cerrar"
                            @click="closeCustom"
                        >
                            <Lucide icon="X" class="h-4 w-4" />
                        </button>
                    </div>
                    <div
                        class="grid min-h-0 flex-1 grid-cols-1 gap-4 overflow-y-auto px-5 py-4 sm:grid-cols-2"
                    >
                        <div>
                            <label
                                for="range-from"
                                class="mb-1.5 block text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                >Desde</label
                            >
                            <FormDate
                                id="range-from"
                                v-model="customFrom"
                                input-class="h-9 text-xs"
                                :max="customTo || null"
                            />
                        </div>
                        <div>
                            <label
                                for="range-to"
                                class="mb-1.5 block text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                >Hasta</label
                            >
                            <FormDate
                                id="range-to"
                                v-model="customTo"
                                input-class="h-9 text-xs"
                                :min="customFrom || null"
                                :max="maxCustomTo"
                            />
                        </div>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 px-5 text-xs"
                            @click="closeCustom"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="h-9 px-5 text-xs"
                            :disabled="!customFrom || !customTo"
                            >Ver periodo</Button
                        >
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
