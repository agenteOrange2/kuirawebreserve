<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, watchEffect } from 'vue';
import Lucide from '@/components/Base/Lucide';
import type { TenantBrand } from '@/types/auth';

/**
 * Armazón de las pantallas de acceso (login, recuperar y restablecer
 * contraseña): formulario a la izquierda, portada con la marca a la
 * derecha. En el dominio de un hotel manda SU marca (logo, nombre, colores
 * y lo que personalizó en /ajustes/general/apariencia); lo que no tenga cae
 * en la de la plataforma (/admin/settings/brand), que además firma abajo.
 */
const props = defineProps<{
    tenantBrand?: TenantBrand | null;
    /** Encabezado del formulario; sin él, el de la marca. */
    heading?: string;
    /** Línea bajo el encabezado; sin ella, la instrucción de la marca. */
    hint?: string;
}>();

const page = usePage();
const branding = computed(
    () =>
        (page.props.branding ?? {}) as {
            app_name?: string | null;
            logo_url?: string | null;
            login_heading?: string | null;
            login_hint?: string | null;
            login_title?: string | null;
            login_overlay?: 'strong' | 'medium' | 'light' | null;
            login_subtitle?: string | null;
            login_background_url?: string | null;
        },
);

const DEFAULT_HINT = 'Ingresa tus credenciales para acceder';
const DEFAULT_SUBTITLE =
    'Reservas, atención por WhatsApp y cobros de tu hotel en un solo lugar.';

const hotel = computed(() => props.tenantBrand ?? null);
const appName = computed(() => branding.value.app_name || 'KuiraReserve');
const logoUrl = computed(
    () => hotel.value?.logo_url ?? branding.value.logo_url ?? null,
);
const heading = computed(
    () =>
        props.heading ??
        (hotel.value
            ? hotel.value.name
            : branding.value.login_heading || appName.value),
);
const hint = computed(
    () =>
        props.hint ??
        (hotel.value?.hint || branding.value.login_hint || DEFAULT_HINT),
);
const coverTitle = computed(() =>
    hotel.value
        ? hotel.value.title || hotel.value.name
        : branding.value.login_title || appName.value,
);
const coverSubtitle = computed(
    () =>
        hotel.value?.subtitle ||
        branding.value.login_subtitle ||
        DEFAULT_SUBTITLE,
);
const backgroundUrl = computed(
    () =>
        hotel.value?.background_url ??
        branding.value.login_background_url ??
        null,
);
// Velo sobre la foto de fondo. Clases completas para que Tailwind las vea.
const overlayClass = computed(
    () =>
        ({
            strong: 'from-theme-1/80 to-theme-2/80',
            medium: 'from-theme-1/60 to-theme-2/60',
            light: 'from-theme-1/40 to-theme-2/40',
        })[branding.value.login_overlay ?? 'strong'] ??
        'from-theme-1/80 to-theme-2/80',
);

// Colores del panel del hotel también en su acceso (mismas variables que
// pisa RazeLayout), para que entrar se sienta parte del mismo lugar.
watchEffect(() => {
    const colors = hotel.value?.colors;
    const rootStyle = document.documentElement.style;
    const apply = (cssVar: string, value: string | null | undefined) =>
        value
            ? rootStyle.setProperty(cssVar, value)
            : rootStyle.removeProperty(cssVar);
    apply('--color-primary', colors?.primary);
    apply('--color-theme-1', colors?.menu_from);
    apply('--color-theme-2', colors?.menu_to);
});
</script>

