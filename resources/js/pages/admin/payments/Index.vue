<script setup lang="ts">
import axios from 'axios';
import { ref } from 'vue';
import { FormHelp, FormSwitch } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

type MethodKey = string;

interface MethodRow {
    method: MethodKey;
    label: string;
    enabled: boolean;
}

interface GatewayRow {
    id: number;
    tenant_id: string;
    tenant_name: string;
    provider: string;
    provider_label: string;
    mode: string;
    active: boolean;
    last_event_at: string | null;
}

const props = defineProps<{
    methods: MethodRow[];
    gateways: GatewayRow[];
}>();

const toast = useToasts();

// Copia local para poder revertir el switch si el PATCH falla.
const methods = ref<MethodRow[]>(props.methods.map((m) => ({ ...m })));

const fallbackMeta = {
    icon: 'CreditCard' as Icon,
    tone: 'border-primary/10 bg-primary/10 text-primary',
    description: 'Método de cobro en línea',
};

const metaFor = (method: string) => methodMeta[method] ?? fallbackMeta;

const methodMeta: Record<
    string,
    { icon: Icon; tone: string; description: string }
> = {
    transfer: {
        icon: 'Landmark',
        tone: 'border-primary/10 bg-primary/10 text-primary',
        description:
            'Cuentas bancarias del hotel; verificación humana del comprobante',
    },
    stripe: {
        icon: 'CreditCard',
        tone: 'border-info/10 bg-info/10 text-info',
        description: 'Checkout hospedado; se confirma solo por webhook',
    },
    mercadopago: {
        icon: 'Wallet',
        tone: 'border-success/10 bg-success/10 text-success',
        description: 'Checkout hospedado; se confirma solo por webhook',
    },
    cash: {
        icon: 'Banknote',
        tone: 'border-warning/10 bg-warning/10 text-warning',
        description:
            'El huésped aparta sin pagar en línea y paga al llegar; cada hotel lo activa en sus ajustes',
    },
};

async function toggleMethod(m: MethodRow) {
    const next = !m.enabled;
    m.enabled = next;
    try {
        await axios.patch(route('admin.payments.methods'), {
            method: m.method,
            enabled: next,
        });
        toast.success('Método actualizado');
    } catch (e: any) {
        m.enabled = !next;
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    }
}
</script>

<template>
    <RazeLayout title="Pagos">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Pagos</h1>
                    <p class="text-sm text-slate-500">
                        Métodos de cobro de la plataforma y pasarelas conectadas
                        por hotel
                    </p>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-5">
                <!-- Métodos de la plataforma -->
                <div class="col-span-12 xl:col-span-5">
                    <div class="box box--stacked flex h-full flex-col">
                        <div
                            class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                        >
                            <Lucide
                                icon="CreditCard"
                                class="h-4 w-4 stroke-[1.5] text-primary"
                            />
                            <h2 class="text-base font-medium">
                                Métodos de la plataforma
                            </h2>
                        </div>
                        <div
                            class="flex-1 divide-y divide-dashed divide-slate-300/70 px-5 py-2"
                        >
                            <div
                                v-for="m in methods"
                                :key="m.method"
                                class="flex items-center gap-3 py-3.5"
                                :class="{ 'opacity-60': !m.enabled }"
                            >
                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border"
                                    :class="metaFor(m.method).tone"
                                >
                                    <Lucide
                                        :icon="metaFor(m.method).icon"
                                        class="h-5 w-5"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium">
                                        {{ m.label }}
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        {{ metaFor(m.method).description }}
                                    </p>
                                </div>
                                <FormSwitch class="shrink-0">
                                    <FormSwitch.Input
                                        :checked="m.enabled"
                                        type="checkbox"
                                        @change="toggleMethod(m)"
                                    />
                                </FormSwitch>
                            </div>
                        </div>
                        <div
                            class="border-t border-dashed border-slate-300/70 px-5 pt-2 pb-4"
                        >
                            <FormHelp>
                                Apagar un método aquí lo desaparece para TODOS
                                los hoteles: no se ofrece por el bot ni se puede
                                configurar en su panel; lo ya conectado queda
                                pausado.
                            </FormHelp>
                        </div>
                    </div>
                </div>

                <!-- Pasarelas conectadas por hotel (solo radiografía) -->
                <div class="col-span-12 xl:col-span-7">
                    <div class="box box--stacked flex h-full flex-col">
                        <div
                            class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                        >
                            <Lucide
                                icon="PlugZap"
                                class="h-4 w-4 stroke-[1.5] text-primary"
                            />
                            <h2 class="text-base font-medium">
                                Pasarelas conectadas
                            </h2>
                            <span
                                class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                                >{{ gateways.length }}</span
                            >
                        </div>
                        <div
                            class="flex-1 overflow-auto p-5 lg:overflow-visible"
                        >
                            <Table v-if="gateways.length" striped>
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th>Hotel</Table.Th>
                                        <Table.Th>Pasarela</Table.Th>
                                        <Table.Th>Estado</Table.Th>
                                        <Table.Th>Último evento</Table.Th>
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    <Table.Tr v-for="g in gateways" :key="g.id">
                                        <Table.Td class="font-medium">{{
                                            g.tenant_name
                                        }}</Table.Td>
                                        <Table.Td>
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <span>{{
                                                    g.provider_label
                                                }}</span>
                                                <span
                                                    v-if="g.mode === 'test'"
                                                    class="rounded-full bg-warning/10 px-2 py-0.5 text-[10px] font-medium text-warning"
                                                >
                                                    Modo prueba
                                                </span>
                                            </div>
                                        </Table.Td>
                                        <Table.Td>
                                            <span
                                                class="rounded-full px-2 py-0.5 text-xs"
                                                :class="
                                                    g.active
                                                        ? 'bg-success/10 text-success'
                                                        : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                                "
                                            >
                                                {{
                                                    g.active
                                                        ? 'Activa'
                                                        : 'Pausada'
                                                }}
                                            </span>
                                        </Table.Td>
                                        <Table.Td>
                                            <span v-if="g.last_event_at">{{
                                                g.last_event_at
                                            }}</span>
                                            <span v-else class="text-slate-400"
                                                >Sin eventos</span
                                            >
                                        </Table.Td>
                                    </Table.Tr>
                                </Table.Tbody>
                            </Table>
                            <div
                                v-else
                                class="flex h-full flex-col items-center justify-center gap-3 py-10 text-center"
                            >
                                <div
                                    class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"
                                >
                                    <Lucide icon="PlugZap" class="h-6 w-6" />
                                </div>
                                <p class="max-w-sm px-6 text-sm text-slate-500">
                                    Ningún hotel ha conectado una pasarela
                                    todavía. Cuando conecten Stripe o Mercado
                                    Pago desde su panel, aparecerán aquí.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
