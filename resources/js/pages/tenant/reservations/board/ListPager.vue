<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

/** Franja de paginación del canon: "x–y de N" y los enlaces a la derecha. */
defineProps<{
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
}>();
</script>

<template>
    <div
        v-if="links.length > 3"
        class="flex flex-wrap items-center gap-2 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
    >
        <span class="text-xs text-slate-500">
            {{ from }}–{{ to }} de {{ total }}
        </span>
        <div class="ml-auto flex flex-wrap gap-1">
            <component
                :is="link.url ? Link : 'span'"
                v-for="(link, i) in links"
                :key="i"
                :href="link.url ?? undefined"
                preserve-state
                preserve-scroll
                class="rounded-md px-2.5 py-1 text-xs"
                :class="
                    link.active
                        ? 'bg-primary text-white'
                        : link.url
                          ? 'text-slate-500 hover:bg-slate-100 dark:hover:bg-darkmode-400'
                          : 'text-slate-300'
                "
            >
                <!-- El rótulo trae las flechas « » de Laravel. -->
                <span v-html="link.label" />
            </component>
        </div>
    </div>
</template>
