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
// Piezas del canon (densidad chica), reusadas en las tarjetas de cada caja.
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const statLabel = 'text-[11px] text-slate-400';
const sectionRubric =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const fieldLabel =
    'mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300';

/** Hay una caja abierta (movimientos o cierre): las tarjetas se apilan. */
const scopeOpen = computed(
    () =>
        detailScope.value !== null ||
        (closingKey.value !== null && !closingShift.value),
);

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
    <!-- Anatomía del canon (densidad chica): cada caja es una tarjeta con sus
         tres cifras separadas, las dos cajas lado a lado en pantalla ancha y
         las listas en renglones a ras con divisor. Antes todo iba en texto
         corrido con renglones de 2px de aire: con los movimientos y los
         cortes reales de cabañas se leía como un bloque amontonado. -->
    <div class="space-y-4 px-5 py-4">
        <p v-if="!state && loading" class="text-xs text-slate-500">
            Leyendo la caja…
        </p>

        <template v-else-if="state">
            <!-- Turno: abrirlo aquí mismo, o ver desde cuándo corre. -->
            <section
                class="flex flex-col gap-3 rounded-xl border px-4 py-3 sm:flex-row sm:items-center"
                :class="
                    state.shift
                        ? 'border-slate-200/70 dark:border-darkmode-400'
                        : 'border-warning/25 bg-warning/5'
                "
            >
                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <div
                        :class="sectionIcon"
                        class="border-slate-200/70 bg-white text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide
                            :icon="state.shift ? 'Clock' : 'LockOpen'"
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{
                                state.shift
                                    ? `Turno abierto desde ${state.shift.started_at}`
                                    : 'No tienes turno abierto'
                            }}
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{
                                state.shift
                                    ? `Fondo inicial ${formatMoney(state.shift.opening_cash)}`
                                    : 'Ábrelo para que lo que cobres caiga en él y el corte cuadre con el cajón.'
                            }}
                        </p>
                    </div>
                </div>
                <div
                    v-if="!state.shift"
                    class="flex shrink-0 items-center gap-2"
                >
                    <div class="relative w-full sm:w-40">
                        <Lucide
                            icon="DollarSign"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                        />
                        <FormInput
                            v-model="openingCash"
                            type="number"
                            min="0"
                            step="1"
                            class="h-9 pl-9 text-xs"
                            placeholder="Fondo inicial"
                            aria-label="Fondo inicial"
                        />
                    </div>
                    <Button
                        variant="primary"
                        class="h-9 shrink-0 rounded-[0.5rem] px-4 text-xs"
                        :disabled="opening"
                        @click="submitShift"
                    >
                        {{ opening ? 'Abriendo…' : 'Abrir turno' }}
                    </Button>
                </div>
                <Button
                    v-else
                    variant="outline-primary"
                    class="h-9 shrink-0 rounded-[0.5rem] px-4 text-xs"
                    title="Cierra el turno y genera el corte de cada caja con el periodo exacto del turno"
                    @click="startCloseShift"
                >
                    <Lucide icon="LogOut" class="mr-1.5 h-3.5 w-3.5" />
                    Cerrar turno y cortar
                </Button>
            </section>

            <!-- Cierre del TURNO: corta todas las cajas del periodo del turno. -->
            <form
                v-if="closingShift && closingKey === 'shift'"
                class="space-y-3 rounded-xl border border-primary/20 bg-primary/5 px-4 py-3 dark:border-primary/30 dark:bg-primary/10"
                @submit.prevent="confirmClose"
            >
                <div>
                    <div class="text-sm font-medium">Cerrar turno y cortar</div>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Se cierra el turno y se guarda un corte por cada caja
                        con movimiento, con el periodo exacto del turno. El
                        arqueo del efectivo se hace en Cortes.
                    </p>
                </div>
                <div>
                    <label :class="fieldLabel" for="shift-reason"
                        >Motivo del cierre</label
                    >
                    <FormInput
                        id="shift-reason"
                        v-model="reason"
                        type="text"
                        class="h-9 text-xs"
                        maxlength="255"
                        placeholder="Fin de turno, relevo, cierre del día…"
                        required
                    />
                </div>
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline-secondary"
                        type="button"
                        class="h-9 rounded-[0.5rem] bg-white px-5 text-xs dark:bg-darkmode-600"
                        @click="cancelClose"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        type="submit"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="busy || !reason.trim()"
                    >
                        {{ busy ? 'Cerrando…' : 'Cerrar turno' }}
                    </Button>
                </div>
            </form>

            <p v-if="!state.scopes.length" class="text-xs text-slate-500">
                Tu usuario no tiene ningún ámbito de corte disponible.
            </p>

            <!-- Una tarjeta por caja, lado a lado. Si una se abre (movimientos
                 o cierre) las dos pasan a ancho completo: a media columna la
                 lista quedaba embutida, y una sola ancha dejaba un hueco
                 junto a la otra. -->
            <div
                v-else
                class="grid items-start gap-4"
                :class="scopeOpen ? '' : 'lg:grid-cols-2'"
            >
                <section
                    v-for="scope in state.scopes"
                    :key="scope.key"
                    class="overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
                >
                    <div :class="cardHeader">
                        <div
                            :class="sectionIcon"
                            class="border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide
                                :icon="
                                    scope.key === 'pos'
                                        ? 'ShoppingCart'
                                        : 'ConciergeBell'
                                "
                                class="h-4 w-4"
                            />
                        </div>
                        <div class="min-w-0 flex-1 basis-0">
                            <div class="text-sm font-medium">
                                {{ scope.label }}
                            </div>
                            <p class="mt-0.5 truncate text-xs text-slate-500">
                                {{ scope.from }} → {{ scope.to }}
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="text-sm font-medium">
                                {{ formatMoney(scope.grand_total) }}
                            </div>
                            <div class="text-[11px] text-slate-400">
                                {{ scope.orders_count }} ventas ·
                                {{ scope.payments_count }} cobros
                            </div>
                        </div>
                    </div>

                    <dl
                        class="grid grid-cols-3 divide-x divide-slate-200/60 border-b border-slate-200/60 dark:divide-darkmode-400 dark:border-darkmode-400"
                    >
                        <div class="px-4 py-2.5">
                            <dt :class="statLabel">Efectivo</dt>
                            <dd class="mt-0.5 text-sm font-medium">
                                {{ formatMoney(scope.cash_total) }}
                            </dd>
                        </div>
                        <div class="px-4 py-2.5">
                            <dt :class="statLabel">Tarjeta</dt>
                            <dd class="mt-0.5 text-sm font-medium">
                                {{ formatMoney(scope.card_total) }}
                            </dd>
                        </div>
                        <div class="px-4 py-2.5">
                            <dt :class="statLabel">En cajón</dt>
                            <dd class="mt-0.5 text-sm font-medium">
                                {{ formatMoney(scope.expected_cash) }}
                            </dd>
                        </div>
                    </dl>

                    <div class="flex flex-wrap gap-2 px-4 py-3">
                        <Button
                            variant="outline-secondary"
                            class="h-8 rounded-[0.5rem] text-xs"
                            title="Ver transacción por transacción y lo que queda por cobrar"
                            @click="expand(scope.key)"
                        >
                            <Lucide
                                :icon="
                                    detailScope === scope.key
                                        ? 'ChevronUp'
                                        : 'ChevronDown'
                                "
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            {{
                                detailScope === scope.key
                                    ? 'Ocultar movimientos'
                                    : 'Ver movimientos'
                            }}
                        </Button>
                        <Button
                            variant="outline-primary"
                            class="h-8 rounded-[0.5rem] text-xs"
                            title="Corta esta caja ahora, con motivo; el turno sigue abierto"
                            @click="startClose(scope)"
                        >
                            <Lucide
                                icon="Scissors"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Cerrar solo esta caja
                        </Button>
                    </div>

                    <!-- Rastro del periodo: cada venta, cada cobro, cada fianza. -->
                    <div
                        v-if="detailScope === scope.key && scope.movements"
                        class="border-t border-slate-200/60 dark:border-darkmode-400"
                    >
                        <div
                            :class="sectionRubric"
                            class="bg-slate-50/70 px-4 py-2 dark:bg-darkmode-700"
                        >
                            Movimientos ({{ scope.movements.length }})
                        </div>
                        <div
                            class="max-h-72 divide-y divide-slate-200/60 overflow-y-auto dark:divide-darkmode-400"
                        >
                            <div
                                v-for="(movement, index) in scope.movements"
                                :key="index"
                                class="flex items-center gap-3 px-4 py-2"
                            >
                                <span
                                    class="w-24 shrink-0 text-[11px] text-slate-400"
                                    >{{ movement.at }}</span
                                >
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-xs">{{
                                        movement.concept
                                    }}</span>
                                    <span
                                        class="block truncate text-[11px] text-slate-500"
                                        >{{ movement.method
                                        }}<template v-if="movement.detail">
                                            · {{ movement.detail }}</template
                                        ></span
                                    >
                                </span>
                                <span
                                    class="shrink-0 text-xs font-medium"
                                    :class="
                                        movement.collected
                                            ? ''
                                            : 'text-slate-400'
                                    "
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
                                class="px-4 py-3 text-xs text-slate-500"
                            >
                                Sin movimientos en este periodo.
                            </p>
                        </div>

                        <template v-if="scope.pending && scope.pending.count">
                            <div
                                :class="sectionRubric"
                                class="border-t border-slate-200/60 bg-slate-50/70 px-4 py-2 dark:border-darkmode-400 dark:bg-darkmode-700"
                            >
                                Por cobrar ({{ scope.pending.count }} ·
                                {{ formatMoney(scope.pending.total) }})
                            </div>
                            <div
                                class="max-h-56 divide-y divide-slate-200/60 overflow-y-auto dark:divide-darkmode-400"
                            >
                                <div
                                    v-for="(item, index) in scope.pending.items"
                                    :key="index"
                                    class="flex items-center gap-3 px-4 py-2"
                                >
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-xs">{{
                                            item.label
                                        }}</span>
                                        <span
                                            v-if="item.detail"
                                            class="block truncate text-[11px] text-slate-500"
                                            >{{ item.detail }}</span
                                        >
                                    </span>
                                    <span
                                        class="shrink-0 text-xs font-medium text-pending"
                                        >{{ formatMoney(item.amount) }}</span
                                    >
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Cierre de ESTA caja: motivo obligatorio, conteo opcional. -->
                    <form
                        v-if="closingKey === scope.key && !closingShift"
                        class="space-y-3 border-t border-slate-200/60 bg-slate-50/50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                        @submit.prevent="confirmClose"
                    >
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label
                                    :class="fieldLabel"
                                    :for="`cash-reason-${scope.key}`"
                                    >Motivo del cierre</label
                                >
                                <FormInput
                                    :id="`cash-reason-${scope.key}`"
                                    v-model="reason"
                                    type="text"
                                    class="h-9 text-xs"
                                    maxlength="1000"
                                    placeholder="Cambio de turno, entrega a gerencia…"
                                    required
                                />
                            </div>
                            <div>
                                <label
                                    :class="fieldLabel"
                                    :for="`cash-counted-${scope.key}`"
                                    >Efectivo contado</label
                                >
                                <FormInput
                                    :id="`cash-counted-${scope.key}`"
                                    v-model="countedCash"
                                    type="number"
                                    min="0"
                                    step="1"
                                    class="h-9 text-xs"
                                    :placeholder="`Esperado ${formatMoney(scope.expected_cash)}`"
                                />
                            </div>
                        </div>
                        <p
                            v-if="difference !== null"
                            class="text-xs"
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
                        <p v-else class="text-xs text-slate-500">
                            El conteo es opcional: sin él el corte se guarda
                            igual, solo que sin arqueo.
                        </p>
                        <div class="flex justify-end gap-2">
                            <Button
                                variant="outline-secondary"
                                type="button"
                                class="h-9 rounded-[0.5rem] bg-white px-5 text-xs dark:bg-darkmode-600"
                                @click="cancelClose"
                            >
                                Cancelar
                            </Button>
                            <Button
                                variant="primary"
                                type="submit"
                                class="h-9 rounded-[0.5rem] px-5 text-xs"
                                :disabled="busy || !reason.trim()"
                            >
                                {{ busy ? 'Cerrando…' : 'Cerrar la caja' }}
                            </Button>
                        </div>
                    </form>
                </section>
            </div>

            <!-- Últimos cortes: renglones a ras y a la derecha la liga a
                 Cortes, donde vive el PDF. -->
            <section
                class="overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
            >
                <div
                    class="flex items-center justify-between gap-2 border-b border-slate-200/60 bg-slate-50/70 px-4 py-2 dark:border-darkmode-400 dark:bg-darkmode-700"
                >
                    <span :class="sectionRubric">Últimos cortes</span>
                    <Link
                        href="/cortes"
                        class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                    >
                        Ver cortes y PDF
                        <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                    </Link>
                </div>
                <div
                    v-if="state.recent_cuts.length"
                    class="max-h-56 divide-y divide-slate-200/60 overflow-y-auto dark:divide-darkmode-400"
                >
                    <div
                        v-for="cut in state.recent_cuts"
                        :key="cut.id"
                        class="flex items-center gap-3 px-4 py-2 text-xs"
                    >
                        <span class="min-w-0 flex-1 truncate">
                            <span class="font-medium">{{
                                cut.scope_label
                            }}</span>
                            <span class="text-slate-500">
                                · {{ cut.closed_at }}</span
                            >
                        </span>
                        <span
                            v-if="
                                cut.difference !== null && cut.difference !== 0
                            "
                            class="shrink-0 rounded-full px-2 py-0.5 text-[11px]"
                            :class="
                                cut.difference < 0
                                    ? 'bg-danger/10 text-danger'
                                    : 'bg-success/10 text-success'
                            "
                            :title="
                                cut.difference < 0
                                    ? 'Faltó efectivo en el arqueo'
                                    : 'Sobró efectivo en el arqueo'
                            "
                            >{{ cut.difference < 0 ? 'Faltó' : 'Sobró' }}
                            {{ formatMoney(Math.abs(cut.difference)) }}</span
                        >
                        <span class="w-24 shrink-0 text-right font-medium">{{
                            formatMoney(cut.grand_total)
                        }}</span>
                    </div>
                </div>
                <p v-else class="px-4 py-3 text-xs text-slate-500">
                    Todavía no hay cortes guardados.
                </p>
            </section>
        </template>
    </div>
</template>
