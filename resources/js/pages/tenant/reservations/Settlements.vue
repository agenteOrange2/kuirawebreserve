<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormLabel, FormSelect } from '@/components/Base/Form';
import { FormDateTime } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useCounterMethods } from '@/composables/useCounterMethods';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Cuentas por cerrar.
 *
 * La salida manual exige cobrar el saldo o forzarla a propósito; la del
 * reloj (stays:auto-checkout) no tiene a quién preguntarle y cerraba en
 * silencio. Después ya no había nada que hacer: los cargos se rechazaban
 * por "estancia no activa" y no existía dónde registrar un cobro tarde, así
 * que el dinero desaparecía del panel.
 *
 * Esta bandeja es ese "después". No inventa cobros: cerrar con motivo deja
 * de mostrar la cuenta, pero el dinero nunca entra al corte.
 */
interface SettlementRow {
    id: number;
    room: string | null;
    guest_name: string;
    guest_phone: string | null;
    reservation_id: number | null;
    reservation_code: string | null;
    check_in_at: string;
    check_out_at: string | null;
    check_out_at_input: string | null;
    planned_end_at: string;
    amount: number;
    pending: number;
    auto_closed: boolean;
    settlement_closed_at: string | null;
    settlement_note: string | null;
    extra_charges: { concept: string; amount: number }[];
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    property: { id: number; name: string };
    stays: {
        data: SettlementRow[];
        links: PaginationLink[];
        total: number;
    };
    filters: { q: string; cerradas: boolean };
    pendingCount: number;
    canManage: boolean;
}>();

const toast = useToasts();
const { methods, first, labelFor } = useCounterMethods();

const q = ref(props.filters.q);
const showClosed = ref(props.filters.cerradas);

function reload() {
    router.get(
        route('tenant.reservations.settlements'),
        {
            q: q.value || undefined,
            cerradas: showClosed.value ? 1 : undefined,
        },
        { preserveState: true, replace: true, only: ['stays', 'filters'] },
    );
}

let timer: ReturnType<typeof setTimeout> | null = null;
watch(q, () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(reload, 350);
});
watch(showClosed, reload);

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n ?? 0);

// Lo que falta cobrar en la página que se está viendo. El total de la
// bandeja completa lo manda el servidor (pendingCount): sumar solo lo
// visible daría una cifra que cambia al pasar de página.
const pageTotal = computed(() =>
    props.stays.data.reduce((sum, row) => sum + (row.pending ?? 0), 0),
);

/* --- Acciones sobre una cuenta ------------------------------------- */

type ActionKind = 'pay' | 'charge' | 'checkout' | 'close';

const action = ref<{ kind: ActionKind; stay: SettlementRow } | null>(null);
const busy = ref(false);
const error = ref<string | null>(null);

const form = reactive({
    method: 'cash',
    reference: '',
    concept: '',
    amount: '' as string | number,
    check_out_at: '',
    note: '',
});

const meta: Record<
    ActionKind,
    { title: string; icon: string; cta: string; hint: string }
> = {
    pay: {
        title: 'Registrar el cobro',
        icon: 'Banknote',
        cta: 'Registrar cobro',
        hint: 'Entra al corte de tu turno de hoy, no al del día de la estancia: el dinero se recibe ahora.',
    },
    charge: {
        title: 'Agregar lo que faltó',
        icon: 'ReceiptText',
        cta: 'Agregar al saldo',
        hint: 'Consumos, daños o cargos que no se capturaron a tiempo. Suben el saldo de esta cuenta.',
    },
    checkout: {
        title: 'Corregir la hora de salida',
        icon: 'Clock',
        cta: 'Guardar la hora',
        hint: 'El cierre automático la pone a los 15 minutos de la salida prevista. Si el huésped se fue a otra hora, aquí se endereza.',
    },
    close: {
        title: 'Cerrar sin cobrar',
        icon: 'Archive',
        cta: 'Cerrar la cuenta',
        hint: 'Cortesía, incobrable o error de captura. El motivo queda escrito: el dinero NO entra al corte.',
    },
};

function open(kind: ActionKind, stay: SettlementRow) {
    error.value = null;
    form.method = first.value;
    form.reference = '';
    form.concept = '';
    form.amount = '';
    form.check_out_at = stay.check_out_at_input ?? '';
    form.note = '';
    action.value = { kind, stay };
}

