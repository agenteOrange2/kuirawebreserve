<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { CashScopeTotals } from '@/composables/useCashSnapshot';
import { useCashSnapshot } from '@/composables/useCashSnapshot';
import { formatMoney } from '../format';

/**
 * Cuerpo del modal de caja: cómo va el corte en curso, su rastro, y cerrarlo.
 *
 * Se puede cerrar de dos maneras, que son dos momentos distintos:
 *  - **Cerrar turno y cortar**: el cambio de guardia. Cierra el turno y genera
 *    los cortes por ámbito del periodo exacto del turno (lo mismo que /turnos
 *    con auto-corte).
 *  - **Cerrar solo esta caja**: cortar un ámbito a media jornada, con motivo.
 *
 * El efectivo contado es OPCIONAL: con él queda el arqueo y la diferencia, sin
 * él el corte se guarda igual. Frenar el cierre por no poder contar el cajón
 * —o por ser un ámbito de pura tarjeta— era peor que un corte sin arqueo.
 * El detalle contable con PDF sigue en /cortes.
 *
 * Los datos salen de `useCashSnapshot`, el mismo que alimenta el chip de la
 * barra: dos consultas darían dos cifras distintas con dinero a la vista.
 */
const emit = defineEmits<{
    (e: 'error', message: string): void;
}>();

const {
    state,
    loading,
    detailScope,
    load,
    openShift,
    toggleDetail,
    closeScope,
    closeShift,
} = useCashSnapshot();

// El turno se abre y se cierra para quien está operando.
const page = usePage();
const userId = computed(
    () => (page.props.auth as { user?: { id?: number } } | undefined)?.user?.id,
);

const openingCash = ref<string>('');
const opening = ref(false);
const busy = ref(false);

/** Ámbito con el formulario de cierre abierto, y lo que se captura ahí. */
const closingKey = ref<string | null>(null);
const reason = ref('');
const countedCash = ref<string>('');
const closingShift = ref(false);

function startClose(scope: CashScopeTotals) {
    closingKey.value = scope.key;
    reason.value = '';
    countedCash.value = '';
    closingShift.value = false;
}

function startCloseShift() {
    closingKey.value = 'shift';
    reason.value = '';
    countedCash.value = '';
    closingShift.value = true;
}

function cancelClose() {
    closingKey.value = null;
}

/** Diferencia del arqueo en vivo: solo si se capturó el conteo. */
const difference = computed(() => {
    if (countedCash.value === '' || closingShift.value) {
        return null;
    }

    const scope = (state.value?.scopes ?? []).find(
        (item) => item.key === closingKey.value,
    );

    if (!scope) {
        return null;
    }

    return Number(countedCash.value) - Number(scope.expected_cash);
});

async function submitShift() {
    if (opening.value) {
        return;
    }

    opening.value = true;
    const error = await openShift(userId.value, Number(openingCash.value) || 0);

    if (error) {
        emit('error', error);
    } else {
        openingCash.value = '';
    }

    opening.value = false;
}

async function confirmClose() {
    if (busy.value || !reason.value.trim()) {
        return;
    }

    busy.value = true;

    let error: string | null = null;

    if (closingShift.value) {
        const shiftId = state.value?.shift?.id;

        if (shiftId !== undefined) {
            error = await closeShift(shiftId, reason.value.trim());
        }
    } else {
        const scope = (state.value?.scopes ?? []).find(
            (item) => item.key === closingKey.value,
        );

        if (scope) {
            error = await closeScope(
                scope,
                userId.value,
                reason.value.trim(),
                countedCash.value === '' ? null : Number(countedCash.value),
            );
        }
    }

    if (error) {
        emit('error', error);
    } else {
        closingKey.value = null;
    }

    busy.value = false;
}

async function expand(scope: string) {
    const error = await toggleDetail(scope);

    if (error) {
        emit('error', error);
    }
}

