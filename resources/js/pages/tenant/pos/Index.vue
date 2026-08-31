<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormLabel, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import type { CounterMethod } from '@/composables/useCounterMethods';
import { useCounterMethods } from '@/composables/useCounterMethods';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface PosProduct {
    id: number;
    name: string;
    category: string | null;
    type: string;
    price: string;
    track_stock: boolean;
    stock_qty: number;
    photo: { id: number; url: string; thumb_url: string } | null;
}

interface CartLine {
    product: PosProduct;
    qty: number;
}

const props = defineProps<{
    property: { id: number; name: string };
    categories: string[];
    products: PosProduct[];
    activeStays: { id: number; label: string }[];
    // Habitación con la que llega el cajero desde el plano (/pos?stay=N).
    preselectStay: number | null;
    stats: {
        products: number;
        out_of_stock: number;
        orders_today: number;
        sold_today: number;
    };
    recentOrders: {
        id: number;
        total: number;
        room: string | null;
        created_at: string;
        summary: string;
        is_void: boolean;
        is_settled: boolean;
        void_reason: string | null;
    }[];
}>();

const CART_KEY = 'kuira.pos.cart';

/**
 * El carrito sobrevive a recargar: en el mostrador una recarga accidental
 * —o el celular descartando la pestaña— borraba la venta a medio armar.
 * Se guardan ids y cantidades, no el producto entero: el precio siempre se
 * vuelve a leer del catálogo para no cobrar uno viejo.
 */
function restoreCart(): CartLine[] {
    try {
        const raw = localStorage.getItem(CART_KEY);
        if (!raw) return [];

        return (JSON.parse(raw) as { id: number; qty: number }[])
            .map(({ id, qty }) => {
                const product = props.products.find((p) => p.id === id);

                return product ? { product, qty } : null;
            })
            .filter((line): line is CartLine => line !== null);
    } catch {
        return [];
    }
}

const cart = ref<CartLine[]>(restoreCart());

watch(
    cart,
    (lines) => {
        try {
            localStorage.setItem(
                CART_KEY,
                JSON.stringify(
                    lines.map((l) => ({ id: l.product.id, qty: l.qty })),
                ),
            );
        } catch {
            /* sin almacenamiento el carrito solo dura la sesión */
        }
    },
    { deep: true },
);
// Si el plano mandó una habitación, se llega con ella puesta: eso es lo que
// pidió quien tocó "Cargar consumo" ahí.
const stayId = ref<string | number>(props.preselectStay ?? '');
const paymentMethod = ref<CounterMethod>('cash');
const paymentReference = ref('');
const discount = ref<number | string>('');
const discountReason = ref('');
const tip = ref<number | string>('');
// Formas de cobro que acepta la recepción (/ajustes/metodos-pago →
// Políticas): sin terminal, el POS tampoco ofrece tarjeta.
const {
    methods,
    first: firstMethod,
    coerce: coerceMethod,
} = useCounterMethods();
const saving = ref(false);
const error = ref<string | null>(null);
const success = ref<string | null>(null);
const search = ref('');
const categoryFilter = ref('');

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n || 0);

const isOutOfStock = (p: PosProduct) =>
    p.track_stock && p.type === 'simple' && p.stock_qty <= 0;

const catalogFiltered = computed(
    () => search.value.trim() !== '' || categoryFilter.value !== '',
);

function clearCatalogFilters() {
    search.value = '';
    categoryFilter.value = '';
}

const filteredProducts = computed(() =>
    props.products.filter((p) => {
        const matchCat =
            !categoryFilter.value || p.category === categoryFilter.value;
        const matchSearch =
            !search.value ||
            p.name.toLowerCase().includes(search.value.toLowerCase());
        return matchCat && matchSearch;
    }),
);

const subtotal = computed(() =>
    cart.value.reduce(
        (sum, line) => sum + line.qty * Number(line.product.price),
        0,
    ),
);

// El descuento nunca deja la cuenta en negativo (el servidor lo vuelve a
// acotar: aquí es solo para que el mostrador vea el total real).
const discountAmount = computed(() =>
    Math.min(Math.max(Number(discount.value) || 0, 0), subtotal.value),
);
const tipAmount = computed(() => Math.max(Number(tip.value) || 0, 0));

const total = computed(
    () => subtotal.value - discountAmount.value + tipAmount.value,
);

