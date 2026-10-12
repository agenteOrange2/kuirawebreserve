<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, provide } from 'vue';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import { useEmbedResize } from '@/composables/useEmbedResize';
import type { WizardAppearance } from '@/composables/useWizardAppearance';
import { useWizardAppearance } from '@/composables/useWizardAppearance';
import type { PublicContact, StepItem } from './theme';
import { bookingThemeKey } from './theme';

/**
 * Armazón de las páginas públicas de reserva (habitaciones, grupos,
 * experiencias y consulta): fondo con los colores del hotel, encabezado con
 * su logo y teléfono, pasos, y al pie los accesos y sus redes. Antes cada
 * página traía su copia y se iban desalineando.
 *
 * La piel oscura se aplica SOLO al contenido: el encabezado y el pie van
 * sobre el degradado del hotel en blanco.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        appearance: WizardAppearance;
        property: {
            name: string;
            logo_url: string | null;
            phone: string | null;
        };
        contact?: PublicContact | null;
        tagline?: string;
        steps?: StepItem[];
        currentStep?: string | null;
        width?: 'narrow' | 'wide';
    }>(),
    {
        contact: null,
        tagline: 'Reserva directamente con nosotros, sin intermediarios',
        steps: () => [],
        currentStep: null,
        width: 'wide',
    },
);

useEmbedResize();

const { isDark, rootStyle } = useWizardAppearance(props.appearance);
const titleColor = computed(
    () => !!props.appearance.title_color && !isDark.value,
);
const vars = computed(() => {
    const { backgroundImage: _bg, ...rest } = rootStyle.value as Record<
        string,
        string
    >;
    return rest;
});
provide(bookingThemeKey, {
    isDark,
    accent: props.appearance.accent,
    vars,
    titleColor,
});

const currentIndex = computed(() =>
    props.steps.findIndex((s) => s.key === props.currentStep),
);

const hasContact = computed(
    () =>
        !!props.contact &&
        (props.contact.socials.length > 0 ||
            !!props.contact.maps_url ||
            !!props.contact.website),
);

// Texto sobre el degradado (encabezado, pasos, pie): el color que eligió el
// hotel en /reservas/ajustes; blanco si no eligió.
const onBg = { color: 'var(--booking-header-text, #ffffff)' };

const pill =
    'flex h-11 w-11 items-center justify-center rounded-full border border-current/25 bg-current/10 transition hover:bg-current/20';
</script>

<template>
    <Head :title="`${title} · ${property.name}`" />
    <!-- flex + m-auto: centrado vertical cuando el contenido es corto, scroll
         normal cuando es largo (justify-center recortaba arriba). -->
    <div
        class="booking-public flex min-h-screen bg-linear-to-b from-theme-1 to-theme-2 px-3 py-6 sm:px-6 sm:py-8"
        :class="titleColor && 'booking-title-color'"
        :style="rootStyle"
    >
        <div
            class="m-auto w-full"
            :class="width === 'narrow' ? 'max-w-2xl' : 'max-w-8xl'"
        >
            <header class="mb-5 flex items-center gap-3 px-1" :style="onBg">
                <img
                    v-if="property.logo_url"
                    :src="property.logo_url"
                    :alt="`Logo de ${property.name}`"
                    class="h-11 w-11 shrink-0 rounded-full bg-white object-contain p-1"
                />
                <div
                    v-else
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-current/10"
                >
                    <Lucide icon="Building2" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <div
                        class="booking-title truncate text-lg font-medium"
                        style="color: inherit"
                    >
                        {{ property.name }}
                    </div>
                    <div class="truncate text-xs opacity-75 sm:text-sm">
                        {{ tagline }}
                    </div>
                </div>
                <a
                    v-if="property.phone"
                    :href="`tel:${property.phone}`"
                    :class="pill"
                    title="Llamar al hotel"
                >
                    <Lucide icon="Phone" class="h-4 w-4" />
                </a>
            </header>

            <!-- Pasos: en celular solo el actual con su barra; desde sm, el
                 recorrido completo con lo ya hecho marcado. -->
            <nav
                v-if="steps.length && currentIndex >= 0"
                class="mb-5 px-1 text-xs font-medium"
                :style="onBg"
                aria-label="Pasos de la reserva"
            >
                <div class="sm:hidden">
                    <div class="flex items-center justify-between gap-3">
                        <span
                            >Paso {{ currentIndex + 1 }} de
                            {{ steps.length }}</span
                        >
                        <span>{{ steps[currentIndex].label }}</span>
                    </div>
                    <div
                        class="mt-2 h-1.5 overflow-hidden rounded-full bg-current/20"
                    >
                        <div
                            class="h-full rounded-full bg-current transition-all duration-300"
                            :style="{
                                width: `${((currentIndex + 1) / steps.length) * 100}%`,
                            }"
                        />
                    </div>
                </div>
                <ol class="hidden items-center gap-2 sm:flex">
                    <template v-for="(s, i) in steps" :key="s.key">
                        <li
                            class="flex items-center gap-1.5"
                            :class="i <= currentIndex ? '' : 'opacity-70'"
                            :aria-current="
                                i === currentIndex ? 'step' : undefined
                            "
                        >
                            <span
                                class="flex h-6 w-6 items-center justify-center rounded-full text-[11px]"
                                :class="
                                    i < currentIndex
                                        ? 'bg-current'
                                        : i === currentIndex
                                          ? 'bg-current ring-4 ring-current/25'
                                          : 'bg-current/20'
                                "
                            >
                                <!-- El número va en el color del fondo: el
                                     círculo es del color del texto elegido y
                                     el fondo contrasta con él por diseño. -->
                                <span
                                    class="flex items-center justify-center"
                                    :style="
                                        i <= currentIndex
                                            ? {
                                                  color: 'var(--booking-bg-from)',
                                              }
                                            : {}
                                    "
                                >
                                    <Lucide
                                        v-if="i < currentIndex"
                                        icon="Check"
                                        class="h-3.5 w-3.5"
                                    />
                                    <template v-else>{{ i + 1 }}</template>
                                </span>
                            </span>
                            {{ s.label }}
                        </li>
                        <li
                            v-if="i < steps.length - 1"
                            class="h-px flex-1 bg-current opacity-30"
                            aria-hidden="true"
                        />
                    </template>
                </ol>
            </nav>

            <main :class="isDark && 'booking-dark'">
                <slot :is-dark="isDark" />
            </main>

            <div
                v-if="$slots.links"
                class="mt-5 flex flex-wrap items-center justify-center gap-2.5"
                :style="onBg"
            >
                <slot name="links" />
            </div>

            <div
                v-if="hasContact && contact"
                class="mt-6 text-center"
                :style="onBg"
            >
                <p class="text-xs font-medium opacity-75">
                    Síguenos y encuéntranos
                </p>
                <div
                    class="mt-2.5 flex flex-wrap items-center justify-center gap-2.5"
                >
                    <a
                        v-for="social in contact.socials"
                        :key="social.url"
                        :href="social.url"
                        target="_blank"
                        rel="noopener"
                        :title="social.type"
                        :class="pill"
                    >
                        <Lucide :icon="social.icon as Icon" class="h-4 w-4" />
                    </a>
                    <a
                        v-if="contact.maps_url"
                        :href="contact.maps_url"
                        target="_blank"
                        rel="noopener"
                        title="Cómo llegar"
                        :class="pill"
                    >
                        <Lucide icon="MapPin" class="h-4 w-4" />
                    </a>
                    <a
                        v-if="contact.website"
                        :href="contact.website"
                        target="_blank"
                        rel="noopener"
                        title="Sitio web"
                        :class="pill"
                    >
                        <Lucide icon="Globe" class="h-4 w-4" />
                    </a>
                </div>
            </div>
            <p class="mt-4 text-center text-xs opacity-75" :style="onBg">
                Impulsado por KuiraWebReserve · tus datos de pago nunca pasan
                por este sitio
            </p>
        </div>
    </div>
</template>
