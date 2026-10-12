<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormCheck,
    FormInput,
    FormSelect,
    FormSwitch,
} from '@/components/Base/Form';
import type { CounterMethod } from '@/composables/useCounterMethods';
import { useCounterMethods } from '@/composables/useCounterMethods';
import Lucide from '@/components/Base/Lucide';
import PlanModal from './PlanModal.vue';

/**
 * Registrar la salida cobrando lo que falta, sin salir del plano.
 *
 * Antes el plano mandaba el check-out sin cuerpo: con saldo pendiente el
 * servidor respondía 422 y el mostrador se quedaba sin salida. El endpoint
 * siempre aceptó método de pago y fianza; lo que faltaba era pedirlos.
 *
 * "Salir sin cobrar" existe pero es explícito (`force`): un huésped que se
 * va debiendo es una decisión de quien atiende, nunca el camino fácil.
 */
interface DamageLine {
    id: string;
    concept: string;
    amount: number;
}

interface Folio {
    lodging_pending: number;
    consumption_pending: number;
    grand_pending: number;
    /** Lo capturado en esta salida, para poder quitarlo antes de cobrar. */
    damages: DamageLine[];
    damages_total: number;
    guarantee_refundable: number;
    /** Con qué se recibió el depósito: por ahí se devuelve. */
    guarantee_method_label: string | null;
    guarantee_reference: string | null;
    /** Cortesía viva (módulo cortesias). */
    courtesy?: {
        status: string;
        kind_label: string;
        amount: number;
        requested_by_name: string | null;
    } | null;
}

const props = defineProps<{
    open: boolean;
    roomNumber: string;
    guestName: string | null;
    folio: Folio | null;
    busy: boolean;
    /** Conceptos y precios sugeridos de /ajustes/danos. */
    damageCatalog: { concept: string; amount: number }[];
    /** Hay ficha de vehículo o de huésped a quién vetar. */
    canBlacklist: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'damage', payload: { concept: string; amount: number }): void;
    (e: 'remove-damage', id: string): void;
    (
        e: 'confirm',
        payload: {
            payment_method: string | null;
            reference: string | null;
            force: boolean;
            guarantee_refund: boolean;
            guarantee_retain_reason: string | null;
            blacklist: boolean;
            blacklist_reason: string | null;
        },
    ): void;
}>();

// Formas de cobro que acepta la recepción (/ajustes/metodos-pago →
// Políticas): lo que el hotel no acepta ni se ofrece aquí.
const {
    methods: counterMethods,
    first: firstMethod,
    coerce: coerceMethod,
} = useCounterMethods();

const method = ref<CounterMethod>('cash');
const reference = ref('');
const refundGuarantee = ref(true);
const retainReason = ref('');

/* --- Revisión de la habitación ------------------------------------------
 * Se revisa ANTES de dejar salir al huésped, en cualquier tipo de propiedad:
 * hotel, cabañas o motel. Cada daño sube la cuenta (y con saldo pendiente la
 * salida no se registra sola), o se cubre con la fianza.
 *
 * La casilla "ya revisé" es el candado: sin ella no se registra la salida,
 * para que el paso no se salte con las prisas del mostrador.
 */
const damageConcept = ref('');
const damageAmount = ref<string>('');
/** Buscador del catálogo: con catorce conceptos la pared de pastillas
 *  tapaba el formulario y había que leerlos todos para hallar uno. */
const damageSearch = ref('');
const blacklist = ref(false);
const blacklistReason = ref('');
const reviewed = ref(false);

/** Elegir del catálogo llena las dos casillas; el precio se puede ajustar. */
function pickDamage(concept: string, amount: number) {
    damageConcept.value = concept;
    damageAmount.value = String(amount);
    damageSearch.value = '';
}

/**
 * El catálogo filtrado por el buscador. Va completo porque la lista tiene
 * su propio scroll: el problema no era cuántos, era que se pintaban como una
 * pared de pastillas que empujaba el formulario fuera de la vista.
 */
