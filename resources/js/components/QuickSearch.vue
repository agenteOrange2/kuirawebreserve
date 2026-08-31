<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import { useMenu } from '@/composables/useMenu';

/**
 * Búsqueda rápida del panel (⌘K). Antes era una lista fija de dos enlaces y
 * lo que se escribía no hacía nada.
 *
 * Las PÁGINAS salen del menú real —que ya viene filtrado por módulo, permiso
 * y modo de operación—, así que el buscador nunca ofrece una pantalla a la
 * que el usuario no tiene acceso, y no hay una segunda lista que mantener.
 * Los DATOS (reservas, huéspedes, habitaciones; hoteles y personas en el
 * admin) los trae el servidor, que también revisa permisos.
 */
const open = defineModel<boolean>({ required: true });

const { menu, isTenantPanel } = useMenu();

const query = ref('');
const inputRef = ref<HTMLInputElement | null>(null);
const highlighted = ref(0);
const loading = ref(false);
const remoteGroups = ref<ResultGroup[]>([]);

interface ResultItem {
    title: string;
    subtitle?: string | null;
    url: string;
    badge?: string | null;
    icon?: Icon;
}

interface ResultGroup {
    label: string;
    icon?: Icon;
    items: ResultItem[];
}

// Sin acentos y en minúsculas: "habitacion" encuentra "Habitaciones".
const normalize = (value: string) =>
    value
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '');

const pathFor = (pageName?: string) => {
    if (!pageName) return null;
    try {
        return new URL(route(pageName)).pathname;
    } catch {
        return null;
    }
};

// Menú (con secciones y submenús) aplanado a una lista buscable. La sección
// viaja como subtítulo para distinguir "Ajustes" de Hotel del de Reservas.
const pages = computed<ResultItem[]>(() => {
    const flat: ResultItem[] = [];
    let section = '';

    for (const entry of menu.value) {
        if (typeof entry === 'string') {
            section = entry;
            continue;
        }
        const push = (item: typeof entry, parent?: string) => {
            const url = pathFor(item.pageName);
            if (!url) return;
            flat.push({
                title: item.title,
                subtitle: [parent, section].filter(Boolean).join(' · ') || null,
                url,
                icon: item.icon,
            });
        };

        if (entry.subMenu?.length) {
            push(entry);
            entry.subMenu.forEach((sub) => push(sub, entry.title));
        } else {
            push(entry);
        }
    }

    return flat;
});

const matchedPages = computed(() => {
    const term = normalize(query.value.trim());
    if (!term) return pages.value.slice(0, 6);

    return pages.value
        .filter((item) =>
            normalize(`${item.title} ${item.subtitle ?? ''}`).includes(term),
        )
        .slice(0, 6);
});

const groups = computed<ResultGroup[]>(() => {
    const result: ResultGroup[] = [];
    if (matchedPages.value.length) {
        result.push({ label: 'Páginas', items: matchedPages.value });
    }
    return [...result, ...remoteGroups.value];
});

const flatResults = computed(() => groups.value.flatMap((g) => g.items));

let timer: ReturnType<typeof setTimeout> | undefined;

watch(query, (value) => {
    highlighted.value = 0;
    clearTimeout(timer);

    const term = value.trim();
    if (term.length < 2) {
        remoteGroups.value = [];
        loading.value = false;
        return;
    }

    loading.value = true;
    // Se espera a que deje de teclear: una consulta por letra sería una
    // consulta por letra contra la base de la operación.
    timer = setTimeout(async () => {
        try {
            const url = isTenantPanel.value
                ? '/api/quick-search'
                : '/admin/api/quick-search';
            const { data } = await axios.get<{ groups: ResultGroup[] }>(url, {
                params: { q: term },
            });
            // Puede haber llegado tarde: si ya cambió lo tecleado, se ignora.
            if (query.value.trim() === term) {
                remoteGroups.value = data.groups ?? [];
            }
        } catch {
            remoteGroups.value = [];
        } finally {
            loading.value = false;
        }
    }, 250);
});

watch(open, async (value) => {
    if (!value) {
        query.value = '';
        remoteGroups.value = [];
        return;
    }
    highlighted.value = 0;
    await nextTick();
    inputRef.value?.focus();
});

const move = (delta: number) => {
    const total = flatResults.value.length;
    if (!total) return;
    highlighted.value = (highlighted.value + delta + total) % total;
};

const go = (item?: ResultItem) => {
    const target = item ?? flatResults.value[highlighted.value];
    if (!target) return;
    open.value = false;
    router.visit(target.url);
};

