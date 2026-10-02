<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import Lucide from '@/components/Base/Lucide';

interface LandingPlan {
    key: string;
    label: string;
    description: string | null;
    price_monthly: number;
    activation_fee: number;
    max_rooms: number | null;
    max_users: number | null;
    max_channels: number | null;
    modules: { key: string; label: string }[];
    ai_monthly_replies: number | null;
}

interface LandingModule {
    key: string;
    label: string;
    description: string;
    group: string;
}

interface LandingGroup {
    key: string;
    label: string;
    description: string;
    icon: string;
    count: number;
}

interface LandingAddon {
    key: string;
    name: string;
    summary: string | null;
    requires: string | null;
}

const props = withDefaults(
    defineProps<{
        canRegister: boolean;
        plans: LandingPlan[];
        modules: LandingModule[];
        moduleGroups?: LandingGroup[];
        addons?: LandingAddon[];
    }>(),
    {
        canRegister: true,
        plans: () => [],
        modules: () => [],
        moduleGroups: () => [],
        addons: () => [],
    },
);

const page = usePage();
const brandName = computed(
    () =>
        (page.props.branding as { app_name?: string } | undefined)?.app_name ||
        'KuiraReserve',
);
const brandLogo = computed(
    () =>
        (page.props.branding as { logo_url?: string | null } | undefined)
            ?.logo_url ?? null,
);
const isAuthenticated = computed(() =>
    Boolean((page.props.auth as { user?: unknown } | undefined)?.user),
);
const isScrolled = ref(false);
const mobileMenuOpen = ref(false);
const submitted = ref(false);

const navItems = [
    { id: 'asistente', label: 'Asistente IA' },
    { id: 'modulos', label: 'Módulos' },
    { id: 'hotel-motel', label: 'Hotel y motel' },
    { id: 'planes', label: 'Planes' },
];

const moduleIcons: Record<string, string> = {
    pos: 'ShoppingCart',
    cobros: 'CreditCard',
    mensajeria: 'MessagesSquare',
    'agente-ia': 'Bot',
    'motor-web': 'Globe',
    'redes-sociales': 'MessageSquareHeart',
    'menu-digital': 'QrCode',
    extras: 'Gift',
    experiencias: 'Compass',
    grupos: 'UsersRound',
    'lista-espera': 'BellRing',
    cupones: 'TicketPercent',
    'tarifas-flexibles': 'Clock3',
    anticipos: 'HandCoins',
    'corte-caja': 'Calculator',
    bitacora: 'History',
    'crm-avanzado': 'IdCard',
    limpieza: 'SprayCan',
    incidencias: 'Wrench',
    'incidencias-avanzado': 'ClipboardCheck',
    encuestas: 'Star',
    'encuestas-avanzado': 'ChartBar',
    promos: 'BadgePercent',
    'tablero-avanzado': 'LayoutGrid',
    'plano-operativo': 'Map',
};

// ── Módulos por familia ──
const groups = computed<LandingGroup[]>(() =>
    props.moduleGroups.length
        ? props.moduleGroups
        : [
              {
                  key: 'otros',
                  label: 'Todos',
                  description: '',
                  icon: 'Blocks',
                  count: props.modules.length,
              },
          ],
);
const activeGroup = ref(groups.value[0]?.key ?? 'otros');
const activeGroupInfo = computed(() =>
    groups.value.find((g) => g.key === activeGroup.value),
);
const groupModules = computed(() =>
    props.moduleGroups.length
        ? props.modules.filter((m) => m.group === activeGroup.value)
        : props.modules,
);

// ── Planes ──
const featuredIndex = computed(() => (props.plans.length > 2 ? 1 : -1));
const isFeatured = (index: number) => index === featuredIndex.value;

/** Cuántos módulos de cada familia trae el plan (solo familias con alguno). */
function coverage(plan: LandingPlan) {
    const keys = new Set(plan.modules.map((m) => m.key));
    return props.moduleGroups
        .map((g) => ({
            key: g.key,
            label: g.label,
            total: g.count,
            included: props.modules.filter(
                (m) => m.group === g.key && keys.has(m.key),
            ).length,
        }))
        .filter((g) => g.included > 0);
}

// "Servicios Digitales ... – Modalidad 1: Motor de Reservas Online" se lee
// como título corto + etiqueta; un nombre simple queda tal cual.
function addonTitle(addon: LandingAddon): {
    title: string;
    tag: string | null;
} {
    const [head, ...rest] = addon.name.split(':');
    if (!rest.length) return { title: addon.name, tag: null };
    const tag = head.split(/[–-]/).pop()?.trim() ?? null;
    return { title: rest.join(':').trim(), tag };
}
const addonName = (key: string) => {
    const addon = props.addons.find((a) => a.key === key);
    return addon ? addonTitle(addon).title : key;
};

// ── Formulario ──
const form = useForm({
    name: '',
    hotel_name: '',
    email: '',
    phone: '',
    rooms: '' as string | number,
    plan_key: props.plans[0]?.key ?? '',
    message: '',
    source: 'landing',
    privacy: false,
    website: '',
});

const selectedPlan = computed(() =>
    props.plans.find((plan) => plan.key === form.plan_key),
);

function money(amount: number): string {
    return amount.toLocaleString('es-MX');
}

function goTo(id: string): void {
    mobileMenuOpen.value = false;
    document.getElementById(id)?.scrollIntoView({
        behavior: 'smooth',
        block: 'start',
    });
}

function choosePlan(plan: LandingPlan): void {
    form.plan_key = plan.key;
    submitted.value = false;
    nextTick(() => goTo('contacto'));
}

function submit(): void {
    submitted.value = false;
    form.post(route('prospects.store'), {
        preserveScroll: true,
        onSuccess: () => {
            const planKey = form.plan_key;
            form.reset();
            form.plan_key = planKey;
            submitted.value = true;
            nextTick(() => goTo('contacto'));
        },
        onError: () => goTo('contacto'),
    });
}

function handleScroll(): void {
    isScrolled.value = window.scrollY > 24;
}

let revealObserver: IntersectionObserver | null = null;

function observeReveal(): void {
    document
        .querySelectorAll<HTMLElement>('.landing-reveal:not(.is-visible)')
        .forEach((element) => {
            if (revealObserver) revealObserver.observe(element);
            else element.classList.add('is-visible');
        });
}

function selectGroup(key: string): void {
    activeGroup.value = key;
    // Las tarjetas nuevas también entran con su animación.
    nextTick(observeReveal);
}

onMounted(() => {
    handleScroll();
    window.addEventListener('scroll', handleScroll, { passive: true });

    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        revealObserver = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        revealObserver?.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.12 },
        );
    }
    observeReveal();
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', handleScroll);
    revealObserver?.disconnect();
});

