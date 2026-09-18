<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { onBeforeUnmount, onMounted } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import PaymentsNav from './PaymentsNav.vue';

interface OverdueBalance {
    id: number;
    code: string;
    guest_name: string;
    pending_label: string;
    due_label: string;
    starts_label: string;
    conversation_id: number | null;
}

interface PendingLink {
    id: number;
    subject: string;
    concept: string;
    amount_label: string;
    provider: string | null;
    checkout_url: string | null;
    expires_label: string | null;
    created_label: string;
}

defineProps<{
    overdueBalances: OverdueBalance[];
    pendingLinks: PendingLink[];
    canManage: boolean;
    canCashCuts: boolean;
    canSettings: boolean;
}>();

const toast = useToasts();

// Los links caducan y los saldos cambian cuando alguien paga: se refresca sola.
let poller: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    poller = setInterval(() => {
        router.reload({ only: ['overdueBalances', 'pendingLinks'] });
    }, 30000);
});
onBeforeUnmount(() => {
    if (poller) clearInterval(poller);
});

async function copyLink(link: PendingLink) {
    if (!link.checkout_url) return;
    try {
        await navigator.clipboard.writeText(link.checkout_url);
        toast.success(
            'Link copiado',
            `${link.amount_label} — compártelo con el huésped.`,
        );
    } catch {
        toast.error('No se pudo copiar', link.checkout_url);
    }
}

async function cancelLink(link: PendingLink) {
    try {
        await axios.delete(`/api/payment-requests/${link.id}`);
        toast.success('Cobro cancelado', 'El link deja de aceptar pagos.');
        router.reload({ only: ['pendingLinks'] });
    } catch (e: any) {
        toast.error(
            'Error',
            e.response?.data?.message ?? 'No se pudo cancelar el cobro.',
        );
    }
}
</script>

<template>
    <RazeLayout title="Por cobrar">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="TriangleAlert" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Por cobrar</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Saldos que ya vencieron y links de pago todavía
                            vivos.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Link
                        :href="route('tenant.payments')"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Volver a caja y pagos
                    </Link>
                </div>
            </div>

            <PaymentsNav
                current="collect"
                :can-manage="canManage"
                :can-cash-cuts="canCashCuts"
                :badges="{ collect: overdueBalances.length }"
            />

            <div
                v-if="
                    canManage && !overdueBalances.length && !pendingLinks.length
                "
                class="box box--stacked mt-4 flex flex-col items-center gap-2 px-4 py-10 text-slate-400"
            >
                <Lucide icon="CircleCheck" class="h-6 w-6 text-success" />
                <p class="text-xs">
                    Nada por cobrar: ningún saldo vencido ni links pendientes.
                </p>
            </div>
            <!-- Saldos vencidos -->
            <div
                v-if="canManage && overdueBalances.length"
                class="box box--stacked mt-4"
            >
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10"
                    >
                        <Lucide
                            icon="TriangleAlert"
                            class="h-4 w-4 text-warning"
                        />
                    </div>
                    <div>
                        <div class="text-sm font-medium">Saldos vencidos</div>
                        <p class="text-xs text-slate-500">
                            Reservas confirmadas cuya fecha límite de pago ya
                            pasó; decide si contactar, extender o cancelar.
                        </p>
                    </div>
                    <span
                        class="ml-auto rounded-full bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning"
                        >{{ overdueBalances.length }}</span
                    >
                </div>
                <div
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="item in overdueBalances"
                        :key="item.id"
                        class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate text-sm font-medium">{{
                                    item.guest_name
                                }}</span>
                                <span
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                                    >{{ item.code }}</span
                                >
                                <span
                                    class="rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                                    >Debe {{ item.pending_label }}</span
                                >
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Venció {{ item.due_label }} · llega el
                                {{ item.starts_label }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            <Button
                                v-if="item.conversation_id"
                                as="a"
                                :href="route('tenant.inbox')"
                                variant="outline-secondary"
                                class="h-8 rounded-[0.5rem] bg-white text-xs"
                                title="Abrir la Bandeja para dar seguimiento"
                            >
                                <Lucide
                                    icon="MessagesSquare"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Conversación
                            </Button>
                            <Button
                                as="a"
                                :href="route('tenant.reservations')"
                                variant="outline-secondary"
                                class="h-8 rounded-[0.5rem] bg-white text-xs"
                                title="Gestionar la reserva en el módulo de reservas"
                            >
                                <Lucide
                                    icon="CalendarDays"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Reserva
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Links de pago vivos -->
            <div
                v-if="pendingLinks.length || overdueBalances.length"
                class="box box--stacked mt-4"
            >
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                    >
                        <Lucide icon="Link" class="h-4 w-4 text-primary" />
                    </div>
                    <div>
                        <div class="text-sm font-medium">
                            Links de pago vivos
                        </div>
                        <p class="text-xs text-slate-500">
                            Cobros de pasarela emitidos y aún sin pagar —
                            cópialos para compartir o cancélalos si ya no
                            aplican.
                        </p>
                    </div>
                    <span
                        v-if="pendingLinks.length"
                        class="ml-auto rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                        >{{ pendingLinks.length }}</span
                    >
                </div>
                <div
                    v-if="pendingLinks.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="link in pendingLinks"
                        :key="link.id"
                        class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium">{{
                                    link.subject
                                }}</span>
                                <span
                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                    >{{ link.concept }} ·
                                    {{ link.amount_label }}</span
                                >
                                <span
                                    v-if="link.provider"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 capitalize dark:bg-darkmode-400"
                                    >{{ link.provider }}</span
                                >
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Emitido {{ link.created_label
                                }}<template v-if="link.expires_label">
                                    · vence {{ link.expires_label }}</template
                                >
                            </p>
                        </div>
                        <div
                            v-if="canManage"
                            class="flex shrink-0 items-center gap-1.5"
                        >
                            <Button
                                v-if="link.checkout_url"
                                variant="outline-secondary"
                                class="h-8 rounded-[0.5rem] bg-white text-xs"
                                @click="copyLink(link)"
                            >
                                <Lucide
                                    icon="Copy"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Copiar link
                            </Button>
                            <button
                                type="button"
                                class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                title="Cancelar el cobro (el link deja de aceptar pagos)"
                                @click="cancelLink(link)"
                            >
                                <Lucide icon="Ban" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
                <div
                    v-else
                    class="px-4 py-6 text-center text-xs text-slate-500"
                >
                    Sin links de pago vivos.
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
