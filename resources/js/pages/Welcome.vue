<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import Lucide from '@/components/Base/Lucide';

interface LandingPlan {
    key: string;
    label: string;
    description: string | null;
    price_monthly: number;
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
}

const props = withDefaults(
    defineProps<{
        canRegister: boolean;
        plans: LandingPlan[];
        modules: LandingModule[];
    }>(),
    {
        canRegister: true,
        plans: () => [],
        modules: () => [],
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

const moduleIcons: Record<string, string> = {
    pos: 'ShoppingCart',
    cobros: 'CreditCard',
    'agente-ia': 'Bot',
    'motor-web': 'Globe2',
    extras: 'Gift',
    experiencias: 'Compass',
    grupos: 'UsersRound',
    'lista-espera': 'BellRing',
    cupones: 'TicketPercent',
};

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
        document
            .querySelectorAll<HTMLElement>('.landing-reveal')
            .forEach((element) => revealObserver?.observe(element));
    } else {
        document
            .querySelectorAll<HTMLElement>('.landing-reveal')
            .forEach((element) => element.classList.add('is-visible'));
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', handleScroll);
    revealObserver?.disconnect();
});
</script>

<template>
    <Head title="Software para hoteles">
        <meta
            name="description"
            content="Reservas, operación, cobros y atención con IA para hoteles que quieren trabajar mejor desde una sola plataforma."
        />
    </Head>

    <div
        class="landing-page min-h-screen overflow-hidden bg-slate-50 text-slate-800"
    >
        <header
            class="fixed inset-x-0 top-0 z-50 transition-all duration-300"
            :class="
                isScrolled
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

                <nav class="ml-auto hidden items-center gap-8 lg:flex">
                    <a
                        href="#modulos"
                        class="landing-nav-link"
                        @click.prevent="goTo('modulos')"
                        >Módulos</a
                    >
                    <a
                        href="#como-funciona"
                        class="landing-nav-link"
                        @click.prevent="goTo('como-funciona')"
                        >Cómo funciona</a
                    >
                    <a
                        href="#planes"
                        class="landing-nav-link"
                        @click.prevent="goTo('planes')"
                        >Planes</a
                    >
                    <a
                        href="#contacto"
                        class="landing-nav-link"
                        @click.prevent="goTo('contacto')"
                        >Contacto</a
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
                    aria-label="Abrir menú"
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
                    <button class="mobile-nav-link" @click="goTo('modulos')">
                        Módulos
                    </button>
                    <button
                        class="mobile-nav-link"
                        @click="goTo('como-funciona')"
                    >
                        Cómo funciona
                    </button>
                    <button class="mobile-nav-link" @click="goTo('planes')">
                        Planes
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
                            Operación hotelera en un solo lugar
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
                            Reservas, habitaciones, huéspedes, cobros y atención
                            con IA en una plataforma diseñada para que tu equipo
                            gane tiempo y venda más.
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
                                @click="goTo('modulos')"
                            >
                                <Lucide
                                    icon="PlayCircle"
                                    class="mr-2 h-4 w-4"
                                />
                                Explorar la plataforma
                            </button>
                        </div>
                        <div
                            class="mt-8 flex flex-wrap gap-x-6 gap-y-3 text-sm text-slate-500"
                        >
                            <span class="flex items-center gap-2"
                                ><Lucide
                                    icon="Check"
                                    class="h-4 w-4 text-success"
                                />
                                Acompañamiento inicial</span
                            >
                            <span class="flex items-center gap-2"
                                ><Lucide
                                    icon="Check"
                                    class="h-4 w-4 text-success"
                                />
                                Crece por módulos</span
                            >
                            <span class="flex items-center gap-2"
                                ><Lucide
                                    icon="Check"
                                    class="h-4 w-4 text-success"
                                />
                                Acceso desde cualquier lugar</span
                            >
                        </div>
                    </div>

                    <div class="relative mx-auto w-full max-w-2xl lg:mx-0">
                        <div
                            class="landing-dashboard relative rounded-[1.4rem] border border-white/80 bg-white/90 p-2.5 shadow-2xl shadow-primary/15 backdrop-blur"
                        >
                            <div
                                class="flex h-10 items-center gap-2 rounded-t-xl border-b border-slate-100 px-4"
                            >
                                <span
                                    class="h-2.5 w-2.5 rounded-full bg-red-400"
                                />
                                <span
                                    class="h-2.5 w-2.5 rounded-full bg-amber-400"
                                />
                                <span
                                    class="h-2.5 w-2.5 rounded-full bg-emerald-400"
                                />
                                <div
                                    class="mx-auto h-5 w-40 rounded-md bg-slate-100"
                                />
                            </div>
                            <div
                                class="grid min-h-[370px] grid-cols-[72px_1fr] overflow-hidden rounded-b-xl bg-slate-50 sm:grid-cols-[155px_1fr]"
                            >
                                <aside
                                    class="bg-linear-to-b from-theme-1 to-theme-2 p-3 sm:p-4"
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
                                                'LayoutDashboard',
                                                'Map',
                                                'CalendarDays',
                                                'Users',
                                                'Wallet',
                                            ]"
                                            :key="item"
                                            class="flex items-center gap-2 rounded-lg px-2 py-2 text-white/60 first:bg-white/10 first:text-white"
                                        >
                                            <Lucide
                                                :icon="item as any"
                                                class="h-3.5 w-3.5 shrink-0"
                                            />
                                            <span
                                                class="hidden h-1.5 rounded bg-current opacity-60 sm:block"
                                                :class="
                                                    item === 'LayoutDashboard'
                                                        ? 'w-14'
                                                        : 'w-10'
                                                "
                                            />
                                        </div>
                                    </div>
                                </aside>
                                <div class="p-4 sm:p-5">
                                    <div
                                        class="mb-5 flex items-center justify-between"
                                    >
                                        <div>
                                            <div
                                                class="text-xs font-semibold text-slate-800"
                                            >
                                                Buenos días, recepción
                                            </div>
                                            <div
                                                class="mt-1 text-[9px] text-slate-400"
                                            >
                                                Resumen de la operación de hoy
                                            </div>
                                        </div>
                                        <span
                                            class="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-[9px] font-semibold text-primary"
                                            >RC</span
                                        >
                                    </div>
                                    <div
                                        class="grid grid-cols-2 gap-2 sm:grid-cols-4"
                                    >
                                        <div
                                            v-for="stat in [
                                                {
                                                    label: 'Ocupación',
                                                    value: '78%',
                                                    icon: 'BedDouble',
                                                },
                                                {
                                                    label: 'Llegadas',
                                                    value: '12',
                                                    icon: 'LogIn',
                                                },
                                                {
                                                    label: 'Salidas',
                                                    value: '8',
                                                    icon: 'LogOut',
                                                },
                                                {
                                                    label: 'Ingresos',
                                                    value: '$24k',
                                                    icon: 'Wallet',
                                                },
                                            ]"
                                            :key="stat.label"
                                            class="rounded-lg border border-slate-100 bg-white p-2.5 shadow-sm"
                                        >
                                            <div
                                                class="flex items-center justify-between"
                                            >
                                                <span
                                                    class="text-[8px] text-slate-400"
                                                    >{{ stat.label }}</span
                                                ><Lucide
                                                    :icon="stat.icon as any"
                                                    class="h-3 w-3 text-primary"
                                                />
                                            </div>
                                            <div
                                                class="mt-1 text-sm font-semibold text-slate-700"
                                            >
                                                {{ stat.value }}
                                            </div>
                                        </div>
                                    </div>
                                    <div
                                        class="mt-3 grid gap-3 sm:grid-cols-[1.35fr_0.65fr]"
                                    >
                                        <div
                                            class="rounded-xl border border-slate-100 bg-white p-3 shadow-sm"
                                        >
                                            <div
                                                class="mb-3 flex items-center justify-between"
                                            >
                                                <span
                                                    class="text-[10px] font-semibold text-slate-700"
                                                    >Ocupación semanal</span
                                                ><span
                                                    class="text-[8px] text-success"
                                                    >+12% este mes</span
                                                >
                                            </div>
                                            <div
                                                class="flex h-28 items-end justify-between gap-2 border-b border-slate-100 px-1"
                                            >
                                                <div
                                                    v-for="(height, index) in [
                                                        42, 58, 50, 76, 64, 88,
                                                        72,
                                                    ]"
                                                    :key="index"
                                                    class="landing-bar w-full rounded-t-sm bg-linear-to-t from-primary to-info"
                                                    :style="{
                                                        height: `${height}%`,
                                                        animationDelay: `${index * 90}ms`,
                                                    }"
                                                />
                                            </div>
                                            <div
                                                class="mt-2 flex justify-between px-1 text-[7px] text-slate-400"
                                            >
                                                <span
                                                    v-for="day in [
                                                        'L',
                                                        'M',
                                                        'M',
                                                        'J',
                                                        'V',
                                                        'S',
                                                        'D',
                                                    ]"
                                                    :key="day"
                                                    >{{ day }}</span
                                                >
                                            </div>
                                        </div>
                                        <div
                                            class="rounded-xl border border-slate-100 bg-white p-3 shadow-sm"
                                        >
                                            <span
                                                class="text-[10px] font-semibold text-slate-700"
                                                >Habitaciones</span
                                            >
                                            <div
                                                class="mt-4 flex justify-center"
                                            >
                                                <div
                                                    class="flex h-20 w-20 items-center justify-center rounded-full bg-[conic-gradient(#03045e_0_78%,#e2e8f0_78%)]"
                                                >
                                                    <div
                                                        class="flex h-14 w-14 flex-col items-center justify-center rounded-full bg-white"
                                                    >
                                                        <span
                                                            class="text-sm font-semibold text-slate-700"
                                                            >78%</span
                                                        ><span
                                                            class="text-[7px] text-slate-400"
                                                            >ocupadas</span
                                                        >
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div
                            class="landing-float-card absolute -right-4 -bottom-7 hidden items-center gap-3 rounded-xl border border-white bg-white p-3 shadow-xl sm:flex"
                        >
                            <span
                                class="flex h-9 w-9 items-center justify-center rounded-lg bg-success/10"
                                ><Lucide
                                    icon="BadgeCheck"
                                    class="h-5 w-5 text-success"
                            /></span>
                            <div>
                                <div class="text-[10px] text-slate-400">
                                    Nueva reserva
                                </div>
                                <div
                                    class="text-xs font-semibold text-slate-700"
                                >
                                    Confirmada automáticamente
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="border-y border-slate-200/80 bg-white py-7">
                <div
                    class="mx-auto flex max-w-7xl flex-wrap items-center justify-center gap-x-12 gap-y-5 px-5 text-sm text-slate-500 lg:px-8"
                >
                    <span class="font-medium text-slate-400"
                        >Todo tu hotel conectado:</span
                    >
                    <span
                        v-for="item in [
                            { icon: 'BedDouble', label: 'Habitaciones' },
                            { icon: 'CalendarCheck', label: 'Reservas' },
                            { icon: 'MessagesSquare', label: 'Mensajería' },
                            { icon: 'CreditCard', label: 'Pagos' },
                            { icon: 'Bot', label: 'Inteligencia artificial' },
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

            <section id="modulos" class="py-24 lg:py-32">
                <div class="mx-auto max-w-7xl px-5 lg:px-8">
                    <div class="landing-reveal mx-auto max-w-2xl text-center">
                        <span class="landing-eyebrow"
                            >Una plataforma, todas las áreas</span
                        >
                        <h2 class="landing-title">
                            Menos herramientas sueltas.<br />Más control de tu
                            operación.
                        </h2>
                        <p class="landing-subtitle">
                            Activa los módulos que tu hotel necesita hoy y suma
                            más conforme crece tu operación.
                        </p>
                    </div>
                    <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        <article
                            v-for="(module, index) in modules"
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
                            <div
                                class="mt-5 flex items-center text-xs font-semibold text-primary opacity-0 transition group-hover:opacity-100"
                            >
                                Integrado en la plataforma
                                <Lucide
                                    icon="ArrowUpRight"
                                    class="ml-1 h-3.5 w-3.5"
                                />
                            </div>
                        </article>
                    </div>
                </div>
            </section>

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
                            class="inline-flex rounded-full border border-info/30 bg-info/10 px-3 py-1.5 text-xs font-semibold tracking-wider text-cyan-300 uppercase"
                            >Simple desde el primer día</span
                        >
                        <h2
                            class="mt-5 text-3xl leading-tight font-semibold tracking-tight sm:text-4xl lg:text-5xl"
                        >
                            De la llegada del huésped al cierre del día, sin
                            perder el hilo.
                        </h2>
                    </div>
                    <div class="mt-14 grid gap-8 lg:grid-cols-3">
                        <article
                            v-for="(step, index) in [
                                {
                                    icon: 'SlidersHorizontal',
                                    title: 'Configura tu hotel',
                                    text: 'Habitaciones, tarifas, equipo y políticas listas en un flujo guiado.',
                                },
                                {
                                    icon: 'Workflow',
                                    title: 'Conecta la operación',
                                    text: 'Reservas, cobros, huéspedes y mensajería comparten la misma información.',
                                },
                                {
                                    icon: 'TrendingUp',
                                    title: 'Decide con claridad',
                                    text: 'Ve ocupación, ingresos y tareas pendientes para actuar a tiempo.',
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
                                        class="h-5 w-5 text-cyan-300" /></span
                                ><span
                                    class="text-5xl font-semibold text-white/[0.06]"
                                    >0{{ index + 1 }}</span
                                >
                            </div>
                            <h3 class="mt-6 text-xl font-semibold">
                                {{ step.title }}
                            </h3>
                            <p class="mt-3 text-sm leading-6 text-slate-400">
                                {{ step.text }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <section id="planes" class="bg-white py-24 lg:py-32">
                <div class="mx-auto max-w-7xl px-5 lg:px-8">
                    <div class="landing-reveal mx-auto max-w-2xl text-center">
                        <span class="landing-eyebrow">Planes flexibles</span>
                        <h2 class="landing-title">
                            Elige el nivel de operación que necesitas.
                        </h2>
                        <p class="landing-subtitle">
                            Todos los planes se administran desde el mismo
                            panel. Puedes evolucionar cuando tu hotel lo
                            requiera.
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
                                plan.key === 'pro' ||
                                (plans.length > 1 && index === 1)
                                    ? 'border-primary bg-primary text-white shadow-2xl shadow-primary/20'
                                    : 'border-slate-200 bg-white shadow-lg shadow-slate-200/50'
                            "
                        >
                            <span
                                v-if="
                                    plan.key === 'pro' ||
                                    (plans.length > 1 && index === 1)
                                "
                                class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-cyan-400 px-3 py-1 text-[10px] font-bold tracking-wider text-slate-950 uppercase"
                                >Más completo</span
                            >
                            <div>
                                <h3 class="text-xl font-semibold">
                                    {{ plan.label }}
                                </h3>
                                <p
                                    class="mt-2 min-h-10 text-sm leading-5"
                                    :class="
                                        plan.key === 'pro' ||
                                        (plans.length > 1 && index === 1)
                                            ? 'text-white/65'
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
                                    plan.key === 'pro' ||
                                    (plans.length > 1 && index === 1)
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
                            <ul class="mt-6 flex-1 space-y-3 text-sm">
                                <li class="flex items-start gap-2.5">
                                    <Lucide
                                        icon="CheckCircle2"
                                        class="mt-0.5 h-4 w-4 shrink-0"
                                        :class="
                                            plan.key === 'pro' ||
                                            (plans.length > 1 && index === 1)
                                                ? 'text-cyan-300'
                                                : 'text-success'
                                        "
                                    /><span>{{
                                        plan.max_rooms
                                            ? `Hasta ${plan.max_rooms} habitaciones`
                                            : 'Habitaciones sin límite'
                                    }}</span>
                                </li>
                                <li class="flex items-start gap-2.5">
                                    <Lucide
                                        icon="CheckCircle2"
                                        class="mt-0.5 h-4 w-4 shrink-0"
                                        :class="
                                            plan.key === 'pro' ||
                                            (plans.length > 1 && index === 1)
                                                ? 'text-cyan-300'
                                                : 'text-success'
                                        "
                                    /><span>{{
                                        plan.max_users
                                            ? `${plan.max_users} usuarios incluidos`
                                            : 'Usuarios sin límite'
                                    }}</span>
                                </li>
                                <li
                                    v-for="module in plan.modules.slice(0, 6)"
                                    :key="module.key"
                                    class="flex items-start gap-2.5"
                                >
                                    <Lucide
                                        icon="CheckCircle2"
                                        class="mt-0.5 h-4 w-4 shrink-0"
                                        :class="
                                            plan.key === 'pro' ||
                                            (plans.length > 1 && index === 1)
                                                ? 'text-cyan-300'
                                                : 'text-success'
                                        "
                                    /><span>{{ module.label }}</span>
                                </li>
                            </ul>
                            <button
                                class="mt-8 w-full rounded-xl px-5 py-3 font-medium transition"
                                :class="
                                    plan.key === 'pro' ||
                                    (plans.length > 1 && index === 1)
                                        ? 'bg-white text-primary hover:bg-cyan-50'
                                        : 'border border-primary/15 bg-primary/5 text-primary hover:bg-primary hover:text-white'
                                "
                                @click="choosePlan(plan)"
                            >
                                Me interesa este plan
                            </button>
                        </article>
                    </div>
                </div>
            </section>

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
                                        text: 'Entendemos primero tu operación.',
                                    },
                                    {
                                        icon: 'Presentation',
                                        title: 'Demo personalizada',
                                        text: 'Te mostramos los módulos que sí necesitas.',
                                    },
                                    {
                                        icon: 'LifeBuoy',
                                        title: 'Acompañamiento',
                                        text: 'No estarás solo durante la configuración.',
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
                                        placeholder="Nombre del hotel"
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
                        class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10"
                        ><Lucide icon="Building2" class="h-4 w-4 text-white"
                    /></span>
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
                    <button class="hover:text-white" @click="goTo('modulos')">
                        Módulos</button
                    ><button class="hover:text-white" @click="goTo('planes')">
                        Planes</button
                    ><button class="hover:text-white" @click="goTo('contacto')">
                        Contacto</button
                    ><Link :href="route('login')" class="hover:text-white"
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
    background: linear-gradient(100deg, #03045e 5%, #0891b2 70%);
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

.landing-bar {
    transform-origin: bottom;
    animation: grow-bar 800ms 500ms cubic-bezier(0.22, 1, 0.36, 1) both;
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
    background: radial-gradient(circle, rgb(8 145 178 / 0.16), transparent 68%);
}

.landing-orb--two {
    bottom: -12rem;
    left: -14rem;
    height: 30rem;
    width: 30rem;
    background: radial-gradient(circle, rgb(3 4 94 / 0.11), transparent 68%);
}

.landing-orb--three {
    top: -10rem;
    right: -8rem;
    height: 32rem;
    width: 32rem;
    background: radial-gradient(circle, rgb(8 145 178 / 0.13), transparent 68%);
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

@keyframes grow-bar {
    from {
        transform: scaleY(0);
    }
    to {
        transform: scaleY(1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .landing-dashboard,
    .landing-float-card,
    .landing-bar {
        animation: none;
    }

    .landing-reveal {
        opacity: 1;
        transform: none;
        transition: none;
    }
}
</style>
