<script setup lang="ts">
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import PlanModal from '@/pages/tenant/floorplan/PlanModal.vue';
import CourtesyFields from './CourtesyFields.vue';
import {
    courtesyBody,
    courtesyFormReady,
    courtesyMoney as money,
    emptyCourtesyForm,
} from './types';
import type {
    CourtesyForm,
    CourtesyPayload,
    CourtesyPreview,
    CourtesyTarget,
} from './types';

/**
 * Dar (propietario) o solicitar (resto del personal) una cortesía, o
 * aprobar una solicitud con otro monto. Muestra en vivo cómo queda la
 * cuenta y cuánto lleva regalado el mes contra el tope; si la cortesía pasa
 * el tope, el servidor responde 409 y el botón pide confirmar otra vez.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        target: CourtesyTarget;
        isOwner: boolean;
        categories: Record<string, string>;
        /** Huésped y cuenta, para que se sepa a quién se le regala. */
        subtitle?: string | null;
        /** `approve`: el propietario aprueba esta solicitud con otro monto. */
        courtesy?: CourtesyPayload | null;
    }>(),
    { subtitle: null, courtesy: null },
);

const emit = defineEmits<{
    (e: 'close'): void;
    (
        e: 'done',
        data: { courtesy: CourtesyPayload; total: number; refund_due: number },
    ): void;
}>();

const toast = useToasts();
const approving = computed(() => props.courtesy !== null);

const form = ref<CourtesyForm>(emptyCourtesyForm());
const preview = ref<CourtesyPreview | null>(null);
const busy = ref(false);
/** Mensaje del 409: con él, el siguiente envío confirma que pasa el tope. */
const capWarning = ref<string | null>(null);

watch(
    () => props.open,
    (open) => {
        if (!open) return;

        capWarning.value = null;
        preview.value = null;
        form.value = props.courtesy
            ? {
                  kind: props.courtesy.kind,
                  value:
                      props.courtesy.kind === 'full'
                          ? ''
                          : props.courtesy.value,
                  category: props.courtesy.category,
                  reason: props.courtesy.reason,
              }
            : emptyCourtesyForm();
        loadPreview();
    },
);

let timer: ReturnType<typeof setTimeout> | null = null;
watch(
    () => [form.value.kind, form.value.value],
    () => {
        capWarning.value = null;
        if (timer) clearTimeout(timer);
        timer = setTimeout(loadPreview, 250);
    },
);

async function loadPreview() {
    if (!props.open || !courtesyFormReady(form.value, false)) {
        preview.value = null;
        return;
    }

    try {
        const { data } = await axios.get<CourtesyPreview>(
            '/api/courtesies/preview',
            {
                params: {
                    ...props.target,
                    ...courtesyBody(form.value),
                    courtesy_id: props.courtesy?.id,
                },
            },
        );
        preview.value = data;
    } catch {
        preview.value = null;
    }
}

const ready = computed(
    () => !busy.value && courtesyFormReady(form.value, !approving.value),
);

const submitLabel = computed(() => {
    if (busy.value) return 'Guardando…';
    if (capWarning.value) return 'Sí, pasar el tope';
    if (approving.value) return 'Aprobar';

    return props.isOwner ? 'Dar cortesía' : 'Solicitar al propietario';
});

async function submit() {
    if (!ready.value) return;

    busy.value = true;
    const confirm = capWarning.value !== null;

    try {
        const url = approving.value
            ? `/api/courtesies/${props.courtesy!.id}/approve`
            : props.target.reservation_id
              ? `/api/reservations/${props.target.reservation_id}/courtesy`
              : `/api/stays/${props.target.stay_id}/courtesy`;

        const body = approving.value
            ? {
                  kind: form.value.kind,
                  value: courtesyBody(form.value).value,
                  confirm_over_cap: confirm,
              }
            : { ...courtesyBody(form.value), confirm_over_cap: confirm };

        const { data } = await axios.post(url, body);

        toast.success(
            data.courtesy.status === 'pending'
                ? 'Solicitud enviada'
                : 'Cortesía aplicada',
            data.courtesy.status === 'pending'
                ? 'Se le avisó al propietario. Mientras decide, la cuenta sigue con su precio.'
                : `La cuenta quedó en ${money(data.total)}.`,
        );
        emit('done', data);
        emit('close');
    } catch (error: any) {
        if (error.response?.status === 409) {
            capWarning.value = error.response.data.message;
        } else {
            toast.error(
                'No se pudo guardar la cortesía',
                error.response?.data?.message ?? 'Ocurrió un error.',
            );
        }
    } finally {
        busy.value = false;
    }
}

const capPercent = computed(() => {
    const p = preview.value;
    if (!p || !p.cap) return 0;

    return Math.min(100, Math.round(((p.month_used + p.amount) / p.cap) * 100));
});
</script>

