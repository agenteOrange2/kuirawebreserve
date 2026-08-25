<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    property: { id: number; name: string };
    logoUrl: string | null;
    summary: {
        has_logo: boolean;
        address: boolean;
        phones: number;
        emails: number;
        socials: number;
        check_in: string;
        check_out: string;
        currency: string;
        timezone: string;
        policies: boolean;
        faqs_active: number;
        faqs_total: number;
    };
}>();

// Cada tarjeta dice qué tiene adentro para no entrar a averiguarlo, y marca
// en ámbar lo que está incompleto: sin políticas ni FAQs el asistente
// responde "no tengo esa información" y el hotel no sabe por qué.
const cards = computed(() => [
    {
        route: 'tenant.general-settings.contact',
        icon: 'Building2' as const,
        title: 'Identidad y contacto',
        description:
            'Nombre, logo, dirección, teléfonos, correos y redes sociales.',
        detail: [
            props.summary.has_logo ? 'Con logo' : 'Sin logo',
            `${props.summary.phones} teléfono(s)`,
            `${props.summary.emails} correo(s)`,
            `${props.summary.socials} red(es)`,
        ].join(' · '),
        warn: !props.summary.address,
    },
    {
        route: 'tenant.general-settings.operation',
        icon: 'Clock' as const,
        title: 'Horarios y moneda',
        description:
            'Check-in, check-out, moneda con la que cobras y zona horaria.',
        detail: `Entrada ${props.summary.check_in} · Salida ${props.summary.check_out} · ${props.summary.currency} · ${props.summary.timezone}`,
        warn: false,
    },
    {
        route: 'tenant.general-settings.policies',
        icon: 'ScrollText' as const,
        title: 'Políticas del hotel',
        description:
            'Lo que el asistente responde sobre reglas de la casa, tal cual lo escribas.',
        detail: props.summary.policies
            ? 'Escritas'
            : 'Sin escribir: el asistente dirá que no tiene esa información',
        warn: !props.summary.policies,
    },
    {
        route: 'tenant.general-settings.faqs',
        icon: 'MessageCircleQuestion' as const,
        title: 'Preguntas frecuentes',
        description:
            'Respuestas puntuales que el asistente usa cuando preguntan algo parecido.',
        detail: props.summary.faqs_total
            ? `${props.summary.faqs_active} activa(s) de ${props.summary.faqs_total}`
            : 'Ninguna capturada',
        warn: props.summary.faqs_active === 0,
    },
    {
        route: 'tenant.panel-appearance',
        icon: 'Palette' as const,
        title: 'Apariencia del panel',
        description: 'Color de acento y del menú lateral para tu equipo.',
        detail: 'Solo afecta lo que ve tu personal, no al huésped.',
        warn: false,
    },
]);
</script>

<template>
    <RazeLayout title="Datos generales">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-wrap items-center justify-between gap-4 p-5"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <img
                        v-if="logoUrl"
                        :src="logoUrl"
                        alt=""
                        class="h-14 w-14 shrink-0 rounded-full object-cover"
                    />
                    <div
                        v-else
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Building2" class="h-7 w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl font-medium">{{ property.name }}</h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Los datos del hotel, cada tema en su propia
                            pantalla. Esto es lo que ve el huésped y lo que usa
                            el asistente para responder.
                        </p>
                    </div>
                </div>
                <Button
                    as="a"
                    :href="route('tenant.hotel-settings')"
                    variant="outline-secondary"
                    class="rounded-[0.5rem] bg-white"
                >
                    <Lucide
                        icon="ArrowLeft"
                        class="mr-2 h-4 w-4 stroke-[1.3]"
                    />
                    Volver a Ajustes
                </Button>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-6">
                <Link
                    v-for="card in cards"
                    :key="card.route"
                    :href="route(card.route)"
                    class="box box--stacked col-span-12 flex items-center gap-4 p-5 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide :icon="card.icon" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ card.title }}</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ card.description }}
                        </p>
                        <p
                            class="mt-1 text-xs"
                            :class="
                                card.warn
                                    ? 'font-medium text-warning'
                                    : 'text-slate-400'
                            "
                        >
                            {{ card.detail }}
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                </Link>
            </div>
        </div>
    </RazeLayout>
</template>
