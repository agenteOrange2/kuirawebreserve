<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';

/**
 * La tarjeta que explica un error (404, 500, 403…). Vive aparte de la
 * página porque se usa con dos envoltorios distintos: dentro del panel,
 * para que el personal conserve el menú y siga navegando, y suelta sobre
 * el degradado de la marca cuando quien lo ve es un huésped sin sesión.
 *
 * Todo el texto llega desde el servidor (config/error-pages.php): aquí no
 * se redacta nada, para que la versión Blade de respaldo no se desvíe.
 */
const props = defineProps<{
    status: number;
    icon: string;
    tone: string;
    badge: string;
    title: string;
    body: string;
    hints: string[];
    folio: string | null;
    home: { url: string; label: string };
    support: { email: string | null; whatsapp: string | null };
    /** Suelta sobre el degradado (sin menú) en vez de dentro del panel. */
    standalone?: boolean;
}>();

// Tailwind no ve las clases armadas al vuelo, así que el tono se resuelve
// contra un mapa literal y no concatenando `border-${tone}/10`.
const toneClasses: Record<string, string> = {
    primary: 'border-primary/10 bg-primary/10 text-primary',
    info: 'border-info/10 bg-info/10 text-info',
    success: 'border-success/10 bg-success/10 text-success',
    warning: 'border-warning/10 bg-warning/10 text-warning',
    pending: 'border-pending/10 bg-pending/10 text-pending',
    danger: 'border-danger/10 bg-danger/10 text-danger',
    dark: 'border-dark/10 bg-dark/10 text-dark dark:text-slate-300',
};
const toneText: Record<string, string> = {
    primary: 'text-primary',
    info: 'text-info',
    success: 'text-success',
    warning: 'text-warning',
    pending: 'text-pending',
    danger: 'text-danger',
    dark: 'text-slate-500',
};

const circleClass = computed(
    () => toneClasses[props.tone] ?? toneClasses.dark!,
);
const accentClass = computed(() => toneText[props.tone] ?? toneText.dark!);
const iconName = computed(() => props.icon as Icon);

const whatsappUrl = computed(() =>
    props.support.whatsapp
        ? `https://wa.me/${props.support.whatsapp}?text=${encodeURIComponent(
              props.folio
                  ? `Hola, me apareció un error en el sistema. El folio es ${props.folio}.`
                  : 'Hola, me apareció un error en el sistema.',
          )}`
        : null,
);
const hasSupport = computed(
    () => Boolean(whatsappUrl.value) || Boolean(props.support.email),
);

function reload() {
    window.location.reload();
}

function goBack() {
    window.history.back();
}

const canGoBack = computed(
    () => typeof window !== 'undefined' && window.history.length > 1,
);
</script>

<template>
    <div class="box box--stacked overflow-hidden">
        <!-- Franja principal: qué pasó -->
        <div class="p-6 text-center sm:p-9">
            <div
                class="mx-auto flex h-14 w-14 items-center justify-center rounded-full border"
                :class="circleClass"
            >
                <Lucide :icon="iconName" class="h-6 w-6" />
            </div>

            <div
                class="mt-4 inline-flex items-center gap-2 rounded-full border border-slate-200/70 bg-slate-50 px-3 py-1 text-[11px] font-medium tracking-wide text-slate-500 uppercase dark:border-darkmode-400 dark:bg-darkmode-700"
            >
                <span :class="accentClass">{{ status }}</span>
                {{ badge }}
            </div>

            <h1
                class="mt-3.5 text-lg leading-snug font-medium text-slate-700 dark:text-slate-200"
            >
                {{ title }}
            </h1>

            <p
                class="mx-auto mt-2 max-w-md text-xs leading-relaxed text-slate-500"
            >
                {{ body }}
            </p>

            <!-- Acciones: volver primero, como en el resto del panel -->
            <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
                <button
                    v-if="canGoBack"
                    type="button"
                    class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    @click="goBack"
                >
                    <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                    Volver
                </button>

                <Link
                    v-if="!standalone"
                    :href="home.url"
                    class="inline-flex h-9 items-center gap-1.5 rounded-[0.5rem] bg-primary px-3.5 text-xs font-medium text-white shadow-md shadow-primary/20 transition hover:bg-primary/90"
                >
                    <Lucide icon="House" class="h-3.5 w-3.5" />
                    {{ home.label }}
                </Link>
                <a
                    v-else
                    :href="home.url"
                    class="inline-flex h-9 items-center gap-1.5 rounded-[0.5rem] bg-primary px-3.5 text-xs font-medium text-white shadow-md shadow-primary/20 transition hover:bg-primary/90"
                >
                    <Lucide icon="House" class="h-3.5 w-3.5" />
                    {{ home.label }}
                </a>

                <button
                    v-if="status >= 500 || status === 419"
                    type="button"
                    class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    @click="reload"
                >
                    <Lucide icon="RefreshCw" class="h-3.5 w-3.5" />
                    Reintentar
                </button>
            </div>
        </div>

        <!-- Qué puede hacer el usuario -->
        <div
            v-if="hints.length"
            class="border-t border-slate-200/60 bg-slate-50/70 px-5 py-4 dark:border-darkmode-400 dark:bg-darkmode-700/40"
        >
            <div
                class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                Qué puedes hacer
            </div>
            <ul class="mt-2 space-y-1.5">
                <li
                    v-for="hint in hints"
                    :key="hint"
                    class="flex items-start gap-2 text-xs leading-relaxed text-slate-500"
                >
                    <Lucide
                        icon="Dot"
                        class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400"
                    />
                    <span>{{ hint }}</span>
                </li>
            </ul>
        </div>

        <!-- Folio + soporte: solo cuando hay algo que rastrear -->
        <div
            v-if="folio || (hasSupport && status >= 500)"
            class="flex flex-col gap-3 border-t border-slate-200/60 px-5 py-3.5 sm:flex-row sm:items-center dark:border-darkmode-400"
        >
            <div v-if="folio" class="flex items-center gap-2 text-xs">
                <span class="text-slate-400">Folio del error</span>
                <span
                    class="rounded-md bg-slate-100 px-2 py-0.5 font-mono text-[11px] font-medium tracking-wider text-slate-600 dark:bg-darkmode-600 dark:text-slate-300"
                    >{{ folio }}</span
                >
            </div>

            <div
                v-if="hasSupport && status >= 500"
                class="flex flex-wrap items-center gap-2 sm:ml-auto"
            >
                <a
                    v-if="whatsappUrl"
                    :href="whatsappUrl"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex h-8 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 text-xs font-medium text-slate-500 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                >
                    <Lucide icon="MessageCircle" class="h-3.5 w-3.5" />
                    Reportar por WhatsApp
                </a>
                <a
                    v-if="support.email"
                    :href="`mailto:${support.email}?subject=${encodeURIComponent(`Error ${status}${folio ? ' · folio ' + folio : ''}`)}`"
                    class="inline-flex h-8 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 text-xs font-medium text-slate-500 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                >
                    <Lucide icon="Mail" class="h-3.5 w-3.5" />
                    {{ support.email }}
                </a>
            </div>
        </div>
    </div>
</template>
