<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormSwitch } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import TenantEditModal from './TenantEditModal.vue';
import TenantHeader from './TenantHeader.vue';
import type { PlanOption, TenantShell } from './types';

interface AddonServiceRow {
    key: string;
    name: string;
    summary: string | null;
    price_monthly: number;
    activation_fee: number;
    modules: string[];
    module_labels: string[];
    ai_monthly_replies: number | null;
    requires: string | null;
    active: boolean;
    contracted: boolean;
}

const props = defineProps<{
    tenant: TenantShell;
    plans: PlanOption[];
    limits: {
        max_properties: number | null;
        max_rooms: number | null;
        max_users: number | null;
        ai_enabled: boolean;
        ai_monthly_replies: number | null;
    };
    usage: { properties: number; rooms: number; users: number };
    billing: {
        plan_monthly: number;
        addons_monthly: number;
        total_monthly: number;
    };
    addonServices: AddonServiceRow[];
}>();

const toast = useToasts();
const pesos = (n: number) => `$${n.toLocaleString('es-MX')}`;

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';

// Los nombres largos del documento comercial ("… – Modalidad N: Título")
// se parten: el título manda y el prefijo queda de rótulo.
const titleOf = (name: string) => name.split(' – ').pop() ?? name;
const byKey = (key: string | null) =>
    props.addonServices.find((s) => s.key === key);

// Cuánto de su tope lleva usado: el dato que dice si le queda plan o ya
// hay que subirlo.
const rows = computed(() =>
    [
        {
            label: 'Propiedades',
            icon: 'Building2' as const,
            used: props.usage.properties,
            cap: props.limits.max_properties,
        },
        {
            label: 'Habitaciones',
            icon: 'BedDouble' as const,
            used: props.usage.rooms,
            cap: props.limits.max_rooms,
        },
        {
            label: 'Usuarios',
            icon: 'Users' as const,
            used: props.usage.users,
            cap: props.limits.max_users,
        },
    ].map((row) => {
        const pct = row.cap
            ? Math.min(100, Math.round((row.used / row.cap) * 100))
            : null;
        return {
            ...row,
            pct,
            over: row.cap !== null && row.used > row.cap,
            full: row.cap !== null && row.used >= row.cap,
        };
    }),
);
const atAnyCap = computed(() => rows.value.some((r) => r.full));

const barTone = (pct: number | null) =>
    pct === null
        ? 'bg-slate-300'
        : pct >= 100
          ? 'bg-danger'
          : pct >= 80
            ? 'bg-warning'
            : 'bg-primary';

const contracted = computed(() =>
    props.addonServices.filter((s) => s.contracted),
);
const pausedContracted = computed(() =>
    contracted.value.filter((s) => !s.active),
);

// ── Contratar / retirar ─────────────────────────────────────────────────
// El interruptor no cambia solo (click.prevent): refleja lo que diga el
// servidor, así un rechazo nunca lo deja mintiendo.
const pending = ref<string | null>(null);
const retiring = ref<AddonServiceRow | null>(null);

// Al retirar uno caen también los contratados que lo amplían.
const retiringAlso = computed(() =>
    retiring.value
        ? props.addonServices.filter(
              (s) => s.requires === retiring.value!.key && s.contracted,
          )
        : [],
);

function blockedReason(service: AddonServiceRow): string | null {
    if (service.contracted) return null;
    if (!service.active) return 'Fuera del catálogo: no se puede contratar.';
    const required = byKey(service.requires);
    if (required && !required.contracted) {
        return `Amplía «${titleOf(required.name)}»: contrátalo primero.`;
    }
    return null;
}

function requestToggle(service: AddonServiceRow) {
    if (pending.value) return;
    if (service.contracted) {
        retiring.value = service;
        return;
    }
    if (blockedReason(service)) return;
    send(service, true);
}

function send(service: AddonServiceRow, contract: boolean) {
    pending.value = service.key;
    router.patch(
        route('admin.tenants.addon-services', [props.tenant.id, service.key]),
        { contracted: contract },
        {
            preserveScroll: true,
            onSuccess: () => {
                retiring.value = null;
                toast.success(
                    contract ? 'Servicio contratado' : 'Servicio retirado',
                    contract
                        ? `${titleOf(service.name)}: se suma ${pesos(service.price_monthly)} MXN/mes`
                        : `${titleOf(service.name)}: dejó de cobrarse y lo que incluye se apagó`,
                );
            },
            onError: (errors) =>
                toast.error(
                    'No se pudo actualizar',
                    errors.service ?? 'Ocurrió un error.',
                ),
            onFinish: () => (pending.value = null),
        },
    );
}