const blocked = computed(() => {
    const current = action.value;
    if (!current) return true;
    if (current.kind === 'charge') {
        return form.concept.trim() === '' || Number(form.amount || 0) <= 0;
    }
    if (current.kind === 'close') return form.note.trim().length < 4;
    if (current.kind === 'checkout') return form.check_out_at === '';

    return false;
});

async function submit() {
    const current = action.value;
    if (!current || blocked.value) return;

    busy.value = true;
    error.value = null;

    const base = `/api/stays/${current.stay.id}/settlement`;

    try {
        if (current.kind === 'pay') {
            const { data } = await axios.post(`${base}/payment`, {
                method: form.method,
                reference: form.reference.trim() || undefined,
            });
            toast.success(
                'Cobro registrado',
                `${current.stay.guest_name} · ${labelFor(form.method)}${
                    data.pending > 0
                        ? `. Todavía debe ${money(data.pending)}.`
                        : '. Cuenta liquidada.'
                }`,
            );
        } else if (current.kind === 'charge') {
            await axios.post(`${base}/charge`, {
                concept: form.concept.trim(),
                amount: Number(form.amount),
            });
            toast.success(
                'Cargo agregado',
                `${form.concept.trim()} · ${money(Number(form.amount))}`,
            );
        } else if (current.kind === 'checkout') {
            await axios.patch(`${base}/checked-out-at`, {
                check_out_at: form.check_out_at,
            });
            toast.success('Hora corregida', 'La salida quedó con su hora real.');
        } else {
            await axios.patch(`${base}/close`, { note: form.note.trim() });
            toast.success(
                'Cuenta cerrada',
                'Sale de la bandeja con su motivo anotado; el dinero no entró al corte.',
            );
        }

        action.value = null;
        router.reload({ only: ['stays', 'pendingCount'] });
    } catch (e: any) {
        const data = e.response?.data;
        const firstError = data?.errors
            ? (Object.values(data.errors as Record<string, string[]>)[0] ?? [])[0]
            : null;
        error.value =
            data?.message ?? firstError ?? 'No se pudo completar la acción.';
    } finally {
        busy.value = false;
    }
}

async function reopen(stay: SettlementRow) {
    try {
        await axios.patch(`/api/stays/${stay.id}/settlement/reopen`);
        toast.success('Cuenta reabierta', 'Vuelve a la bandeja para cobrarla.');
        router.reload({ only: ['stays', 'pendingCount'] });
    } catch {
        toast.error('No se pudo reabrir', 'Intenta de nuevo.');
    }
}
</script>