const indexOf = (group: ResultGroup, item: ResultItem) =>
    flatResults.value.indexOf(item);

const onKeydown = (event: KeyboardEvent) => {
    // ⌘K / Ctrl+K desde cualquier pantalla; el chip del header ya lo anuncia.
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        open.value = !open.value;
        return;
    }
    if (!open.value) return;
    if (event.key === 'Escape') open.value = false;
};

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
    clearTimeout(timer);
});
</script>

<template>
    <Transition
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-all duration-200 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="open"
            class="fixed inset-0 z-[60] flex items-start justify-center pt-[12vh]"
        >
            <div
                class="fixed inset-0 bg-black/60 backdrop-blur-sm"
                @click="open = false"
            ></div>
            <div
                class="relative z-10 flex max-h-[70vh] w-[92%] max-w-[620px] flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-darkmode-600"
            >
                <div
                    class="flex items-center border-b px-5 py-4 dark:border-darkmode-400"
                >
                    <Lucide
                        icon="Search"
                        class="mr-3 h-5 w-5 shrink-0 text-slate-400"
                    />
                    <input
                        ref="inputRef"
                        v-model="query"
                        type="text"
                        class="min-w-0 flex-1 border-0 bg-transparent p-0 text-base outline-none placeholder:text-slate-400 focus:ring-0 dark:text-slate-200"
                        :placeholder="
                            isTenantPanel
                                ? 'Buscar una reserva, un huésped, una habitación o una página...'
                                : 'Buscar un hotel, una persona o una página...'
                        "
                        @keydown.down.prevent="move(1)"
                        @keydown.up.prevent="move(-1)"
                        @keydown.enter.prevent="go()"
                        @keydown.escape="open = false"
                    />
                    <Lucide
                        v-if="loading"
                        icon="LoaderCircle"
                        class="ml-3 h-4 w-4 shrink-0 animate-spin text-slate-400"
                    />
                    <div
                        class="ml-3 shrink-0 rounded border px-1.5 py-0.5 text-xs text-slate-400 dark:border-darkmode-400"
                    >
                        ESC
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto p-3">
                    <template v-if="flatResults.length">
                        <div v-for="group in groups" :key="group.label">
                            <div
                                class="px-2 py-1.5 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                {{ group.label }}
                            </div>
                            <Link
                                v-for="item in group.items"
                                :key="group.label + item.url + item.title"
                                :href="item.url"
                                :class="[
                                    'flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2.5',
                                    indexOf(group, item) === highlighted
                                        ? 'bg-primary/10'
                                        : 'hover:bg-slate-100 dark:hover:bg-darkmode-400',
                                ]"
                                @click="open = false"
                                @mouseenter="highlighted = indexOf(group, item)"
                            >
                                <Lucide
                                    :icon="item.icon ?? group.icon ?? 'File'"
                                    class="h-4 w-4 shrink-0 text-slate-500"
                                />
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="truncate text-sm dark:text-slate-300"
                                    >
                                        {{ item.title }}
                                    </div>
                                    <div
                                        v-if="item.subtitle"
                                        class="truncate text-xs text-slate-400"
                                    >
                                        {{ item.subtitle }}
                                    </div>
                                </div>
                                <span
                                    v-if="item.badge"
                                    class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400 dark:text-slate-300"
                                >
                                    {{ item.badge }}
                                </span>
                            </Link>
                        </div>
                    </template>
                    <div
                        v-else
                        class="flex flex-col items-center gap-2 px-4 py-10 text-center"
                    >
                        <Lucide
                            icon="SearchX"
                            class="h-6 w-6 text-slate-300"
                        />
                        <div class="text-sm text-slate-500">
                            {{
                                query.trim().length < 2
                                    ? 'Escribe al menos dos letras para buscar.'
                                    : `Sin resultados para "${query.trim()}".`
                            }}
                        </div>
                    </div>
                </div>

                <div
                    class="flex items-center gap-4 border-t px-5 py-2.5 text-[11px] text-slate-400 dark:border-darkmode-400"
                >
                    <span class="flex items-center gap-1">
                        <Lucide icon="ArrowUp" class="h-3 w-3" />
                        <Lucide icon="ArrowDown" class="h-3 w-3" />
                        para moverte
                    </span>
                    <span class="flex items-center gap-1">
                        <Lucide icon="CornerDownLeft" class="h-3 w-3" />
                        para abrir
                    </span>
                </div>
            </div>
        </div>
    </Transition>
</template>
