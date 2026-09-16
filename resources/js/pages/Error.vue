<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ErrorState from '@/components/ErrorState.vue';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Pantalla de error con el theme (404, 500, 403, 419…), en lugar de la
 * página gris de Laravel. La arma bootstrap/app.php desde
 * config/error-pages.php.
 *
 * Tiene dos caras a propósito:
 *
 * - Con sesión abierta se monta dentro de RazeLayout: quien trabaja en el
 *   panel conserva el menú y se va a otra sección sin retroceder a ciegas.
 * - Sin sesión —el huésped en el wizard, o alguien que llegó por un enlace
 *   viejo— se muestra suelta sobre el degradado de la marca, igual que la
 *   pantalla de hotel suspendido.
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
    appName: string;
}>();

const page = usePage();
const auth = computed(() => page.props.auth as { user?: unknown } | undefined);
const branding = computed(
    () =>
        (page.props.branding ?? {}) as {
            logo_url?: string | null;
            login_background_url?: string | null;
        },
);

const inPanel = computed(() => Boolean(auth.value?.user));
const headTitle = computed(() => `${props.status} · ${props.badge}`);
</script>

<template>
    <!-- Dentro del panel: el usuario conserva menú y buscador -->
    <RazeLayout v-if="inPanel" :title="headTitle">
        <div class="mt-2 grid grid-cols-12 gap-5">
            <div class="col-span-12 xl:col-span-8 xl:col-start-3">
                <ErrorState v-bind="props" />
            </div>
        </div>
    </RazeLayout>

    <!-- Sin sesión: suelta, sobre el degradado de la marca -->
    <template v-else>
        <Head :title="headTitle" />

        <div class="fixed inset-0 bg-linear-to-b from-theme-1 to-theme-2">
            <template v-if="branding.login_background_url">
                <img
                    :src="branding.login_background_url"
                    alt=""
                    class="absolute inset-0 h-full w-full object-cover"
                />
                <div
                    class="absolute inset-0 bg-linear-to-b from-theme-1/90 to-theme-2/90"
                ></div>
            </template>
            <div
                class="absolute inset-0 bg-texture-white bg-fixed bg-center bg-no-repeat"
            ></div>
        </div>

        <div
            class="relative z-10 flex min-h-screen flex-col items-center justify-center px-5 py-10 sm:px-8 sm:py-14"
        >
            <div class="mb-7 flex items-center gap-3">
                <img
                    v-if="branding.logo_url"
                    :src="branding.logo_url"
                    :alt="appName"
                    class="max-h-11 max-w-[180px] object-contain"
                />
                <template v-else>
                    <div
                        class="flex h-11 w-11 items-center justify-center rounded-[0.6rem] border border-white/20 bg-white/10"
                    >
                        <Lucide icon="Building2" class="h-6 w-6 text-white" />
                    </div>
                    <div class="text-lg font-medium text-white">
                        {{ appName }}
                    </div>
                </template>
            </div>

            <div class="w-full max-w-[36rem]">
                <ErrorState v-bind="props" standalone />
            </div>

            <p class="mt-6 text-center text-xs text-white/60">
                {{ appName }} — plataforma de reservas y atención para hoteles
            </p>
        </div>
    </template>
</template>