<template>
    <RazeLayout title="Cuentas por cerrar">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                    >
                        <Lucide icon="ReceiptText" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Cuentas por cerrar
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Estancias que ya se cerraron y a las que les quedó
                            dinero sin registrar.
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        :as="Link"
                        :href="route('tenant.reservations')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="ArrowLeft"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.3]"
                        />
                        Volver a reservas
                    </Button>
                </div>
            </div>

            <div class="box box--stacked mt-5">
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div class="relative w-full sm:w-72">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                        />
                        <FormInput
                            v-model="q"
                            type="text"
                            placeholder="Buscar por huésped o habitación…"
                            class="h-9 pl-9 text-xs"
                        />
                    </div>
                    <div
                        class="inline-flex gap-1 rounded-[0.5rem] bg-slate-100/80 p-1 dark:bg-darkmode-700"
                    >
                        <button
                            type="button"
                            class="rounded-[0.4rem] px-2.5 py-1 text-xs font-medium transition"
                            :class="
                                !showClosed
                                    ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                    : 'text-slate-500'
                            "
                            @click="showClosed = false"
                        >
                            Con saldo ({{ pendingCount }})
                        </button>
                        <button
                            type="button"
                            class="rounded-[0.4rem] px-2.5 py-1 text-xs font-medium transition"
                            :class="
                                showClosed
                                    ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                    : 'text-slate-500'
                            "
                            @click="showClosed = true"
                        >
                            Cerradas con motivo
                        </button>
                    </div>
                    <span
                        v-if="!showClosed && stays.data.length"
                        class="ml-auto text-xs text-slate-500"
                    >
                        En esta página: {{ money(pageTotal) }} sin cobrar.
                    </span>
                </div>

                <div class="overflow-auto p-4 lg:overflow-visible">
                    <Table v-if="stays.data.length" striped>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Habitación</Table.Th>
                                <Table.Th>Huésped</Table.Th>
                                <Table.Th>Estancia</Table.Th>
                                <Table.Th>Saldo</Table.Th>
                                <Table.Th v-if="canManage" class="text-right"
                                    >Acciones</Table.Th
                                >
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="s in stays.data" :key="s.id">
                                <Table.Td class="font-medium">
                                    {{ s.room ?? '—' }}
                                    <span
                                        v-if="s.auto_closed"
                                        class="mt-0.5 block text-[11px] text-slate-500"
                                        title="Nadie registró la salida: la cerró el reloj"
                                        >cerró sola</span
                                    >
                                </Table.Td>
                                <Table.Td>
                                    <span class="text-sm font-medium">{{
                                        s.guest_name
                                    }}</span>
                                    <span
                                        v-if="s.reservation_code"
                                        class="block text-xs text-slate-500"
                                        >{{ s.reservation_code }}</span
                                    >
                                    <a
                                        v-if="s.guest_phone"
                                        :href="`tel:${s.guest_phone}`"
                                        class="text-xs text-primary hover:underline"
                                        >{{ s.guest_phone }}</a
                                    >
                                </Table.Td>
                                <Table.Td class="text-xs">
                                    {{ s.check_in_at }}
                                    <span class="text-slate-400">→</span>
                                    {{ s.check_out_at ?? '—' }}
                                </Table.Td>
                                <Table.Td>
                                    <span
                                        class="text-sm font-medium"
                                        :class="
                                            s.settlement_closed_at
                                                ? 'text-slate-500'
                                                : 'text-pending'
                                        "
                                        >{{ money(s.pending) }}</span
                                    >
                                    <span
                                        class="block text-[11px] text-slate-500"
                                        >de {{ money(s.amount) }}</span
                                    >
                                    <span
                                        v-if="s.settlement_note"
                                        class="mt-0.5 block text-[11px] text-slate-500"
                                        >{{ s.settlement_note }}</span
                                    >
                                </Table.Td>
                                <Table.Td v-if="canManage">
                                    <div
                                        class="flex flex-wrap justify-end gap-1.5"
                                    >
                                        <template
                                            v-if="!s.settlement_closed_at"
                                        >
                                            <Button
                                                variant="primary"
                                                class="h-8 rounded-[0.5rem] text-xs whitespace-nowrap"
                                                @click="open('pay', s)"
                                            >
                                                <Lucide
                                                    icon="Banknote"
                                                    class="mr-1.5 h-3.5 w-3.5"
                                                />
                                                Cobrar
                                            </Button>
                                            <button
                                                type="button"
                                                class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-primary dark:hover:bg-darkmode-400"
                                                title="Agregar lo que faltó"
                                                @click="open('charge', s)"
                                            >
                                                <Lucide
                                                    icon="ReceiptText"
                                                    class="h-4 w-4"
                                                />
                                            </button>
                                            <button
                                                type="button"
                                                class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-primary dark:hover:bg-darkmode-400"
                                                title="Corregir la hora de salida"
                                                @click="open('checkout', s)"
                                            >
                                                <Lucide
                                                    icon="Clock"
                                                    class="h-4 w-4"
                                                />
                                            </button>
                                            <button
                                                type="button"
                                                class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-warning dark:hover:bg-darkmode-400"
                                                title="Cerrar sin cobrar"
                                                @click="open('close', s)"
                                            >
                                                <Lucide
                                                    icon="Archive"
                                                    class="h-4 w-4"
                                                />
                                            </button>
                                        </template>
                                        <Button
                                            v-else
                                            variant="outline-secondary"
                                            class="h-8 rounded-[0.5rem] bg-white text-xs whitespace-nowrap"
                                            @click="reopen(s)"
                                        >
                                            <Lucide
                                                icon="RotateCcw"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Reabrir
                                        </Button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div v-else class="py-10 text-center text-sm text-slate-500">
                        {{
                            filters.q
                                ? 'Nada coincide con la búsqueda.'
                                : showClosed
                                  ? 'Ninguna cuenta se ha cerrado con motivo.'
                                  : 'No hay cuentas pendientes: todo lo que se cerró quedó cobrado.'
                        }}
                    </div>

                    <div
                        v-if="stays.links.length > 3"
                        class="mt-4 flex flex-wrap justify-center gap-1"
                    >
                        <template v-for="(link, i) in stays.links" :key="i">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-state
                                class="rounded-md px-3 py-1.5 text-sm"
                                :class="
                                    link.active
                                        ? 'bg-primary text-white'
                                        : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400'
                                "
                            >
                                <span v-html="link.label" />
                            </Link>
                            <span
                                v-else
                                class="px-3 py-1.5 text-sm text-slate-400"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Una sola superficie para las cuatro acciones: son el mismo
             renglón visto de cuatro maneras, y cuatro modales distintos
             obligarían a aprenderse cuatro pantallas. -->
        <Dialog :open="action !== null" @close="action = null">
            <Dialog.Panel v-if="action" class="w-full max-w-md">
                <div
                    class="flex items-center gap-3.5 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide
                            :icon="meta[action.kind].icon as any"
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-sm font-medium">
                            {{ meta[action.kind].title }}
                        </h3>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Hab. {{ action.stay.room ?? '—' }} ·
                            {{ action.stay.guest_name }} ·
                            {{ money(action.stay.pending) }} sin cobrar
                        </p>
                    </div>
                </div>

                <div class="space-y-4 px-5 py-4">
                    <p class="text-xs text-slate-500">
                        {{ meta[action.kind].hint }}
                    </p>

                    <template v-if="action.kind === 'pay'">
                        <div>
                            <FormLabel class="text-xs"
                                >¿Cómo lo recibiste?</FormLabel
                            >
                            <FormSelect
                                v-model="form.method"
                                class="h-9 text-xs"
                            >
                                <option
                                    v-for="m in methods"
                                    :key="m.key"
                                    :value="m.key"
                                >
                                    {{ m.label }}
                                </option>
                            </FormSelect>
                        </div>
                        <div v-if="form.method !== 'cash'">
                            <FormLabel class="text-xs"
                                >Referencia (opcional)</FormLabel
                            >
                            <FormInput
                                v-model="form.reference"
                                type="text"
                                class="h-9 text-xs"
                                placeholder="Folio del voucher o de la transferencia"
                                maxlength="100"
                            />
                        </div>
                    </template>

                    <template v-else-if="action.kind === 'charge'">
                        <div>
                            <FormLabel class="text-xs">Concepto</FormLabel>
                            <FormInput
                                v-model="form.concept"
                                type="text"
                                class="h-9 text-xs"
                                placeholder="Consumo del frigobar, daño en la lámpara…"
                                maxlength="100"
                            />
                        </div>
                        <div>
                            <FormLabel class="text-xs">Monto</FormLabel>
                            <FormInput
                                v-model="form.amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                class="h-9 text-xs"
                            />
                        </div>
                    </template>

                    <template v-else-if="action.kind === 'checkout'">
                        <div>
                            <FormLabel class="text-xs"
                                >Salida real del huésped</FormLabel
                            >
                            <FormDateTime
                                v-model="form.check_out_at"
                                input-class="h-9 text-xs"
                            />
                            <p class="mt-1 text-[11px] text-slate-500">
                                Entrada: {{ action.stay.check_in_at }} ·
                                registrada hoy como
                                {{ action.stay.check_out_at ?? '—' }}
                            </p>
                        </div>
                    </template>

                    <template v-else>
                        <div>
                            <FormLabel class="text-xs"
                                >¿Por qué no se cobra?</FormLabel
                            >
                            <FormInput
                                v-model="form.note"
                                type="text"
                                class="h-9 text-xs"
                                placeholder="Cortesía, incobrable, se capturó dos veces…"
                                maxlength="255"
                            />
                        </div>
                    </template>

                    <p
                        v-if="error"
                        class="rounded-lg bg-danger/10 px-3.5 py-3 text-xs text-danger"
                    >
                        {{ error }}
                    </p>
                </div>

                <div
                    class="flex justify-end gap-2 border-t border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <Button
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                        @click="action = null"
                    >
                        Volver
                    </Button>
                    <Button
                        :variant="
                            action.kind === 'close' ? 'warning' : 'primary'
                        "
                        class="h-9 rounded-[0.5rem] text-xs"
                        :disabled="busy || blocked"
                        @click="submit"
                    >
                        <Lucide
                            :icon="meta[action.kind].icon as any"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        {{ busy ? 'Guardando…' : meta[action.kind].cta }}
                    </Button>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