<template>
    <PlanModal
        :open="open"
        icon="HandHeart"
        tone="pending"
        :title="
            approving
                ? 'Aprobar con otro monto'
                : isOwner
                  ? 'Dar cortesía'
                  : 'Solicitar cortesía'
        "
        :subtitle="subtitle"
        panel-class="sm:w-[94vw] lg:w-[620px]"
        static-backdrop
        :busy="busy"
        @close="emit('close')"
    >
        <div
            v-if="approving && courtesy"
            class="rounded-lg border border-slate-200/70 bg-slate-50/70 px-3.5 py-2.5 text-xs dark:border-darkmode-400 dark:bg-darkmode-600"
        >
            <span class="font-medium">{{ courtesy.requested_by_name }}</span>
            pidió {{ courtesy.kind_label.toLowerCase() }} ({{
                money(courtesy.amount)
            }}).
            <span class="text-slate-500">Motivo: {{ courtesy.reason }}</span>
        </div>

        <CourtesyFields
            v-model="form"
            :categories="categories"
            :hide-reason="approving"
            :disabled="busy"
        />

        <!-- Cómo queda la cuenta -->
        <div
            v-if="preview"
            class="overflow-hidden rounded-lg border border-slate-200/70 text-xs dark:border-darkmode-400"
        >
            <div
                class="grid grid-cols-3 divide-x divide-slate-200/70 dark:divide-darkmode-400"
            >
                <div class="px-3 py-2.5">
                    <div class="text-[11px] text-slate-500">Hospedaje</div>
                    <div class="mt-0.5 text-sm font-medium">
                        {{ money(preview.lodging_base) }}
                    </div>
                </div>
                <div class="bg-pending/5 px-3 py-2.5">
                    <div class="text-[11px] text-slate-500">Cortesía</div>
                    <div class="mt-0.5 text-sm font-medium text-pending">
                        −{{ money(preview.amount) }}
                    </div>
                </div>
                <div class="px-3 py-2.5">
                    <div class="text-[11px] text-slate-500">Total nuevo</div>
                    <div class="mt-0.5 text-sm font-medium">
                        {{ money(preview.total_after) }}
                    </div>
                    <div class="text-[11px] text-slate-400">
                        antes {{ money(preview.total_before) }}
                    </div>
                </div>
            </div>

            <div
                v-if="preview.coupon_code"
                class="flex items-start gap-2 border-t border-slate-200/70 px-3 py-2 text-slate-500 dark:border-darkmode-400"
            >
                <Lucide
                    icon="TicketPercent"
                    class="mt-0.5 h-3.5 w-3.5 shrink-0"
                />
                Se retira el cupón {{ preview.coupon_code }} (−{{
                    money(preview.coupon_discount)
                }}): cupón y cortesía no se acumulan.
            </div>

            <div
                v-if="preview.refund_due > 0"
                class="flex items-start gap-2 border-t border-slate-200/70 bg-warning/5 px-3 py-2 dark:border-darkmode-400"
            >
                <Lucide
                    icon="Undo2"
                    class="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning"
                />
                Ya pagó {{ money(preview.paid) }}: quedará un saldo a favor del
                huésped de {{ money(preview.refund_due) }} para devolver.
            </div>

            <div
                v-if="preview.cap"
                class="border-t border-slate-200/70 px-3 py-2 dark:border-darkmode-400"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="text-slate-500">Cortesías del mes</span>
                    <span
                        class="font-medium"
                        :class="preview.over_cap ? 'text-danger' : ''"
                    >
                        {{ money(preview.month_used + preview.amount) }} de
                        {{ money(preview.cap) }}
                    </span>
                </div>
                <div
                    class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                >
                    <div
                        class="h-full rounded-full"
                        :class="preview.over_cap ? 'bg-danger' : 'bg-pending'"
                        :style="{ width: `${capPercent}%` }"
                    />
                </div>
            </div>
        </div>

        <div
            v-if="capWarning"
            class="flex items-start gap-2 rounded-lg border border-danger/20 bg-danger/5 px-3.5 py-2.5 text-xs"
        >
            <Lucide
                icon="TriangleAlert"
                class="mt-0.5 h-3.5 w-3.5 shrink-0 text-danger"
            />
            <div>
                <div class="font-medium text-danger">Pasa el tope del mes</div>
                <div class="mt-0.5 text-slate-600 dark:text-slate-300">
                    {{ capWarning }} Si de todos modos la quieres dar,
                    confírmalo otra vez.
                </div>
            </div>
        </div>

        <template #footer-start>
            <template v-if="!isOwner && !approving">
                Se le avisa al propietario. Mientras decide, la cuenta sigue con
                su precio: no cobres esa parte todavía.
            </template>
        </template>
        <template #footer>
            <Button
                variant="outline-secondary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="busy"
                @click="emit('close')"
            >
                Cancelar
            </Button>
            <Button
                :variant="capWarning ? 'danger' : 'primary'"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="!ready"
                @click="submit"
            >
                <Lucide icon="HandHeart" class="mr-1.5 h-3.5 w-3.5" />
                {{ submitLabel }}
            </Button>
        </template>
    </PlanModal>
</template>
