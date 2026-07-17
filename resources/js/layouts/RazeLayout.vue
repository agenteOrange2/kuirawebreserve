<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Breadcrumb from '@/components/Base/Breadcrumb';
import { Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import ToastHost from '@/components/ToastHost.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useCompactMenu } from '@/composables/useCompactMenu';
import { useMenu } from '@/composables/useMenu';

const props = defineProps<{ title?: string }>();

const page = usePage();
const auth = computed(() => page.props.auth as any);
const tenant = computed(
    () =>
        page.props.panelTenant as {
            id: string;
            name: string;
            plan: string;
        } | null,
);

const { menu, isTenantPanel } = useMenu();
const brandName = computed(
    () =>
        tenant.value?.name ??
        ((page.props.branding as { app_name?: string } | undefined)?.app_name ||
            'KuiraReserve'),
);
const userInitials = computed(() => {
    const name = (auth.value?.user?.name ?? '').trim();
    if (!name) return '?';
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((part: string) => part.charAt(0).toUpperCase())
        .join('');
});
const homeRoute = computed(() =>
    isTenantPanel.value ? route('tenant.dashboard') : route('admin.dashboard'),
);
const { appearance, updateAppearance } = useAppearance();

const toggleDarkMode = () => {
    updateAppearance(appearance.value === 'dark' ? 'light' : 'dark');
};
const { compactMenu, setCompactMenu } = useCompactMenu();

const compactMenuOnHover = ref(false);
const activeMobileMenu = ref(false);
const showSearch = ref(false);
const searchQuery = ref('');

const toggleCompactMenu = (event: MouseEvent) => {
    event.preventDefault();
    setCompactMenu(!compactMenu.value);
};

const pathFor = (pageName?: string) => {
    if (!pageName) return null;
    try {
        return new URL(route(pageName)).pathname;
    } catch {
        return null;
    }
};

// Solo se ilumina el item cuya ruta es la coincidencia MÁS específica de la
// URL actual; sin esto, /reservas/calendario encendería también "Reservas".
const activePageName = computed(() => {
    const current = page.url.split('?')[0];
    let best: string | undefined;
    let bestLength = -1;

    for (const item of menu.value) {
        if (typeof item === 'string') continue;
        for (const entry of [item, ...(item.subMenu ?? [])]) {
            if (!entry.pageName) continue;
            const path = pathFor(entry.pageName);
            if (!path) continue;
            const matches =
                current === path ||
                current.startsWith(path.endsWith('/') ? path : `${path}/`);
            if (matches && path.length > bestLength) {
                best = entry.pageName;
                bestLength = path.length;
            }
        }
    }

    return best;
});

const isActive = (pageName?: string) =>
    pageName !== undefined && pageName === activePageName.value;

// ── Grupos colapsables del menú ──
const openGroups = ref<Record<string, boolean>>({});

const groupHasActive = (item: { subMenu?: { pageName?: string }[] }) =>
    item.subMenu?.some((sub) => isActive(sub.pageName)) ?? false;

const toggleGroup = (title: string) => {
    openGroups.value[title] = !openGroups.value[title];
};

// El grupo que contiene la página actual se abre solo (también al navegar).
watch(
    activePageName,
    (name) => {
        for (const item of menu.value) {
            if (typeof item === 'string' || !item.subMenu) continue;
            if (item.subMenu.some((sub) => sub.pageName === name)) {
                openGroups.value[item.title] = true;
            }
        }
    },
    { immediate: true },
);

const requestFullscreen = () => {
    const el = document.documentElement;
    if (el.requestFullscreen) {
        el.requestFullscreen();
    }
};
</script>

<template>
    <Head :title="props.title" />
    <div
        :class="[
            'raze',
            'before:fixed before:top-0 before:h-screen before:w-full before:bg-linear-to-b before:from-slate-100 before:to-slate-50 before:content-[\'\'] dark:before:from-darkmode-800 dark:before:to-darkmode-800',
        ]"
    >
        <!-- BEGIN: Side Menu -->
        <div
            :class="[
                'side-menu group fixed top-0 left-0 z-50 shadow-xl transition-[margin] duration-300 xl:ml-0 xl:shadow-none',
                'after:fixed after:inset-0 after:bg-black/80 after:content-[\'\'] after:xl:hidden',
                { 'side-menu--collapsed': compactMenu },
                { 'side-menu--on-hover': compactMenuOnHover },
                { 'ml-0 after:block': activeMobileMenu },
                { '-ml-[275px] after:hidden': !activeMobileMenu },
            ]"
        >
            <div
                :class="[
                    'fixed z-50 ml-[275px] h-10 w-10 items-center justify-center xl:hidden',
                    { flex: activeMobileMenu },
                    { hidden: !activeMobileMenu },
                ]"
            >
                <a
                    href="#"
                    @click.prevent="activeMobileMenu = false"
                    class="mt-5 ml-5"
                >
                    <Lucide icon="X" class="h-8 w-8 text-white" />
                </a>
            </div>
            <div
                :class="[
                    'relative z-20 flex h-screen w-[275px] flex-col overflow-hidden bg-linear-to-b from-theme-1 to-theme-2 transition-[width] duration-300 xl:rounded-[0_1.2rem_1.2rem_0/0_1.7rem_1.7rem_0] group-[.side-menu--collapsed]:xl:w-[91px] group-[.side-menu--collapsed.side-menu--on-hover]:xl:w-[275px] group-[.side-menu--collapsed.side-menu--on-hover]:xl:shadow-[6px_0_12px_-4px_#0000000f]',
                    'after:absolute after:inset-0 after:-mr-4 after:bg-texture-white after:bg-contain after:bg-fixed after:bg-[center_-20rem] after:bg-no-repeat after:content-[\'\']',
                ]"
                @mouseover.prevent="compactMenuOnHover = true"
                @mouseleave.prevent="compactMenuOnHover = false"
            >
                <!-- Logo -->
                <div
                    class="relative z-10 hidden h-[65px] w-[275px] flex-none items-center overflow-hidden px-5 duration-300 xl:flex group-[.side-menu--collapsed]:xl:w-[91px] group-[.side-menu--collapsed.side-menu--on-hover]:xl:w-[275px]"
                >
                    <Link
                        href="/"
                        class="flex items-center transition-[margin] duration-300 group-[.side-menu--collapsed]:xl:ml-2 group-[.side-menu--collapsed.side-menu--on-hover]:xl:ml-0"
                    >
                        <div
                            class="flex h-[34px] w-[34px] items-center justify-center rounded-lg bg-white/8 transition-transform ease-in-out group-[.side-menu--collapsed.side-menu--on-hover]:xl:-rotate-180"
                        >
                            <Lucide
                                icon="Building2"
                                class="h-5 w-5 text-white"
                            />
                        </div>
                        <div
                            class="ml-3.5 max-w-[160px] truncate font-medium text-white transition-opacity group-[.side-menu--collapsed]:xl:opacity-0 group-[.side-menu--collapsed.side-menu--on-hover]:xl:opacity-100"
                        >
                            {{ brandName }}
                        </div>
                    </Link>
                    <a
                        href="#"
                        @click="toggleCompactMenu"
                        class="ml-auto hidden h-[20px] w-[20px] items-center justify-center rounded-full border border-white/40 text-white transition-[opacity,transform] hover:bg-white/5 3xl:flex group-[.side-menu--collapsed]:xl:rotate-180 group-[.side-menu--collapsed]:xl:opacity-0 group-[.side-menu--collapsed.side-menu--on-hover]:xl:opacity-100"
                    >
                        <Lucide
                            icon="ArrowLeft"
                            class="h-3.5 w-3.5 stroke-[1.3]"
                        />
                    </a>
                </div>

                <!-- Menu Items -->
                <div
                    class="scrollbar-slim z-20 h-full w-full overflow-x-hidden overflow-y-auto px-5 pb-3"
                >
                    <ul class="scrollable">
                        <template v-for="(item, index) in menu" :key="index">
                            <!-- Divider -->
                            <li
                                v-if="typeof item === 'string'"
                                class="side-menu__divider"
                            >
                                {{ item }}
                            </li>
                            <!-- Grupo con submenu -->
                            <li v-else-if="item.subMenu">
                                <a
                                    href="#"
                                    :class="[
                                        'side-menu__link',
                                        {
                                            'side-menu__link--active':
                                                groupHasActive(item) &&
                                                !openGroups[item.title],
                                        },
                                        {
                                            'side-menu__link--active-dropdown':
                                                openGroups[item.title],
                                        },
                                    ]"
                                    @click.prevent="toggleGroup(item.title)"
                                >
                                    <Lucide
                                        :icon="item.icon"
                                        class="side-menu__link__icon"
                                    />
                                    <div class="side-menu__link__title">
                                        {{ item.title }}
                                    </div>
                                    <Lucide
                                        icon="ChevronDown"
                                        :class="[
                                            'side-menu__link__chevron h-4 w-4 stroke-[1.3] transition-transform',
                                            {
                                                'rotate-180':
                                                    openGroups[item.title],
                                            },
                                        ]"
                                    />
                                </a>
                                <ul v-show="openGroups[item.title]">
                                    <li
                                        v-for="(sub, subIndex) in item.subMenu"
                                        :key="subIndex"
                                    >
                                        <Link
                                            :href="
                                                sub.pageName
                                                    ? route(sub.pageName)
                                                    : '#'
                                            "
                                            :class="[
                                                'side-menu__link',
                                                {
                                                    'side-menu__link--active':
                                                        isActive(sub.pageName),
                                                },
                                            ]"
                                        >
                                            <Lucide
                                                :icon="sub.icon"
                                                class="side-menu__link__icon"
                                            />
                                            <div class="side-menu__link__title">
                                                {{ sub.title }}
                                            </div>
                                            <div
                                                v-if="sub.badge"
                                                class="side-menu__link__badge"
                                            >
                                                {{ sub.badge }}
                                            </div>
                                        </Link>
                                    </li>
                                </ul>
                            </li>
                            <!-- Menu Item -->
                            <li v-else>
                                <Link
                                    :href="
                                        item.pageName
                                            ? route(item.pageName)
                                            : '#'
                                    "
                                    :class="[
                                        'side-menu__link',
                                        {
                                            'side-menu__link--active': isActive(
                                                item.pageName,
                                            ),
                                        },
                                    ]"
                                >
                                    <Lucide
                                        :icon="item.icon"
                                        class="side-menu__link__icon"
                                    />
                                    <div class="side-menu__link__title">
                                        {{ item.title }}
                                    </div>
                                    <div
                                        v-if="item.badge"
                                        class="side-menu__link__badge"
                                    >
                                        {{ item.badge }}
                                    </div>
                                </Link>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            <!-- Top Bar -->
            <div
                :class="[
                    'fixed inset-x-0 top-0 mt-3.5 h-[65px] transition-[margin] duration-100 xl:ml-[275px] group-[.side-menu--collapsed]:xl:ml-[90px]',
                    'before:absolute before:inset-x-0 before:top-0 before:mx-5 before:-mt-[15px] before:h-[20px] before:backdrop-blur before:content-[\'\']',
                ]"
            >
                <div
                    class="box absolute inset-x-0 mx-5 h-full before:absolute before:inset-x-4 before:top-0 before:z-[-1] before:mx-auto before:mt-3 before:h-full before:rounded-lg before:border before:border-slate-200 before:bg-slate-50 before:shadow-sm before:content-[''] dark:border-darkmode-400 dark:bg-darkmode-600 dark:before:border-darkmode-500 dark:before:bg-darkmode-700"
                >
                    <div class="flex h-full w-full items-center px-5">
                        <!-- Mobile Menu Toggle + Search -->
                        <div class="flex items-center gap-1 xl:hidden">
                            <a
                                href="#"
                                @click.prevent="activeMobileMenu = true"
                                class="rounded-full p-2 hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            >
                                <Lucide
                                    icon="AlignJustify"
                                    class="h-[18px] w-[18px]"
                                />
                            </a>
                            <a
                                href="#"
                                class="rounded-full p-2 hover:bg-slate-100 dark:hover:bg-darkmode-400"
                                @click.prevent="showSearch = true"
                            >
                                <Lucide
                                    icon="Search"
                                    class="h-[18px] w-[18px]"
                                />
                            </a>
                        </div>

                        <!-- BEGIN: Breadcrumb -->
                        <Breadcrumb class="hidden flex-1 xl:block">
                            <Breadcrumb.Link :to="homeRoute">{{
                                isTenantPanel ? 'Panel' : 'Admin'
                            }}</Breadcrumb.Link>
                            <Breadcrumb.Link :to="page.url" :active="true">
                                {{ props.title || 'Dashboard' }}
                            </Breadcrumb.Link>
                        </Breadcrumb>
                        <!-- END: Breadcrumb -->

                        <!-- BEGIN: Search -->
                        <div
                            class="relative hidden flex-1 justify-center xl:flex"
                            @click.prevent="showSearch = !showSearch"
                        >
                            <div
                                class="flex w-[350px] cursor-pointer items-center rounded-[0.5rem] border bg-slate-50 px-3.5 py-2 text-slate-400 transition-colors hover:bg-slate-100 dark:border-darkmode-400 dark:bg-darkmode-400 dark:hover:bg-darkmode-300"
                            >
                                <Lucide
                                    icon="Search"
                                    class="h-[18px] w-[18px]"
                                />
                                <div class="mr-auto ml-2.5">
                                    Búsqueda rápida...
                                </div>
                                <div>⌘K</div>
                            </div>
                        </div>
                        <!-- END: Search -->

                        <!-- BEGIN: Notification & User Menu -->
                        <div class="flex flex-1 items-center">
                            <div class="ml-auto flex items-center gap-1">
                                <div
                                    v-if="isTenantPanel && tenant"
                                    class="mr-2 hidden items-center gap-1.5 rounded-full bg-primary/10 py-1 pr-2.5 pl-1.5 text-xs font-medium text-primary sm:flex"
                                >
                                    <Lucide
                                        icon="BadgeCheck"
                                        class="h-3.5 w-3.5"
                                    />
                                    <span class="capitalize">{{
                                        tenant.plan
                                    }}</span>
                                </div>
                                <a
                                    href="#"
                                    @click.prevent="toggleDarkMode"
                                    class="rounded-full p-2 hover:bg-slate-100 dark:hover:bg-darkmode-400"
                                >
                                    <Lucide
                                        :icon="
                                            appearance === 'dark'
                                                ? 'Sun'
                                                : 'Moon'
                                        "
                                        class="h-[18px] w-[18px]"
                                    />
                                </a>
                                <a
                                    href="#"
                                    @click.prevent="requestFullscreen"
                                    class="hidden rounded-full p-2 hover:bg-slate-100 sm:block dark:hover:bg-darkmode-400"
                                >
                                    <Lucide
                                        icon="Expand"
                                        class="h-[18px] w-[18px]"
                                    />
                                </a>
                            </div>
                            <Menu class="ml-4">
                                <Menu.Button
                                    class="flex h-[38px] w-[38px] items-center justify-center overflow-hidden rounded-full border-2 border-white bg-linear-to-br from-theme-1 to-theme-2 text-xs font-semibold text-white shadow-sm"
                                >
                                    {{ userInitials }}
                                </Menu.Button>
                                <Menu.Items class="mt-1 w-56">
                                    <Menu.Item>
                                        <div class="px-4 py-2">
                                            <div class="font-medium">
                                                {{ auth.user?.name }}
                                            </div>
                                            <div class="text-xs text-slate-500">
                                                {{ auth.user?.email }}
                                            </div>
                                        </div>
                                    </Menu.Item>
                                    <Menu.Divider />
                                    <Menu.Item v-if="!isTenantPanel">
                                        <Link
                                            :href="
                                                route(
                                                    'admin.settings.profile.edit',
                                                )
                                            "
                                        >
                                            <Lucide
                                                icon="User"
                                                class="mr-2 h-4 w-4"
                                            />
                                            Perfil
                                        </Link>
                                    </Menu.Item>
                                    <Menu.Item>
                                        <Link
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                            class="w-full text-left"
                                        >
                                            <Lucide
                                                icon="Power"
                                                class="mr-2 h-4 w-4"
                                            />
                                            Cerrar Sesión
                                        </Link>
                                    </Menu.Item>
                                </Menu.Items>
                            </Menu>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- END: Side Menu -->

        <!-- BEGIN: Quick Search -->
        <Transition
            enter-active-class="transition-all duration-300 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-all duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="showSearch"
                class="fixed inset-0 z-[60] flex items-start justify-center pt-[15vh]"
                @click.self="showSearch = false"
                @keydown.escape="showSearch = false"
            >
                <div
                    class="fixed inset-0 bg-black/60 backdrop-blur-sm"
                    @click="showSearch = false"
                ></div>
                <div
                    class="relative z-10 w-[90%] max-w-[600px] rounded-xl bg-white shadow-2xl dark:bg-darkmode-600"
                >
                    <div
                        class="flex items-center border-b px-5 py-4 dark:border-darkmode-400"
                    >
                        <Lucide
                            icon="Search"
                            class="mr-3 h-5 w-5 text-slate-400"
                        />
                        <input
                            ref="searchInputRef"
                            v-model="searchQuery"
                            type="text"
                            class="flex-1 border-0 bg-transparent p-0 text-base outline-none placeholder:text-slate-400 focus:ring-0 dark:text-slate-200"
                            placeholder="Buscar páginas, configuración..."
                            @keydown.escape="showSearch = false"
                        />
                        <div
                            class="ml-3 rounded border px-1.5 py-0.5 text-xs text-slate-400 dark:border-darkmode-400"
                        >
                            ESC
                        </div>
                    </div>
                    <div class="max-h-[50vh] overflow-y-auto p-3">
                        <div
                            class="px-2 py-1.5 text-xs font-medium text-slate-400 uppercase"
                        >
                            Páginas
                        </div>
                        <Link
                            :href="homeRoute"
                            class="flex cursor-pointer items-center rounded-lg px-3 py-2.5 hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showSearch = false"
                        >
                            <Lucide
                                icon="LayoutDashboard"
                                class="mr-3 h-4 w-4 text-slate-500"
                            />
                            <span class="dark:text-slate-300">Dashboard</span>
                        </Link>
                        <Link
                            v-if="isTenantPanel"
                            :href="route('tenant.plano')"
                            class="flex cursor-pointer items-center rounded-lg px-3 py-2.5 hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showSearch = false"
                        >
                            <Lucide
                                icon="Map"
                                class="mr-3 h-4 w-4 text-slate-500"
                            />
                            <span class="dark:text-slate-300">Plano</span>
                        </Link>
                        <Link
                            v-if="!isTenantPanel"
                            :href="route('admin.settings.profile.edit')"
                            class="flex cursor-pointer items-center rounded-lg px-3 py-2.5 hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showSearch = false"
                        >
                            <Lucide
                                icon="Settings"
                                class="mr-3 h-4 w-4 text-slate-500"
                            />
                            <span class="dark:text-slate-300"
                                >Configuración</span
                            >
                        </Link>
                    </div>
                </div>
            </div>
        </Transition>
        <!-- END: Quick Search -->

        <!-- BEGIN: Content -->
        <div
            :class="[
                'relative z-20 px-5 pt-[56px] pb-16 transition-[margin,width] duration-100',
                { 'xl:ml-[275px]': !compactMenu },
                { 'xl:ml-[91px]': compactMenu },
            ]"
        >
            <div class="container mt-[65px]">
                <slot />
            </div>
        </div>
        <!-- END: Content -->

        <ToastHost />
    </div>
</template>
