<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';

// Página pública (como el webchat): el huésped aterriza aquí al volver del
// checkout. Mientras el pago siga pendiente, se refresca sola — la verdad
// del estado la pone el webhook, no esta vista.
interface Social {
    type: string;
    url: string;
    icon: Icon;
}

const props = defineProps<{
    hotel: {
        name: string;
        website: string | null;
        maps_url: string | null;
        socials: Social[];
    };
    lookupUrl: string;
    notified: { email: boolean; whatsapp: boolean };
    secondary: { code: string; label: string } | null;
    payment: {
        status: string;
        status_label: string;
        concept: string;
        amount_label: string;
        reservation_code: string | null;
        reservation_confirmed: boolean;
        checkout_url: string | null;
    };
}>();

let poller: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    if (props.payment.status === 'pending') {
        poller = setInterval(() => router.reload(), 7000);
    }
});
onBeforeUnmount(() => {
    if (poller) clearInterval(poller);
});
</script>

<template>
    <div
        class="flex min-h-screen items-center justify-center bg-slate-100 px-4 dark:bg-darkmode-800"
    >
        <div class="box box--stacked w-full max-w-md p-8 text-center">
            <template v-if="payment.status === 'paid'">
                <div
                    class="mx-auto flex h-14 w-14 items-center justify-center rounded-full border border-success/10 bg-success/10"
                >
                    <Lucide icon="CircleCheck" class="h-7 w-7 text-success" />
                </div>
                <h1 class="mt-4 text-lg font-medium">Pago recibido</h1>
                <p class="mt-2 text-sm text-slate-500">
                    Recibimos tu {{ payment.concept.toLowerCase() }} de
                    {{ payment.amount_label
                    }}<template v-if="secondary"> ({{ secondary.label }})</template>.
                    <template v-if="payment.reservation_confirmed">
                        Tu reserva {{ payment.reservation_code }} está
                        confirmada. Te esperamos en {{ hotel.name }}.
                    </template>
                    <template v-else>
                        Quedó registrado en tu reserva
                        {{ payment.reservation_code }}.
                    </template>
                </p>
                <p
                    v-if="notified.email || notified.whatsapp"
                    class="mt-2 text-xs text-slate-400"
                >
                    Te enviamos los detalles
                    <template v-if="notified.email && notified.whatsapp"
                        >por correo y WhatsApp</template
                    >
                    <template v-else-if="notified.email">a tu correo</template>
                    <template v-else>por WhatsApp</template>.
                </p>
                <div class="mt-5 flex flex-col gap-2">
                    <Button
                        v-if="hotel.website"
                        as="a"
                        :href="hotel.website"
                        target="_blank"
                        variant="primary"
                        class="rounded-[0.5rem]"
                    >
                        <Lucide icon="Globe" class="mr-2 h-4 w-4" /> Volver al
                        sitio de {{ hotel.name }}
                    </Button>
                    <Button
                        as="a"
                        :href="lookupUrl"
                        :variant="hotel.website ? 'outline-secondary' : 'primary'"
                        class="rounded-[0.5rem]"
                        :class="hotel.website ? 'bg-white' : ''"
                    >
                        <Lucide icon="CalendarCheck" class="mr-2 h-4 w-4" />
                        Consultar mi reserva
                    </Button>
                </div>
            </template>

            <template v-else-if="payment.status === 'pending'">
                <div
                    class="mx-auto flex h-14 w-14 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                >
                    <Lucide
                        icon="RefreshCw"
                        class="h-7 w-7 animate-spin text-primary"
                    />
                </div>
                <h1 class="mt-4 text-lg font-medium">Confirmando tu pago…</h1>
                <p class="mt-2 text-sm text-slate-500">
                    En cuanto el banco lo confirme, tu reserva
                    {{ payment.reservation_code }} quedará lista y te avisaremos
                    por el chat. Esta página se actualiza sola; puedes cerrarla
                    sin problema.
                </p>
                <Button
                    v-if="payment.checkout_url"
                    as="a"
                    :href="payment.checkout_url"
                    variant="primary"
                    class="mt-5 rounded-[0.5rem]"
                >
                    <Lucide icon="CreditCard" class="mr-2 h-4 w-4" /> Volver al
                    pago
                </Button>
            </template>

            <template v-else>
                <div
                    class="mx-auto flex h-14 w-14 items-center justify-center rounded-full border border-warning/10 bg-warning/10"
                >
                    <Lucide icon="Clock" class="h-7 w-7 text-warning" />
                </div>
                <h1 class="mt-4 text-lg font-medium">
                    Este cobro ya no está disponible
                </h1>
                <p class="mt-2 text-sm text-slate-500">
                    La solicitud de pago está
                    {{ payment.status_label.toLowerCase() }}. Escríbenos por el
                    chat de {{ hotel.name }} y te generamos una nueva al momento.
                </p>
            </template>

            <!-- Síguenos: los canales del hotel para todos los medios -->
            <div
                v-if="hotel.socials.length"
                class="mt-6 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
            >
                <p class="text-xs text-slate-400">Síguenos</p>
                <div class="mt-2 flex flex-wrap items-center justify-center gap-2">
                    <a
                        v-for="social in hotel.socials"
                        :key="social.url"
                        :href="social.url"
                        target="_blank"
                        class="flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:border-primary/40 hover:text-primary dark:border-darkmode-400"
                        :title="social.type"
                    >
                        <Lucide :icon="social.icon" class="h-4 w-4" />
                    </a>
                </div>
            </div>

            <p class="mt-6 text-xs text-slate-400">{{ hotel.name }}</p>
        </div>
    </div>
</template>
