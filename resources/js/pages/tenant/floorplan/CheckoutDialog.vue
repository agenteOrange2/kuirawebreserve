<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect, FormSwitch } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import type { CounterMethod } from '@/composables/useCounterMethods';
import { useCounterMethods } from '@/composables/useCounterMethods';
import Lucide from '@/components/Base/Lucide';

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
interface Folio {
    lodging_pending: number;
    consumption_pending: number;
    grand_pending: number;
    guarantee_refundable: number;
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
const blacklist = ref(false);
const blacklistReason = ref('');
const reviewed = ref(false);
const damagesAdded = ref(0);

/** Elegir del catálogo llena las dos casillas; el precio se puede ajustar. */
function pickDamage(concept: string, amount: number) {
    damageConcept.value = concept;
    damageAmount.value = String(amount);
}

function addDamage() {
    const concept = damageConcept.value.trim();
    const amount = Number(damageAmount.value);

    if (!concept || !(amount > 0)) {
        return;
    }

    emit('damage', { concept, amount });
    damagesAdded.value += 1;
    damageConcept.value = '';
    damageAmount.value = '';
}

const pending = computed(() => Number(props.folio?.grand_pending ?? 0));
const guarantee = computed(() =>
    Number(props.folio?.guarantee_refundable ?? 0),
);

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
            damagesAdded.value = 0;
        }
    },
);

