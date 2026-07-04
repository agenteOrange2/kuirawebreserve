<script setup lang="ts">
import Lucide from '@/components/Base/Lucide';
import { useToasts, type Toast } from '@/composables/useToasts';

const { toasts, dismiss } = useToasts();

const iconFor = (toast: Toast) =>
    ({ success: 'CircleCheck', error: 'CircleAlert', info: 'Info' })[toast.type] as
        | 'CircleCheck'
        | 'CircleAlert'
        | 'Info';

const toneFor = (toast: Toast) =>
    ({
        success: 'text-success',
        error: 'text-danger',
        info: 'text-primary',
    })[toast.type];
</script>

<template>
    <div class="pointer-events-none fixed right-5 top-20 z-[70] flex w-[340px] max-w-[calc(100vw-2.5rem)] flex-col gap-2">
        <TransitionGroup
            enter-active-class="transition-all duration-300 ease-out"
            enter-from-class="opacity-0 translate-x-6"
            enter-to-class="opacity-100 translate-x-0"
            leave-active-class="transition-all duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0 translate-x-6"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto flex items-start gap-3 rounded-lg border border-slate-200/80 bg-white px-4 py-3 shadow-xl dark:border-darkmode-400 dark:bg-darkmode-600"
            >
                <Lucide :icon="iconFor(toast)" :class="['mt-0.5 h-5 w-5 shrink-0', toneFor(toast)]" />
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium dark:text-slate-200">{{ toast.title }}</div>
                    <div v-if="toast.message" class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ toast.message }}
                    </div>
                </div>
                <button class="shrink-0 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300" @click="dismiss(toast.id)">
                    <Lucide icon="X" class="h-4 w-4" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
