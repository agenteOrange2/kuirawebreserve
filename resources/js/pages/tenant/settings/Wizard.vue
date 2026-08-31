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
import { Dialog } from '@/components/Base/Headless';
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
    productsTotal: number;
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
        selected.value.some((p) => p.in_stock),
);
const previewSteps = computed(() => {
    const steps = ['Fechas', 'Tus datos'];
    if (extrasStepActive.value) steps.push('Extras');
    steps.push('Confirmación');
    return steps;
});

const busyProduct = ref<number | null>(null);

// Los elegidos se llevan en memoria para poder quitarlos y agregarlos sin
// recargar; la pantalla ya solo recibe estos, no el catálogo entero.
const selected = ref<ProductRow[]>([...props.products]);

const selectedByCategory = computed(() => {
    const groups = new Map<string, ProductRow[]>();
    selected.value.forEach((p) => {
        const key = p.category ?? 'Sin categoría';
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key)!.push(p);
    });
    return [...groups.entries()];
});

const selectedProductsCount = computed(() => selected.value.length);

const outOfStockSelectedCount = computed(
    () => selected.value.filter((p) => !p.in_stock).length,
);

// ── Buscador (modal) ──
const pickerOpen = ref(false);
const pickerTerm = ref('');
const pickerResults = ref<ProductRow[]>([]);
const pickerTruncated = ref(false);
const pickerLoading = ref(false);
let pickerTimer: ReturnType<typeof setTimeout> | undefined;

function openPicker() {
    pickerOpen.value = true;
    pickerTerm.value = '';
    searchProducts();
}

// Se espera a que deje de teclear: sin esto cada letra dispara una consulta.
function onPickerType() {
    if (pickerTimer) clearTimeout(pickerTimer);
    pickerTimer = setTimeout(searchProducts, 250);
}

async function searchProducts() {
    pickerLoading.value = true;
    try {
        const { data } = await axios.get(
            route('tenant.wizard-settings.products'),
            {
                params: { q: pickerTerm.value },
            },
        );
        pickerResults.value = data.products;
        pickerTruncated.value = data.truncated;
    } catch {
        toast.error('No se pudo buscar', 'Intenta de nuevo.');
    } finally {
        pickerLoading.value = false;
    }
}

const isSelected = (id: number) => selected.value.some((p) => p.id === id);

