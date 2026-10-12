<script setup lang="ts">
import { FormInput, FormTextarea } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import type { CourtesyForm, CourtesyKind } from './types';

/**
 * Los campos de una cortesía: qué tipo (gratis, porcentaje o monto), cuánto,
 * de qué categoría y por qué. Los usan el diálogo (ficha y plano) y el alta
 * de walk-in, para que en los tres lugares se pida exactamente lo mismo.
 */
const props = withDefaults(
    defineProps<{
        categories: Record<string, string>;
        /** Aprobar con otro monto: el motivo ya lo escribió quien la pidió. */
        hideReason?: boolean;
        disabled?: boolean;
    }>(),
    { hideReason: false, disabled: false },
);

const form = defineModel<CourtesyForm>({ required: true });

const kinds: { key: CourtesyKind; label: string; icon: Icon; hint: string }[] =
    [
        {
            key: 'full',
            label: 'Gratis',
            icon: 'Gift',
            hint: 'El hospedaje completo',
        },
        {
            key: 'percent',
            label: 'Porcentaje',
            icon: 'Percent',
            hint: 'Un % del hospedaje',
        },
        {
            key: 'amount',
            label: 'Monto',
            icon: 'BadgeDollarSign',
            hint: 'Pesos a descontar',
        },
    ];

function pick(kind: CourtesyKind) {
    if (props.disabled) return;
    form.value = { ...form.value, kind, value: '' };
}

const label =
    'mb-1.5 block text-[11px] font-medium tracking-wide text-slate-400 uppercase';
</script>

<template>
    <div class="space-y-4">
        <div>
            <span :class="label">Qué se regala</span>
            <div class="grid grid-cols-3 gap-2">
                <button
                    v-for="option in kinds"
                    :key="option.key"
                    type="button"
                    class="flex flex-col items-start gap-1 rounded-lg border px-3 py-2.5 text-left transition disabled:opacity-60"
                    :class="
                        form.kind === option.key
                            ? 'border-primary/40 bg-primary/5 text-primary'
                            : 'border-slate-200 text-slate-600 hover:border-primary/30 dark:border-darkmode-400 dark:text-slate-300'
                    "
                    :disabled="disabled"
                    @click="pick(option.key)"
                >
                    <span
                        class="inline-flex items-center gap-1.5 text-xs font-medium"
                    >
                        <Lucide :icon="option.icon" class="h-3.5 w-3.5" />
                        {{ option.label }}
                    </span>
                    <span class="text-[11px] text-slate-500">{{
                        option.hint
                    }}</span>
                </button>
            </div>
        </div>

        <div v-if="form.kind !== 'full'" class="max-w-[220px]">
            <span :class="label">{{
                form.kind === 'percent' ? 'Porcentaje' : 'Monto a descontar'
            }}</span>
            <div class="relative">
                <Lucide
                    :icon="form.kind === 'percent' ? 'Percent' : 'DollarSign'"
                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                />
                <FormInput
                    v-model="form.value"
                    type="number"
                    inputmode="decimal"
                    min="0"
                    :max="form.kind === 'percent' ? 100 : undefined"
                    step="0.01"
                    class="h-9 pl-9 text-xs"
                    :placeholder="form.kind === 'percent' ? '50' : '300'"
                    :disabled="disabled"
                />
            </div>
        </div>

        <template v-if="!hideReason">
            <div>
                <span :class="label">Categoría</span>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="(text, key) in categories"
                        :key="key"
                        type="button"
                        class="rounded-full border px-2.5 py-1 text-[11px] font-medium transition disabled:opacity-60"
                        :class="
                            form.category === key
                                ? 'border-primary/40 bg-primary/10 text-primary'
                                : 'border-slate-200 text-slate-500 hover:border-primary/30 hover:text-primary dark:border-darkmode-400'
                        "
                        :disabled="disabled"
                        @click="form = { ...form, category: String(key) }"
                    >
                        {{ text }}
                    </button>
                </div>
            </div>

            <div>
                <span :class="label">Motivo</span>
                <FormTextarea
                    v-model="form.reason"
                    rows="2"
                    maxlength="255"
                    class="text-xs"
                    placeholder="Ej. socio del dueño, compensación por la regadera que falló"
                    :disabled="disabled"
                />
                <p class="mt-1 text-[11px] text-slate-400">
                    Queda en la bitácora y en el reporte de cortesías.
                </p>
            </div>
        </template>
    </div>
</template>
