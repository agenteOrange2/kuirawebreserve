<script setup lang="ts">
import { computed } from 'vue';
import Lucide from '@/components/Base/Lucide';
import { formatMoney } from '../format';
import type { CheckoutFolio } from '../types';

/**
 * Llevarse la cuenta: imprimirla o mandársela al huésped.
 *
 * El PDF se descarga del servidor (mismo camino que el corte de caja). El
 * envío por WhatsApp abre wa.me con el resumen ESCRITO, no con un link al
 * archivo: el PDF vive detrás del permiso del panel y publicar la cuenta de un
 * huésped para poder adjuntarla sería regalar sus datos.
 */
const props = defineProps<{ folio: CheckoutFolio }>();

const phone = computed(() => {
    const raw = (props.folio.stay.guest_phone ?? '').replace(/\D/g, '');

    if (raw === '') {
        return null;
    }

    // Diez dígitos = número nacional; wa.me los quiere con lada de país.
    return raw.length === 10 ? `52${raw}` : raw;
});

const message = computed(() => {
    const folio = props.folio;
    const lines = [
        `Cuenta de la habitación ${folio.stay.room ?? ''}`.trim(),
        `Hospedaje: ${formatMoney(folio.lodging_total)}`,
    ];

    if (folio.consumption.length) {
        const consumed = folio.consumption.reduce(
            (sum, order) => sum + Number(order.total),
            0,
        );
        lines.push(`Consumos: ${formatMoney(consumed)}`);
    }

    lines.push(
        folio.grand_pending > 0
            ? `Por pagar: ${formatMoney(folio.grand_pending)}`
            : 'Cuenta saldada. ¡Gracias por su visita!',
    );

    return lines.join('\n');
});

const whatsappUrl = computed(() =>
    phone.value
        ? `https://wa.me/${phone.value}?text=${encodeURIComponent(message.value)}`
        : null,
);
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <a
            :href="`/estancias/${folio.stay.id}/cuenta.pdf`"
            target="_blank"
            rel="noopener"
            class="inline-flex min-h-10 items-center gap-2 rounded-[0.5rem] border border-slate-200 px-3 text-sm text-slate-600 transition hover:border-primary/40 hover:text-primary dark:border-darkmode-400 dark:text-slate-300"
            title="Descarga la cuenta en PDF para imprimirla"
        >
            <Lucide icon="Printer" class="h-4 w-4" />
            Imprimir cuenta
        </a>
        <a
            v-if="whatsappUrl"
            :href="whatsappUrl"
            target="_blank"
            rel="noopener"
            class="inline-flex min-h-10 items-center gap-2 rounded-[0.5rem] border border-slate-200 px-3 text-sm text-slate-600 transition hover:border-primary/40 hover:text-primary dark:border-darkmode-400 dark:text-slate-300"
            title="Abre WhatsApp con el resumen de la cuenta"
        >
            <Lucide icon="MessageCircle" class="h-4 w-4" />
            Mandar por WhatsApp
        </a>
        <span
            v-else
            class="inline-flex min-h-10 items-center text-xs text-slate-500"
            >El huésped no tiene teléfono registrado.</span
        >
    </div>
</template>