<template>
    <div
        class="container grid grid-cols-12 px-5 py-10 sm:px-10 sm:py-14 md:px-36 lg:h-screen lg:max-w-[1550px] lg:py-0 lg:pr-12 lg:pl-14 xl:px-24 2xl:max-w-[1750px]"
    >
        <div
            :class="[
                'relative z-50 col-span-12 h-full rounded-2xl bg-white p-7 sm:p-14 lg:col-span-5 lg:bg-transparent lg:p-0 lg:pr-10 xl:pr-24 2xl:col-span-4',
                'before:absolute before:inset-0 before:mx-5 before:-mb-3.5 before:rounded-2xl before:bg-white/40 before:content-[\'\']',
            ]"
        >
            <div
                class="relative z-10 flex h-full w-full flex-col justify-center py-2 lg:py-24"
            >
                <div v-if="logoUrl" class="flex h-[55px] items-center">
                    <img
                        :src="logoUrl"
                        :alt="hotel?.name ?? appName"
                        class="max-h-[55px] max-w-[200px] object-contain"
                    />
                </div>
                <div
                    v-else
                    class="flex h-[55px] w-[55px] items-center justify-center rounded-[0.8rem] border border-primary/30"
                >
                    <div
                        class="relative flex h-[50px] w-[50px] items-center justify-center rounded-[0.6rem] bg-white bg-linear-to-b from-theme-1/90 to-theme-2/90"
                    >
                        <Lucide icon="Building2" class="h-8 w-8 text-white" />
                    </div>
                </div>
                <div class="mt-10">
                    <div class="text-2xl font-medium">{{ heading }}</div>
                    <div class="mt-2.5 text-slate-600">{{ hint }}</div>

                    <slot />

                    <!-- Firma de la plataforma en el acceso de cada hotel -->
                    <div
                        v-if="hotel"
                        class="mt-8 flex flex-wrap items-center justify-center gap-x-2 gap-y-1 border-t border-slate-200/70 pt-5 text-xs text-slate-400 xl:justify-start"
                    >
                        <span class="whitespace-nowrap"
                            >Con la tecnología de</span
                        >
                        <img
                            v-if="branding.logo_url"
                            :src="branding.logo_url"
                            alt=""
                            class="h-4 max-w-[90px] object-contain"
                        />
                        <span
                            class="font-medium whitespace-nowrap text-slate-500"
                            >{{ appName }}</span
                        >
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div
        class="fixed inset-0 container grid h-screen w-screen grid-cols-12 pr-12 pl-14 lg:max-w-[1550px] xl:px-24 2xl:max-w-[1750px]"
    >
        <div
            :class="[
                'relative z-20 col-span-12 h-screen lg:col-span-5 2xl:col-span-4',
                'after:absolute after:inset-y-0 after:right-0 after:hidden after:w-[800%] after:rounded-[0_1.2rem_1.2rem_0/0_1.7rem_1.7rem_0] after:bg-white after:bg-linear-to-b after:from-white after:to-slate-100/80 after:content-[\'\'] after:lg:block',
                'before:absolute before:inset-y-0 before:right-0 before:my-6 before:-mr-4 before:hidden before:w-[800%] before:rounded-[0_1.2rem_1.2rem_0/0_1.7rem_1.7rem_0] before:bg-white/50 before:bg-linear-to-b before:from-white/10 before:to-slate-50/10 before:content-[\'\'] before:lg:block',
            ]"
        ></div>
        <div
            :class="[
                'col-span-7 h-full lg:relative 2xl:col-span-8',
                'before:absolute before:inset-y-0 before:left-0 before:w-screen before:bg-linear-to-b before:from-theme-1 before:to-theme-2 before:content-[\'\'] before:lg:-ml-10 before:lg:w-[800%]',
                'after:absolute after:inset-y-0 after:left-0 after:w-screen after:bg-texture-white after:bg-fixed after:bg-center after:bg-no-repeat after:content-[\'\'] after:lg:w-[800%] after:lg:bg-[25rem_-25rem]',
            ]"
        >
            <!-- Fondo configurable (anclado al viewport: el panel blanco lo
                 tapa a la izquierda y a la derecha cubre hasta el borde) -->
            <template v-if="backgroundUrl">
                <img
                    :src="backgroundUrl"
                    alt=""
                    class="fixed inset-0 h-full w-full object-cover"
                />
                <div
                    class="fixed inset-0 bg-linear-to-b"
                    :class="overlayClass"
                ></div>
            </template>

            <div
                class="sticky top-0 z-10 ml-16 hidden h-screen flex-col justify-center lg:flex xl:ml-28 2xl:ml-36"
            >
                <div
                    class="text-[2.6rem] leading-[1.4] font-medium whitespace-pre-line text-white xl:text-5xl xl:leading-[1.2]"
                >
                    {{ coverTitle }}
                </div>
                <div
                    class="mt-5 max-w-xl text-base leading-relaxed text-white/70 xl:text-lg"
                >
                    {{ coverSubtitle }}
                </div>
            </div>
        </div>
    </div>
</template>
