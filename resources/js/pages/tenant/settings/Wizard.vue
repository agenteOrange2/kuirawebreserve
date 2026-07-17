<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
} from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ProductRow {
    id: number;
    name: string;
    category: string | null;
    unit: string;
    price: number;
    available_in_wizard: boolean;
    in_stock: boolean;
}

const props = defineProps<{
    property: { id: number; name: string };
    wizardUrl: string;
    settings: {
        guest_policy: 'family' | 'adults_only';
        block_mode_label: string;
        wizard_extras_enabled: boolean;
        payment_mode: 'automatic' | 'always' | 'never';
    };
    hasPosModule: boolean;
    products: ProductRow[];
    paymentReadiness: {
        gateway_connected: boolean;
        gateway_provider: string | null;
        transfer_accounts_count: number;
        ready: boolean;
    };
    canManage: boolean;
}>();

const toast = useToasts();

// ── Modalidad y huéspedes ──
const savingGuest = ref(false);
const guestErrors = reactive<Record<string, string>>({});
const guestForm = reactive({
    guest_policy: props.settings.guest_policy,
    block_mode_label: props.settings.block_mode_label,
});

async function saveGuestSettings() {
    savingGuest.value = true;
    Object.keys(guestErrors).forEach((k) => delete guestErrors[k]);
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: {
                guest_policy: guestForm.guest_policy,
                block_mode_label: guestForm.block_mode_label || null,
            },
        });
        toast.success('Guardado', 'Modalidad y huéspedes actualizados.');
    } catch (e: any) {
        const errs = e.response?.data?.errors;
        if (errs)
            Object.entries(errs).forEach(
                ([k, v]) =>
                    (guestErrors[k.replace('settings.', '')] = (
                        v as string[]
                    )[0]),
            );
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? Object.values(guestErrors)[0],
        );
    } finally {
        savingGuest.value = false;
    }
}

// ── Extras (POS) ──
const savingExtras = ref(false);
const extrasEnabled = ref(props.settings.wizard_extras_enabled);

async function toggleExtras() {
    savingExtras.value = true;
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: { wizard_extras_enabled: extrasEnabled.value },
        });
        toast.success(
            extrasEnabled.value
                ? 'Paso de extras activado'
                : 'Paso de extras desactivado',
            extrasEnabled.value
                ? 'El wizard ya puede ofrecer los productos que marques abajo.'
                : 'El wizard dejó de mostrar el paso de extras.',
        );
    } catch (e: any) {
        extrasEnabled.value = !extrasEnabled.value; // revertir
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        savingExtras.value = false;
    }
}

// ── Pago: solo resumen — el modo de pago y los métodos se configuran en su
// área aislada /ajustes/metodos-pago (todo lo de cobros vive junto allá) ──
const paymentMode = props.settings.payment_mode;
const paymentModeLabels: Record<string, string> = {
    automatic: 'Automático (lo decide cada tarifa)',
    always: 'Siempre pedir pago en línea',
    never: 'Nunca pedir pago en línea',
};

// ── Vista previa: así se ve el wizard hoy, con la config actual ──
const extrasStepActive = computed(
    () =>
        props.hasPosModule &&
        extrasEnabled.value &&
        props.products.some((p) => localProducts[p.id] && p.in_stock),
);
const previewSteps = computed(() => {
    const steps = ['Fechas', 'Tus datos'];
    if (extrasStepActive.value) steps.push('Extras');
    steps.push('Confirmación');
    return steps;
});

const localProducts = reactive<Record<number, boolean>>(
    Object.fromEntries(
        props.products.map((p) => [p.id, p.available_in_wizard]),
    ),
);
const busyProduct = ref<number | null>(null);

const productsByCategory = computed(() => {
    const groups = new Map<string, ProductRow[]>();
    props.products.forEach((p) => {
        const key = p.category ?? 'Sin categoría';
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key)!.push(p);
    });
    return [...groups.entries()];
});

const selectedProductsCount = computed(
    () => Object.values(localProducts).filter(Boolean).length,
);
const outOfStockSelectedCount = computed(
    () =>
        props.products.filter((p) => localProducts[p.id] && !p.in_stock).length,
);

