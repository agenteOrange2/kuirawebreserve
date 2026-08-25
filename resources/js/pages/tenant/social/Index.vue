<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
    answered_count: number;
    url: string;
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
    posts: Post[];
    filters: { meses: number };
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
const activeNetwork = ref<string>('todas');
const busy = ref(false);

const visiblePosts = computed(() =>
    activeNetwork.value === 'todas'
        ? props.posts
        : props.posts.filter((post) => post.network === activeNetwork.value),
);

const periodLabel = computed(
    () =>
        props.periods
            .find((period) => period.value === props.filters.meses)
            ?.label.toLowerCase() ?? 'periodo',
);

// El filtro recarga la página: el periodo decide qué se ve Y qué se escanea.
function applyPeriod() {
    router.get(
        route('tenant.social'),
        { meses: months.value },
        { preserveScroll: true, preserveState: true },
    );
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
</script>

<template>
    <RazeLayout title="Redes sociales">
        <div class="mt-2 grid grid-cols-12 gap-5">
            <!-- Encabezado -->
            <div class="col-span-12">
                <div
                    class="box box--stacked flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                        >
                            <Lucide
                                icon="Share2"
                                class="h-5 w-5 sm:h-7 sm:w-7"
                            />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-lg font-medium sm:text-xl">
                                Redes sociales
                            </h1>
                            <p class="mt-1 text-sm text-slate-500">
                                Elige una publicación para ver y atender sus
                                comentarios.
                            </p>
                        </div>
                    </div>
                    <Button
                        as="a"
                        :href="route('tenant.social-settings')"
                        variant="outline-primary"
                        class="min-h-11 w-full rounded-[0.5rem] bg-white md:w-auto"
                    >
                        <Lucide icon="Settings" class="mr-2 h-4 w-4" />
                        Ajustes
                    </Button>
                </div>
            </div>

            <!-- Permisos faltantes: la causa número uno de "no aparece nada" -->
            <div
                v-for="issue in permissionIssues"
                :key="issue.account"
                class="col-span-12"
            >
                <div
                    class="box box--stacked flex flex-col gap-3 border-l-4 border-l-warning p-5 sm:flex-row sm:items-start"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-warning/20 bg-warning/10 text-warning"
                    >
                        <Lucide icon="TriangleAlert" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-sm font-medium">
                            {{ issue.network }} ({{ issue.account }}): faltan
                            pasos para recibir los comentarios
                        </h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Los mensajes privados funcionan, pero mientras
                            esto no se resuelva la pantalla se queda vacía
                            aunque publiques.
                        </p>
                        <ol class="mt-3 flex flex-col gap-3">
                            <li
                                v-for="(item, index) in issue.items"
                                :key="item.tipo"
                                class="flex gap-3"
                            >
                                <span
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-warning/20 bg-warning/10 text-xs font-medium text-warning"
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
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ item.accion }}
                                    </p>
                                </div>
                            </li>
                        </ol>
                        <Button
                            as="a"
                            :href="route('tenant.agent')"
                            variant="outline-warning"
                            class="mt-4 min-h-10 rounded-[0.5rem] bg-white text-xs"
                        >
                            <Lucide icon="Plug" class="mr-1.5 h-3.5 w-3.5" />
                            Ir a la conexión
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Periodo y escaneo -->
            <div class="col-span-12">
                <div
                    class="box box--stacked flex flex-col gap-4 p-4 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div>
                            <label
                                class="mb-1 block text-xs text-slate-500"
                                for="periodo"
                            >
                                Periodo
                            </label>
                            <div class="relative">
                                <Lucide
                                    icon="CalendarRange"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                                />
                                <FormSelect
                                    id="periodo"
                                    v-model.number="months"
                                    class="w-full pl-9 sm:w-56"
                                    @change="applyPeriod"
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
                        </div>
                        <Button
                            variant="outline-secondary"
                            class="min-h-11 w-full rounded-[0.5rem] bg-white sm:w-auto"
                            :disabled="busy"
                            @click="sync"
                        >
                            <Lucide icon="RefreshCw" class="mr-2 h-4 w-4" />
                            {{
                                busy
                                    ? 'Escaneando...'
                                    : 'Escanear ' + periodLabel
                            }}
                        </Button>
                    </div>
                    <p class="max-w-xl text-xs text-slate-500">
                        Los comentarios nuevos llegan solos al instante. El
                        escaneo sirve para traer lo publicado antes de conectar
                        el módulo: revisa las publicaciones
                        {{ periodLabel }} y guarda las que falten.
                        <span v-if="lastSyncedAt" class="block">
                            Último escaneo: {{ lastSyncedAt }}.
                        </span>
                    </p>
                </div>
            </div>

            <!-- Resumen del periodo -->
            <div
                v-for="card in cards"
                :key="card.label"
                class="col-span-12 sm:col-span-6 xl:col-span-3"
            >
                <div
                    class="box box--stacked flex h-full items-center gap-4 p-5"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border"
                        :class="card.tone"
                    >
                        <Lucide :icon="card.icon" class="h-5 w-5" />
                    </div>
                    <div>
                        <div class="text-xl font-medium">{{ card.value }}</div>
                        <div class="mt-0.5 text-xs text-slate-500">
                            {{ card.label }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Redes -->
            <div class="col-span-12">
                <div class="box box--stacked flex flex-wrap gap-2 p-3">
                    <button
                        type="button"
                        class="min-h-11 rounded-[0.5rem] border px-4 text-sm transition"
                        :class="
                            activeNetwork === 'todas'
                                ? 'border-primary/20 bg-primary/10 text-primary'
                                : 'border-slate-200/70 text-slate-500 hover:bg-slate-100/70 dark:border-darkmode-400'
                        "
                        @click="activeNetwork = 'todas'"
                    >
                        Todas
                    </button>
                    <button
                        v-for="network in networks"
                        :key="network.key"
                        type="button"
                        class="flex min-h-11 items-center gap-2 rounded-[0.5rem] border px-4 text-sm transition"
                        :class="
                            activeNetwork === network.key
                                ? 'border-primary/20 bg-primary/10 text-primary'
                                : 'border-slate-200/70 text-slate-500 hover:bg-slate-100/70 dark:border-darkmode-400'
                        "
                        :title="network.note ?? undefined"
                        @click="activeNetwork = network.key"
                    >
                        {{ network.label }}
                        <span
                            v-if="network.connected && network.account"
                            class="max-w-40 truncate rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                            :title="'Cuenta conectada: ' + network.account"
                        >
                            {{ network.account }}
                        </span>
                        <span
                            v-else-if="!network.connected"
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                        >
                            sin conectar
                        </span>
                    </button>
                </div>
                <p class="mt-2 px-1 text-xs text-slate-500">
                    Solo se leen las publicaciones de las cuentas conectadas
                    aquí. Si no reconoces el nombre, es otra página: cámbiala
                    desde el asistente.
                </p>
            </div>

            <!-- Publicaciones -->
            <div
                v-if="visiblePosts.length === 0"
                class="col-span-12"
            >
                <div
                    class="box box--stacked flex flex-col items-center gap-3 p-10 text-center"
                >
                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-full border border-slate-200/70 bg-slate-100/70 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="Image" class="h-6 w-6" />
                    </div>
                    <p class="text-sm text-slate-500">
                        No hay publicaciones {{ periodLabel }}.
                    </p>
                    <p class="max-w-md text-xs text-slate-500">
                        Amplía el periodo o usa Escanear para traerlas de la
                        red social.
                    </p>
                </div>
            </div>

            <div
                v-for="post in visiblePosts"
                :key="post.id"
                class="col-span-12 sm:col-span-6 xl:col-span-4"
            >
                <div class="box box--stacked flex h-full flex-col">
                    <a
                        :href="post.url"
                        class="flex flex-1 flex-col gap-3 p-4"
                    >
                        <div class="flex items-start gap-3">
                            <img
                                v-if="post.media_url"
                                :src="post.media_url"
                                alt=""
                                class="h-16 w-16 shrink-0 rounded-[0.5rem] object-cover"
                            />
                            <div
                                v-else
                                class="flex h-16 w-16 shrink-0 items-center justify-center rounded-[0.5rem] bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="Image" class="h-6 w-6" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex items-center gap-2 text-xs text-slate-500"
                                >
                                    <span
                                        class="rounded-full border border-slate-200/70 px-2 py-0.5 dark:border-darkmode-400"
                                    >
                                        {{ post.network_label }}
                                    </span>
                                    <span v-if="post.published_label">
                                        {{ post.published_label }}
                                    </span>
                                </div>
                                <p class="mt-1.5 text-sm leading-relaxed">
                                    {{ post.excerpt }}
                                </p>
                            </div>
                        </div>

                        <div
                            class="mt-auto flex flex-wrap items-center gap-1.5 text-xs"
                        >
                            <span
                                class="rounded-full border border-slate-200/70 px-2 py-0.5 text-slate-500 dark:border-darkmode-400"
                            >
                                {{ post.comments_count }} comentario(s)
                            </span>
                            <span
                                v-if="post.purchase_count > 0"
                                class="rounded-full border border-success/20 bg-success/10 px-2 py-0.5 text-success"
                            >
                                {{ post.purchase_count }} con interés
                            </span>
                            <span
                                v-if="post.pending_count > 0"
                                class="rounded-full border border-warning/20 bg-warning/10 px-2 py-0.5 text-warning"
                            >
                                {{ post.pending_count }} por atender
                            </span>
                        </div>
                    </a>

                    <div
                        class="flex items-center justify-between border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                    >
                        <a
                            :href="post.url"
                            class="flex items-center gap-1.5 text-xs font-medium text-primary"
                        >
                            Ver comentarios
                            <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                        </a>
                        <a
                            v-if="post.permalink"
                            :href="post.permalink"
                            target="_blank"
                            rel="noopener"
                            class="flex items-center gap-1.5 text-xs text-slate-500"
                            title="Abrir en la red social"
                        >
                            <Lucide icon="ExternalLink" class="h-3.5 w-3.5" />
                            Abrir
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
