<script setup lang="ts">
import { computed } from 'vue';
import Lucide from '@/components/Base/Lucide';

/**
 * Maqueta proporcional del login (/login) para /admin/settings/brand: la
 * misma composición —formulario blanco a la izquierda, degradado del theme
 * con foto y textos a la derecha— a escala. `large` es la del modal.
 */
const props = defineProps<{
    appName: string;
    logoUrl: string | null;
    heading: string;
    hint: string;
    title: string;
    subtitle: string;
    backgroundUrl: string | null;
    overlay: 'strong' | 'medium' | 'light';
    large?: boolean;
    /** Login de un hotel: la plataforma firma abajo del formulario. */
    poweredBy?: string | null;
}>();

// Mismas clases que Login.vue: completas para que Tailwind las genere.
const overlayClass = computed(
    () =>
        ({
            strong: 'from-theme-1/80 to-theme-2/80',
            medium: 'from-theme-1/60 to-theme-2/60',
            light: 'from-theme-1/40 to-theme-2/40',
        })[props.overlay],
);
</script>

<template>
    <div
        class="relative grid grid-cols-12 overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
        :class="large ? 'aspect-[16/9]' : 'aspect-[4/3]'"
    >
        <!-- Izquierda: formulario -->
        <div
            class="col-span-5 flex flex-col justify-center bg-white dark:bg-darkmode-600"
            :class="large ? 'gap-3 p-8' : 'gap-2 p-4'"
        >
            <img
                v-if="logoUrl"
                :src="logoUrl"
                :alt="appName"
                class="w-fit object-contain"
                :class="
                    large ? 'max-h-10 max-w-[160px]' : 'max-h-7 max-w-[110px]'
                "
            />
            <div
                v-else
                class="flex items-center justify-center rounded-lg bg-linear-to-b from-theme-1/90 to-theme-2/90"
                :class="large ? 'h-10 w-10' : 'h-7 w-7'"
            >
                <Lucide
                    icon="Building2"
                    class="text-white"
                    :class="large ? 'h-5 w-5' : 'h-3.5 w-3.5'"
                />
            </div>
            <div :class="large ? 'mt-2' : 'mt-1'">
                <div
                    class="truncate font-medium"
                    :class="large ? 'text-lg' : 'text-xs'"
                >
                    {{ heading }}
                </div>
                <div
                    class="mt-0.5 line-clamp-2 text-slate-500"
                    :class="large ? 'text-xs' : 'text-[10px] leading-tight'"
                >
                    {{ hint }}
                </div>
            </div>
            <div
                class="rounded-md border border-slate-200/70 dark:border-darkmode-400"
                :class="large ? 'h-9' : 'h-5'"
            ></div>
            <div
                class="rounded-md border border-slate-200/70 dark:border-darkmode-400"
                :class="large ? 'h-9' : 'h-5'"
            ></div>
            <div
                class="rounded-full bg-linear-to-r from-theme-1/70 to-theme-2/70"
                :class="large ? 'h-9' : 'h-5'"
            ></div>
            <div
                v-if="poweredBy"
                class="truncate border-t border-slate-200/70 text-slate-400 dark:border-darkmode-400"
                :class="large ? 'mt-2 pt-3 text-xs' : 'mt-1 pt-1.5 text-[9px]'"
            >
                Con la tecnología de
                <span class="font-medium text-slate-500">{{ poweredBy }}</span>
            </div>
        </div>

        <!-- Derecha: fondo + velo + textos -->
        <div class="relative col-span-7 overflow-hidden">
            <img
                v-if="backgroundUrl"
                :src="backgroundUrl"
                alt=""
                class="absolute inset-0 h-full w-full object-cover"
            />
            <div
                class="absolute inset-0 bg-linear-to-b"
                :class="
                    backgroundUrl ? overlayClass : 'from-theme-1 to-theme-2'
                "
            ></div>
            <div
                class="relative z-10 flex h-full flex-col justify-center"
                :class="large ? 'p-12' : 'p-5'"
            >
                <div
                    class="line-clamp-4 font-medium whitespace-pre-line text-white"
                    :class="
                        large
                            ? 'text-3xl leading-tight'
                            : 'text-sm leading-snug'
                    "
                >
                    {{ title }}
                </div>
                <div
                    class="line-clamp-4 text-white/75"
                    :class="
                        large ? 'mt-4 max-w-md text-sm' : 'mt-2 text-[10px]'
                    "
                >
                    {{ subtitle }}
                </div>
            </div>
        </div>
    </div>
</template>