// ── Maqueta del plano (semáforo con los colores reales del panel) ──
const roomStatus: Record<string, { label: string; tone: string }> = {
    available: { label: 'Disponible', tone: 'bg-success' },
    reserved: { label: 'Reservada', tone: 'bg-info' },
    occupied: { label: 'Ocupada', tone: 'bg-danger' },
    dirty: { label: 'Sucia', tone: 'bg-pending' },
    cleaning: { label: 'Limpiando', tone: 'bg-warning' },
    maintenance: { label: 'Mantenimiento', tone: 'bg-dark' },
};
const mockRooms: Array<{ n: string; s: keyof typeof roomStatus }> = [
    { n: '101', s: 'occupied' },
    { n: '102', s: 'available' },
    { n: '103', s: 'reserved' },
    { n: '104', s: 'occupied' },
    { n: '105', s: 'dirty' },
    { n: '106', s: 'available' },
    { n: '201', s: 'cleaning' },
    { n: '202', s: 'occupied' },
    { n: '203', s: 'available' },
    { n: '204', s: 'maintenance' },
    { n: '205', s: 'reserved' },
    { n: '206', s: 'occupied' },
];

const channels = [
    { icon: 'MessageCircle', label: 'WhatsApp' },
    { icon: 'Facebook', label: 'Messenger' },
    { icon: 'Instagram', label: 'Instagram' },
    { icon: 'Send', label: 'Telegram' },
    { icon: 'Music2', label: 'TikTok' },
    { icon: 'MessagesSquare', label: 'Chat de tu sitio' },
];

const assistantSkills = [
    {
        icon: 'Calculator',
        title: 'Cotiza con tus precios reales',
        text: 'Consulta disponibilidad y tarifas en vivo y entrega el total desglosado. Nunca inventa precios ni habitaciones.',
    },
    {
        icon: 'CalendarCheck',
        title: 'Aparta y manda la liga de pago',
        text: 'Crea el apartado, aplica cupones y comparte la liga de Stripe, Mercado Pago o PayPal, o tus datos de transferencia.',
    },
    {
        icon: 'ScanLine',
        title: 'Lee comprobantes y notas de voz',
        text: 'Reconoce el comprobante de transferencia que mandan por chat y entiende los audios del huésped.',
    },
    {
        icon: 'UserRoundCheck',
        title: 'Sabe cuándo pasar con una persona',
        text: 'Quejas, cambios a reservas pagadas o grupos grandes llegan a tu bandeja con todo el contexto.',
    },
];
</script>