const editingPlan = ref(false);
</script>

<template>
    <RazeLayout :title="`${tenant.name} · Plan`">
        <TenantHeader :tenant="tenant" :plans="plans" active="plan" />

        <div class="mt-4 grid grid-cols-12 items-stretch gap-5">
            <!-- Lo que paga -->
            <div class="col-span-12 flex flex-col xl:col-span-5">
                <div class="box box--stacked flex flex-1 flex-col">
                    <div :class="cardHeader">
                        <div
                            :class="[
                                sectionIcon,
                                'border-success/10 bg-success/10 text-success',
                            ]"
                        >
                            <Lucide icon="Receipt" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-medium">Lo que paga</h2>
                            <p class="text-xs text-slate-500">
                                Plan base más servicios adicionales.
                            </p>
                        </div>
                        <Button
                            variant="outline-secondary"
                            class="h-8 shrink-0 rounded-[0.5rem] text-xs"
                            @click="editingPlan = true"
                        >
                            <Lucide icon="Layers" class="mr-1.5 h-3.5 w-3.5" />
                            Cambiar plan
                        </Button>
                    </div>
                    <div class="flex flex-1 flex-col px-4 py-3 text-xs">
                        <div
                            class="divide-y divide-dashed divide-slate-200/70 dark:divide-darkmode-400"
                        >
                            <div
                                class="flex items-center justify-between gap-3 py-2"
                            >
                                <span class="text-slate-500"
                                    >Plan {{ tenant.plan_label }}</span
                                >
                                <span class="font-medium tabular-nums">{{
                                    pesos(billing.plan_monthly)
                                }}</span>
                            </div>
                            <div
                                v-for="s in contracted"
                                :key="s.key"
                                class="flex items-center justify-between gap-3 py-2"
                            >
                                <span
                                    class="min-w-0 truncate"
                                    :class="
                                        s.active
                                            ? 'text-slate-500'
                                            : 'text-slate-400 line-through'
                                    "
                                    :title="s.name"
                                    >{{ titleOf(s.name) }}</span
                                >
                                <span
                                    class="shrink-0 font-medium tabular-nums"
                                    :class="{ 'text-slate-400': !s.active }"
                                    >{{
                                        s.active
                                            ? `+${pesos(s.price_monthly)}`
                                            : 'pausado'
                                    }}</span
                                >
                            </div>
                        </div>
                        <div
                            class="mt-1 flex items-center justify-between border-t border-slate-200/70 pt-3 dark:border-darkmode-400"
                        >
                            <span class="font-medium">Total al mes</span>
                            <span class="text-sm font-medium text-primary"
                                >{{ pesos(billing.total_monthly) }} MXN</span
                            >
                        </div>
                        <div
                            v-if="pausedContracted.length"
                            class="mt-3 flex items-start gap-2 rounded-lg bg-slate-100 px-3 py-2 text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                        >
                            <Lucide
                                icon="CirclePause"
                                class="mt-px h-3.5 w-3.5 shrink-0"
                            />
                            Tiene contratado un servicio que salió del catálogo:
                            no se cobra ni enciende nada hasta que se reactive
                            en Servicios adicionales.
                        </div>

                        <div
                            class="mt-auto flex items-center gap-3 rounded-lg border border-dashed border-slate-200/70 px-3 py-2.5 dark:border-darkmode-400"
                            :class="{ 'mt-4': true }"
                        >
                            <Lucide
                                icon="Bot"
                                class="h-4 w-4 shrink-0 text-slate-400"
                            />
                            <div class="min-w-0 flex-1">
                                <div class="font-medium">
                                    Asistente IA
                                    {{
                                        limits.ai_enabled
                                            ? 'incluido'
                                            : 'fuera del plan'
                                    }}
                                </div>
                                <div class="text-[11px] text-slate-500">
                                    <template
                                        v-if="
                                            limits.ai_monthly_replies !== null
                                        "
                                        >{{
                                            limits.ai_monthly_replies.toLocaleString(
                                                'es-MX',
                                            )
                                        }}
                                        respuestas al mes del plan</template
                                    >
                                    <template v-else
                                        >Sin tope de respuestas</template
                                    >
                                </div>
                            </div>
                            <Link
                                :href="
                                    route('admin.tenants.assistant', tenant.id)
                                "
                                class="shrink-0 font-medium text-primary"
                                >Ajustar</Link
                            >
                        </div>
                    </div>
                </div>
            </div>

            <!-- Qué tanto le queda del plan -->
            <div class="col-span-12 flex flex-col xl:col-span-7">
                <div class="box box--stacked flex flex-1 flex-col">
                    <div :class="cardHeader">
                        <div
                            :class="[
                                sectionIcon,
                                atAnyCap
                                    ? 'border-warning/10 bg-warning/10 text-warning'
                                    : 'border-primary/10 bg-primary/10 text-primary',
                            ]"
                        >
                            <Lucide icon="Gauge" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-sm font-medium">
                                Uso contra sus topes
                            </h2>
                            <p class="text-xs text-slate-500">
                                Los topes salen del plan; se suben cambiando de
                                plan.
                            </p>
                        </div>
                    </div>
                    <div
                        class="flex flex-1 flex-col justify-center gap-4 px-4 py-4"
                    >
                        <div v-for="row in rows" :key="row.label">
                            <div
                                class="flex items-center justify-between gap-3 text-xs"
                            >
                                <span
                                    class="inline-flex items-center gap-1.5 text-slate-500"
                                >
                                    <Lucide
                                        :icon="row.icon"
                                        class="h-3.5 w-3.5"
                                    />
                                    {{ row.label }}
                                </span>
                                <span class="font-medium tabular-nums">
                                    {{ row.used }}
                                    <span class="font-normal text-slate-400">
                                        de {{ row.cap ?? 'sin límite' }}</span
                                    >
                                    <span
                                        v-if="row.full"
                                        class="ml-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            row.over
                                                ? 'bg-danger/10 text-danger'
                                                : 'bg-warning/10 text-warning'
                                        "
                                        >{{
                                            row.over ? 'Excedido' : 'Al tope'
                                        }}</span
                                    >
                                </span>
                            </div>
                            <div
                                class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                            >
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="barTone(row.pct)"
                                    :style="{ width: `${row.pct ?? 4}%` }"
                                />
                            </div>
                        </div>
                        <p
                            v-if="atAnyCap"
                            class="flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning"
                        >
                            <Lucide
                                icon="TriangleAlert"
                                class="mt-px h-3.5 w-3.5 shrink-0"
                            />
                            Llegó al tope: el hotel ya no puede dar de alta más
                            de eso. Es buen momento para ofrecerle el siguiente
                            plan.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Servicios adicionales -->
            <div class="col-span-12">
                <div class="box box--stacked">
                    <div :class="cardHeader">
                        <div
                            :class="[
                                sectionIcon,
                                'border-primary/10 bg-primary/10 text-primary',
                            ]"
                        >
                            <Lucide icon="PackagePlus" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-medium">
                                Servicios adicionales
                            </h2>
                            <p class="text-xs text-slate-500">
                                {{ contracted.length }} contratado{{
                                    contracted.length === 1 ? '' : 's'
                                }}
                                <template v-if="billing.addons_monthly">
                                    · +{{ pesos(billing.addons_monthly) }} al
                                    mes</template
                                >
                            </p>
                        </div>
                        <Link
                            :href="route('admin.services')"
                            class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-[0.5rem] border border-slate-200 px-3 text-xs font-medium text-slate-600 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:text-slate-300"
                        >
                            Catálogo y precios
                            <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                        </Link>
                    </div>
                    <div
                        class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                    >
                        <div
                            v-for="service in addonServices"
                            :key="service.key"
                            class="flex items-start gap-3 px-4 py-3 sm:px-5"
                        >
                            <div
                                class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border"
                                :class="
                                    service.contracted
                                        ? 'border-success/10 bg-success/10 text-success'
                                        : 'border-slate-200 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400'
                                "
                            >
                                <Lucide
                                    :icon="
                                        service.contracted
                                            ? 'CircleCheck'
                                            : 'PackagePlus'
                                    "
                                    class="h-3.5 w-3.5"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <span
                                        class="text-sm font-medium"
                                        :title="service.name"
                                        >{{ titleOf(service.name) }}</span
                                    >
                                    <span
                                        v-if="!service.active"
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                        >Fuera del catálogo</span
                                    >
                                </div>
                                <p
                                    v-if="service.summary"
                                    class="mt-0.5 line-clamp-1 text-xs text-slate-500"
                                    :title="service.summary"
                                >
                                    {{ service.summary }}
                                </p>
                                <div
                                    class="mt-1.5 flex flex-wrap items-center gap-1.5"
                                >
                                    <span
                                        v-for="label in service.module_labels"
                                        :key="label"
                                        class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                        >{{ label }}</span
                                    >
                                    <span
                                        v-if="service.ai_monthly_replies"
                                        class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                        >{{
                                            service.ai_monthly_replies.toLocaleString(
                                                'es-MX',
                                            )
                                        }}
                                        respuestas IA/mes</span
                                    >
                                    <span
                                        v-if="blockedReason(service)"
                                        class="inline-flex items-center gap-1 rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                    >
                                        <Lucide icon="Link" class="h-3 w-3" />
                                        {{ blockedReason(service) }}
                                    </span>
                                </div>
                            </div>
                            <div
                                class="hidden w-32 shrink-0 text-right sm:block"
                            >
                                <div class="text-sm font-medium tabular-nums">
                                    {{ pesos(service.price_monthly) }}
                                    <span
                                        class="text-[11px] font-normal text-slate-500"
                                        >/mes</span
                                    >
                                </div>
                                <div class="text-[11px] text-slate-400">
                                    {{ pesos(service.activation_fee) }}
                                    activación
                                </div>
                            </div>
                            <FormSwitch
                                class="mt-1 shrink-0"
                                :title="
                                    blockedReason(service) ??
                                    (service.contracted
                                        ? 'Retirar a este hotel'
                                        : 'Contratar para este hotel')
                                "
                            >
                                <FormSwitch.Input
                                    :checked="service.contracted"
                                    type="checkbox"
                                    :disabled="
                                        pending === service.key ||
                                        !!blockedReason(service)
                                    "
                                    @click.prevent="requestToggle(service)"
                                />
                            </FormSwitch>
                        </div>
                    </div>
                    <p
                        class="border-t border-slate-200/60 px-4 py-2.5 text-[11px] text-slate-400 dark:border-darkmode-400"
                    >
                        Contratar enciende al instante lo que incluye y suma su
                        precio; retirarlo lo apaga sin borrar datos. La cuota de
                        activación se cobra aparte, una sola vez.
                    </p>
                </div>
            </div>
        </div>

        <TenantEditModal
            :tenant="editingPlan ? tenant : null"
            :plans="plans"
            @close="editingPlan = false"
        />

        <!-- Confirmación: retirar un servicio -->
        <Dialog
            :open="retiring !== null"
            @close="pending === null && (retiring = null)"
        >
            <Dialog.Panel>
                <div v-if="retiring" class="p-5">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                        >
                            <Lucide icon="Unplug" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                Retirar «{{ titleOf(retiring.name) }}»
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ tenant.name }} deja de pagar
                                {{ pesos(retiring.price_monthly) }} al mes y
                                pierde al instante lo que incluye. Sus datos se
                                conservan y se puede volver a contratar.
                            </p>
                            <div
                                v-if="retiring.module_labels.length"
                                class="mt-3 flex flex-wrap gap-1.5"
                            >
                                <span
                                    v-for="label in retiring.module_labels"
                                    :key="label"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                    >{{ label }}</span
                                >
                            </div>
                            <div
                                v-if="retiringAlso.length"
                                class="mt-3 rounded-lg border border-dashed border-danger/30 px-3 py-2 text-xs"
                            >
                                <div class="font-medium text-danger">
                                    También se le retira, porque lo amplía:
                                </div>
                                <ul
                                    class="mt-1 list-disc pl-4 text-slate-600 dark:text-slate-300"
                                >
                                    <li v-for="d in retiringAlso" :key="d.key">
                                        {{ titleOf(d.name) }}
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="pending !== null"
                            @click="retiring = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="pending !== null"
                            @click="send(retiring, false)"
                        >
                            <Lucide icon="Unplug" class="mr-1.5 h-3.5 w-3.5" />
                            {{ pending ? 'Retirando...' : 'Sí, retirar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
