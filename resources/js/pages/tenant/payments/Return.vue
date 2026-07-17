<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';

// Página pública (como el webchat): el huésped aterriza aquí al volver del
// checkout. Mientras el pago siga pendiente, se refresca sola — la verdad
// del estado la pone el webhook, no esta vista.
const props = defineProps<{
    hotel: string;
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
    <div class="flex min-h-screen items-center justify-center bg-slate-100 px-4 dark:bg-darkmode-800">
        <div class="box box--stacked w-full max-w-md p-8 text-center">
            <template v-if="payment.status === 'paid'">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full border border-success/10 bg-success/10">
                    <Lucide icon="CircleCheck" class="h-7 w-7 text-success" />
                </div>
                <h1 class="mt-4 text-lg font-medium">Pago recibido</h1>
                <p class="mt-2 text-sm text-slate-500">
                    Recibimos tu {{ payment.concept.toLowerCase() }} de {{ payment.amount_label }}.
                    <template v-if="payment.reservation_confirmed">
                        Tu reserva {{ payment.reservation_code }} está confirmada. Te esperamos en {{ hotel }}.
                    </template>
                    <template v-else> Quedó registrado en tu reserva {{ payment.reservation_code }}. </template>
                </p>
            </template>

            <template v-else-if="payment.status === 'pending'">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                    <Lucide icon="RefreshCw" class="h-7 w-7 animate-spin text-primary" />
                </div>
                <h1 class="mt-4 text-lg font-medium">Confirmando tu pago…</h1>
                <p class="mt-2 text-sm text-slate-500">
                    En cuanto el banco lo confirme, tu reserva {{ payment.reservation_code }} quedará lista y te avisaremos por el chat.
                    Esta página se actualiza sola; puedes cerrarla sin problema.
                </p>
                <Button v-if="payment.checkout_url" as="a" :href="payment.checkout_url" variant="primary" class="mt-5 rounded-[0.5rem]">
                    <Lucide icon="CreditCard" class="mr-2 h-4 w-4" /> Volver al pago
                </Button>
            </template>

            <template v-else>
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full border border-warning/10 bg-warning/10">
                    <Lucide icon="Clock" class="h-7 w-7 text-warning" />
                </div>
                <h1 class="mt-4 text-lg font-medium">Este cobro ya no está disponible</h1>
                <p class="mt-2 text-sm text-slate-500">
                    La solicitud de pago está {{ payment.status_label.toLowerCase() }}. Escríbenos por el chat de {{ hotel }} y te generamos una nueva al momento.
                </p>
            </template>

            <p class="mt-6 text-xs text-slate-400">{{ hotel }}</p>
        </div>
    </div>
</template>