<template>
    <Head title="Software para hoteles y moteles">
        <meta
            name="description"
            content="Reservas, plano de habitaciones, cobros en línea y un asistente con IA que atiende WhatsApp, Messenger e Instagram. Para hoteles y moteles, desde una sola plataforma."
        />
        <meta property="og:title" content="Software para hoteles y moteles" />
        <meta
            property="og:description"
            content="Reservas, operación, cobros y atención con IA desde una sola plataforma."
        />
    </Head>

    <div
        class="landing-page min-h-screen overflow-hidden bg-slate-50 text-slate-800"
    >
        <header
            class="fixed inset-x-0 top-0 z-50 transition-all duration-300"
            :class="
                isScrolled || mobileMenuOpen
                    ? 'border-b border-slate-200/80 bg-white/90 shadow-sm backdrop-blur-xl'
                    : 'bg-transparent'
            "
        >
            <div class="mx-auto flex h-20 max-w-7xl items-center px-5 lg:px-8">
                <a
                    href="#inicio"
                    class="flex items-center gap-3"
                    @click.prevent="goTo('inicio')"
                >
                    <span
                        class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-xl bg-linear-to-br from-theme-1 to-theme-2 shadow-lg shadow-primary/20"
                    >
                        <img
                            v-if="brandLogo"
                            :src="brandLogo"
                            :alt="brandName"
                            class="h-full w-full bg-white object-contain p-1"
                        />
                        <Lucide
                            v-else
                            icon="Building2"
                            class="h-5 w-5 text-white"
                        />
                    </span>
                    <span
                        class="text-lg font-semibold tracking-tight text-slate-900"
                        >{{ brandName }}</span
                    >
                </a>

                <nav class="ml-auto hidden items-center gap-7 lg:flex">
                    <a
                        v-for="item in navItems"
                        :key="item.id"
                        :href="`#${item.id}`"
                        class="landing-nav-link"
                        @click.prevent="goTo(item.id)"
                        >{{ item.label }}</a
                    >
                </nav>

                <div class="ml-auto hidden items-center gap-3 lg:ml-8 lg:flex">
                    <Link
                        :href="route(isAuthenticated ? 'dashboard' : 'login')"
                        class="rounded-lg px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-primary"
                    >
                        {{ isAuthenticated ? 'Ir al panel' : 'Iniciar sesión' }}
                    </Link>
                    <button
                        class="rounded-lg bg-primary px-5 py-2.5 text-sm font-medium text-white shadow-lg shadow-primary/20 transition hover:-translate-y-0.5 hover:bg-theme-2"
                        @click="goTo('contacto')"
                    >
                        Solicitar demo
                    </button>
                </div>

                <button
                    class="ml-auto flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700 lg:hidden"
                    :aria-label="mobileMenuOpen ? 'Cerrar menú' : 'Abrir menú'"
                    @click="mobileMenuOpen = !mobileMenuOpen"
                >
                    <Lucide
                        :icon="mobileMenuOpen ? 'X' : 'Menu'"
                        class="h-5 w-5"
                    />
                </button>
            </div>

            <div
                v-if="mobileMenuOpen"
                class="border-t border-slate-200 bg-white px-5 py-4 shadow-xl lg:hidden"
            >
                <nav class="mx-auto flex max-w-7xl flex-col gap-1">
                    <button
                        v-for="item in navItems"
                        :key="item.id"
                        class="mobile-nav-link"
                        @click="goTo(item.id)"
                    >
                        {{ item.label }}
                    </button>
                    <button class="mobile-nav-link" @click="goTo('contacto')">
                        Solicitar demo
                    </button>
                    <Link
                        :href="route(isAuthenticated ? 'dashboard' : 'login')"
                        class="mobile-nav-link"
                    >
                        {{ isAuthenticated ? 'Ir al panel' : 'Iniciar sesión' }}
                    </Link>
                </nav>
            </div>
        </header>

        <main>
            <!-- Portada -->
            <section
                id="inicio"
                class="relative overflow-hidden pt-36 pb-24 lg:pt-44 lg:pb-32"
            >
                <div class="landing-orb landing-orb--one" />
                <div class="landing-orb landing-orb--two" />
                <div
                    class="mx-auto grid max-w-7xl items-center gap-14 px-5 lg:grid-cols-[0.9fr_1.1fr] lg:px-8"
                >
                    <div class="relative z-10">
                        <div
                            class="mb-6 inline-flex items-center gap-2 rounded-full border border-primary/10 bg-primary/5 px-3.5 py-2 text-xs font-semibold tracking-wide text-primary uppercase"
                        >
                            <span class="relative flex h-2 w-2">
                                <span
                                    class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success opacity-60"
                                />
                                <span
                                    class="relative inline-flex h-2 w-2 rounded-full bg-success"
                                />
                            </span>
                            Hoteles y moteles en un solo lugar
                        </div>
                        <h1
                            class="max-w-2xl text-4xl leading-[1.08] font-semibold tracking-[-0.04em] text-slate-950 sm:text-5xl lg:text-6xl"
                        >
                            Tu hotel trabaja mejor cuando todo
                            <span class="landing-gradient-text"
                                >se conecta.</span
                            >
                        </h1>
                        <p
                            class="mt-6 max-w-xl text-lg leading-8 text-slate-600"
                        >
                            Reservas, plano de habitaciones, cobros en línea y
                            un asistente con IA que contesta WhatsApp, Messenger
                            e Instagram mientras tu equipo atiende al huésped.
                        </p>
                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            <button
                                class="group inline-flex items-center justify-center rounded-xl bg-primary px-6 py-3.5 font-medium text-white shadow-xl shadow-primary/20 transition hover:-translate-y-0.5 hover:bg-theme-2"
                                @click="goTo('contacto')"
                            >
                                Quiero una demostración
                                <Lucide
                                    icon="ArrowRight"
                                    class="ml-2 h-4 w-4 transition-transform group-hover:translate-x-1"
                                />
                            </button>
                            <button
                                class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-6 py-3.5 font-medium text-slate-700 shadow-sm transition hover:border-primary/30 hover:text-primary"
                                @click="goTo('asistente')"
                            >
                                <Lucide icon="Bot" class="mr-2 h-4 w-4" />
                                Ver el asistente en acción
                            </button>
                        </div>
                        <div
                            class="mt-8 flex flex-wrap gap-x-6 gap-y-3 text-sm text-slate-500"
                        >
                            <span
                                v-for="item in [
                                    'Acompañamiento inicial',
                                    'Crece por módulos',
                                    'Desde cualquier dispositivo',
                                ]"
                                :key="item"
                                class="flex items-center gap-2"
                                ><Lucide
                                    icon="Check"
                                    class="h-4 w-4 text-success"
                                />{{ item }}</span
                            >
                        </div>
                    </div>

                    <!-- Maqueta: plano operativo con el semáforo del panel -->
                    <div class="relative mx-auto w-full max-w-2xl lg:mx-0">
                        <div
                            class="landing-dashboard relative rounded-[1.4rem] border border-white/80 bg-white/90 p-2.5 shadow-2xl shadow-primary/15 backdrop-blur"
                        >
                            <div
                                class="flex h-10 items-center gap-2 rounded-t-xl border-b border-slate-100 px-4"
                            >
                                <span
                                    class="h-2.5 w-2.5 rounded-full bg-danger/70"
                                />
                                <span
                                    class="h-2.5 w-2.5 rounded-full bg-warning/70"
                                />
                                <span
                                    class="h-2.5 w-2.5 rounded-full bg-success/70"
                                />
                                <div
                                    class="mx-auto flex h-5 w-44 items-center justify-center rounded-md bg-slate-100 text-[8px] text-slate-400"
                                >
                                    tuhotel.{{ brandName.toLowerCase() }}.com
                                </div>
                            </div>
                            <div
                                class="grid min-h-[370px] grid-cols-[52px_1fr] overflow-hidden rounded-b-xl bg-slate-50 sm:grid-cols-[150px_1fr]"
                            >
                                <aside
                                    class="bg-linear-to-b from-theme-1 to-theme-2 p-2.5 sm:p-4"
                                >
                                    <div
                                        class="mb-7 flex items-center gap-2 text-white"
                                    >
                                        <span
                                            class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/10"
                                            ><Lucide
                                                icon="Building2"
                                                class="h-3.5 w-3.5"
                                        /></span>
                                        <span
                                            class="hidden text-[11px] font-medium sm:inline"
                                            >Hotel Central</span
                                        >
                                    </div>
                                    <div class="space-y-2">
                                        <div
                                            v-for="item in [
                                                { icon: 'Map', label: 'Plano' },
                                                {
                                                    icon: 'Inbox',
                                                    label: 'Bandeja',
                                                },
                                                {
                                                    icon: 'CalendarDays',
                                                    label: 'Reservas',
                                                },
                                                {
                                                    icon: 'Wallet',
                                                    label: 'Caja',
                                                },
                                                {
                                                    icon: 'Users',
                                                    label: 'Huéspedes',
                                                },
                                            ]"
                                            :key="item.label"
                                            class="flex items-center gap-2 rounded-lg px-2 py-2 text-white/60 first:bg-white/10 first:text-white"
                                        >
                                            <Lucide
                                                :icon="item.icon as any"
                                                class="h-3.5 w-3.5 shrink-0"
                                            />
                                            <span
                                                class="hidden text-[10px] sm:inline"
                                                >{{ item.label }}</span
                                            >
                                        </div>
                                    </div>
                                </aside>
                                <div class="p-3 sm:p-5">
                                    <div
                                        class="mb-4 flex items-center justify-between"
                                    >
                                        <div>
                                            <div
                                                class="text-xs font-semibold text-slate-800"
                                            >
                                                Plano de hoy
                                            </div>
                                            <div
                                                class="mt-0.5 text-[9px] text-slate-400"
                                            >
                                                Cada habitación con su estado en
                                                vivo
                                            </div>
                                        </div>
                                        <span
                                            class="rounded-full bg-success/10 px-2 py-0.5 text-[9px] font-semibold text-success"
                                            >67% ocupación</span
                                        >
                                    </div>
                                    <div
                                        class="grid grid-cols-3 gap-2 sm:grid-cols-4"
                                    >
                                        <div
                                            v-for="(room, index) in mockRooms"
                                            :key="room.n"
                                            class="landing-room flex h-14 flex-col justify-between rounded-lg p-2 text-white shadow-sm"
                                            :class="roomStatus[room.s].tone"
                                            :style="{
                                                animationDelay: `${300 + index * 60}ms`,
                                            }"
                                        >
                                            <span
                                                class="text-[11px] font-semibold"
                                                >{{ room.n }}</span
                                            >
                                            <span
                                                class="truncate text-[8px] opacity-90"
                                                >{{
                                                    roomStatus[room.s].label
                                                }}</span
                                            >
                                        </div>
                                    </div>
                                    <div
                                        class="mt-4 grid grid-cols-3 gap-2 rounded-lg border border-slate-100 bg-white p-2.5 shadow-sm"
                                    >
                                        <div
                                            v-for="stat in [
                                                {
                                                    label: 'Llegadas',
                                                    value: '12',
                                                },
                                                {
                                                    label: 'Salidas',
                                                    value: '8',
                                                },
                                                {
                                                    label: 'Caja del turno',
                                                    value: '$24,350',
                                                },
                                            ]"
                                            :key="stat.label"
                                        >
                                            <div
                                                class="text-[8px] text-slate-400"
                                            >
                                                {{ stat.label }}
                                            </div>
                                            <div
                                                class="text-xs font-semibold text-slate-700"
                                            >
                                                {{ stat.value }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div
                            class="landing-float-card absolute -top-6 -left-4 hidden items-center gap-3 rounded-xl border border-white bg-white p-3 shadow-xl sm:flex"
                        >
                            <span
                                class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10"
                                ><Lucide
                                    icon="Bot"
                                    class="h-5 w-5 text-primary"
                            /></span>
                            <div>
                                <div class="text-[10px] text-slate-400">
                                    Asistente IA · WhatsApp
                                </div>
                                <div
                                    class="text-xs font-semibold text-slate-700"
                                >
                                    Apartado creado, liga enviada
                                </div>
                            </div>
                        </div>
                        <div
                            class="landing-float-card landing-float-card--late absolute -right-4 -bottom-7 hidden items-center gap-3 rounded-xl border border-white bg-white p-3 shadow-xl sm:flex"
                        >
                            <span
                                class="flex h-9 w-9 items-center justify-center rounded-lg bg-success/10"
                                ><Lucide
                                    icon="BadgeCheck"
                                    class="h-5 w-5 text-success"
                            /></span>
                            <div>
                                <div class="text-[10px] text-slate-400">
                                    Pago recibido · Mercado Pago
                                </div>
                                <div
                                    class="text-xs font-semibold text-slate-700"
                                >
                                    Anticipo de $1,850 registrado
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Franja -->
            <section class="border-y border-slate-200/80 bg-white py-7">
                <div
                    class="mx-auto flex max-w-7xl flex-wrap items-center justify-center gap-x-10 gap-y-5 px-5 text-sm text-slate-500 lg:px-8"
                >
                    <span class="font-medium text-slate-400"
                        >Todo tu hotel conectado:</span
                    >
                    <span
                        v-for="item in [
                            { icon: 'Map', label: 'Plano en vivo' },
                            { icon: 'CalendarCheck', label: 'Reservas' },
                            { icon: 'MessagesSquare', label: 'Mensajería' },
                            { icon: 'CreditCard', label: 'Cobros en línea' },
                            { icon: 'Bot', label: 'Asistente IA' },
                            { icon: 'Car', label: 'Modo motel' },
                        ]"
                        :key="item.label"
                        class="flex items-center gap-2 font-medium"
                        ><Lucide
                            :icon="item.icon as any"
                            class="h-4 w-4 text-primary/60"
                        />{{ item.label }}</span
                    >
                </div>
            </section>

            <!-- Asistente IA -->
            <section id="asistente" class="relative py-24 lg:py-32">
                <div
                    class="mx-auto grid max-w-7xl items-center gap-14 px-5 lg:grid-cols-[1.05fr_0.95fr] lg:px-8"
                >
                    <div class="landing-reveal">
                        <span class="landing-eyebrow">Asistente con IA</span>
                        <h2 class="landing-title">
                            Contesta, cotiza y aparta mientras tú atiendes.
                        </h2>
                        <p class="landing-subtitle max-w-xl">
                            El asistente responde en tus canales a cualquier
                            hora con la información real de tu hotel, y deja en
                            tu bandeja solo lo que necesita a una persona.
                        </p>
                        <div class="mt-8 grid gap-4 sm:grid-cols-2">
                            <div
                                v-for="skill in assistantSkills"
                                :key="skill.title"
                                class="flex gap-3.5"
                            >
                                <span
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-primary/10 bg-primary/5 text-primary"
                                    ><Lucide
                                        :icon="skill.icon as any"
                                        class="h-4 w-4"
                                /></span>
                                <div>
                                    <h3
                                        class="text-sm font-semibold text-slate-800"
                                    >
                                        {{ skill.title }}
                                    </h3>
                                    <p
                                        class="mt-1 text-sm leading-6 text-slate-500"
                                    >
                                        {{ skill.text }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="mt-8 flex flex-wrap gap-2">
                            <span
                                v-for="channel in channels"
                                :key="channel.label"
                                class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 shadow-sm"
                                ><Lucide
                                    :icon="channel.icon as any"
                                    class="h-3.5 w-3.5 text-primary"
                                />{{ channel.label }}</span
                            >
                        </div>
                    </div>

                    <!-- Conversación de ejemplo -->
                    <div
                        class="landing-reveal relative mx-auto w-full max-w-md"
                    >
                        <div
                            class="overflow-hidden rounded-[1.6rem] border border-white bg-white shadow-2xl shadow-primary/15"
                        >
                            <div
                                class="flex items-center gap-3 bg-linear-to-r from-theme-1 to-theme-2 px-4 py-3.5 text-white"
                            >
                                <span
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-white/15"
                                    ><Lucide icon="Building2" class="h-4 w-4"
                                /></span>
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold">
                                        Hotel Central
                                    </div>
                                    <div
                                        class="flex items-center gap-1 text-[11px] text-white/70"
                                    >
                                        <span
                                            class="h-1.5 w-1.5 rounded-full bg-success"
                                        />
                                        Responde al momento
                                    </div>
                                </div>
                                <Lucide
                                    icon="MessageCircle"
                                    class="ml-auto h-4 w-4 text-white/70"
                                />
                            </div>
                            <div
                                class="space-y-2.5 bg-slate-50 px-4 py-5 text-[13px] leading-5"
                            >
                                <div
                                    class="landing-bubble landing-bubble--guest"
                                >
                                    Hola, ¿tienen habitación para el sábado?
                                    Somos 2.
                                </div>
                                <div class="landing-bubble landing-bubble--bot">
                                    Hola, con gusto. Para el sábado 4 de octubre
                                    tengo:
                                    <span class="mt-1.5 block font-medium"
                                        >Doble estándar · 1 noche × $1,250 =
                                        $1,250</span
                                    >
                                    <span class="block font-medium"
                                        >Junior suite · 1 noche × $1,690 =
                                        $1,690</span
                                    >
                                </div>
                                <div
                                    class="landing-bubble landing-bubble--guest flex items-center gap-2"
                                >
                                    <Lucide
                                        icon="Mic"
                                        class="h-3.5 w-3.5 shrink-0"
                                    />
                                    <span
                                        >Nota de voz · "Me quedo con la doble, a
                                        nombre de Ana Torres"</span
                                    >
                                </div>
                                <div class="landing-bubble landing-bubble--bot">
                                    Listo, Ana. Tu apartado
                                    <span class="font-medium"
                                        >RES-2026-0142</span
                                    >
                                    queda pendiente de pago. Aquí tu liga para
                                    el anticipo:
                                    <span
                                        class="mt-2 flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-xs"
                                    >
                                        <Lucide
                                            icon="CreditCard"
                                            class="h-4 w-4 text-primary"
                                        />
                                        <span class="min-w-0 flex-1">
                                            <span
                                                class="block font-medium text-slate-700"
                                                >Pagar anticipo $625</span
                                            >
                                            <span
                                                class="block text-[11px] text-slate-400"
                                                >Pago seguro en línea</span
                                            >
                                        </span>
                                        <Lucide
                                            icon="ArrowUpRight"
                                            class="h-3.5 w-3.5 text-slate-400"
                                        />
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div
                            class="absolute -bottom-5 left-1/2 flex -translate-x-1/2 items-center gap-2 rounded-full border border-white bg-white px-4 py-2 text-xs font-medium whitespace-nowrap text-slate-600 shadow-lg"
                        >
                            <Lucide
                                icon="ShieldCheck"
                                class="h-4 w-4 text-success"
                            />
                            Precios y disponibilidad salen del sistema
                        </div>
                    </div>
                </div>
            </section>

            <!-- Módulos por familia -->
            <section id="modulos" class="bg-white py-24 lg:py-32">
                <div class="mx-auto max-w-7xl px-5 lg:px-8">
                    <div class="landing-reveal mx-auto max-w-2xl text-center">
                        <span class="landing-eyebrow"
                            >{{ modules.length }} módulos en
                            {{ moduleGroups.length || 1 }} áreas</span
                        >
                        <h2 class="landing-title">
                            Menos herramientas sueltas.<br />Más control de tu
                            operación.
                        </h2>
                        <p class="landing-subtitle">
                            Activa lo que tu hotel necesita hoy y suma módulos
                            conforme crece tu operación.
                        </p>
                    </div>

                    <div
                        v-if="moduleGroups.length > 1"
                        class="landing-reveal -mx-5 mt-12 overflow-x-auto px-5 pb-2 lg:mx-0 lg:overflow-visible lg:px-0"
                    >
                        <div
                            class="mx-auto flex w-max gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-1.5 lg:w-fit lg:max-w-full lg:flex-wrap lg:justify-center lg:gap-1"
                        >
                            <button
                                v-for="group in groups"
                                :key="group.key"
                                type="button"
                                class="flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-medium whitespace-nowrap transition lg:px-3 lg:text-[13px]"
                                :class="
                                    activeGroup === group.key
                                        ? 'bg-primary text-white shadow-lg shadow-primary/20'
                                        : 'text-slate-600 hover:bg-white hover:text-primary'
                                "
                                @click="selectGroup(group.key)"
                            >
                                <Lucide
                                    :icon="group.icon as any"
                                    class="h-4 w-4"
                                />
                                {{ group.label }}
                                <span
                                    class="rounded-full px-1.5 text-[11px]"
                                    :class="
                                        activeGroup === group.key
                                            ? 'bg-white/20'
                                            : 'bg-slate-200/70 text-slate-500'
                                    "
                                    >{{ group.count }}</span
                                >
                            </button>
                        </div>
                    </div>
                    <p
                        v-if="activeGroupInfo?.description"
                        class="mt-5 text-center text-sm text-slate-500"
                    >
                        {{ activeGroupInfo.description }}
                    </p>

                    <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        <article
                            v-for="(module, index) in groupModules"
                            :key="module.key"
                            class="landing-reveal landing-feature-card group"
                            :style="{
                                transitionDelay: `${Math.min(index, 5) * 70}ms`,
                            }"
                        >
                            <span
                                class="flex h-12 w-12 items-center justify-center rounded-xl border border-primary/10 bg-primary/5 text-primary transition duration-300 group-hover:scale-110 group-hover:-rotate-3 group-hover:bg-primary group-hover:text-white"
                            >
                                <Lucide
                                    :icon="
                                        (moduleIcons[module.key] ||
                                            'Sparkles') as any
                                    "
                                    class="h-5 w-5"
                                />
                            </span>
                            <h3
                                class="mt-5 text-lg font-semibold text-slate-900"
                            >
                                {{ module.label }}
                            </h3>
                            <p class="mt-2 text-sm leading-6 text-slate-500">
                                {{ module.description }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Hotel y motel -->
            <section id="hotel-motel" class="py-24 lg:py-32">
                <div class="mx-auto max-w-7xl px-5 lg:px-8">
                    <div class="landing-reveal mx-auto max-w-2xl text-center">
                        <span class="landing-eyebrow"
                            >Hotel, motel o ambos</span
                        >
                        <h2 class="landing-title">
                            Se adapta a como opera tu propiedad.
                        </h2>
                        <p class="landing-subtitle">
                            Cada modo trae sus propias pantallas. Si manejas los
                            dos, se activan juntos en la misma cuenta.
                        </p>
                    </div>
                    <div class="mt-14 grid gap-6 lg:grid-cols-2">
                        <article
                            v-for="(mode, index) in [
                                {
                                    icon: 'Hotel',
                                    title: 'Para hoteles',
                                    text: 'Venta directa y estancias por noche, con todo el seguimiento del huésped.',
                                    items: [
                                        'Reservas desde tu sitio web con un widget por habitación',
                                        'Anticipos, saldos y ligas de pago con fecha límite',
                                        'Reservas grupales con un solo folio',
                                        'Contrato digital, pre-registro y encuesta al salir',
                                    ],
                                },
                                {
                                    icon: 'Car',
                                    title: 'Para moteles',
                                    text: 'Rotaciones por hora y entradas rápidas sin perder el control de la caja.',
                                    items: [
                                        'Caseta en dos momentos: abrir acceso y después placa y cobro',
                                        'Registro de vehículos por estancia',
                                        'Tarifas por hora o por bloque y reportes de rotaciones',
                                        'Plano en pantalla completa, pensado para pantalla táctil',
                                    ],
                                },
                            ]"
                            :key="mode.title"
                            class="landing-reveal relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-7 shadow-lg shadow-slate-200/50"
                            :style="{ transitionDelay: `${index * 100}ms` }"
                        >
                            <div class="flex items-center gap-4">
                                <span
                                    class="flex h-12 w-12 items-center justify-center rounded-xl bg-linear-to-br from-theme-1 to-theme-2 text-white shadow-lg shadow-primary/20"
                                    ><Lucide
                                        :icon="mode.icon as any"
                                        class="h-5 w-5"
                                /></span>
                                <div>
                                    <h3
                                        class="text-xl font-semibold text-slate-900"
                                    >
                                        {{ mode.title }}
                                    </h3>
                                    <p class="mt-0.5 text-sm text-slate-500">
                                        {{ mode.text }}
                                    </p>
                                </div>
                            </div>
                            <ul class="mt-6 space-y-3 text-sm text-slate-600">
                                <li
                                    v-for="item in mode.items"
                                    :key="item"
                                    class="flex items-start gap-2.5"
                                >
                                    <Lucide
                                        icon="CheckCircle2"
                                        class="mt-0.5 h-4 w-4 shrink-0 text-success"
                                    />
                                    <span>{{ item }}</span>
                                </li>
                            </ul>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Cómo funciona -->
            <section
                id="como-funciona"
                class="relative bg-slate-950 py-24 text-white lg:py-32"
            >
                <div
                    class="absolute inset-0 bg-texture-white bg-cover opacity-60"
                />
                <div class="relative mx-auto max-w-7xl px-5 lg:px-8">
                    <div class="landing-reveal max-w-2xl">
                        <span
                            class="inline-flex rounded-full border border-info/40 bg-info/15 px-3 py-1.5 text-xs font-semibold tracking-wider text-white uppercase"
                            >Simple desde el primer día</span
                        >
                        <h2
                            class="mt-5 text-3xl leading-tight font-semibold tracking-tight sm:text-4xl lg:text-5xl"
                        >
                            De la llegada del huésped al cierre del día, sin
                            perder el hilo.
                        </h2>
                    </div>
                    <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        <article
                            v-for="(step, index) in [
                                {
                                    icon: 'SlidersHorizontal',
                                    title: 'Configura tu hotel',
                                    text: 'Habitaciones, tarifas, equipo y políticas en un flujo guiado, con nuestro acompañamiento.',
                                },
                                {
                                    icon: 'PlugZap',
                                    title: 'Conecta canales y cobros',
                                    text: 'WhatsApp, redes, tu sitio web y tu pasarela de pago comparten la misma información.',
                                },
                                {
                                    icon: 'Map',
                                    title: 'Opera desde el plano',
                                    text: 'Entradas, salidas, limpieza, consumos y caja del turno sin cambiar de pantalla.',
                                },
                                {
                                    icon: 'TrendingUp',
                                    title: 'Decide con claridad',
                                    text: 'Reportes de ocupación, ingresos, cortes y satisfacción para actuar a tiempo.',
                                },
                            ]"
                            :key="step.title"
                            class="landing-reveal relative rounded-2xl border border-white/10 bg-white/[0.04] p-6 backdrop-blur-sm"
                            :style="{ transitionDelay: `${index * 100}ms` }"
                        >
                            <div class="flex items-center justify-between">
                                <span
                                    class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/10"
                                    ><Lucide
                                        :icon="step.icon as any"
                                        class="h-5 w-5 text-white" /></span
                                ><span
                                    class="text-5xl font-semibold text-white/[0.06]"
                                    >0{{ index + 1 }}</span
                                >
                            </div>
                            <h3 class="mt-6 text-lg font-semibold">
                                {{ step.title }}
                            </h3>
                            <p class="mt-3 text-sm leading-6 text-slate-400">
                                {{ step.text }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Planes -->
            <section id="planes" class="bg-white py-24 lg:py-32">
                <div class="mx-auto max-w-7xl px-5 lg:px-8">
                    <div class="landing-reveal mx-auto max-w-2xl text-center">
                        <span class="landing-eyebrow">Planes flexibles</span>
                        <h2 class="landing-title">
                            Elige el nivel de operación que necesitas.
                        </h2>
                        <p class="landing-subtitle">
                            Todos los planes se administran desde el mismo
                            panel. Puedes subir de plan o sumar servicios cuando
                            tu hotel lo requiera.
                        </p>
                    </div>
                    <div
                        class="mx-auto mt-14 grid max-w-5xl items-stretch gap-6"
                        :class="
                            plans.length > 2
                                ? 'lg:grid-cols-3'
                                : 'lg:grid-cols-2'
                        "
                    >
                        <article
                            v-for="(plan, index) in plans"
                            :key="plan.key"
                            class="landing-reveal relative flex flex-col rounded-2xl border p-7 transition duration-300 hover:-translate-y-1"
                            :class="
                                isFeatured(index)
                                    ? 'border-primary bg-primary text-white shadow-2xl shadow-primary/20'
                                    : 'border-slate-200 bg-white shadow-lg shadow-slate-200/50'
                            "
                        >
                            <span
                                v-if="isFeatured(index)"
                                class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-info px-3 py-1 text-[10px] font-bold tracking-wider text-white uppercase"
                                >Recomendado</span
                            >
                            <div>
                                <h3 class="text-xl font-semibold">
                                    {{ plan.label }}
                                </h3>
                                <p
                                    class="mt-2 min-h-15 text-sm leading-5"
                                    :class="
                                        isFeatured(index)
                                            ? 'text-white/70'
                                            : 'text-slate-500'
                                    "
                                >
                                    {{
                                        plan.description ||
                                        'Todo lo necesario para profesionalizar la operación de tu hotel.'
                                    }}
                                </p>
                            </div>
                            <div
                                class="mt-6 border-y py-5"
                                :class="
                                    isFeatured(index)
                                        ? 'border-white/15'
                                        : 'border-slate-100'
                                "
                            >
                                <template v-if="plan.price_monthly > 0"
                                    ><span
                                        class="text-4xl font-semibold tracking-tight"
                                        >${{ money(plan.price_monthly) }}</span
                                    ><span class="ml-1 text-sm opacity-60"
                                        >MXN / mes</span
                                    >
                                    <span
                                        v-if="plan.activation_fee > 0"
                                        class="mt-1 block text-xs opacity-60"
                                        >Activación única de ${{
                                            money(plan.activation_fee)
                                        }}</span
                                    ></template
                                >
                                <template v-else
                                    ><span
                                        class="text-3xl font-semibold tracking-tight"
                                        >A la medida</span
                                    ><span class="mt-1 block text-xs opacity-60"
                                        >Cotización según tu operación</span
                                    ></template
                                >
                            </div>
                            <ul class="mt-6 space-y-3 text-sm">
                                <li
                                    v-for="limit in [
                                        plan.max_rooms
                                            ? `Hasta ${plan.max_rooms} habitaciones`
                                            : 'Habitaciones sin límite',
                                        plan.max_users
                                            ? `${plan.max_users} usuarios incluidos`
                                            : 'Usuarios sin límite',
                                        plan.max_channels === null
                                            ? 'Canales de mensajería sin límite'
                                            : plan.max_channels > 0
                                              ? `${plan.max_channels} ${plan.max_channels === 1 ? 'canal' : 'canales'} de mensajería`
                                              : null,
                                        plan.ai_monthly_replies
                                            ? `Asistente IA: ${money(plan.ai_monthly_replies)} respuestas al mes`
                                            : null,
                                    ].filter(Boolean)"
                                    :key="String(limit)"
                                    class="flex items-start gap-2.5"
                                >
                                    <Lucide
                                        icon="CheckCircle2"
                                        class="mt-0.5 h-4 w-4 shrink-0"
                                        :class="
                                            isFeatured(index)
                                                ? 'text-white'
                                                : 'text-success'
                                        "
                                    /><span>{{ limit }}</span>
                                </li>
                            </ul>
                            <div
                                v-if="coverage(plan).length"
                                class="mt-6 rounded-xl p-4"
                                :class="
                                    isFeatured(index)
                                        ? 'bg-white/10'
                                        : 'bg-slate-50'
                                "
                            >
                                <div
                                    class="text-[11px] font-semibold tracking-wider uppercase"
                                    :class="
                                        isFeatured(index)
                                            ? 'text-white/60'
                                            : 'text-slate-400'
                                    "
                                >
                                    {{ plan.modules.length }} módulos incluidos
                                </div>
                                <div class="mt-3 space-y-2">
                                    <div
                                        v-for="g in coverage(plan)"
                                        :key="g.key"
                                        class="text-xs"
                                    >
                                        <div class="flex justify-between gap-2">
                                            <span>{{ g.label }}</span>
                                            <span class="opacity-60"
                                                >{{ g.included }} de
                                                {{ g.total }}</span
                                            >
                                        </div>
                                        <div
                                            class="mt-1 h-1 overflow-hidden rounded-full"
                                            :class="
                                                isFeatured(index)
                                                    ? 'bg-white/15'
                                                    : 'bg-slate-200'
                                            "
                                        >
                                            <div
                                                class="h-full rounded-full"
                                                :class="
                                                    isFeatured(index)
                                                        ? 'bg-white'
                                                        : 'bg-primary'
                                                "
                                                :style="{
                                                    width: `${Math.round((g.included / g.total) * 100)}%`,
                                                }"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex-1" />
                            <button
                                class="mt-8 w-full rounded-xl px-5 py-3 font-medium transition"
                                :class="
                                    isFeatured(index)
                                        ? 'bg-white text-primary hover:bg-slate-100'
                                        : 'border border-primary/15 bg-primary/5 text-primary hover:bg-primary hover:text-white'
                                "
                                @click="choosePlan(plan)"
                            >
                                Me interesa este plan
                            </button>
                        </article>
                    </div>

                    <!-- Servicios adicionales -->
                    <div v-if="addons.length" class="mx-auto mt-20 max-w-5xl">
                        <div class="landing-reveal text-center">
                            <h3
                                class="text-2xl font-semibold tracking-tight text-slate-950"
                            >
                                Servicios que suman a cualquier plan
                            </h3>
                            <p class="mt-3 text-sm text-slate-500">
                                Se contratan aparte y se activan sin cambiar de
                                plan.
                            </p>
                        </div>
                        <div
                            class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
                        >
                            <article
                                v-for="(addon, index) in addons"
                                :key="addon.key"
                                class="landing-reveal flex flex-col rounded-2xl border border-slate-200 bg-slate-50/60 p-5 transition duration-300 hover:-translate-y-1 hover:border-primary/20 hover:bg-white hover:shadow-xl hover:shadow-primary/5"
                                :style="{
                                    transitionDelay: `${Math.min(index, 5) * 70}ms`,
                                }"
                            >
                                <span
                                    v-if="addonTitle(addon).tag"
                                    class="self-start rounded-full bg-primary/5 px-2.5 py-1 text-[10px] font-semibold tracking-wider text-primary uppercase"
                                    >{{ addonTitle(addon).tag }}</span
                                >
                                <h4
                                    class="mt-3 text-base font-semibold text-slate-900"
                                >
                                    {{ addonTitle(addon).title }}
                                </h4>
                                <p
                                    class="mt-2 flex-1 text-sm leading-6 text-slate-500"
                                >
                                    {{ addon.summary }}
                                </p>
                                <p
                                    v-if="addon.requires"
                                    class="mt-4 border-t border-slate-200 pt-3 text-[11px] text-slate-400"
                                >
                                    Requiere {{ addonName(addon.requires) }}
                                </p>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Contacto -->
            <section
                id="contacto"
                class="relative overflow-hidden bg-slate-100 py-24 lg:py-32"
            >
                <div class="landing-orb landing-orb--three" />
                <div
                    class="relative mx-auto grid max-w-7xl gap-12 px-5 lg:grid-cols-[0.8fr_1.2fr] lg:px-8"
                >
                    <div class="landing-reveal lg:pt-8">
                        <span class="landing-eyebrow"
                            >Hablemos de tu hotel</span
                        >
                        <h2
                            class="mt-5 text-3xl leading-tight font-semibold tracking-tight text-slate-950 sm:text-4xl"
                        >
                            Descubre cómo se vería tu operación en
                            {{ brandName }}.
                        </h2>
                        <p class="mt-5 text-base leading-7 text-slate-600">
                            Déjanos tus datos y prepararemos una demostración
                            enfocada en el tamaño y las necesidades reales de tu
                            propiedad.
                        </p>
                        <div class="mt-8 space-y-4">
                            <div
                                v-for="item in [
                                    {
                                        icon: 'Clock3',
                                        title: 'Conversación breve',
                                        text: 'Entendemos primero cómo opera tu hotel o motel.',
                                    },
                                    {
                                        icon: 'Presentation',
                                        title: 'Demo personalizada',
                                        text: 'Te mostramos los módulos que sí necesitas.',
                                    },
                                    {
                                        icon: 'LifeBuoy',
                                        title: 'Acompañamiento',
                                        text: 'Te ayudamos a configurar habitaciones, tarifas y canales.',
                                    },
                                ]"
                                :key="item.title"
                                class="flex gap-4"
                            >
                                <span
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-primary shadow-sm"
                                    ><Lucide
                                        :icon="item.icon as any"
                                        class="h-4 w-4"
                                /></span>
                                <div>
                                    <h3
                                        class="text-sm font-semibold text-slate-800"
                                    >
                                        {{ item.title }}
                                    </h3>
                                    <p class="mt-0.5 text-sm text-slate-500">
                                        {{ item.text }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="landing-reveal rounded-2xl border border-white bg-white p-6 shadow-2xl shadow-slate-300/40 sm:p-8"
                    >
                        <div
                            v-if="submitted"
                            class="flex min-h-[500px] flex-col items-center justify-center text-center"
                        >
                            <span
                                class="flex h-20 w-20 items-center justify-center rounded-full bg-success/10"
                                ><Lucide
                                    icon="CheckCircle2"
                                    class="h-10 w-10 text-success"
                            /></span>
                            <h3
                                class="mt-6 text-2xl font-semibold text-slate-900"
                            >
                                ¡Gracias por contactarnos!
                            </h3>
                            <p
                                class="mt-3 max-w-sm text-sm leading-6 text-slate-500"
                            >
                                Recibimos tu solicitud para el plan
                                {{ selectedPlan?.label }}. Nuestro equipo se
                                pondrá en contacto contigo muy pronto.
                            </p>
                            <button
                                class="mt-7 text-sm font-semibold text-primary hover:underline"
                                @click="submitted = false"
                            >
                                Enviar otra solicitud
                            </button>
                        </div>
                        <form v-else @submit.prevent="submit">
                            <div
                                class="mb-7 flex items-start justify-between gap-4"
                            >
                                <div>
                                    <h3
                                        class="text-xl font-semibold text-slate-900"
                                    >
                                        Solicita tu demostración
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Te responderemos con los siguientes
                                        pasos.
                                    </p>
                                </div>
                                <span
                                    class="hidden rounded-lg bg-primary/5 px-3 py-2 text-xs font-semibold text-primary sm:inline"
                                    >Sin compromiso</span
                                >
                            </div>
                            <div class="grid gap-5 sm:grid-cols-2">
                                <label class="landing-field"
                                    ><span>Tu nombre *</span
                                    ><input
                                        v-model="form.name"
                                        type="text"
                                        autocomplete="name"
                                        placeholder="Nombre completo"
                                    /><small v-if="form.errors.name">{{
                                        form.errors.name
                                    }}</small></label
                                >
                                <label class="landing-field"
                                    ><span>Hotel o propiedad *</span
                                    ><input
                                        v-model="form.hotel_name"
                                        type="text"
                                        autocomplete="organization"
                                        placeholder="Nombre del hotel o motel"
                                    /><small v-if="form.errors.hotel_name">{{
                                        form.errors.hotel_name
                                    }}</small></label
                                >
                                <label class="landing-field"
                                    ><span>Correo electrónico *</span
                                    ><input
                                        v-model="form.email"
                                        type="email"
                                        autocomplete="email"
                                        placeholder="tu@hotel.com"
                                    /><small v-if="form.errors.email">{{
                                        form.errors.email
                                    }}</small></label
                                >
                                <label class="landing-field"
                                    ><span>Teléfono / WhatsApp *</span
                                    ><input
                                        v-model="form.phone"
                                        type="tel"
                                        autocomplete="tel"
                                        placeholder="+52 000 000 0000"
                                    /><small v-if="form.errors.phone">{{
                                        form.errors.phone
                                    }}</small></label
                                >
                                <label class="landing-field"
                                    ><span>Número de habitaciones</span
                                    ><input
                                        v-model="form.rooms"
                                        type="number"
                                        min="1"
                                        max="10000"
                                        placeholder="Ej. 30"
                                    /><small v-if="form.errors.rooms">{{
                                        form.errors.rooms
                                    }}</small></label
                                >
                                <label class="landing-field"
                                    ><span>Plan de interés *</span
                                    ><select v-model="form.plan_key">
                                        <option
                                            v-for="plan in plans"
                                            :key="plan.key"
                                            :value="plan.key"
                                        >
                                            {{ plan.label }}
                                        </option></select
                                    ><small v-if="form.errors.plan_key">{{
                                        form.errors.plan_key
                                    }}</small></label
                                >
                                <label class="landing-field sm:col-span-2"
                                    ><span>¿Qué quieres mejorar?</span
                                    ><textarea
                                        v-model="form.message"
                                        rows="3"
                                        placeholder="Cuéntanos brevemente sobre tu operación actual..."
                                    /><small v-if="form.errors.message">{{
                                        form.errors.message
                                    }}</small></label
                                >
                            </div>
                            <label class="sr-only" aria-hidden="true"
                                >Sitio web<input
                                    v-model="form.website"
                                    tabindex="-1"
                                    autocomplete="off"
                            /></label>
                            <label
                                class="mt-5 flex cursor-pointer items-start gap-3 text-xs leading-5 text-slate-500"
                                ><input
                                    v-model="form.privacy"
                                    type="checkbox"
                                    class="mt-0.5 rounded border-slate-300 text-primary focus:ring-primary"
                                /><span
                                    >Acepto que mis datos sean usados para
                                    atender esta solicitud comercial.</span
                                ></label
                            >
                            <small
                                v-if="form.errors.privacy"
                                class="mt-1 block text-xs text-danger"
                                >{{ form.errors.privacy }}</small
                            >
                            <button
                                type="submit"
                                class="group mt-6 flex w-full items-center justify-center rounded-xl bg-primary px-6 py-3.5 font-medium text-white shadow-lg shadow-primary/20 transition hover:bg-theme-2 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="form.processing || !plans.length"
                            >
                                <span>{{
                                    form.processing
                                        ? 'Enviando solicitud...'
                                        : 'Solicitar mi demo'
                                }}</span
                                ><Lucide
                                    v-if="!form.processing"
                                    icon="ArrowRight"
                                    class="ml-2 h-4 w-4 transition-transform group-hover:translate-x-1"
                                />
                            </button>
                            <p
                                class="mt-4 text-center text-[11px] text-slate-400"
                            >
                                <Lucide
                                    icon="LockKeyhole"
                                    class="mr-1 inline h-3 w-3"
                                />
                                Tus datos se almacenan de forma segura.
                            </p>
                        </form>
                    </div>
                </div>
            </section>
        </main>

        <footer class="bg-slate-950 py-10 text-slate-400">
            <div
                class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-6 px-5 sm:flex-row lg:px-8"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="flex h-9 w-9 items-center justify-center overflow-hidden rounded-lg bg-white/10"
                    >
                        <img
                            v-if="brandLogo"
                            :src="brandLogo"
                            :alt="brandName"
                            class="h-full w-full bg-white object-contain p-1"
                        />
                        <Lucide
                            v-else
                            icon="Building2"
                            class="h-4 w-4 text-white"
                        />
                    </span>
                    <div>
                        <div class="text-sm font-semibold text-white">
                            {{ brandName }}
                        </div>
                        <div class="text-[11px]">
                            Hoteles conectados, equipos enfocados.
                        </div>
                    </div>
                </div>
                <div
                    class="flex flex-wrap items-center justify-center gap-6 text-xs"
                >
                    <button
                        v-for="item in navItems"
                        :key="item.id"
                        class="hover:text-white"
                        @click="goTo(item.id)"
                    >
                        {{ item.label }}
                    </button>
                    <button class="hover:text-white" @click="goTo('contacto')">
                        Contacto
                    </button>
                    <Link :href="route('login')" class="hover:text-white"
                        >Acceso clientes</Link
                    >
                </div>
                <p class="text-xs">
                    © {{ new Date().getFullYear() }} {{ brandName }}
                </p>
            </div>
        </footer>
    </div>
</template>

<style scoped>
@reference "../../css/app.css";

.landing-page {
    font-family: 'Public Sans', sans-serif;
}

.landing-page [id] {
    scroll-margin-top: 80px;
}

.landing-nav-link {
    @apply text-sm font-medium text-slate-600 transition hover:text-primary;
}

.mobile-nav-link {
    @apply rounded-lg px-3 py-2.5 text-left text-sm font-medium text-slate-700 transition hover:bg-primary/5 hover:text-primary;
}

.landing-gradient-text {
    background: linear-gradient(
        100deg,
        var(--color-primary) 5%,
        var(--color-info) 70%
    );
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}

.landing-eyebrow {
    @apply inline-flex rounded-full border border-primary/10 bg-primary/5 px-3 py-1.5 text-xs font-semibold tracking-wider text-primary uppercase;
}

.landing-title {
    @apply mt-5 text-3xl leading-tight font-semibold tracking-tight text-slate-950 sm:text-4xl lg:text-5xl;
}

.landing-subtitle {
    @apply mt-5 text-base leading-7 text-slate-600;
}

.landing-feature-card {
    @apply rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition duration-300 hover:-translate-y-1 hover:border-primary/20 hover:shadow-xl hover:shadow-primary/5;
}

.landing-bubble {
    @apply max-w-[85%] rounded-2xl px-3.5 py-2.5 shadow-sm;
}

.landing-bubble--guest {
    @apply ml-auto rounded-br-md bg-primary text-white;
}

.landing-bubble--bot {
    @apply rounded-bl-md border border-slate-200 bg-white text-slate-700;
}

.landing-field {
    @apply block;
}

.landing-field > span {
    @apply mb-2 block text-xs font-semibold text-slate-700;
}

.landing-field input,
.landing-field select,
.landing-field textarea {
    @apply w-full rounded-lg border-slate-200 bg-slate-50 px-3.5 py-3 text-sm text-slate-800 transition placeholder:text-slate-400 focus:border-primary focus:bg-white focus:ring-2 focus:ring-primary/10;
}

.landing-field small {
    @apply mt-1 block text-xs text-danger;
}

.landing-reveal {
    opacity: 0;
    transform: translateY(24px);
    transition:
        opacity 650ms ease,
        transform 650ms cubic-bezier(0.22, 1, 0.36, 1);
}

.landing-reveal.is-visible {
    opacity: 1;
    transform: translateY(0);
}

.landing-dashboard {
    animation: dashboard-in 900ms cubic-bezier(0.22, 1, 0.36, 1) both;
}

.landing-float-card {
    animation: float-card 4s ease-in-out infinite;
}

.landing-float-card--late {
    animation-delay: 2s;
}

.landing-room {
    animation: room-in 500ms cubic-bezier(0.22, 1, 0.36, 1) both;
}

.landing-orb {
    position: absolute;
    border-radius: 9999px;
    filter: blur(1px);
    pointer-events: none;
}

.landing-orb--one {
    top: 8rem;
    right: -12rem;
    height: 34rem;
    width: 34rem;
    background: radial-gradient(
        circle,
        color-mix(in srgb, var(--color-info) 16%, transparent),
        transparent 68%
    );
}

.landing-orb--two {
    bottom: -12rem;
    left: -14rem;
    height: 30rem;
    width: 30rem;
    background: radial-gradient(
        circle,
        color-mix(in srgb, var(--color-primary) 11%, transparent),
        transparent 68%
    );
}

.landing-orb--three {
    top: -10rem;
    right: -8rem;
    height: 32rem;
    width: 32rem;
    background: radial-gradient(
        circle,
        color-mix(in srgb, var(--color-info) 13%, transparent),
        transparent 68%
    );
}

@keyframes dashboard-in {
    from {
        opacity: 0;
        transform: translateY(28px) scale(0.97);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@keyframes float-card {
    0%,
    100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-8px);
    }
}

@keyframes room-in {
    from {
        opacity: 0;
        transform: scale(0.85);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .landing-dashboard,
    .landing-float-card,
    .landing-room {
        animation: none;
    }

    .landing-reveal {
        opacity: 1;
        transform: none;
        transition: none;
    }
}
</style>
