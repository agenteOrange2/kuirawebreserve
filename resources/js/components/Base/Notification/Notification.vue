<script setup lang="ts">
import '@/assets/css/vendors/toastify.css';
import { type HTMLAttributes, ref, onMounted, inject } from 'vue';
import { init, reInit } from './notification';
import Toastify, { type Options } from 'toastify-js';

export interface NotificationElement extends HTMLDivElement {
    toastify: ReturnType<typeof Toastify>;
    showToast: () => void;
    hideToast: () => void;
}

export interface NotificationProps extends /* @vue-ignore */ HTMLAttributes {
    options?: Options;
    refKey?: string;
}

export type ProvideNotification = (el: NotificationElement) => void;

const props = defineProps<NotificationProps>();

const toastifyRef = ref<NotificationElement>();

const bindInstance = (el: NotificationElement) => {
    if (props.refKey) {
        const bind = inject<ProvideNotification>(`bind[${props.refKey}]`);
        if (bind) {
            bind(el);
        }
    }
};

const vNotificationDirective = {
    mounted(el: NotificationElement) {
        init(el, props);
    },
    updated(el: NotificationElement) {
        reInit(el);
    },
};

onMounted(() => {
    if (toastifyRef.value) {
        bindInstance(toastifyRef.value);
    }
});
</script>

<template>
    <div
        class="hidden rounded-lg border border-slate-200/60 bg-white py-5 pr-14 pl-5 shadow-xl dark:border-darkmode-600 dark:bg-darkmode-600 dark:text-slate-300"
        v-notification-directive
        ref="toastifyRef"
    >
        <slot></slot>
    </div>
</template>
