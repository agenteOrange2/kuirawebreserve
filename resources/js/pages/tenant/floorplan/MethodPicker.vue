<script setup lang="ts">
import { FormInput } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useCounterMethods } from '@/composables/useCounterMethods';
import type { CounterMethod } from '@/composables/useCounterMethods';

/**
 * Con qué se cobra en el mostrador: los métodos que acepta el hotel
 * (/ajustes/metodos-pago → Políticas) y la referencia si no es efectivo.
 * Lo usan cobrar el saldo y extender cobrando.
 */
defineProps<{ disabled?: boolean }>();

const method = defineModel<CounterMethod>('method', { required: true });
const reference = defineModel<string>('reference', { default: '' });

const { methods } = useCounterMethods();
</script>

<template>
    <div class="space-y-3">
        <div
            class="grid gap-2"
            :class="methods.length > 2 ? 'grid-cols-3' : 'grid-cols-2'"
        >
            <button
                v-for="option in methods"
                :key="option.key"
                type="button"
                class="flex h-9 items-center justify-center gap-2 rounded-[0.5rem] border text-xs font-medium transition disabled:opacity-60"
                :class="
                    method === option.key
                        ? 'border-primary bg-primary/5 text-primary'
                        : 'border-slate-200/70 text-slate-500 dark:border-darkmode-400'
                "
                :disabled="disabled"
                @click="method = option.key"
            >
                <Lucide :icon="option.icon" class="h-3.5 w-3.5" />
                {{ option.label }}
            </button>
        </div>
        <FormInput
            v-if="method !== 'cash'"
            v-model="reference"
            class="h-9 text-xs"
            placeholder="Referencia o autorización (opcional)"
            maxlength="100"
            :disabled="disabled"
        />
    </div>
</template>
