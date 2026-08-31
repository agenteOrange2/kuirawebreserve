<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';

/**
 * Pantalla que ve un hotel suspendido por la plataforma (403 de
 * EnsureTenantIsActive). Es standalone a propósito: sin menú ni sesión,
 * porque hasta el login está bloqueado. Sirve a dos públicos — el equipo
 * del hotel y el huésped que llegó al wizard o al chat — así que el texto
 * principal es neutro y la parte de "contacta a la plataforma" va aparte.
 */
const props = defineProps<{
    hotel?: string | null;
    suspendedSince?: string | null;
    platformUrl?: string | null;
    support?: {
        email?: string | null;
        whatsapp?: string | null;
    };
}>();

const page = usePage();
const branding = computed(
    () =>
        (page.props.branding ?? {}) as {
            app_name?: string | null;
            logo_url?: string | null;
            login_background_url?: string | null;
        },
);

const appName = computed(() => branding.value.app_name || 'KuiraReserve');
const hotelName = computed(() => props.hotel || 'Este hotel');
const whatsappUrl = computed(() =>
    props.support?.whatsapp
        ? `https://wa.me/${props.support.whatsapp}?text=${encodeURIComponent(
              `Hola, escribo por el acceso suspendido de ${hotelName.value}.`,
          )}`
        : null,
);
const hasSupport = computed(
    () => Boolean(whatsappUrl.value) || Boolean(props.support?.email),
);

function reload() {
    window.location.reload();
}
</script>

<template>
    <Head title="Acceso suspendido" />

    <!-- Fondo: mismo degradado con textura del login -->
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
        <!-- Marca de la plataforma -->
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
                <div class="text-lg font-medium text-white">{{ appName }}</div>
            </template>
        </div>

        <div class="box box--stacked w-full max-w-[38rem] p-7 sm:p-10">
            <div class="flex flex-col items-center text-center">
                <div
                    class="flex h-16 w-16 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                >
                    <Lucide icon="ShieldAlert" class="h-8 w-8" />
                </div>

                <div
                    class="mt-5 inline-flex items-center gap-2 rounded-full border border-slate-200/70 bg-slate-50 px-3 py-1 text-xs font-medium tracking-wide text-slate-500 uppercase dark:border-darkmode-400 dark:bg-darkmode-700"
                >
                    <span class="text-pending">403</span>
                    Acceso suspendido
                </div>

                <h1
                    class="mt-4 text-2xl leading-snug font-medium text-slate-700 sm:text-3xl dark:text-slate-200"
                >
                    {{ hotelName }} no está disponible por ahora
                </h1>

                <p
                    class="mt-3 max-w-md text-base leading-relaxed text-slate-500"
                >
                    El servicio de esta propiedad está suspendido de forma
                    temporal, así que su panel, sus reservas en línea y su chat
                    quedaron fuera de servicio mientras tanto.
                </p>

                <div
                    v-if="suspendedSince"
                    class="mt-5 inline-flex items-center gap-2 rounded-[0.6rem] border border-slate-200/70 bg-slate-50/70 px-3.5 py-2 text-sm text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                >
                    <Lucide icon="Clock" class="h-4 w-4 text-slate-400" />
                    Suspendido desde el {{ suspendedSince }}
                </div>
            </div>

            <!-- Para el equipo del hotel -->
            <div
                class="mt-7 border-t border-dashed border-slate-300/70 pt-6 dark:border-darkmode-400"
            >
                <div class="flex items-start gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Headset" class="h-5 w-5" />
                    </div>
                    <div class="flex-1">
                        <div
                            class="font-medium text-slate-700 dark:text-slate-200"
                        >
                            ¿Eres del equipo del hotel?
                        </div>
                        <p class="mt-1 text-sm leading-relaxed text-slate-500">
                            La suspensión la levanta el administrador de
                            {{ appName }}. Escríbenos y te ayudamos a
                            reactivarlo; tu información sigue guardada tal como
                            la dejaste.
                        </p>

                        <div
                            v-if="hasSupport"
                            class="mt-4 flex flex-col gap-2.5 sm:flex-row sm:flex-wrap"
                        >
                            <Button
                                v-if="whatsappUrl"
                                as="a"
                                :href="whatsappUrl"
                                target="_blank"
                                rel="noopener"
                                variant="primary"
                                class="rounded-[0.5rem] shadow-md shadow-primary/20"
                            >
                                <Lucide
                                    icon="MessageCircle"
                                    class="mr-2 h-4 w-4"
                                />
                                Escribir por WhatsApp
                            </Button>
                            <Button
                                v-if="support?.email"
                                as="a"
                                :href="`mailto:${support.email}`"
                                variant="outline-secondary"
                                class="rounded-[0.5rem] bg-white dark:bg-transparent"
                            >
                                <Lucide icon="Mail" class="mr-2 h-4 w-4" />
                                {{ support.email }}
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="mt-6 flex flex-col items-center justify-between gap-3 border-t border-dashed border-slate-300/70 pt-5 sm:flex-row dark:border-darkmode-400"
            >
                <a
                    v-if="platformUrl"
                    :href="platformUrl"
                    class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-primary"
                >
                    <Lucide icon="ArrowLeft" class="h-4 w-4" />
                    Ir a {{ appName }}
                </a>
                <span v-else></span>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-primary"
                    @click="reload"
                >
                    <Lucide icon="RefreshCw" class="h-4 w-4" />
                    Reintentar
                </button>
            </div>
        </div>

        <p class="mt-6 text-center text-xs text-white/60">
            {{ appName }} — plataforma de reservas y atención para hoteles
        </p>
    </div>
</template>