// Al abrir el modal se relee: entre la última vuelta del reloj y este clic
// pudo entrar un cobro.
onMounted(() => {
    void load().then((error) => error && emit('error', error));
});
</script>

<template>
    <div class="px-6 py-5 sm:px-7">
        <p v-if="!state && loading" class="text-sm text-slate-500">
            Leyendo la caja…
        </p>

        <template v-else-if="state">
            <!-- Sin turno abierto el corte se arma por fechas y el fondo de
                 caja no cuenta: se ofrece abrirlo aquí mismo. -->
            <div v-if="!state.shift" class="space-y-2">
                <p class="text-sm text-slate-500">
                    No tienes turno abierto. Ábrelo para que lo que cobres caiga
                    en él y el corte cuadre con el cajón.
                </p>
                <div class="flex gap-2">
                    <FormInput
                        v-model="openingCash"
                        type="number"
                        min="0"
                        step="1"
                        placeholder="Fondo inicial"
                    />
                    <Button
                        variant="primary"
                        class="min-h-11 shrink-0 rounded-[0.5rem]"
                        :disabled="opening"
                        @click="submitShift"
                    >
                        {{ opening ? 'Abriendo…' : 'Abrir turno' }}
                    </Button>
                </div>
            </div>

            <div
                v-else
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
            >
                <div class="flex items-center gap-2 text-sm text-slate-500">
                    <Lucide icon="Clock" class="h-4 w-4 shrink-0" />
                    Turno abierto desde {{ state.shift.started_at }} · fondo
                    {{ formatMoney(state.shift.opening_cash) }}
                </div>
                <Button
                    variant="outline-primary"
                    class="min-h-10 rounded-[0.5rem]"
                    title="Cierra el turno y genera el corte de cada caja con el periodo exacto del turno"
                    @click="startCloseShift"
                >
                    <Lucide icon="LogOut" class="mr-2 h-4 w-4" />
                    Cerrar turno y cortar
                </Button>
            </div>

            <div
                v-for="scope in state.scopes"
                :key="scope.key"
                class="mt-4 rounded-xl border border-slate-200/70 p-4 dark:border-darkmode-400"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <span class="font-medium">{{ scope.label }}</span>
                    <span class="text-lg font-semibold">{{
                        formatMoney(scope.grand_total)
                    }}</span>
                </div>
                <p class="mt-0.5 text-xs text-slate-500">
                    {{ scope.from }} → {{ scope.to }} ·
                    {{ scope.orders_count }} ventas ·
                    {{ scope.payments_count }} cobros
                </p>
                <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm">
                    <span
                        >Efectivo
                        <strong>{{
                            formatMoney(scope.cash_total)
                        }}</strong></span
                    >
                    <span
                        >Tarjeta
                        <strong>{{
                            formatMoney(scope.card_total)
                        }}</strong></span
                    >
                    <span class="text-slate-500"
                        >En cajón
                        <strong>{{
                            formatMoney(scope.expected_cash)
                        }}</strong></span
                    >
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <Button
                        variant="outline-secondary"
                        class="min-h-10 rounded-[0.5rem]"
                        :title="`Ver transacción por transacción y lo que queda por cobrar`"
                        @click="expand(scope.key)"
                    >
                        <Lucide
                            :icon="
                                detailScope === scope.key
                                    ? 'ChevronUp'
                                    : 'ChevronDown'
                            "
                            class="mr-2 h-4 w-4"
                        />
                        {{
                            detailScope === scope.key
                                ? 'Ocultar movimientos'
                                : 'Ver movimientos'
                        }}
                    </Button>
                    <Button
                        variant="outline-primary"
                        class="min-h-10 rounded-[0.5rem]"
                        title="Corta esta caja ahora, con motivo; el turno sigue abierto"
                        @click="startClose(scope)"
                    >
                        <Lucide icon="Scissors" class="mr-2 h-4 w-4" />
                        Cerrar solo esta caja
                    </Button>
                </div>

                <!-- Rastro del periodo: cada venta, cada cobro, cada fianza. -->
                <div
                    v-if="detailScope === scope.key && scope.movements"
                    class="mt-3 border-t border-slate-200/70 pt-3 dark:border-darkmode-400"
                >
                    <div class="mb-1 text-xs font-medium text-slate-500">
                        Movimientos ({{ scope.movements.length }})
                    </div>
                    <div
                        v-for="(movement, index) in scope.movements"
                        :key="index"
                        class="flex items-start gap-3 py-1 text-sm"
                    >
                        <span class="shrink-0 text-xs text-slate-400">{{
                            movement.at
                        }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{
                                movement.concept
                            }}</span>
                            <span class="block truncate text-xs text-slate-500"
                                >{{ movement.method
                                }}<template v-if="movement.detail">
                                    · {{ movement.detail }}</template
                                ></span
                            >
                        </span>
                        <span
                            class="shrink-0 font-medium"
                            :class="movement.collected ? '' : 'text-slate-400'"
                            :title="
                                movement.collected
                                    ? undefined
                                    : 'No entró a esta caja: se cobra en el check-out'
                            "
                            >{{ formatMoney(movement.amount) }}</span
                        >
                    </div>
                    <p
                        v-if="!scope.movements.length"
                        class="text-sm text-slate-500"
                    >
                        Sin movimientos en este periodo.
                    </p>

                    <template v-if="scope.pending && scope.pending.count">
                        <div
                            class="mt-3 mb-1 text-xs font-medium text-slate-500"
                        >
                            Por cobrar ({{ scope.pending.count }} ·
                            {{ formatMoney(scope.pending.total) }})
                        </div>
                        <div
                            v-for="(item, index) in scope.pending.items"
                            :key="index"
                            class="flex items-start gap-3 py-1 text-sm"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block truncate">{{
                                    item.label
                                }}</span>
                                <span
                                    v-if="item.detail"
                                    class="block truncate text-xs text-slate-500"
                                    >{{ item.detail }}</span
                                >
                            </span>
                            <span class="shrink-0 text-pending">{{
                                formatMoney(item.amount)
                            }}</span>
                        </div>
                    </template>
                </div>

                <!-- Cierre de ESTA caja: motivo obligatorio, conteo opcional. -->
                <form
                    v-if="closingKey === scope.key && !closingShift"
                    class="mt-3 space-y-3 border-t border-slate-200/70 pt-3 dark:border-darkmode-400"
                    @submit.prevent="confirmClose"
                >
                    <div>
                        <label
                            class="text-xs text-slate-500"
                            :for="`cash-reason-${scope.key}`"
                            >Motivo del cierre</label
                        >
                        <FormInput
                            :id="`cash-reason-${scope.key}`"
                            v-model="reason"
                            type="text"
                            class="mt-1"
                            maxlength="1000"
                            placeholder="Cambio de turno, entrega a gerencia, corte parcial…"
                            required
                        />
                    </div>
                    <div>
                        <label
                            class="text-xs text-slate-500"
                            :for="`cash-counted-${scope.key}`"
                            >Efectivo contado (opcional)</label
                        >
                        <FormInput
                            :id="`cash-counted-${scope.key}`"
                            v-model="countedCash"
                            type="number"
                            min="0"
                            step="1"
                            class="mt-1"
                            :placeholder="`Esperado ${formatMoney(scope.expected_cash)}`"
                        />
                        <p
                            v-if="difference !== null"
                            class="mt-1 text-xs"
                            :class="
                                difference === 0
                                    ? 'text-success'
                                    : difference < 0
                                      ? 'text-primary'
                                      : 'text-pending'
                            "
                        >
                            {{
                                difference === 0
                                    ? 'Cuadra con lo esperado.'
                                    : difference < 0
                                      ? `Faltan ${formatMoney(Math.abs(difference))}`
                                      : `Sobran ${formatMoney(difference)}`
                            }}
                        </p>
                        <p v-else class="mt-1 text-xs text-slate-500">
                            Sin conteo el corte se guarda igual, solo que sin
                            arqueo.
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            variant="primary"
                            type="submit"
                            class="min-h-11 rounded-[0.5rem]"
                            :disabled="busy || !reason.trim()"
                        >
                            {{ busy ? 'Cerrando…' : 'Cerrar la caja' }}
                        </Button>
                        <Button
                            variant="outline-secondary"
                            type="button"
                            class="min-h-11 rounded-[0.5rem]"
                            @click="cancelClose"
                        >
                            Cancelar
                        </Button>
                    </div>
                </form>
            </div>

            <p v-if="!state.scopes.length" class="text-sm text-slate-500">
                Tu usuario no tiene ningún ámbito de corte disponible.
            </p>

            <!-- Cierre del TURNO: corta todas las cajas del periodo del turno. -->
            <form
                v-if="closingShift && closingKey === 'shift'"
                class="mt-4 space-y-3 rounded-xl border border-primary/20 bg-primary/5 p-4 dark:border-primary/30 dark:bg-primary/10"
                @submit.prevent="confirmClose"
            >
                <div class="text-sm font-medium">Cerrar turno y cortar</div>
                <p class="text-xs text-slate-500">
                    Se cierra el turno y se guarda un corte por cada caja con
                    movimiento, con el periodo exacto del turno. El arqueo del
                    efectivo se hace en Cortes.
                </p>
                <div>
                    <label class="text-xs text-slate-500" for="shift-reason"
                        >Motivo del cierre</label
                    >
                    <FormInput
                        id="shift-reason"
                        v-model="reason"
                        type="text"
                        class="mt-1"
                        maxlength="255"
                        placeholder="Fin de turno, relevo, cierre del día…"
                        required
                    />
                </div>
                <div class="flex gap-2">
                    <Button
                        variant="primary"
                        type="submit"
                        class="min-h-11 rounded-[0.5rem]"
                        :disabled="busy || !reason.trim()"
                    >
                        {{ busy ? 'Cerrando…' : 'Cerrar turno' }}
                    </Button>
                    <Button
                        variant="outline-secondary"
                        type="button"
                        class="min-h-11 rounded-[0.5rem] bg-white dark:bg-darkmode-600"
                        @click="cancelClose"
                    >
                        Cancelar
                    </Button>
                </div>
            </form>

            <div
                v-if="state.recent_cuts.length"
                class="mt-4 border-t border-slate-200/70 pt-3 dark:border-darkmode-400"
            >
                <div class="mb-1 text-xs font-medium text-slate-500">
                    Últimos cortes
                </div>
                <div
                    v-for="cut in state.recent_cuts"
                    :key="cut.id"
                    class="flex items-center justify-between gap-2 py-0.5 text-sm"
                >
                    <span class="min-w-0 flex-1 truncate text-slate-500"
                        >{{ cut.closed_at }} · {{ cut.scope_label }}</span
                    >
                    <span>{{ formatMoney(cut.grand_total) }}</span>
                    <span
                        v-if="cut.difference !== null && cut.difference !== 0"
                        class="shrink-0"
                        :class="
                            cut.difference < 0 ? 'text-primary' : 'text-success'
                        "
                        :title="
                            cut.difference < 0
                                ? 'Faltó efectivo en el arqueo'
                                : 'Sobró efectivo en el arqueo'
                        "
                        >{{ formatMoney(cut.difference) }}</span
                    >
                </div>
            </div>

            <!-- El detalle contable con PDF vive en su página. -->
            <Link
                href="/cortes"
                class="mt-4 inline-flex items-center gap-1.5 text-sm text-primary hover:underline"
            >
                <Lucide icon="ArrowRight" class="h-4 w-4" />
                Ver los cortes guardados y su PDF
            </Link>
        </template>
    </div>
</template>