async function setAvailability(product: ProductRow, next: boolean) {
    busyProduct.value = product.id;
    try {
        await axios.patch(`/api/products/${product.id}`, {
            available_in_wizard: next,
        });
        selected.value = next
            ? [...selected.value, { ...product, available_in_wizard: true }]
            : selected.value.filter((p) => p.id !== product.id);
    } catch (e: any) {
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
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ShoppingBag" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Wizard de reservas
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cómo se comporta
                            <code
                                class="rounded bg-slate-100 px-1 py-0.5 dark:bg-darkmode-400"
                                >/reservar</code
                            >: pasos, huéspedes, pago y extras.
                        </p>
                    </div>
                </div>
                <div
                    class="flex w-full flex-wrap items-center gap-2 md:w-auto md:shrink-0 md:justify-end"
                >
                    <!-- El volver vive con las acciones, no flotando encima
                         de la tarjeta. -->
                    <Link
                        :href="route('tenant.hotel-settings')"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Volver a Ajustes
                    </Link>
                    <Button
                        as="a"
                        :href="route('tenant.reservations.settings')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                        title="Logo, colores y modo oscuro del wizard público"
                    >
                        <Lucide icon="Palette" class="mr-1.5 h-3.5 w-3.5" />
                        Apariencia
                    </Button>
                    <Button
                        as="a"
                        :href="wizardUrl"
                        target="_blank"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                    >
                        <Lucide
                            icon="ExternalLink"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        Ver mi wizard
                    </Button>
                </div>
            </div>

            <!-- Vista previa: así se ve el wizard hoy, con la configuración actual -->
            <div class="box box--stacked mt-4 px-4 py-3">
                <div
                    class="mb-2.5 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                >
                    <Lucide icon="Eye" class="h-3.5 w-3.5" />
                    Así se ve tu wizard hoy
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <template v-for="(step, i) in previewSteps" :key="step">
                        <span
                            class="flex h-7 items-center gap-1.5 rounded-full bg-primary/10 px-2.5 text-[11px] font-medium text-primary"
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
                <p class="mt-2.5 text-[11px] text-slate-500">
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
                    <div class="box box--stacked flex h-full flex-col p-4">
                        <div
                            class="mb-1 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
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
                    <div class="box box--stacked flex h-full flex-col p-4">
                        <div
                            class="mb-1 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
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
                            class="flex flex-wrap items-center justify-between gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div>
                                <div
                                    class="flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
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
                            class="flex items-center gap-2 px-4 py-3 text-xs text-slate-500"
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
                                class="px-4 py-3 text-xs text-slate-500"
                            >
                                El paso está apagado — actívalo arriba para
                                curar qué productos se ofrecen.
                            </div>
                            <div v-else class="p-4">
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
                                    <div
                                        class="mb-4 flex flex-wrap items-center justify-between gap-3"
                                    >
                                        <div class="text-xs text-slate-500">
                                            <span
                                                class="font-medium text-slate-600 dark:text-slate-300"
                                                >{{
                                                    selectedProductsCount
                                                }}
                                                producto(s)</span
                                            >
                                            en el wizard, de
                                            {{ productsTotal }} en tu
                                            inventario.
                                        </div>
                                        <Button
                                            v-if="canManage"
                                            variant="outline-primary"
                                            class="h-8 rounded-[0.5rem] bg-white text-xs"
                                            @click="openPicker"
                                        >
                                            <Lucide
                                                icon="Plus"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Agregar productos
                                        </Button>
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

                                    <!-- Solo lo elegido: la pregunta que
                                         responde esta pantalla es "¿qué vende
                                         mi wizard?", no "¿qué hay en mi
                                         almacén?". Para agregar está el
                                         buscador. -->
                                    <div
                                        v-if="selectedProductsCount === 0"
                                        class="rounded-lg border border-dashed border-slate-300/70 py-8 text-center text-sm text-slate-500 dark:border-darkmode-400"
                                    >
                                        Todavía no ofreces ningún producto en el
                                        wizard.
                                        <button
                                            v-if="canManage"
                                            type="button"
                                            class="font-medium text-primary hover:underline"
                                            @click="openPicker"
                                        >
                                            Elige los primeros
                                        </button>
                                    </div>

                                    <div v-else class="space-y-5">
                                        <div
                                            v-for="[
                                                category,
                                                items,
                                            ] in selectedByCategory"
                                            :key="category"
                                        >
                                            <div
                                                class="mb-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                            >
                                                {{ category }}
                                            </div>
                                            <div
                                                class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3"
                                            >
                                                <div
                                                    v-for="p in items"
                                                    :key="p.id"
                                                    class="flex items-center justify-between gap-2 rounded-lg border border-primary/30 bg-primary/5 p-3 text-sm"
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
                                                    <button
                                                        v-if="canManage"
                                                        type="button"
                                                        class="shrink-0 rounded-full p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                                        title="Quitar del wizard"
                                                        :disabled="
                                                            busyProduct === p.id
                                                        "
                                                        @click="
                                                            setAvailability(
                                                                p,
                                                                false,
                                                            )
                                                        "
                                                    >
                                                        <Lucide
                                                            icon="X"
                                                            class="h-4 w-4"
                                                        />
                                                    </button>
                                                </div>
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

        <!-- Buscador de productos: consulta al servidor conforme escribes,
             en vez de traerse el inventario completo a la pantalla. -->
        <Dialog :open="pickerOpen" size="lg" @close="pickerOpen = false">
            <Dialog.Panel>
                <Dialog.Title>
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Package" class="h-5 w-5" />
                    </div>
                    <h2 class="ml-3 text-base font-medium">
                        Agregar productos al wizard
                    </h2>
                </Dialog.Title>
                <Dialog.Description>
                    <div class="relative">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                        />
                        <FormInput
                            v-model="pickerTerm"
                            class="pl-9"
                            placeholder="Busca por nombre o categoría"
                            @input="onPickerType"
                        />
                    </div>

                    <div
                        v-if="pickerLoading"
                        class="py-8 text-center text-sm text-slate-500"
                    >
                        Buscando...
                    </div>

                    <div
                        v-else-if="pickerResults.length === 0"
                        class="py-8 text-center text-sm text-slate-500"
                    >
                        No hay productos que coincidan.
                    </div>

                    <div
                        v-else
                        class="mt-3 max-h-[50vh] space-y-2 overflow-y-auto pr-1"
                    >
                        <div
                            v-for="p in pickerResults"
                            :key="p.id"
                            class="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm"
                            :class="
                                isSelected(p.id)
                                    ? 'border-primary/30 bg-primary/5'
                                    : 'border-slate-200/70 dark:border-darkmode-400'
                            "
                        >
                            <div class="min-w-0">
                                <div class="truncate font-medium">
                                    {{ p.name }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ p.category ?? 'Sin categoría' }} ·
                                    {{ money(p.price) }} / {{ p.unit }}
                                </div>
                                <div
                                    v-if="!p.in_stock"
                                    class="mt-0.5 flex items-center gap-1 text-xs text-warning"
                                >
                                    <Lucide
                                        icon="TriangleAlert"
                                        class="h-3 w-3"
                                    />
                                    Sin existencias
                                </div>
                            </div>
                            <Button
                                :variant="
                                    isSelected(p.id)
                                        ? 'outline-secondary'
                                        : 'outline-primary'
                                "
                                class="h-8 shrink-0 rounded-[0.5rem] bg-white text-xs"
                                :disabled="busyProduct === p.id"
                                @click="setAvailability(p, !isSelected(p.id))"
                            >
                                {{ isSelected(p.id) ? 'Quitar' : 'Agregar' }}
                            </Button>
                        </div>

                        <p
                            v-if="pickerTruncated"
                            class="pt-1 text-center text-xs text-slate-500"
                        >
                            Se muestran los primeros 40. Escribe para afinar la
                            búsqueda.
                        </p>
                    </div>
                </Dialog.Description>
                <Dialog.Footer>
                    <Button
                        variant="outline-secondary"
                        class="w-24"
                        @click="pickerOpen = false"
                    >
                        Listo
                    </Button>
                </Dialog.Footer>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
