<script setup lang="ts">
import axios from 'axios';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import CourtesyDialog from './CourtesyDialog.vue';
import { courtesyMoney as money } from './types';
import type { CourtesyAccess, CourtesyPayload, CourtesyTarget } from './types';

/**
 * La franja de cortesía de una cuenta (ficha de la reserva y plano): qué se
 * regaló, o qué espera al propietario, y los botones de quien mira. El
 * propietario aprueba, aprueba con otro monto, rechaza o retira; quien la
 * pidió puede cancelarla; el resto solo la solicita.
 */
const props = withDefaults(
    defineProps<{
        courtesy: CourtesyPayload | null;
        access: CourtesyAccess;
        target: CourtesyTarget;
        /** La cuenta sigue abierta: se pueden dar o cambiar cortesías. */
        live: boolean;
        subtitle?: string | null;
        /** Dentro del plano: sin borde superior propio. */
        bare?: boolean;
    }>(),
    { subtitle: null, bare: false },
);

const emit = defineEmits<{ (e: 'changed'): void }>();

const toast = useToasts();
const dialogOpen = ref(false);
const approveWith = ref<CourtesyPayload | null>(null);
const busy = ref(false);
/** Acción que espera el segundo clic: rechazar, retirar o pasar el tope. */
const confirming = ref<'reject' | 'revoke' | 'cancel' | 'cap' | null>(null);
const capMessage = ref<string | null>(null);

const pending = computed(() => props.courtesy?.status === 'pending');
const approved = computed(() => props.courtesy?.status === 'approved');
const mine = computed(
    () => props.courtesy?.requested_by === props.access.user_id,
);
const canStart = computed(
    () => props.access.enabled && props.live && !props.courtesy,
);

function openGive() {
    approveWith.value = null;
    dialogOpen.value = true;
}

function openApproveWith() {
    approveWith.value = props.courtesy;
    dialogOpen.value = true;
}

async function act(
    action: 'approve' | 'reject' | 'revoke' | 'cancel',
    body: Record<string, unknown> = {},
) {
    if (!props.courtesy || busy.value) return;

    busy.value = true;
    try {
        if (action === 'cancel') {
            await axios.delete(`/api/courtesies/${props.courtesy.id}`);
        } else {
            await axios.post(
                `/api/courtesies/${props.courtesy.id}/${action}`,
                body,
            );
        }

        const titles = {
            approve: 'Cortesía aprobada',
            reject: 'Cortesía rechazada',
            revoke: 'Cortesía retirada',
            cancel: 'Solicitud cancelada',
        };
        toast.success(
            titles[action],
            action === 'revoke'
                ? 'La cuenta volvió a su precio.'
                : action === 'approve'
                  ? 'El descuento ya está en la cuenta.'
                  : 'La cuenta sigue con su precio.',
        );
        confirming.value = null;
        capMessage.value = null;
        emit('changed');
    } catch (error: any) {
        if (error.response?.status === 409) {
            capMessage.value = error.response.data.message;
            confirming.value = 'cap';
        } else {
            toast.error(
                'No se pudo completar',
                error.response?.data?.message ?? 'Ocurrió un error.',
            );
        }
    } finally {
        busy.value = false;
    }
}

const ghost =
    'font-medium text-slate-500 transition disabled:opacity-50 hover:text-primary';
</script>

