<script setup lang="ts">
import { computed } from 'vue';
import Lucide from '@/components/Base/Lucide';
import CourtesyFields from './CourtesyFields.vue';
import { courtesyMoney as money, estimateCourtesy } from './types';
import type { CourtesyForm } from './types';
import { useCourtesyAccess } from './useCourtesyAccess';

/**
 * El apartado "Cortesía" de los formularios de alta (registro exprés,
 * llegó sin reserva, apartar para otra fecha y nueva reserva). Va plegado:
 * se abre con un botón y la cortesía viaja en el mismo alta. La del
 * propietario nace aplicada; la del resto queda esperando su aprobación.
 */
const props = withDefaults(
    defineProps<{
        /** Hospedaje estimado (tarifa y persona extra) para el estimado. */
        lodging: number;
        disabled?: boolean;
        /** Mensaje del 409: la cortesía pasa el tope del mes. */
        capWarning?: string | null;
        /** Qué pasa con el cobro si la pide alguien que no es propietario. */
        pendingNote?: string;
    }>(),
    {
        disabled: false,
        capWarning: null,
        pendingNote:
            'Se le pide al propietario. El registro se guarda igual con el precio normal; si aprueba, el descuento entra solo.',
    },
);

const on = defineModel<boolean>('on', { required: true });
const form = defineModel<CourtesyForm>('form', { required: true });

const access = useCourtesyAccess();
const discount = computed(() =>
    access.value.is_owner ? estimateCourtesy(form.value, props.lodging) : 0,
);
</script>

<template>
    <section
        v-if="access.enabled"
        class="border-t border-slate-200/60 pt-5 dark:border-darkmode-400"
    >
        <div class="flex flex-wrap items-center gap-2">
            <div
                class="flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                <Lucide icon="HandHeart" class="h-3.5 w-3.5" />
                Cortesía
            </div>
            <span v-if="!on" class="text-xs text-slate-500">
                {{
                    access.is_owner
                        ? 'Gratis o con descuento para un cliente importante o conocido.'
                        : 'Gratis o con descuento; la aprueba el propietario.'
                }}
            </span>
            <button
                type="button"
                class="ml-auto inline-flex h-8 items-center gap-1.5 rounded-[0.5rem] border px-3 text-xs font-medium transition disabled:opacity-60"
                :class="
                    on
                        ? 'border-pending/40 bg-pending/10 text-pending'
                        : 'border-slate-200 text-slate-500 hover:border-pending/30 hover:text-pending dark:border-darkmode-400'
                "
                :disabled="disabled"
                @click="on = !on"
            >
                <Lucide :icon="on ? 'X' : 'Plus'" class="h-3.5 w-3.5" />
                {{
                    on
                        ? 'Quitar cortesía'
                        : access.is_owner
                          ? 'Dar cortesía'
                          : 'Solicitar cortesía'
                }}
            </button>
        </div>

        <template v-if="on">
            <div class="mt-3">
                <CourtesyFields
                    v-model="form"
                    :categories="access.categories"
                    :disabled="disabled"
                />
            </div>
            <p
                v-if="access.is_owner"
                class="mt-3 rounded-lg bg-pending/5 px-3 py-2 text-xs text-slate-600 dark:text-slate-300"
            >
                Cortesía estimada
                <span class="font-medium text-pending"
                    >−{{ money(discount) }}</span
                >
                sobre el hospedaje de {{ money(lodging) }}: quedan
                {{ money(Math.max(0, lodging - discount)) }}.
            </p>
            <p
                v-else
                class="mt-3 rounded-lg bg-warning/5 px-3 py-2 text-xs text-slate-600 dark:text-slate-300"
            >
                {{ pendingNote }}
            </p>
            <div
                v-if="capWarning"
                class="mt-3 flex items-start gap-2 rounded-lg border border-danger/20 bg-danger/5 px-3 py-2 text-xs"
            >
                <Lucide
                    icon="TriangleAlert"
                    class="mt-0.5 h-3.5 w-3.5 shrink-0 text-danger"
                />
                <span
                    >{{ capWarning }} Vuelve a guardar para confirmar que sí la
                    das.</span
                >
            </div>
        </template>
    </section>
</template>