const damageMatches = computed(() => {
    const q = damageSearch.value.trim().toLowerCase();

    return q
        ? props.damageCatalog.filter((d) => d.concept.toLowerCase().includes(q))
        : props.damageCatalog;
});

function addDamage() {
    const concept = damageConcept.value.trim();
    const amount = Number(damageAmount.value);

    if (!concept || !(amount > 0)) {
        return;
    }

    emit('damage', { concept, amount });
    damageConcept.value = '';
    damageAmount.value = '';
    damageSearch.value = '';
}

const pending = computed(() => Number(props.folio?.grand_pending ?? 0));
const guarantee = computed(() =>
    Number(props.folio?.guarantee_refundable ?? 0),
);

/** Lo que se cargó en esta salida; el servidor manda la lista, no un contador. */
const damages = computed<DamageLine[]>(() => props.folio?.damages ?? []);
const damagesTotal = computed(() => Number(props.folio?.damages_total ?? 0));

/**
 * Con la fianza aplicada a la cuenta, cubre hasta donde alcanza y lo demás
 * se cobra en mostrador. Antes no se decía en ninguna parte: el huésped
 * pagaba la cuenta completa Y perdía el depósito.
 */
const guaranteeApplied = computed(() =>
    refundGuarantee.value ? 0 : Math.min(guarantee.value, pending.value),
);

const toCollect = computed(() =>
    Math.max(0, round2(pending.value - guaranteeApplied.value)),
);

/** Fianza que sobra después de cubrir la cuenta: se le devuelve al huésped. */
const guaranteeLeftover = computed(() =>
    refundGuarantee.value
        ? 0
        : round2(guarantee.value - guaranteeApplied.value),
);

function round2(value: number): number {
    return Math.round(value * 100) / 100;
}

function money(value: number): string {
    return Number(value).toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    });
}

// Cada apertura empieza limpia: arrastrar la referencia de la salida
// anterior es cómo se acaba con un folio que dice lo que no es.
watch(
    () => props.open,
    (open) => {
        if (open) {
            method.value = firstMethod.value;
            reference.value = '';
            refundGuarantee.value = true;
            retainReason.value = '';
            damageConcept.value = '';
            damageAmount.value = '';
            blacklist.value = false;
            blacklistReason.value = '';
            reviewed.value = false;
            damageSearch.value = '';
        }
    },
);

function confirm(force = false) {
    emit('confirm', {
        // Con la fianza cubriendo la cuenta puede no quedar nada que cobrar:
        // mandar método entonces crearía un pago de cero.
        payment_method:
            force || toCollect.value <= 0 ? null : coerceMethod(method.value),
        reference: reference.value || null,
        force,
        guarantee_refund: refundGuarantee.value,
        guarantee_retain_reason: refundGuarantee.value
            ? null
            : retainReason.value || null,
        // Vetar es una decisión aparte del cobro: se manda junto porque es el
        // mismo momento, pero el plano la ejecuta antes de registrar la salida
        // (después el cuarto ya no trae la ficha del huésped).
        blacklist: blacklist.value,
        blacklist_reason: blacklist.value
            ? blacklistReason.value.trim() || null
            : null,
    });
}
</script>

