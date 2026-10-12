<script setup lang="ts">
import { ref } from 'vue';
import Lucide from '@/components/Base/Lucide';

export interface BankAccount {
    banco: string;
    titular: string;
    cuenta: string;
    tipo?: string;
    aviso?: string | null;
    // La tarjeta, para quien en su app solo puede transferir a tarjeta.
    alternativa?: {
        cuenta: string;
        tipo: string;
        aviso?: string | null;
    } | null;
}

/**
 * Cuentas del hotel para transferir, cada número con su botón de copiar
 * (dictar 18 dígitos de una CLABE a mano es donde se equivoca la gente).
 * Lo usan el pago del wizard y la consulta de reserva.
 */
defineProps<{ accounts: BankAccount[] }>();

const copied = ref<string | null>(null);
async function copy(text: string) {
    try {
        await navigator.clipboard.writeText(text.replace(/\s/g, ''));
        copied.value = text;
        window.setTimeout(() => (copied.value = null), 2000);
    } catch {
        // El número sigue a la vista si el navegador no deja copiar.
    }
}

const copyBtn =
    'inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 transition hover:border-primary/40 hover:text-primary';
</script>

<template>
    <div class="space-y-2">
        <div
            v-for="acc in accounts"
            :key="acc.cuenta"
            class="rounded-xl border border-slate-200 p-3.5 text-sm"
        >
            <div class="font-medium text-slate-700">{{ acc.banco }}</div>
            <div class="text-slate-500">{{ acc.titular }}</div>
            <div
                class="mt-2 flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2"
            >
                <div class="min-w-0">
                    <div v-if="acc.tipo" class="text-xs text-slate-500">
                        {{ acc.tipo }}
                    </div>
                    <div class="font-mono text-sm break-all text-slate-800">
                        {{ acc.cuenta }}
                    </div>
                </div>
                <button
                    type="button"
                    :class="copyBtn"
                    @click="copy(acc.cuenta)"
                >
                    <Lucide
                        :icon="copied === acc.cuenta ? 'Check' : 'Copy'"
                        class="h-3.5 w-3.5"
                    />
                    {{ copied === acc.cuenta ? 'Copiado' : 'Copiar' }}
                </button>
            </div>
            <div v-if="acc.aviso" class="mt-1.5 text-xs text-pending">
                {{ acc.aviso }}
            </div>
            <div
                v-if="acc.alternativa"
                class="mt-2 flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2"
            >
                <div class="min-w-0">
                    <div class="text-xs text-slate-500">
                        Si tu app solo transfiere a tarjeta ·
                        {{ acc.alternativa.tipo }}
                    </div>
                    <div class="font-mono text-sm break-all text-slate-800">
                        {{ acc.alternativa.cuenta }}
                    </div>
                </div>
                <button
                    type="button"
                    :class="copyBtn"
                    @click="copy(acc.alternativa.cuenta)"
                >
                    <Lucide
                        :icon="
                            copied === acc.alternativa.cuenta ? 'Check' : 'Copy'
                        "
                        class="h-3.5 w-3.5"
                    />
                    {{
                        copied === acc.alternativa.cuenta ? 'Copiado' : 'Copiar'
                    }}
                </button>
            </div>
        </div>
    </div>
</template>
