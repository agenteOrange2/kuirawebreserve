<script lang="ts">
export default {
    inheritAttrs: false,
};
</script>

<script setup lang="ts">
import _ from 'lodash';
import { twMerge } from 'tailwind-merge';
import { computed, useAttrs, inject } from 'vue';
import { type ProvideTable } from './Table.vue';

const table = inject<ProvideTable>('table', {
    dark: false,
    bordered: false,
    hover: false,
    striped: false,
    sm: false,
});

const attrs = useAttrs();

const computedClass = computed(() =>
    twMerge([
        // Celda densa por default (antes px-5 py-3): con ~20 filas en pantalla
        // esos 4px por lado eran media fila de diferencia. Una vista que
        // necesite más aire lo pisa con su propia clase (twMerge).
        'px-4 py-2.5 border-b dark:border-darkmode-300',
        table?.dark && 'border-slate-600 dark:border-darkmode-300',
        table?.bordered && 'border-l border-r border-t',
        table?.sm && 'px-4 py-2',
        typeof attrs.class === 'string' && attrs.class,
    ]),
);
</script>

<template>
    <td :class="computedClass" v-bind="_.omit(attrs, 'class')">
        <slot></slot>
    </td>
</template>
