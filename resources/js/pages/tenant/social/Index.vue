<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface Post {
    id: number;
    network: string;
    network_label: string;
    excerpt: string;
    permalink: string | null;
    media_url: string | null;
    published_at: string | null;
    published_label: string | null;
    comments_count: number;
    pending_count: number;
    purchase_count: number;
    url: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Network {
    key: string;
    label: string;
    connected: boolean;
    account: string | null;
    note: string | null;
}

interface PermissionIssue {
    account: string;
    network: string;
    items: Array<{ tipo: string; detalle: string; accion: string }>;
}

const props = defineProps<{
    posts: {
        data: Post[];
        links: PaginationLink[];
        total: number;
    };
    filters: { meses: number; red: string };
    periods: Array<{ value: number; label: string }>;
    stats: {
        nuevos: number;
        respondidos: number;
        pendientes: number;
        conversaciones: number;
    };
    networks: Network[];
    lastSyncedAt: string | null;
    permissionIssues: PermissionIssue[];
}>();

const months = ref(props.filters.meses);
const activeNetwork = ref<string>(props.filters.red || 'todas');
const busy = ref(false);

// Imágenes que el servidor no pudo recuperar: se cambian por el placeholder
// en vez de dejar el icono de imagen rota del navegador.
const broken = reactive(new Set<number>());

// Cómo se ven las publicaciones: lista (default) o tarjetas. La elección se
// recuerda por navegador.
const view = ref<'list' | 'grid'>('list');

try {
    if (localStorage.getItem('redes-vista') === 'grid') {
        view.value = 'grid';
    }
} catch {
    // Navegación privada o storage bloqueado: se queda la lista.
}

function setView(value: 'list' | 'grid') {
    view.value = value;

    try {
        localStorage.setItem('redes-vista', value);
    } catch {
        // Sin storage, el cambio vive solo esta visita.
    }
}

const periodLabel = computed(
    () =>
        props.periods
            .find((period) => period.value === props.filters.meses)
            ?.label.toLowerCase() ?? 'periodo',
);

// Periodo y red viven en la URL: el periodo decide qué se ve Y qué se
// escanea, y con paginación el filtro de red también es del servidor.
function reload() {
    const params: Record<string, string | number> = { meses: months.value };

    if (activeNetwork.value !== 'todas') {
        params.red = activeNetwork.value;
    }

    router.get(route('tenant.social'), params, {
        preserveScroll: true,
        preserveState: true,
    });
}

function setNetwork(key: string) {
    activeNetwork.value = key;
    reload();
}

function sync() {
    busy.value = true;
    router.post(
        route('tenant.social.sync'),
        { meses: months.value },
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}

const cards = computed(() => [
    {
        label: 'Sin atender',
        value: props.stats.nuevos,
        icon: 'Inbox' as const,
        tone: 'border-pending/20 bg-pending/10 text-pending',
    },
    {
        label: 'Respondidos',
        value: props.stats.respondidos,
        icon: 'MessageSquareText' as const,
        tone: 'border-success/20 bg-success/10 text-success',
    },
    {
        label: 'Esperan a una persona',
        value: props.stats.pendientes,
        icon: 'TriangleAlert' as const,
        tone: 'border-warning/20 bg-warning/10 text-warning',
    },
    {
        label: 'Conversaciones abiertas',
        value: props.stats.conversaciones,
        icon: 'MessagesSquare' as const,
        tone: 'border-primary/20 bg-primary/10 text-primary',
    },
]);

// Bordes de la tira de resumen: 2x2 en móvil, 1x4 desde lg.
const cellBorders = [
    '',
    'border-l',
    'border-t lg:border-l lg:border-t-0',
    'border-l border-t lg:border-t-0',
];
</script>

<template>
    <RazeLayout title="Redes sociales">
        <div class="mt-2 grid grid-cols-12 gap-5">
            <!-- Encabezado: título, periodo y acciones en una sola tira -->
            <div class="col-span-12">
                <div class="box box--stacked p-4 sm:p-5">
                    <div
                        class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide icon="Share2" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h1 class="text-base font-medium">
                                    Redes sociales
                                </h1>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Elige una publicación para ver y atender sus
                                    comentarios.
                                </p>
                            </div>
                        </div>
                        <div
                            class="flex flex-col gap-2 sm:flex-row sm:items-center"
                        >
                            <div class="relative">
                                <Lucide
                                    icon="CalendarRange"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-3.5 w-3.5 stroke-[1.3] text-slate-400"
                                />
                                <FormSelect
                                    id="periodo"
                                    v-model.number="months"
                                    class="w-full pl-8 text-xs sm:w-44"
                                    aria-label="Periodo"
                                    @change="reload"
                                >
                                    <option
                                        v-for="period in periods"
                                        :key="period.value"
                                        :value="period.value"
                                    >
                                        {{ period.label }}
                                    </option>
                                </FormSelect>
                            </div>
                            <Button
                                variant="outline-secondary"
                                class="rounded-[0.5rem] bg-white text-xs"
                                :disabled="busy"
                                :title="'Escanear ' + periodLabel"
                                @click="sync"
                            >
                                <Lucide
                                    icon="RefreshCw"
                                    class="mr-1.5 h-3.5 w-3.5"
                                    :class="busy ? 'animate-spin' : ''"
                                />
                                {{ busy ? 'Escaneando...' : 'Escanear' }}
                            </Button>
                            <Button
                                as="a"
                                :href="route('tenant.social-settings')"
                                variant="outline-primary"
                                class="rounded-[0.5rem] bg-white text-xs"
                            >
                                <Lucide
                                    icon="Settings"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Ajustes
                            </Button>
                        </div>
                    </div>
                    <p
                        class="mt-3 border-t border-dashed border-slate-200/70 pt-3 text-xs text-slate-500 dark:border-darkmode-400"
                    >
                        Los comentarios nuevos llegan solos al instante.
                        Escanear revisa las publicaciones {{ periodLabel }} y
                        guarda las que falten, útil para lo publicado antes de
                        conectar el módulo.
                        <span v-if="lastSyncedAt">
                            Último escaneo: {{ lastSyncedAt }}.
                        </span>
                    </p>
                </div>
            </div>

            <!-- Permisos faltantes: la causa número uno de "no aparece nada" -->
            <div
                v-for="issue in permissionIssues"
                :key="issue.account"
                class="col-span-12"
            >
                <div
                    class="box box--stacked flex flex-col gap-3 border-l-4 border-l-warning p-4 sm:flex-row sm:items-start sm:p-5"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-warning/20 bg-warning/10 text-warning"
                    >
                        <Lucide icon="TriangleAlert" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-sm font-medium">
                            {{ issue.network }} ({{ issue.account }}): faltan
                            pasos para recibir los comentarios
                        </h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Los mensajes privados funcionan, pero mientras esto
                            no se resuelva la pantalla se queda vacía aunque
                            publiques.
                        </p>
                        <ol class="mt-3 flex flex-col gap-2.5">
                            <li
                                v-for="(item, index) in issue.items"
                                :key="item.tipo"
                                class="flex gap-2.5"
                            >
                                <span
                                    class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-warning/20 bg-warning/10 text-xs font-medium text-warning"
                                >
                                    {{ index + 1 }}
                                </span>
                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span class="text-xs font-medium">
                                            {{ item.tipo }}
                                        </span>
                                        <span
                                            class="rounded-full border border-slate-200/70 px-2 py-0.5 font-mono text-xs text-slate-500 dark:border-darkmode-400"
                                        >
                                            {{ item.detalle }}
                                        </span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        {{ item.accion }}
                                    </p>
                                </div>
                            </li>
                        </ol>
                        <Button
                            as="a"
                            :href="route('tenant.agent')"
                            variant="outline-warning"
                            class="mt-3 rounded-[0.5rem] bg-white text-xs"
                        >
                            <Lucide icon="Plug" class="mr-1.5 h-3.5 w-3.5" />
                            Ir a la conexión
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Resumen del periodo: una sola tira con divisores -->
            <div class="col-span-12">
                <div class="box box--stacked grid grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(card, index) in cards"
                        :key="card.label"
                        class="flex items-center gap-3 border-slate-200/60 p-4 dark:border-darkmode-400"
                        :class="cellBorders[index]"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                            :class="card.tone"
                        >
                            <Lucide :icon="card.icon" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-base leading-tight font-medium">
                                {{ card.value }}
                            </div>
                            <div class="truncate text-xs text-slate-500">
                                {{ card.label }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtro por red -->
            <div class="col-span-12">
                <div class="box box--stacked p-4 sm:px-5">
                    <div
                        class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between"
                    >
                        <div class="flex items-center gap-2">
                            <h2 class="text-sm font-medium">Publicaciones</h2>
                            <span
                                class="rounded-full border border-slate-200/70 px-2 py-0.5 text-xs text-slate-500 dark:border-darkmode-400"
                            >
                                {{ posts.total }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button
                                type="button"
                                class="flex h-8 items-center rounded-full border px-3 text-xs transition"
                                :class="
                                    activeNetwork === 'todas'
                                        ? 'border-primary/20 bg-primary/10 font-medium text-primary'
                                        : 'border-slate-200/70 text-slate-500 hover:bg-slate-100/70 dark:border-darkmode-400 dark:hover:bg-darkmode-400'
                                "
                                @click="setNetwork('todas')"
                            >
                                Todas
                            </button>
                            <button
                                v-for="network in networks"
                                :key="network.key"
                                type="button"
                                class="flex h-8 items-center gap-1.5 rounded-full border px-3 text-xs transition"
                                :class="
                                    activeNetwork === network.key
                                        ? 'border-primary/20 bg-primary/10 font-medium text-primary'
                                        : 'border-slate-200/70 text-slate-500 hover:bg-slate-100/70 dark:border-darkmode-400 dark:hover:bg-darkmode-400'
                                "
                                :title="
                                    network.note ??
                                    (network.account
                                        ? 'Cuenta conectada: ' + network.account
                                        : undefined)
                                "
                                @click="setNetwork(network.key)"
                            >
                                <span
                                    class="h-1.5 w-1.5 rounded-full"
                                    :class="
                                        network.connected
                                            ? 'bg-success'
                                            : 'bg-slate-300 dark:bg-darkmode-300'
                                    "
                                ></span>
                                {{ network.label }}
                                <span
                                    v-if="network.connected && network.account"
                                    class="hidden max-w-28 truncate text-slate-400 sm:inline"
                                >
                                    {{ network.account }}
                                </span>
                                <span
                                    v-else-if="!network.connected"
                                    class="hidden text-slate-400 sm:inline"
                                >
                                    sin conectar
                                </span>
                            </button>
                            <div
                                class="ml-1 flex items-center rounded-full border border-slate-200/70 p-0.5 dark:border-darkmode-400"
                            >
                                <button
                                    type="button"
                                    class="flex h-7 w-8 items-center justify-center rounded-full transition"
                                    :class="
                                        view === 'list'
                                            ? 'bg-primary/10 text-primary'
                                            : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'
                                    "
                                    title="Ver como lista"
                                    @click="setView('list')"
                                >
                                    <Lucide icon="List" class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    class="flex h-7 w-8 items-center justify-center rounded-full transition"
                                    :class="
                                        view === 'grid'
                                            ? 'bg-primary/10 text-primary'
                                            : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'
                                    "
                                    title="Ver como tarjetas"
                                    @click="setView('grid')"
                                >
                                    <Lucide icon="LayoutGrid" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-slate-400">
                        Solo se leen las publicaciones de las cuentas conectadas
                        aquí. Si no reconoces el nombre, es otra página:
                        cámbiala desde el asistente.
                    </p>
                </div>
            </div>

            <!-- Publicaciones -->
            <div v-if="posts.data.length === 0" class="col-span-12">
                <div
                    class="box box--stacked flex flex-col items-center gap-2 p-10 text-center"
                >
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-slate-200/70 bg-slate-100/70 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="Image" class="h-4 w-4" />
                    </div>
                    <p class="text-sm text-slate-500">
                        No hay publicaciones {{ periodLabel }}.
                    </p>
                    <p class="max-w-md text-xs text-slate-500">
                        Amplía el periodo o usa Escanear para traerlas de la red
                        social.
                    </p>
                </div>
            </div>

            <!-- Vista de lista (default): densa, una publicación por renglón -->
            <div v-else-if="view === 'list'" class="col-span-12">
                <div class="box box--stacked">
                    <div
                        v-for="post in posts.data"
                        :key="post.id"
                        class="group flex items-center gap-1.5 border-b border-slate-200/60 px-4 py-3 transition first:rounded-t-[0.6rem] last:rounded-b-[0.6rem] last:border-b-0 hover:bg-slate-50 sm:px-5 dark:border-darkmode-400 dark:hover:bg-darkmode-400/30"
                    >
                        <a
                            :href="post.url"
                            class="flex min-w-0 flex-1 items-center gap-3"
                        >
                            <img
                                v-if="post.media_url && !broken.has(post.id)"
                                :src="post.media_url"
                                alt=""
                                loading="lazy"
                                decoding="async"
                                class="h-12 w-12 shrink-0 rounded-[0.5rem] object-cover"
                                @error="broken.add(post.id)"
                            />
                            <div
                                v-else
                                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-[0.5rem] bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="Image" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-500"
                                >
                                    <span
                                        class="font-medium text-slate-600 dark:text-slate-300"
                                    >
                                        {{ post.network_label }}
                                    </span>
                                    <span v-if="post.published_label">
                                        {{ post.published_label }}
                                    </span>
                                </div>
                                <p
                                    class="mt-0.5 truncate text-sm text-slate-700 transition group-hover:text-primary dark:text-slate-200"
                                >
                                    {{ post.excerpt }}
                                </p>
                                <div
                                    class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs"
                                >
                                    <span
                                        class="flex items-center gap-1 text-slate-500"
                                        title="Comentarios"
                                    >
                                        <Lucide
                                            icon="MessageSquareText"
                                            class="h-3 w-3"
                                        />
                                        {{ post.comments_count }}
                                    </span>
                                    <span
                                        v-if="post.purchase_count > 0"
                                        class="flex items-center gap-1 text-success"
                                    >
                                        <Lucide
                                            icon="Sparkles"
                                            class="h-3 w-3"
                                        />
                                        {{ post.purchase_count }} con interés
                                    </span>
                                    <span
                                        v-if="post.pending_count > 0"
                                        class="flex items-center gap-1 text-warning"
                                    >
                                        <Lucide
                                            icon="CircleAlert"
                                            class="h-3 w-3"
                                        />
                                        {{ post.pending_count }} por atender
                                    </span>
                                </div>
                            </div>
                            <Lucide
                                icon="ChevronRight"
                                class="ml-1 hidden h-4 w-4 shrink-0 text-slate-300 transition group-hover:text-primary sm:block"
                            />
                        </a>
                        <a
                            v-if="post.permalink"
                            :href="post.permalink"
                            target="_blank"
                            rel="noopener"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-primary dark:hover:bg-darkmode-400"
                            title="Abrir en la red social"
                        >
                            <Lucide icon="ExternalLink" class="h-3.5 w-3.5" />
                        </a>
                    </div>
                </div>
            </div>

            <!-- Vista de tarjetas -->
            <div
                v-for="post in view === 'grid' ? posts.data : []"
                :key="post.id"
                class="col-span-12 sm:col-span-6 xl:col-span-4"
            >
                <div class="box box--stacked relative flex h-full flex-col">
                    <a :href="post.url" class="group flex flex-1 flex-col">
                        <div
                            class="relative h-40 w-full overflow-hidden rounded-t-[0.6rem] bg-slate-100 dark:bg-darkmode-400"
                        >
                            <img
                                v-if="post.media_url && !broken.has(post.id)"
                                :src="post.media_url"
                                alt=""
                                loading="lazy"
                                decoding="async"
                                class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                                @error="broken.add(post.id)"
                            />
                            <div
                                v-else
                                class="flex h-full w-full items-center justify-center text-slate-300 dark:text-slate-500"
                            >
                                <Lucide icon="Image" class="h-6 w-6" />
                            </div>
                            <span
                                class="absolute top-2.5 left-2.5 rounded-full bg-white/90 px-2 py-0.5 text-xs font-medium text-slate-600 shadow-sm dark:bg-darkmode-600/90 dark:text-slate-300"
                            >
                                {{ post.network_label }}
                            </span>
                        </div>
                        <div class="flex flex-1 flex-col p-4">
                            <div
                                v-if="post.published_label"
                                class="text-xs text-slate-500"
                            >
                                {{ post.published_label }}
                            </div>
                            <p
                                class="mt-1 line-clamp-2 text-sm leading-relaxed text-slate-700 transition group-hover:text-primary dark:text-slate-200"
                            >
                                {{ post.excerpt }}
                            </p>
                            <div
                                class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 pt-3 text-xs"
                            >
                                <span
                                    class="flex items-center gap-1 text-slate-500"
                                    title="Comentarios"
                                >
                                    <Lucide
                                        icon="MessageSquareText"
                                        class="h-3 w-3"
                                    />
                                    {{ post.comments_count }}
                                </span>
                                <span
                                    v-if="post.purchase_count > 0"
                                    class="flex items-center gap-1 text-success"
                                >
                                    <Lucide icon="Sparkles" class="h-3 w-3" />
                                    {{ post.purchase_count }} con interés
                                </span>
                                <span
                                    v-if="post.pending_count > 0"
                                    class="flex items-center gap-1 text-warning"
                                >
                                    <Lucide
                                        icon="CircleAlert"
                                        class="h-3 w-3"
                                    />
                                    {{ post.pending_count }} por atender
                                </span>
                            </div>
                        </div>
                    </a>
                    <a
                        v-if="post.permalink"
                        :href="post.permalink"
                        target="_blank"
                        rel="noopener"
                        class="absolute top-2.5 right-2.5 z-10 flex h-7 w-7 items-center justify-center rounded-full bg-white/90 text-slate-500 shadow-sm transition hover:text-primary dark:bg-darkmode-600/90 dark:text-slate-300"
                        title="Abrir en la red social"
                    >
                        <Lucide icon="ExternalLink" class="h-3.5 w-3.5" />
                    </a>
                </div>
            </div>

            <!-- Paginación -->
            <div
                v-if="posts.links.length > 3"
                class="col-span-12 flex flex-wrap justify-center gap-1"
            >
                <template v-for="(link, i) in posts.links" :key="i">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-state
                        class="rounded-md px-3 py-1.5 text-xs"
                        :class="
                            link.active
                                ? 'bg-primary font-medium text-white'
                                : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400'
                        "
                    >
                        <span v-html="link.label" />
                    </Link>
                    <span
                        v-else
                        class="px-3 py-1.5 text-xs text-slate-400"
                        v-html="link.label"
                    />
                </template>
            </div>
        </div>
    </RazeLayout>
</template>