<template>
    <div
        v-if="courtesy || canStart"
        class="flex flex-wrap items-center gap-x-2 gap-y-2 px-4 py-3 text-xs"
        :class="
            bare ? '' : 'border-t border-slate-200/60 dark:border-darkmode-400'
        "
    >
        <Lucide icon="HandHeart" class="h-3.5 w-3.5 shrink-0 text-pending" />

        <!-- Aprobada -->
        <template v-if="approved && courtesy">
            <span
                class="inline-flex items-center gap-1.5 rounded-full bg-pending/10 px-2.5 py-1 text-[11px] font-medium text-pending"
            >
                Cortesía · {{ courtesy.kind_label }}
                <span>−{{ money(courtesy.amount) }}</span>
            </span>
            <span class="min-w-0 text-slate-500">
                {{ courtesy.category_label }}:
                <span class="text-slate-600 dark:text-slate-300">{{
                    courtesy.reason
                }}</span>
                <template v-if="courtesy.decided_by_name">
                    · la dio {{ courtesy.decided_by_name }}</template
                >
                <template v-if="courtesy.over_cap">
                    · pasó el tope del mes</template
                >
            </span>
            <template v-if="access.is_owner && live">
                <template v-if="confirming === 'revoke'">
                    <span class="ml-auto text-slate-500"
                        >¿Retirar y cobrar completo?</span
                    >
                    <button
                        type="button"
                        class="font-medium text-danger disabled:opacity-50"
                        :disabled="busy"
                        @click="act('revoke')"
                    >
                        Sí, retirar
                    </button>
                    <button
                        type="button"
                        :class="ghost"
                        @click="confirming = null"
                    >
                        No
                    </button>
                </template>
                <button
                    v-else
                    type="button"
                    class="ml-auto font-medium text-slate-500 hover:text-danger"
                    @click="confirming = 'revoke'"
                >
                    Retirar cortesía
                </button>
            </template>
        </template>

        <!-- Esperando al propietario -->
        <template v-else-if="pending && courtesy">
            <span
                class="inline-flex items-center gap-1.5 rounded-full bg-warning/10 px-2.5 py-1 text-[11px] font-medium text-warning"
            >
                <Lucide icon="Clock" class="h-3 w-3" />
                Esperando al propietario
            </span>
            <span class="min-w-0 text-slate-500">
                {{ courtesy.requested_by_name }} pide
                {{ courtesy.kind_label.toLowerCase() }} (−{{
                    money(courtesy.amount)
                }}) ·
                <span class="text-slate-600 dark:text-slate-300">{{
                    courtesy.reason
                }}</span>
            </span>

            <div
                v-if="access.is_owner && confirming !== 'cap'"
                class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto"
            >
                <template v-if="confirming === 'reject'">
                    <span class="text-slate-500">¿Rechazarla?</span>
                    <button
                        type="button"
                        class="font-medium text-danger disabled:opacity-50"
                        :disabled="busy"
                        @click="act('reject')"
                    >
                        Sí, rechazar
                    </button>
                    <button
                        type="button"
                        :class="ghost"
                        @click="confirming = null"
                    >
                        No
                    </button>
                </template>
                <template v-else>
                    <button
                        type="button"
                        class="font-medium text-slate-500 hover:text-danger"
                        :disabled="busy"
                        @click="confirming = 'reject'"
                    >
                        Rechazar
                    </button>
                    <Button
                        variant="outline-secondary"
                        class="h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                        :disabled="busy"
                        @click="openApproveWith"
                    >
                        Otro monto
                    </Button>
                    <Button
                        variant="primary"
                        class="h-8 rounded-[0.5rem] text-xs"
                        :disabled="busy"
                        @click="act('approve')"
                    >
                        <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                        Aprobar
                    </Button>
                </template>
            </div>

            <div
                v-else-if="access.is_owner && confirming === 'cap'"
                class="flex w-full flex-wrap items-center gap-2 rounded-lg border border-danger/20 bg-danger/5 px-3 py-2"
            >
                <Lucide
                    icon="TriangleAlert"
                    class="h-3.5 w-3.5 shrink-0 text-danger"
                />
                <span
                    class="min-w-0 flex-1 text-slate-600 dark:text-slate-300"
                    >{{ capMessage }}</span
                >
                <button type="button" :class="ghost" @click="confirming = null">
                    Cancelar
                </button>
                <Button
                    variant="danger"
                    class="h-8 rounded-[0.5rem] text-xs"
                    :disabled="busy"
                    @click="act('approve', { confirm_over_cap: true })"
                >
                    Aprobar de todos modos
                </Button>
            </div>

            <template v-else-if="mine">
                <span class="w-full text-[11px] text-slate-400 sm:w-auto">
                    No cobres esa parte todavía.
                </span>
                <template v-if="confirming === 'cancel'">
                    <button
                        type="button"
                        class="font-medium text-danger disabled:opacity-50 sm:ml-auto"
                        :disabled="busy"
                        @click="act('cancel')"
                    >
                        Sí, cancelar solicitud
                    </button>
                    <button
                        type="button"
                        :class="ghost"
                        @click="confirming = null"
                    >
                        No
                    </button>
                </template>
                <button
                    v-else
                    type="button"
                    class="font-medium text-slate-500 hover:text-danger sm:ml-auto"
                    @click="confirming = 'cancel'"
                >
                    Cancelar solicitud
                </button>
            </template>
            <span v-else class="w-full text-[11px] text-slate-400 sm:w-auto">
                No cobres esa parte todavía.
            </span>
        </template>

        <!-- Sin cortesía -->
        <template v-else>
            <span class="text-slate-500">
                {{
                    access.is_owner
                        ? 'Hospedaje gratis o con descuento para un cliente importante o conocido.'
                        : 'Hospedaje gratis o con descuento: lo aprueba el propietario.'
                }}
            </span>
            <Button
                variant="outline-secondary"
                class="ml-auto h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                @click="openGive"
            >
                <Lucide icon="HandHeart" class="mr-1.5 h-3.5 w-3.5" />
                {{ access.is_owner ? 'Dar cortesía' : 'Solicitar cortesía' }}
            </Button>
        </template>

        <CourtesyDialog
            :open="dialogOpen"
            :target="target"
            :is-owner="access.is_owner"
            :categories="access.categories"
            :subtitle="subtitle"
            :courtesy="approveWith"
            @close="dialogOpen = false"
            @done="emit('changed')"
        />
    </div>
</template>