function confirm(force = false) {
    emit('confirm', {
        payment_method:
            force || pending.value <= 0 ? null : coerceMethod(method.value),
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
    <Dialog :open="open" @close="$emit('close')">
        <Dialog.Panel>
            <div
                class="flex items-center gap-3.5 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                >
                    <Lucide icon="LogOut" class="h-5 w-5 text-primary" />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-medium">
                        Salida de la {{ roomNumber }}
                    </h2>
                    <p class="mt-0.5 truncate text-xs text-slate-500">
                        {{ guestName ?? 'Sin nombre' }}
                    </p>
                </div>
            </div>

            <div class="space-y-4 px-5 py-4">
                <div
                    v-if="folio"
                    class="rounded-xl border border-slate-200/70 p-4 dark:border-darkmode-400"
                >
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-500">Hospedaje</span>
                        <span>{{ money(folio.lodging_pending) }}</span>
                    </div>
                    <div class="mt-1 flex items-center justify-between text-sm">
                        <span class="text-slate-500">Consumos</span>
                        <span>{{ money(folio.consumption_pending) }}</span>
                    </div>
                    <div
                        class="mt-2 flex items-center justify-between border-t border-slate-200/70 pt-2 font-semibold dark:border-darkmode-400"
                    >
                        <span>Por cobrar</span>
                        <span>{{ money(pending) }}</span>
                    </div>
                </div>

                <template v-if="pending > 0">
                    <div>
                        <label
                            class="text-xs text-slate-500"
                            for="checkout-method"
                            >Cómo paga</label
                        >
                        <FormSelect
                            id="checkout-method"
                            v-model="method"
                            class="mt-1"
                        >
                            <option
                                v-for="m in counterMethods"
                                :key="m.key"
                                :value="m.key"
                            >
                                {{ m.label }}
                            </option>
                        </FormSelect>
                    </div>
                    <div v-if="method !== 'cash'">
                        <label
                            class="text-xs text-slate-500"
                            for="checkout-reference"
                            >Referencia (opcional)</label
                        >
                        <FormInput
                            id="checkout-reference"
                            v-model="reference"
                            type="text"
                            maxlength="100"
                            class="mt-1"
                        />
                    </div>
                </template>

                <p v-else class="text-xs text-slate-500">
                    No queda nada por cobrar: la salida se registra directo.
                </p>

                <!-- Revisión de la habitación: el paso obligado antes de
                     dejar salir al huésped. Cada daño sube la cuenta o se
                     cubre con la fianza, y queda como incidencia. -->
                <div
                    class="rounded-xl border p-4"
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
                            :class="
                                reviewed ? 'text-slate-400' : 'text-warning'
                            "
                        />
                        <span class="text-sm font-medium">
                            Revisión de la habitación
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Antes de dejar salir al huésped, revisa que no falte ni
                        esté dañado nada. Lo que agregues sube a la cuenta y
                        queda como incidencia; si hay fianza, puedes cubrirlo
                        con ella.
                    </p>

                    <div
                        v-if="damageCatalog.length"
                        class="mt-3 flex flex-wrap gap-1.5"
                    >
                        <button
                            v-for="damage in damageCatalog"
                            :key="damage.concept"
                            type="button"
                            class="rounded-full border border-slate-200 px-3 py-1 text-xs text-slate-600 transition hover:border-primary/40 hover:text-primary dark:border-darkmode-400 dark:text-slate-300"
                            @click="pickDamage(damage.concept, damage.amount)"
                        >
                            {{ damage.concept }} · {{ money(damage.amount) }}
                        </button>
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

                    <div class="mt-3 flex flex-wrap items-end gap-2">
                        <div class="min-w-0 flex-1">
                            <label
                                class="text-xs text-slate-500"
                                for="damage-concept"
                                >Qué se dañó</label
                            >
                            <FormInput
                                id="damage-concept"
                                v-model="damageConcept"
                                type="text"
                                maxlength="100"
                                class="mt-1"
                                placeholder="Toalla quemada"
                            />
                        </div>
                        <div class="w-32">
                            <label
                                class="text-xs text-slate-500"
                                for="damage-amount"
                                >Importe</label
                            >
                            <FormInput
                                id="damage-amount"
                                v-model="damageAmount"
                                type="number"
                                min="0"
                                step="1"
                                class="mt-1"
                            />
                        </div>
                        <Button
                            variant="outline-primary"
                            class="min-h-11 rounded-[0.5rem] text-xs"
                            :disabled="busy || !damageConcept.trim()"
                            @click="addDamage"
                        >
                            Agregar a la cuenta
                        </Button>
                    </div>

                    <p
                        v-if="damagesAdded > 0"
                        class="mt-3 rounded-lg bg-pending/10 px-3 py-2 text-[11px] font-medium text-pending"
                    >
                        {{ damagesAdded }}
                        {{
                            damagesAdded === 1
                                ? 'daño cargado'
                                : 'daños cargados'
                        }}
                        a la cuenta de esta estancia.
                    </p>

                    <label
                        class="mt-3 flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2.5"
                        :class="
                            reviewed
                                ? 'border-success/30 bg-success/5'
                                : 'border-slate-200/70 bg-white dark:border-darkmode-400 dark:bg-darkmode-600'
                        "
                    >
                        <input
                            v-model="reviewed"
                            type="checkbox"
                            class="mt-0.5 rounded border-slate-300"
                        />
                        <span class="min-w-0">
                            <span class="block text-xs font-medium">
                                Ya revisé la habitación
                            </span>
                            <span class="block text-[11px] text-slate-500">
                                Sin faltantes ni daños, o los que había ya
                                quedaron cargados arriba.
                            </span>
                        </span>
                    </label>

                    <label
                        v-if="canBlacklist"
                        class="mt-3 flex items-center gap-3 text-sm"
                    >
                        <input
                            v-model="blacklist"
                            type="checkbox"
                            class="rounded border-slate-300"
                        />
                        Vetar a este cliente y su vehículo
                    </label>
                    <FormInput
                        v-if="blacklist"
                        v-model="blacklistReason"
                        type="text"
                        maxlength="255"
                        class="mt-2"
                        placeholder="Por qué se veta (lo verá la caseta en su próxima visita)"
                    />
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
                        <span class="text-sm"
                            >Devolver la fianza de {{ money(guarantee) }}</span
                        >
                    </label>
                    <p
                        v-if="damagesAdded > 0 && refundGuarantee"
                        class="mt-2 text-[11px] text-slate-500"
                    >
                        Los daños ya se cargaron a la cuenta. Si prefieres
                        cubrirlos con la fianza, apaga la devolución y anota el
                        motivo.
                    </p>
                    <FormInput
                        v-if="!refundGuarantee"
                        v-model="retainReason"
                        type="text"
                        maxlength="255"
                        class="mt-3"
                        placeholder="Motivo de la retención (daños, faltantes)"
                    />
                </div>
            </div>

            <div
                class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
            >
                <Button
                    variant="outline-secondary"
                    class="rounded-[0.5rem]"
                    @click="$emit('close')"
                    >Cancelar</Button
                >
                <Button
                    v-if="pending > 0"
                    variant="outline-danger"
                    class="rounded-[0.5rem]"
                    :disabled="busy || !reviewed"
                    title="El huésped se va debiendo; el saldo queda en su historial"
                    @click="confirm(true)"
                    >Salir sin cobrar</Button
                >
                <Button
                    variant="primary"
                    class="rounded-[0.5rem]"
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
                            : pending > 0
                              ? `Cobrar ${money(pending)} y registrar salida`
                              : 'Registrar salida'
                    }}
                </Button>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