async function toggleProduct(product: ProductRow) {
    const next = !localProducts[product.id];
    localProducts[product.id] = next;
    busyProduct.value = product.id;
    try {
        await axios.patch(`/api/products/${product.id}`, {
            available_in_wizard: next,
        });
    } catch (e: any) {
        localProducts[product.id] = !next; // revertir
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ?? product.name,
        );
    } finally {
        busyProduct.value = null;
    }
}

const money = (n: number) =>
    `$${n.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;
</script>

<template>
    <RazeLayout title="Wizard de reservas">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-lg font-medium">Wizard de reservas</h1>
                    <p class="mt-0.5 text-sm text-slate-500">
                        Área aislada: todo lo que controla cómo se comporta
                        <code
                            class="rounded bg-slate-100 px-1 py-0.5 dark:bg-darkmode-400"
                            >/reservar</code
                        >
                        vive aquí.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        as="a"
                        :href="route('tenant.hotel-settings')"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="ArrowLeft"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Volver a Ajustes
                    </Button>
                    <Button
                        as="a"
                        :href="wizardUrl"
                        target="_blank"
                        variant="outline-primary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="ExternalLink"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Ver mi wizard
                    </Button>
                </div>
            </div>

            <!-- Vista previa: así se ve el wizard hoy, con la configuración actual -->
            <div class="box box--stacked mt-5 p-5">
                <div
                    class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                >
                    <Lucide icon="Eye" class="h-3.5 w-3.5" /> Así se ve tu
                    wizard hoy
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <template v-for="(step, i) in previewSteps" :key="step">
                        <span
                            class="flex items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1.5 text-xs font-medium text-primary"
                        >
                            <span
                                class="flex h-4 w-4 items-center justify-center rounded-full bg-primary text-[10px] text-white"
                                >{{ i + 1 }}</span
                            >
                            {{ step }}
                        </span>
                        <Lucide
                            v-if="i < previewSteps.length - 1"
                            icon="ChevronRight"
                            class="h-3.5 w-3.5 text-slate-300"
                        />
                    </template>
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    <span v-if="paymentMode === 'automatic'">
                        En "Confirmación" se pide pago en línea solo si la
                        tarifa elegida tiene anticipo configurado — si no, el
                        hotel confirma directo.
                    </span>
                    <span v-else-if="paymentMode === 'always'"
                        >Todas las reservas piden pago en línea al confirmar,
                        tengan o no anticipo configurado en su tarifa.</span
                    >
                    <span v-else
                        >Ninguna reserva pide pago en línea — el hotel siempre
                        confirma directo, aunque la tarifa tenga anticipo
                        configurado.</span
                    >
                </p>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-6">
                <!-- 1. Modalidad y huéspedes -->
                <div class="col-span-12 xl:col-span-6">
                    <div class="box box--stacked flex h-full flex-col p-5">
                        <div
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Users" class="h-3.5 w-3.5" />
                            Modalidad y huéspedes
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Las pestañas "por noche" / "por rato" las decide
                            solo el catálogo (qué tarifas tengas activas) — esto
                            controla el resto.
                        </p>
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1 block text-sm font-medium"
                                    >Tipo de huéspedes</label
                                >
                                <FormSelect v-model="guestForm.guest_policy">
                                    <option value="family">
                                        Familias (permite niños)
                                    </option>
                                    <option value="adults_only">
                                        Solo adultos, 18+ (caso motel)
                                    </option>
                                </FormSelect>
                                <FormHelp
                                    v-if="
                                        guestForm.guest_policy === 'adults_only'
                                    "
                                >
                                    El wizard oculta el campo de niños y muestra
                                    "exclusivo para mayores de edad".
                                </FormHelp>
                                <FormHelp
                                    v-if="guestErrors.guest_policy"
                                    class="text-danger"
                                    >{{ guestErrors.guest_policy }}</FormHelp
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium"
                                    >¿Cómo le llamas a tus estancias por
                                    rato/bloque?</label
                                >
                                <FormInput
                                    v-model="guestForm.block_mode_label"
                                    type="text"
                                    maxlength="60"
                                    placeholder="Por rato/periodo"
                                />
                                <FormHelp
                                    >Ej. "Por rato", "Por periodo", "Por horas".
                                    Solo se usa si vendes tarifas por
                                    bloque.</FormHelp
                                >
                                <FormHelp
                                    v-if="guestErrors.block_mode_label"
                                    class="text-danger"
                                    >{{
                                        guestErrors.block_mode_label
                                    }}</FormHelp
                                >
                            </div>
                        </div>
                        <div class="mt-auto pt-4">
                            <Button
                                variant="primary"
                                :disabled="savingGuest"
                                @click="saveGuestSettings"
                            >
                                <Lucide icon="Check" class="mr-2 h-4 w-4" />
                                {{ savingGuest ? 'Guardando…' : 'Guardar' }}
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- 2. Pago (resumen, enlaza a donde se configura de verdad) -->
                <div class="col-span-12 xl:col-span-6">
                    <div class="box box--stacked flex h-full flex-col p-5">
                        <div
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="CreditCard" class="h-3.5 w-3.5" />
                            Pago
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Si hay más de un método listo, el wizard deja elegir
                            al huésped en vez de decidir en silencio.
                        </p>
                        <div
                            class="mb-4 flex items-center justify-between rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                        >
                            <span class="flex items-center gap-2.5 text-sm">
                                <Lucide
                                    icon="Wallet"
                                    class="h-4 w-4 text-slate-400"
                                />
                                ¿Cuándo pide pago en línea?
                            </span>
                            <span
                                class="rounded-full bg-primary/10 px-2.5 py-1 text-xs font-medium text-primary"
                            >
                                {{ paymentModeLabels[paymentMode] }}
                            </span>
                        </div>
                        <div class="space-y-3">
                            <div
                                class="flex items-center justify-between rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                            >
                                <span class="flex items-center gap-2.5 text-sm">
                                    <Lucide
                                        icon="CreditCard"
                                        class="h-4 w-4 text-slate-400"
                                    />
                                    Pasarela de pago
                                </span>
                                <span
                                    class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="
                                        paymentReadiness.gateway_connected
                                            ? 'bg-success/10 text-success'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                >
                                    <Lucide
                                        :icon="
                                            paymentReadiness.gateway_connected
                                                ? 'CircleCheck'
                                                : 'CircleX'
                                        "
                                        class="h-3.5 w-3.5"
                                    />
                                    {{
                                        paymentReadiness.gateway_connected
                                            ? paymentReadiness.gateway_provider
                                            : 'Sin conectar'
                                    }}
                                </span>
                            </div>
                            <div
                                class="flex items-center justify-between rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                            >
                                <span class="flex items-center gap-2.5 text-sm">
                                    <Lucide
                                        icon="Landmark"
                                        class="h-4 w-4 text-slate-400"
                                    />
                                    Transferencia bancaria
                                </span>
                                <span
                                    class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="
                                        paymentReadiness.transfer_accounts_count >
                                        0
                                            ? 'bg-success/10 text-success'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                >
                                    <Lucide
                                        :icon="
                                            paymentReadiness.transfer_accounts_count >
                                            0
                                                ? 'CircleCheck'
                                                : 'CircleX'
                                        "
                                        class="h-3.5 w-3.5"
                                    />
                                    {{
                                        paymentReadiness.transfer_accounts_count >
                                        0
                                            ? `${paymentReadiness.transfer_accounts_count} cuenta(s)`
                                            : 'Sin cuentas'
                                    }}
                                </span>
                            </div>
                            <div
                                v-if="!paymentReadiness.ready"
                                class="flex items-center gap-2 rounded-md border border-warning/30 bg-warning/5 px-3 py-2.5 text-xs text-slate-600 dark:text-slate-300"
                            >
                                <Lucide
                                    icon="TriangleAlert"
                                    class="h-4 w-4 shrink-0 text-warning"
                                />
                                Sin ningún método listo, una tarifa con anticipo
                                no podrá cobrarse en el wizard.
                            </div>
                        </div>
                        <div class="mt-auto pt-4">
                            <Link
                                :href="route('tenant.payment-methods')"
                                class="flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                            >
                                Configurar métodos de pago
                                <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- 3. Extras (POS) -->
                <div class="col-span-12">
                    <div class="box box--stacked">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/60 p-5 dark:border-darkmode-400"
                        >
                            <div>
                                <div
                                    class="flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    <Lucide
                                        icon="ShoppingBag"
                                        class="h-3.5 w-3.5"
                                    />
                                    Extras del wizard (punto de venta)
                                </div>
                                <p class="mt-1 text-sm text-slate-500">
                                    Paso opcional: el huésped pide bebidas,
                                    comida u otros productos de tu inventario al
                                    reservar. Se suman al total y quedan listos
                                    para preparar en cuanto llega — el stock se
                                    descuenta hasta el check-in, nunca en un
                                    apartado que puede expirar.
                                </p>
                            </div>
                            <FormSwitch v-if="hasPosModule">
                                <FormSwitch.Input
                                    v-model="extrasEnabled"
                                    type="checkbox"
                                    :disabled="savingExtras"
                                    @change="toggleExtras"
                                />
                            </FormSwitch>
                        </div>

                        <div
                            v-if="!hasPosModule"
                            class="flex items-center gap-2 p-5 text-sm text-slate-500"
                        >
                            <Lucide
                                icon="Blocks"
                                class="h-4 w-4 shrink-0 text-slate-400"
                            />
                            Necesitas el módulo
                            <strong>Punto de venta</strong> activo para ofrecer
                            extras en el wizard.
                        </div>

                        <template v-else>
                            <div
                                v-if="!extrasEnabled"
                                class="p-5 text-sm text-slate-500"
                            >
                                El paso está apagado — actívalo arriba para
                                curar qué productos se ofrecen.
                            </div>
                            <div v-else class="p-5">
                                <div
                                    v-if="!products.length"
                                    class="flex flex-col items-center gap-2 py-8 text-center text-sm text-slate-500"
                                >
                                    <Lucide
                                        icon="Package"
                                        class="h-8 w-8 text-slate-300"
                                    />
                                    Aún no tienes productos activos en
                                    Inventario.
                                    <Link
                                        :href="route('tenant.inventory')"
                                        class="font-medium text-primary hover:underline"
                                        >Ir a Inventario</Link
                                    >
                                </div>
                                <template v-else>
                                    <div class="mb-4 text-xs text-slate-500">
                                        {{ selectedProductsCount }} de
                                        {{ products.length }} producto(s)
                                        visibles en el wizard. Marca solo lo que
                                        quieras vender sin que tu personal lo
                                        capture.
                                    </div>
                                    <div
                                        v-if="outOfStockSelectedCount > 0"
                                        class="mb-4 flex items-center gap-2 rounded-md border border-warning/30 bg-warning/5 px-3 py-2.5 text-xs text-slate-600 dark:text-slate-300"
                                    >
                                        <Lucide
                                            icon="TriangleAlert"
                                            class="h-4 w-4 shrink-0 text-warning"
                                        />
                                        {{ outOfStockSelectedCount }}
                                        producto(s) marcados sin existencias —
                                        el huésped NO los verá hasta que
                                        registres stock en Inventario, aunque el
                                        interruptor esté activo.
                                    </div>
                                    <div class="space-y-5">
                                        <div
                                            v-for="[
                                                category,
                                                items,
                                            ] in productsByCategory"
                                            :key="category"
                                        >
                                            <div
                                                class="mb-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                                            >
                                                {{ category }}
                                            </div>
                                            <div
                                                class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3"
                                            >
                                                <label
                                                    v-for="p in items"
                                                    :key="p.id"
                                                    class="flex cursor-pointer items-center justify-between gap-2 rounded-lg border p-3 text-sm transition"
                                                    :class="
                                                        localProducts[p.id]
                                                            ? 'border-primary/30 bg-primary/5'
                                                            : 'border-slate-200/70 dark:border-darkmode-400'
                                                    "
                                                >
                                                    <span class="min-w-0">
                                                        <span
                                                            class="block truncate font-medium"
                                                            >{{ p.name }}</span
                                                        >
                                                        <span
                                                            class="text-xs text-slate-500"
                                                            >{{
                                                                money(p.price)
                                                            }}
                                                            / {{ p.unit }}</span
                                                        >
                                                        <span
                                                            v-if="!p.in_stock"
                                                            class="mt-0.5 flex items-center gap-1 text-xs text-warning"
                                                        >
                                                            <Lucide
                                                                icon="TriangleAlert"
                                                                class="h-3 w-3"
                                                            />
                                                            Sin existencias
                                                        </span>
                                                    </span>
                                                    <FormSwitch
                                                        class="shrink-0"
                                                    >
                                                        <FormSwitch.Input
                                                            :checked="
                                                                localProducts[
                                                                    p.id
                                                                ]
                                                            "
                                                            type="checkbox"
                                                            :disabled="
                                                                busyProduct ===
                                                                p.id
                                                            "
                                                            @change="
                                                                toggleProduct(p)
                                                            "
                                                        />
                                                    </FormSwitch>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