const itemCount = computed(() =>
    cart.value.reduce((sum, line) => sum + line.qty, 0),
);

function add(product: PosProduct) {
    if (isOutOfStock(product)) return;
    const line = cart.value.find((l) => l.product.id === product.id);
    if (line) line.qty += 1;
    else cart.value.push({ product, qty: 1 });
    success.value = null;
}
function decrease(line: CartLine) {
    line.qty -= 1;
    if (line.qty <= 0) removeLine(line);
}
function removeLine(line: CartLine) {
    cart.value = cart.value.filter((l) => l !== line);
}

// En pantallas chicas el carrito vive debajo de toda la lista: con un
// catálogo largo había que recorrerla entera para llegar a cobrar. La
// barra fija de abajo salta directo.
const cartSection = ref<HTMLElement | null>(null);

function goToCart() {
    cartSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function resetSale() {
    cart.value = [];
    stayId.value = '';
    discount.value = '';
    discountReason.value = '';
    tip.value = '';
    paymentReference.value = '';
}

function clearCart() {
    resetSale();
}

// Última venta registrada, para ofrecer su ticket sin ir a buscarla.
const lastOrderId = ref<number | null>(null);

function ticketUrl(orderId: number) {
    return `/pos/ticket/${orderId}`;
}

function openTicket(orderId: number) {
    window.open(ticketUrl(orderId), '_blank', 'noopener');
}

async function submit() {
    if (!cart.value.length) return;
    saving.value = true;
    error.value = null;
    try {
        const { data } = await axios.post('/api/orders', {
            property_id: props.property.id,
            stay_id: stayId.value || null,
            payment_method: coerceMethod(paymentMethod.value),
            payment_reference: paymentReference.value || null,
            discount: discountAmount.value || null,
            discount_reason: discountReason.value || null,
            tip: tipAmount.value || null,
            lines: cart.value.map((l) => ({
                product_id: l.product.id,
                qty: l.qty,
            })),
        });
        lastOrderId.value = data.id;
        success.value = `Venta #${data.id} registrada · ${money(Number(data.total))}`;
        resetSale();
        router.reload({ only: ['products', 'recentOrders', 'activeStays'] });
    } catch (e: any) {
        error.value =
            e.response?.data?.message ?? 'No se pudo registrar la venta.';
    } finally {
        saving.value = false;
    }
}

// Cancelación de venta: devuelve la mercancía al inventario y la saca del
// corte de caja y del folio.
const voidingId = ref<number | null>(null);
const voidReason = ref('');
const voidBusy = ref(false);
const voidError = ref<string | null>(null);

function askVoid(orderId: number) {
    voidingId.value = orderId;
    voidReason.value = '';
    voidError.value = null;
}

async function confirmVoid() {
    if (voidingId.value === null || voidBusy.value) return;
    voidBusy.value = true;
    voidError.value = null;
    try {
        await axios.post(`/api/orders/${voidingId.value}/void`, {
            reason: voidReason.value || null,
        });
        voidingId.value = null;
        router.reload({ only: ['products', 'recentOrders', 'activeStays'] });
    } catch (e: any) {
        voidError.value =
            e.response?.data?.message ?? 'No se pudo cancelar la venta.';
    } finally {
        voidBusy.value = false;
    }
}
</script>

<template>
    <RazeLayout title="POS">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ShoppingCart" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Punto de venta</h1>
                        <p class="mt-0.5 truncate text-xs text-slate-500">
                            {{ property.name }}
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2.5"
                >
                    <Button
                        :as="Link"
                        :href="route('tenant.pos.history')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="Receipt"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.5]"
                        />
                        Historial
                    </Button>
                    <Button
                        :as="Link"
                        :href="route('tenant.inventory')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="Package"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.5]"
                        />
                        Inventario
                    </Button>
                </div>
            </div>

            <!-- Cifras del turno: lo que el cajero preguntaba o iba a buscar
                 al historial. -->
            <div class="mt-4 grid grid-cols-12 gap-4">
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="Coins" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium">
                            {{ money(stats.sold_today) }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Vendido hoy
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Receipt" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.orders_today }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Ventas de hoy
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                    >
                        <Lucide icon="Package" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.products }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Productos a la venta
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-12 flex items-center gap-2.5 p-3 sm:col-span-6 xl:col-span-3"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                        :class="
                            stats.out_of_stock > 0
                                ? 'border-danger/10 bg-danger/10 text-danger'
                                : 'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400'
                        "
                    >
                        <Lucide icon="PackageX" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ stats.out_of_stock }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Agotados
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 items-start gap-5">
                <!-- Catálogo -->
                <div class="col-span-12 xl:col-span-7">
                    <!-- Buscador + categorías. Pegajoso: con un catálogo largo
                         la búsqueda se perdía al scrollear y había que subir
                         hasta arriba para cambiar de categoría. -->
                    <div
                        class="sticky top-[68px] z-20 -mx-1 px-1 pb-1 backdrop-blur"
                    >
                        <div
                            class="box box--stacked flex flex-col gap-2 px-3 py-2.5"
                        >
                            <div class="flex items-center gap-2">
                                <div class="relative min-w-0 flex-1">
                                    <Lucide
                                        icon="Search"
                                        class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                    />
                                    <FormInput
                                        v-model="search"
                                        type="search"
                                        placeholder="Buscar producto"
                                        class="h-9 pl-9 text-xs"
                                    />
                                </div>
                                <span
                                    class="shrink-0 text-xs text-slate-500"
                                    :title="`${filteredProducts.length} de ${products.length} productos`"
                                >
                                    {{ filteredProducts.length }}/{{
                                        products.length
                                    }}
                                </span>
                                <button
                                    v-if="catalogFiltered"
                                    type="button"
                                    title="Limpiar filtros del catálogo"
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-100 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                    @click="clearCatalogFilters"
                                >
                                    <Lucide icon="X" class="h-4 w-4" />
                                </button>
                            </div>
                            <!-- En una sola línea con scroll lateral: envueltos
                                 ocupaban tres renglones de alto fijo. -->
                            <div
                                v-if="categories.length"
                                class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1"
                            >
                                <button
                                    class="h-7 shrink-0 rounded-full px-3 text-xs font-medium transition"
                                    :class="
                                        !categoryFilter
                                            ? 'bg-primary text-white'
                                            : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-darkmode-400'
                                    "
                                    @click="categoryFilter = ''"
                                >
                                    Todo
                                </button>
                                <button
                                    v-for="c in categories"
                                    :key="c"
                                    class="h-7 shrink-0 rounded-full px-3 text-xs font-medium whitespace-nowrap transition"
                                    :class="
                                        categoryFilter === c
                                            ? 'bg-primary text-white'
                                            : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-darkmode-400'
                                    "
                                    @click="categoryFilter = c"
                                >
                                    {{ c }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Lista de productos: una fila por producto con la
                         miniatura a la izquierda. Caben muchos más a la vista
                         que en rejilla y se leen de corrido. -->
                    <div
                        v-if="filteredProducts.length"
                        class="box box--stacked mt-4 divide-y divide-slate-200/60 dark:divide-darkmode-400"
                    >
                        <button
                            v-for="p in filteredProducts"
                            :key="p.id"
                            class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition hover:bg-slate-50 disabled:cursor-not-allowed dark:hover:bg-darkmode-600"
                            :disabled="isOutOfStock(p)"
                            :class="{ 'opacity-40': isOutOfStock(p) }"
                            @click="add(p)"
                        >
                            <span
                                class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100 dark:bg-darkmode-400"
                            >
                                <img
                                    v-if="p.photo"
                                    :src="p.photo.thumb_url"
                                    :alt="p.name"
                                    class="h-full w-full object-cover"
                                />
                                <span
                                    v-else
                                    class="text-sm font-medium text-slate-300"
                                >
                                    {{ p.name.charAt(0).toUpperCase() }}
                                </span>
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1.5">
                                    <span class="truncate text-sm font-medium">
                                        {{ p.name }}
                                    </span>
                                    <Lucide
                                        v-if="p.type === 'composite'"
                                        icon="ChefHat"
                                        title="Compuesto (receta)"
                                        class="h-3.5 w-3.5 shrink-0 text-pending"
                                    />
                                </span>
                                <span
                                    class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-400"
                                >
                                    <span v-if="p.category">
                                        {{ p.category }}
                                    </span>
                                    <span
                                        v-if="
                                            p.type === 'simple' && p.track_stock
                                        "
                                        class="rounded-full px-1.5 py-0.5"
                                        :class="
                                            p.stock_qty <= 0
                                                ? 'bg-danger/10 text-danger'
                                                : 'bg-slate-100 dark:bg-darkmode-400'
                                        "
                                    >
                                        {{
                                            p.stock_qty <= 0
                                                ? 'Agotado'
                                                : `${p.stock_qty} disp.`
                                        }}
                                    </span>
                                </span>
                            </span>

                            <span
                                class="shrink-0 text-sm font-semibold text-primary"
                            >
                                {{ money(Number(p.price)) }}
                            </span>
                            <span
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Lucide icon="Plus" class="h-4 w-4" />
                            </span>
                        </button>
                    </div>
                    <div
                        v-else
                        class="box box--stacked mt-4 flex flex-col items-center gap-3 px-5 py-12 text-center"
                    >
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="Package" class="h-7 w-7" />
                        </div>
                        <div>
                            <p class="text-sm font-medium">
                                {{
                                    catalogFiltered
                                        ? 'Ningún producto coincide'
                                        : 'Sin productos activos'
                                }}
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{
                                    catalogFiltered
                                        ? 'Cambia la búsqueda o vuelve a la categoría Todo.'
                                        : 'Da de alta productos en Inventario para poder venderlos.'
                                }}
                            </p>
                        </div>
                        <Button
                            v-if="catalogFiltered"
                            variant="outline-secondary"
                            @click="clearCatalogFilters"
                        >
                            <Lucide icon="X" class="mr-1.5 h-3.5 w-3.5" />
                            Limpiar filtros
                        </Button>
                        <Button
                            v-else
                            :as="Link"
                            :href="route('tenant.inventory')"
                            variant="outline-primary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs"
                        >
                            Ir a Inventario
                        </Button>
                    </div>
                </div>

                <!-- Cuenta -->
                <div ref="cartSection" class="col-span-12 xl:col-span-5">
                    <div class="box box--stacked sticky top-24 flex flex-col">
                        <div
                            class="flex items-center gap-3 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Lucide icon="ShoppingCart" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">Cuenta</span>
                                    <span
                                        v-if="itemCount"
                                        class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                                    >
                                        {{ itemCount }}
                                    </span>
                                </div>
                                <div class="text-xs text-slate-500">
                                    Toca un producto para agregarlo.
                                </div>
                            </div>
                            <button
                                v-if="cart.length"
                                type="button"
                                class="ml-auto text-xs font-medium text-slate-400 transition hover:text-danger"
                                @click="clearCart"
                            >
                                Vaciar
                            </button>
                        </div>

                        <!-- Renglones: con su propio scroll para que el total
                             y el botón de cobrar nunca queden fuera de la
                             pantalla en una cuenta larga. -->
                        <div
                            v-if="cart.length"
                            class="max-h-[38vh] divide-y divide-slate-200/60 overflow-y-auto dark:divide-darkmode-400"
                        >
                            <div
                                v-for="line in cart"
                                :key="line.product.id"
                                class="flex items-center gap-2 px-4 py-2.5"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">
                                        {{ line.product.name }}
                                    </div>
                                    <div class="text-xs text-slate-400">
                                        {{ money(Number(line.product.price)) }}
                                        c/u
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-1.5">
                                    <button
                                        type="button"
                                        title="Quitar uno"
                                        class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-300 text-slate-500 transition hover:border-primary hover:text-primary dark:border-darkmode-400"
                                        @click="decrease(line)"
                                    >
                                        <Lucide
                                            icon="Minus"
                                            class="h-3.5 w-3.5"
                                        />
                                    </button>
                                    <span
                                        class="w-5 text-center text-sm font-medium"
                                    >
                                        {{ line.qty }}
                                    </span>
                                    <button
                                        type="button"
                                        title="Agregar uno"
                                        class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-300 text-slate-500 transition hover:border-primary hover:text-primary dark:border-darkmode-400"
                                        @click="line.qty += 1"
                                    >
                                        <Lucide
                                            icon="Plus"
                                            class="h-3.5 w-3.5"
                                        />
                                    </button>
                                    <span
                                        class="w-16 text-right text-sm font-semibold"
                                    >
                                        {{
                                            money(
                                                line.qty *
                                                    Number(line.product.price),
                                            )
                                        }}
                                    </span>
                                    <!-- Quitar el renglón de un golpe: sin
                                         esto había que picarle al menos hasta
                                         bajarlo a cero. -->
                                    <button
                                        type="button"
                                        title="Quitar de la cuenta"
                                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                        @click="removeLine(line)"
                                    >
                                        <Lucide
                                            icon="Trash2"
                                            class="h-3.5 w-3.5"
                                        />
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 px-5 py-10 text-center"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="ShoppingCart" class="h-7 w-7" />
                            </div>
                            <div class="text-sm font-medium">
                                La cuenta está vacía
                            </div>
                            <div class="text-xs text-slate-500">
                                Toca productos del catálogo para agregarlos.
                            </div>
                        </div>

                        <!-- A quién se le cobra y cómo -->
                        <div
                            class="space-y-3 border-t border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                        >
                            <div>
                                <FormLabel htmlFor="pos-stay">
                                    Cargar a habitación
                                </FormLabel>
                                <div class="relative">
                                    <Lucide
                                        icon="BedDouble"
                                        class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                    />
                                    <FormSelect
                                        id="pos-stay"
                                        v-model="stayId"
                                        class="h-9 pl-9 text-xs"
                                    >
                                        <option value="">
                                            Venta directa (se cobra ahora)
                                        </option>
                                        <option
                                            v-for="s in activeStays"
                                            :key="s.id"
                                            :value="s.id"
                                        >
                                            {{ s.label }}
                                        </option>
                                    </FormSelect>
                                </div>
                            </div>

                            <!-- Método de pago (solo venta directa) -->
                            <div v-if="!stayId">
                                <FormLabel htmlFor="pos-method">
                                    Método de pago
                                </FormLabel>
                                <div
                                    id="pos-method"
                                    class="grid gap-1.5"
                                    :class="
                                        methods.length > 2
                                            ? 'grid-cols-3'
                                            : 'grid-cols-2'
                                    "
                                >
                                    <button
                                        v-for="m in methods"
                                        :key="m.key"
                                        type="button"
                                        class="flex h-9 items-center justify-center gap-1.5 rounded-lg border text-xs font-medium transition"
                                        :class="
                                            paymentMethod === m.key
                                                ? 'border-primary bg-primary/10 text-primary'
                                                : 'border-slate-200/70 bg-white text-slate-500 hover:bg-slate-50 dark:border-darkmode-400 dark:bg-darkmode-600'
                                        "
                                        @click="paymentMethod = m.key"
                                    >
                                        <Lucide
                                            :icon="m.icon"
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ m.short }}
                                    </button>
                                </div>
                            </div>
                            <p
                                v-else
                                class="flex items-center gap-2 rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning"
                            >
                                <Lucide icon="Info" class="h-4 w-4 shrink-0" />
                                Se carga a la habitación y se cobra en el
                                check-out.
                            </p>

                            <!-- Referencia del cobro (autorización de tarjeta
                                 o folio de transferencia) -->
                            <div v-if="!stayId && paymentMethod !== 'cash'">
                                <FormLabel htmlFor="pos-reference">
                                    Referencia del cobro
                                </FormLabel>
                                <FormInput
                                    id="pos-reference"
                                    v-model="paymentReference"
                                    type="text"
                                    maxlength="100"
                                    class="h-9 text-xs"
                                    placeholder="Autorización o folio"
                                />
                            </div>

                            <!-- Descuento y propina -->
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <FormLabel htmlFor="pos-discount">
                                        Descuento
                                    </FormLabel>
                                    <FormInput
                                        id="pos-discount"
                                        v-model="discount"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        class="h-9 text-xs"
                                        placeholder="0.00"
                                    />
                                </div>
                                <div>
                                    <FormLabel htmlFor="pos-tip">
                                        Propina
                                    </FormLabel>
                                    <FormInput
                                        id="pos-tip"
                                        v-model="tip"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        class="h-9 text-xs"
                                        placeholder="0.00"
                                    />
                                </div>
                            </div>
                            <div v-if="discountAmount > 0">
                                <FormLabel htmlFor="pos-discount-reason">
                                    Motivo del descuento
                                </FormLabel>
                                <FormInput
                                    id="pos-discount-reason"
                                    v-model="discountReason"
                                    type="text"
                                    maxlength="100"
                                    class="h-9 text-xs"
                                    placeholder="Cortesía, promoción, ajuste"
                                />
                            </div>
                        </div>

                        <!-- Total y cobro: pegado abajo de la tarjeta -->
                        <div
                            class="border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div
                                v-if="discountAmount > 0 || tipAmount > 0"
                                class="mb-2 space-y-1 border-b border-dashed border-slate-300/70 pb-2 text-xs dark:border-darkmode-400"
                            >
                                <div class="flex justify-between">
                                    <span class="text-slate-500">Subtotal</span>
                                    <span>{{ money(subtotal) }}</span>
                                </div>
                                <div
                                    v-if="discountAmount > 0"
                                    class="flex justify-between text-success"
                                >
                                    <span>Descuento</span>
                                    <span>-{{ money(discountAmount) }}</span>
                                </div>
                                <div
                                    v-if="tipAmount > 0"
                                    class="flex justify-between"
                                >
                                    <span class="text-slate-500">Propina</span>
                                    <span>{{ money(tipAmount) }}</span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-slate-500">
                                    Total a cobrar
                                </span>
                                <span class="text-lg font-semibold">
                                    {{ money(total) }}
                                </span>
                            </div>

                            <p
                                v-if="error"
                                class="mt-2 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                            >
                                {{ error }}
                            </p>
                            <div
                                v-if="success"
                                class="mt-2 rounded-lg bg-success/10 px-3 py-2 text-xs text-success"
                            >
                                <p class="flex items-center gap-2">
                                    <Lucide
                                        icon="CircleCheck"
                                        class="h-4 w-4 shrink-0"
                                    />
                                    {{ success }}
                                </p>
                                <button
                                    v-if="lastOrderId"
                                    type="button"
                                    class="mt-1.5 ml-6 inline-flex items-center gap-1.5 font-medium underline underline-offset-2"
                                    @click="openTicket(lastOrderId)"
                                >
                                    <Lucide
                                        icon="Printer"
                                        class="h-3.5 w-3.5"
                                    />
                                    Imprimir ticket
                                </button>
                            </div>

                            <Button
                                class="mt-3 h-10 w-full rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                                variant="primary"
                                :disabled="saving || !cart.length"
                                @click="submit"
                            >
                                <Lucide
                                    icon="CreditCard"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                {{
                                    saving
                                        ? 'Registrando...'
                                        : `Cobrar ${money(total)}`
                                }}
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ventas recientes: a lo ancho y DEBAJO de las dos columnas.
                 Antes vivían al final del catálogo, así que había que
                 recorrerlo entero para ver la última venta. -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <Lucide icon="Receipt" class="h-4 w-4 text-slate-400" />
                        Ventas recientes
                        <span
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500 dark:bg-darkmode-400"
                        >
                            {{ recentOrders.length }}
                        </span>
                    </div>
                    <Link
                        :href="route('tenant.pos.history')"
                        class="ml-auto text-xs font-medium text-primary hover:underline"
                    >
                        Ver el historial completo
                    </Link>
                </div>

                <div v-if="recentOrders.length" class="overflow-auto">
                    <Table sm hover class="text-xs">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="whitespace-nowrap">
                                    Venta
                                </Table.Th>
                                <Table.Th>Detalle</Table.Th>
                                <Table.Th class="whitespace-nowrap">
                                    Cargo
                                </Table.Th>
                                <Table.Th class="text-right whitespace-nowrap">
                                    Total
                                </Table.Th>
                                <Table.Th class="text-right whitespace-nowrap">
                                    Acciones
                                </Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr
                                v-for="o in recentOrders"
                                :key="o.id"
                                :class="o.is_void ? 'opacity-60' : ''"
                            >
                                <Table.Td class="whitespace-nowrap">
                                    <div class="text-sm font-medium">
                                        #{{ o.id }}
                                    </div>
                                    <div class="text-slate-500">
                                        {{ o.created_at }}
                                    </div>
                                </Table.Td>
                                <Table.Td>
                                    <p
                                        class="line-clamp-2 max-w-96 text-slate-600 dark:text-slate-300"
                                        :class="o.is_void ? 'line-through' : ''"
                                        :title="o.summary"
                                    >
                                        {{ o.summary }}
                                    </p>
                                    <p
                                        v-if="o.is_void && o.void_reason"
                                        class="mt-0.5 text-slate-400"
                                    >
                                        {{ o.void_reason }}
                                    </p>
                                </Table.Td>
                                <Table.Td class="whitespace-nowrap">
                                    <span
                                        v-if="o.is_void"
                                        class="inline-flex items-center gap-1 rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                    >
                                        <Lucide
                                            icon="Ban"
                                            class="h-3.5 w-3.5"
                                        />
                                        Cancelada
                                    </span>
                                    <span
                                        v-else-if="o.room"
                                        class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                    >
                                        <Lucide
                                            icon="BedDouble"
                                            class="h-3.5 w-3.5"
                                        />
                                        Hab. {{ o.room }}
                                    </span>
                                    <span v-else class="text-slate-400">
                                        Venta directa
                                    </span>
                                </Table.Td>
                                <Table.Td
                                    class="text-right font-semibold whitespace-nowrap"
                                    :class="o.is_void ? 'line-through' : ''"
                                >
                                    {{ money(o.total) }}
                                </Table.Td>
                                <Table.Td class="text-right whitespace-nowrap">
                                    <div
                                        class="flex items-center justify-end gap-1.5"
                                    >
                                        <button
                                            type="button"
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-primary dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                            title="Imprimir ticket"
                                            @click="openTicket(o.id)"
                                        >
                                            <Lucide
                                                icon="Printer"
                                                class="h-3.5 w-3.5"
                                            />
                                        </button>
                                        <button
                                            v-if="!o.is_void && !o.is_settled"
                                            type="button"
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-danger dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                            title="Cancelar venta"
                                            @click="askVoid(o.id)"
                                        >
                                            <Lucide
                                                icon="Ban"
                                                class="h-3.5 w-3.5"
                                            />
                                        </button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="Receipt" class="h-7 w-7" />
                    </div>
                    <div>
                        <p class="text-sm font-medium">Sin ventas todavía</p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Las últimas diez aparecen aquí en cuanto cobres.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <Dialog :open="voidingId !== null" @close="voidingId = null">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="text-center">
                        <Lucide
                            icon="Ban"
                            class="mx-auto mb-3 h-10 w-10 text-danger"
                        />
                        <h2 class="text-base font-medium">
                            ¿Cancelar la venta #{{ voidingId }}?
                        </h2>
                        <p class="mt-2 text-xs text-slate-500">
                            Lo vendido regresa al inventario y la venta deja de
                            contar en el corte de caja. Queda registrada como
                            cancelada, no se borra.
                        </p>
                    </div>

                    <div class="mt-4 text-left">
                        <FormLabel htmlFor="pos-void-reason">
                            Motivo (opcional)
                        </FormLabel>
                        <FormInput
                            id="pos-void-reason"
                            v-model="voidReason"
                            type="text"
                            maxlength="255"
                            class="h-9 text-xs"
                            placeholder="Se cobró de más, el cliente devolvió"
                        />
                    </div>

                    <p
                        v-if="voidError"
                        class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                    >
                        {{ voidError }}
                    </p>

                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                            @click="voidingId = null"
                        >
                            Volver
                        </Button>
                        <Button
                            variant="danger"
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                            :disabled="voidBusy"
                            @click="confirmVoid"
                        >
                            {{ voidBusy ? 'Cancelando...' : 'Sí, cancelar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Barra fija de cuenta (solo pantallas chicas): el carrito queda
             hasta abajo de la lista y sin esto había que recorrerla toda
             para ver el total o cobrar. -->
        <div
            v-if="cart.length"
            class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200/80 bg-white/95 p-3 shadow-[0_-4px_16px_#0000000d] backdrop-blur xl:hidden dark:border-darkmode-400 dark:bg-darkmode-600/95"
        >
            <button
                type="button"
                class="flex w-full items-center justify-between gap-3 rounded-xl bg-primary px-4 py-3 text-white"
                @click="goToCart"
            >
                <span class="flex items-center gap-2.5">
                    <span
                        class="flex h-7 min-w-7 items-center justify-center rounded-full bg-white/20 px-1.5 text-sm font-semibold"
                        >{{ itemCount }}</span
                    >
                    <span class="text-sm font-medium">Ver la cuenta</span>
                </span>
                <span class="text-lg font-medium">{{ money(total) }}</span>
            </button>
        </div>

        <!-- Colchón para que la barra no tape la última fila. -->
        <div v-if="cart.length" class="h-24 xl:hidden" />
    </RazeLayout>
</template>