<template>
    <!-- Ancho grande y a dos columnas: en una sola, la revisión de la
         habitación —que es donde de verdad se trabaja— quedaba debajo del
         pliegue, con el catálogo de daños y los campos apretados en 380px. -->
    <PlanModal
        :open="open"
        icon="LogOut"
        :title="`Salida de la ${roomNumber}`"
        :subtitle="guestName ?? 'Sin nombre'"
        panel-class="sm:w-[94vw] lg:w-[900px]"
        static-backdrop
        :busy="busy"
        @close="$emit('close')"
    >
        <template #header-extra>
            <span
                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                :class="
                    toCollect > 0
                        ? 'bg-pending/10 text-pending'
                        : 'bg-success/10 text-success'
                "
            >
                {{
                    toCollect > 0
                        ? `Por cobrar ${money(toCollect)}`
                        : 'Sin saldo'
                }}
            </span>
        </template>

        <div class="grid gap-4 lg:grid-cols-5">
            <!-- El dinero a la izquierda: la cuenta, cómo se cobra y
                         qué pasa con la fianza. Se lee de arriba abajo. -->
            <div class="space-y-4 lg:col-span-2">
                <div
                    v-if="folio"
                    class="rounded-xl border border-slate-200/70 p-4 dark:border-darkmode-400"
                >
                    <div
                        class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                    >
                        La cuenta
                    </div>
                    <div
                        class="mt-2.5 flex items-center justify-between text-xs"
                    >
                        <span class="text-slate-500">Hospedaje</span>
                        <span class="font-medium">{{
                            money(folio.lodging_pending)
                        }}</span>
                    </div>
                    <div
                        v-if="folio.courtesy?.status === 'approved'"
                        class="mt-1.5 flex items-center justify-between text-xs"
                    >
                        <span class="text-slate-500"
                            >Cortesía ({{ folio.courtesy.kind_label }})</span
                        >
                        <span class="font-medium text-pending"
                            >−{{ money(folio.courtesy.amount) }} ya
                            restada</span
                        >
                    </div>
                    <div
                        v-if="folio.courtesy?.status === 'pending'"
                        class="mt-2 flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning"
                    >
                        <Lucide
                            icon="Clock"
                            class="mt-0.5 h-3.5 w-3.5 shrink-0"
                        />
                        <span
                            >{{ folio.courtesy.requested_by_name }} pidió una
                            cortesía ({{ folio.courtesy.kind_label }}, −{{
                                money(folio.courtesy.amount)
                            }}) y el propietario aún no contesta. Si el huésped
                            se va sin pagar esa parte, usa «Salir sin cobrar»:
                            la cuenta queda en Cuentas por cerrar y ahí la
                            decide el propietario.</span
                        >
                    </div>
                    <div
                        class="mt-1.5 flex items-center justify-between text-xs"
                    >
                        <span class="text-slate-500">Consumos</span>
                        <span class="font-medium">{{
                            money(folio.consumption_pending)
                        }}</span>
                    </div>
                    <div
                        v-if="damagesTotal > 0"
                        class="mt-1.5 flex items-center justify-between text-xs"
                    >
                        <span class="text-slate-500">Daños de esta salida</span>
                        <span class="font-medium">{{
                            money(damagesTotal)
                        }}</span>
                    </div>
                    <div
                        class="mt-2.5 flex items-center justify-between border-t border-slate-200/70 pt-2.5 text-xs dark:border-darkmode-400"
                    >
                        <span class="text-slate-500">Suma</span>
                        <span class="font-medium">{{ money(pending) }}</span>
                    </div>
                    <!-- La fianza aplicada baja lo que se cobra en
                                 mostrador; el excedente es lo que de verdad
                                 hay que pedirle al huésped. -->
                    <div
                        v-if="guaranteeApplied > 0"
                        class="mt-1.5 flex items-center justify-between text-xs"
                    >
                        <span class="text-slate-500"
                            >Cubierto con la fianza</span
                        >
                        <span class="font-medium text-success"
                            >−{{ money(guaranteeApplied) }}</span
                        >
                    </div>
                    <div
                        class="mt-2 flex items-center justify-between border-t border-slate-200/70 pt-2 text-sm font-semibold dark:border-darkmode-400"
                    >
                        <span>Por cobrar</span>
                        <span :class="toCollect > 0 ? 'text-pending' : ''">{{
                            money(toCollect)
                        }}</span>
                    </div>
                    <p
                        v-if="guaranteeLeftover > 0"
                        class="mt-2 rounded-lg bg-info/5 px-3 py-2 text-[11px] text-slate-600 dark:text-slate-300"
                    >
                        Sobran {{ money(guaranteeLeftover) }} de la fianza: eso
                        se le devuelve al huésped.
                    </p>
                    <p
                        v-else-if="toCollect <= 0"
                        class="mt-2 text-[11px] text-slate-500"
                    >
                        No queda nada por cobrar: la salida se registra directo.
                    </p>
                </div>

                <div
                    v-if="toCollect > 0"
                    class="rounded-xl border border-slate-200/70 p-4 dark:border-darkmode-400"
                >
                    <div
                        class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                    >
                        Cómo paga los {{ money(toCollect) }}
                    </div>
                    <FormSelect
                        id="checkout-method"
                        v-model="method"
                        class="mt-2.5 h-9 text-xs"
                    >
                        <option
                            v-for="m in counterMethods"
                            :key="m.key"
                            :value="m.key"
                        >
                            {{ m.label }}
                        </option>
                    </FormSelect>
                    <div v-if="method !== 'cash'" class="mt-3">
                        <label
                            class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                            for="checkout-reference"
                            >Referencia (opcional)</label
                        >
                        <FormInput
                            id="checkout-reference"
                            v-model="reference"
                            type="text"
                            maxlength="100"
                            class="h-9 text-xs"
                            placeholder="Autorización o folio"
                        />
                    </div>
                </div>

                <!-- La fianza es un pasivo: se devuelve salvo decisión
                             explícita, y quedársela exige motivo. -->
                <div
                    v-if="guarantee > 0"
                    class="rounded-xl border border-slate-200/70 p-4 dark:border-darkmode-400"
                >
                    <label class="flex items-center gap-3">
                        <FormSwitch>
                            <FormSwitch.Input
                                v-model="refundGuarantee"
                                type="checkbox"
                            />
                        </FormSwitch>
                        <span class="text-xs font-medium"
                            >Devolver la fianza de {{ money(guarantee) }}</span
                        >
                    </label>
                    <p
                        v-if="!refundGuarantee"
                        class="mt-2 rounded-lg bg-pending/10 px-3 py-2 text-[11px] text-slate-700 dark:text-slate-200"
                    >
                        <template v-if="guaranteeApplied > 0">
                            Se aplican
                            {{ money(guaranteeApplied) }} a la cuenta y se
                            cobran {{ money(toCollect) }} en mostrador.
                        </template>
                        <template v-else>
                            La cuenta está en ceros: la fianza se queda como
                            penalización, no cubre nada.
                        </template>
                    </p>
                    <!-- El efectivo sale del cajón; lo demás se
                                 devuelve por donde entró, y el folio del
                                 comprobante es lo único con lo que se puede. -->
                    <p
                        v-if="
                            folio?.guarantee_method_label &&
                            folio.guarantee_method_label !== 'Efectivo'
                        "
                        class="mt-2 rounded-lg bg-info/5 px-3 py-2 text-[11px] text-slate-600 dark:text-slate-300"
                    >
                        Se recibió por
                        {{ folio.guarantee_method_label.toLowerCase() }}
                        <template v-if="folio.guarantee_reference">
                            (referencia
                            {{ folio.guarantee_reference }})</template
                        >: devuélvela por ahí, no del cajón.
                    </p>
                    <p
                        v-if="damagesTotal > 0 && refundGuarantee"
                        class="mt-2 text-[11px] text-slate-500"
                    >
                        Los daños están en la cuenta. Si prefieres cubrirlos con
                        la fianza, apaga la devolución y anota el motivo.
                    </p>
                    <FormInput
                        v-if="!refundGuarantee"
                        v-model="retainReason"
                        type="text"
                        maxlength="255"
                        class="mt-3 h-9 text-xs"
                        placeholder="Motivo de la retención (daños, faltantes)"
                    />
                </div>
            </div>

            <!-- Revisión de la habitación: el paso obligado antes de
                         dejar salir al huésped. Ocupa la columna ancha porque
                         es donde se teclea. -->
            <div
                class="rounded-xl border p-4 lg:col-span-3"
                :class="
                    reviewed
                        ? 'border-slate-200/70 dark:border-darkmode-400'
                        : 'border-warning/40 bg-warning/5'
                "
            >
                <div class="flex items-center gap-2">
                    <Lucide
                        icon="Hammer"
                        class="h-4 w-4 shrink-0"
                        :class="reviewed ? 'text-slate-400' : 'text-warning'"
                    />
                    <span class="text-sm font-medium">
                        Revisión de la habitación
                    </span>
                </div>
                <p class="mt-1 text-xs text-slate-500">
                    Antes de dejar salir al huésped, revisa que no falte ni esté
                    dañado nada. Lo que agregues sube a la cuenta y queda como
                    incidencia; si hay fianza, puedes cubrirlo con ella.
                </p>

                <!-- Buscador y no la lista entera: con catorce
                             conceptos la pared de pastillas empujaba el
                             formulario fuera de la vista y había que leerlos
                             todos para hallar uno. -->
                <div v-if="damageCatalog.length" class="mt-3">
                    <div class="relative">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                        />
                        <FormInput
                            v-model="damageSearch"
                            type="text"
                            class="h-9 pl-9 text-xs"
                            placeholder="Buscar en la lista de daños…"
                        />
                    </div>
                    <!-- Lista y no pastillas sueltas: en renglones se
                                 lee de un vistazo qué cuesta qué, y con
                                 catorce conceptos las pastillas eran un
                                 párrafo de texto donde no se distinguía uno
                                 del otro. -->
                    <div
                        v-if="damageMatches.length"
                        class="mt-2 max-h-48 divide-y divide-slate-200/60 overflow-y-auto rounded-lg border border-slate-200/70 bg-white dark:divide-darkmode-400 dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <button
                            v-for="damage in damageMatches"
                            :key="damage.concept"
                            type="button"
                            class="flex w-full items-center gap-3 px-3 py-2 text-left transition hover:bg-primary/5"
                            @click="pickDamage(damage.concept, damage.amount)"
                        >
                            <span
                                class="min-w-0 flex-1 truncate text-xs text-slate-600 dark:text-slate-300"
                                >{{ damage.concept }}</span
                            >
                            <span class="text-xs font-medium">{{
                                money(damage.amount)
                            }}</span>
                            <Lucide
                                icon="Plus"
                                class="h-3.5 w-3.5 shrink-0 text-slate-400"
                            />
                        </button>
                    </div>
                    <p v-else class="mt-2 text-[11px] text-slate-500">
                        Nada con ese nombre; captúralo abajo con su importe.
                    </p>
                </div>

                <p
                    v-else
                    class="mt-3 rounded-lg border border-dashed border-slate-300/70 px-3 py-2 text-[11px] text-slate-500 dark:border-darkmode-400"
                >
                    Todavía no tienes lista de daños con precio.
                    <a
                        :href="route('tenant.damage-catalog')"
                        class="font-medium text-primary hover:underline"
                        >Ármala en Ajustes</a
                    >
                    para que todos los turnos cobren lo mismo.
                </p>

                <div class="mt-3 grid gap-3 sm:grid-cols-12">
                    <div class="sm:col-span-6">
                        <label
                            class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                            for="damage-concept"
                            >Qué se dañó</label
                        >
                        <FormInput
                            id="damage-concept"
                            v-model="damageConcept"
                            type="text"
                            maxlength="100"
                            class="h-9 text-xs"
                            placeholder="Toalla quemada"
                        />
                    </div>
                    <div class="sm:col-span-3">
                        <label
                            class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                            for="damage-amount"
                            >Importe</label
                        >
                        <FormInput
                            id="damage-amount"
                            v-model="damageAmount"
                            type="number"
                            min="0"
                            step="1"
                            class="h-9 text-xs"
                        />
                    </div>
                    <div class="flex items-end sm:col-span-3">
                        <Button
                            variant="outline-primary"
                            class="h-9 w-full justify-center rounded-[0.5rem] text-xs"
                            :disabled="busy || !damageConcept.trim()"
                            @click="addDamage"
                        >
                            Agregar
                        </Button>
                    </div>
                </div>

                <!-- Lo cargado, con su importe y su botón de quitar:
                             antes solo decía "2 daños cargados" y un concepto
                             mal tecleado se quedaba en la cuenta para siempre. -->
                <div v-if="damages.length" class="mt-3">
                    <div
                        class="mb-1.5 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                    >
                        Cargado a esta cuenta
                    </div>
                    <div
                        class="divide-y divide-slate-200/60 rounded-lg border border-slate-200/70 bg-white dark:divide-darkmode-400 dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <div
                            v-for="damage in damages"
                            :key="damage.id"
                            class="flex items-center gap-2 py-1 pr-1 pl-3"
                        >
                            <span class="min-w-0 flex-1 truncate text-xs">{{
                                damage.concept
                            }}</span>
                            <span class="text-xs font-medium">{{
                                money(damage.amount)
                            }}</span>
                            <button
                                type="button"
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger disabled:opacity-40"
                                title="Quitar de la cuenta"
                                :disabled="busy"
                                @click="$emit('remove-damage', damage.id)"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                    <p class="mt-1.5 text-[11px] text-slate-500">
                        Suman {{ money(damagesTotal) }} a la cuenta de esta
                        estancia.
                    </p>
                </div>

                <label
                    class="mt-3 flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2.5"
                    :class="
                        reviewed
                            ? 'border-success/30 bg-success/5'
                            : 'border-slate-200/70 bg-white dark:border-darkmode-400 dark:bg-darkmode-600'
                    "
                >
                    <FormCheck.Input
                        v-model="reviewed"
                        type="checkbox"
                        class="mt-0.5 shrink-0"
                    />
                    <span class="min-w-0">
                        <span class="block text-xs font-medium">
                            Ya revisé la habitación
                        </span>
                        <span class="block text-[11px] text-slate-500">
                            Sin faltantes ni daños, o los que había ya quedaron
                            cargados arriba.
                        </span>
                    </span>
                </label>

                <div
                    v-if="canBlacklist"
                    class="mt-3 border-t border-slate-200/60 pt-3 dark:border-darkmode-400"
                >
                    <label
                        class="flex cursor-pointer items-center gap-2.5 text-xs"
                    >
                        <FormCheck.Input v-model="blacklist" type="checkbox" />
                        Vetar a este cliente y su vehículo
                    </label>
                    <FormInput
                        v-if="blacklist"
                        v-model="blacklistReason"
                        type="text"
                        maxlength="255"
                        class="mt-2 h-9 text-xs"
                        placeholder="Por qué se veta (lo verá la caseta en su próxima visita)"
                    />
                </div>
            </div>
        </div>

        <template #footer>
            <Button
                variant="outline-secondary"
                class="h-9 justify-center rounded-[0.5rem] px-5 text-xs"
                :disabled="busy"
                @click="$emit('close')"
                >Cancelar</Button
            >
            <Button
                v-if="toCollect > 0"
                variant="outline-danger"
                class="h-9 justify-center rounded-[0.5rem] px-5 text-xs"
                :disabled="busy || !reviewed"
                title="El huésped se va debiendo; la cuenta queda en Cuentas por cerrar hasta que se cobre o el propietario la cierre"
                @click="confirm(true)"
                >Salir sin cobrar</Button
            >
            <Button
                variant="primary"
                class="h-9 justify-center rounded-[0.5rem] px-5 text-xs"
                :title="
                    reviewed
                        ? undefined
                        : 'Marca «Ya revisé la habitación» para registrar la salida'
                "
                :disabled="
                    busy ||
                    !reviewed ||
                    (!refundGuarantee && !retainReason.trim())
                "
                @click="confirm(false)"
            >
                {{
                    busy
                        ? 'Registrando…'
                        : toCollect > 0
                          ? `Cobrar ${money(toCollect)} y registrar salida`
                          : 'Registrar salida'
                }}
            </Button>
        </template>
    </PlanModal>
</template>
