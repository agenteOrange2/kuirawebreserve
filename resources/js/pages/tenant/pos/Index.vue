<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
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

const cart = ref<CartLine[]>([]);
const stayId = ref<string | number>('');
const paymentMethod = ref<'cash' | 'card' | 'transfer'>('cash');
const paymentReference = ref('');
const discount = ref<number | string>('');
const discountReason = ref('');
const tip = ref<number | string>('');
const methods = [
    { key: 'cash', label: 'Efectivo', icon: 'Banknote' },
    { key: 'card', label: 'Tarjeta', icon: 'CreditCard' },
    { key: 'transfer', label: 'Transfer.', icon: 'ArrowLeftRight' },
] as const;
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
    if (line.qty <= 0) cart.value = cart.value.filter((l) => l !== line);
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
            payment_method: paymentMethod.value,
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
                class="box box--stacked flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                    >
                        <Lucide
                            icon="ShoppingCart"
                            class="h-5 w-5 sm:h-7 sm:w-7"
                        />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-lg font-medium sm:text-xl">
                            Punto de venta
                        </h1>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ property.name }}
                        </p>
                    </div>
                </div>
                <Button
                    as="a"
                    :href="route('tenant.inventory')"
                    variant="outline-secondary"
                    class="w-full rounded-[0.5rem] bg-white md:w-auto"
                >
                    <Lucide icon="Package" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    Inventario
                </Button>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-6">
                <!-- Productos -->
                <div class="col-span-12 xl:col-span-7">
                    <!-- Buscador + categorías -->
                    <div class="box box--stacked flex flex-col gap-3 p-3">
                        <div class="relative">
                            <Lucide
                                icon="Search"
                                class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                            />
                            <FormInput
                                v-model="search"
                                type="text"
                                placeholder="Buscar producto…"
                                class="pl-9"
                            />
                        </div>
                        <div
                            v-if="categories.length"
                            class="flex flex-wrap gap-1.5"
                        >
                            <button
                                class="rounded-full px-3 py-1 text-xs font-medium transition"
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
                                class="rounded-full px-3 py-1 text-xs font-medium transition"
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

                    <!-- Lista de productos: una fila por producto con la
                         miniatura a la izquierda. Caben muchos más a la vista
                         que en rejilla y se leen de corrido. -->
                    <div
                        v-if="filteredProducts.length"
                        class="box box--stacked mt-4 divide-y divide-slate-100 dark:divide-darkmode-400"
                    >
                        <button
                            v-for="p in filteredProducts"
                            :key="p.id"
                            class="flex w-full items-center gap-3.5 p-3.5 text-left transition hover:bg-slate-50 disabled:cursor-not-allowed dark:hover:bg-darkmode-600"
                            :disabled="isOutOfStock(p)"
                            :class="{ 'opacity-40': isOutOfStock(p) }"
                            @click="add(p)"
                        >
                            <span
                                class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100 dark:bg-darkmode-400"
                            >
                                <img
                                    v-if="p.photo"
                                    :src="p.photo.thumb_url"
                                    :alt="p.name"
                                    class="h-full w-full object-cover"
                                />
                                <span
                                    v-else
                                    class="text-lg font-medium text-slate-300"
                                    >{{ p.name.charAt(0).toUpperCase() }}</span
                                >
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1.5">
                                    <span class="truncate font-medium">{{
                                        p.name
                                    }}</span>
                                    <Lucide
                                        v-if="p.type === 'composite'"
                                        icon="ChefHat"
                                        title="Compuesto (receta)"
                                        class="h-4 w-4 shrink-0 text-pending"
                                    />
                                </span>
                                <span
                                    class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-400"
                                >
                                    <span v-if="p.category">{{
                                        p.category
                                    }}</span>
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
                                class="shrink-0 text-base font-medium text-primary sm:text-lg"
                                >{{ money(Number(p.price)) }}</span
                            >
                            <span
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Lucide icon="Plus" class="h-4 w-4" />
                            </span>
                        </button>
                    </div>
                    <div
                        v-else
                        class="box box--stacked mt-4 flex flex-col items-center gap-3 py-12 text-center"
                    >
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="Package" class="h-6 w-6" />
                        </div>
                        <p class="text-sm text-slate-500">
                            {{
                                search || categoryFilter
                                    ? 'Sin productos que coincidan.'
                                    : 'Sin productos activos.'
                            }}
                        </p>
                        <Button
                            v-if="!search && !categoryFilter"
                            as="a"
                            :href="route('tenant.inventory')"
                            variant="outline-primary"
                            size="sm"
                            class="rounded-[0.5rem]"
                            >Ir a Inventario</Button
                        >
                    </div>

                    <!-- Ventas recientes -->
                    <div class="box box--stacked mt-6">
                        <div
                            class="flex items-center gap-2 border-b border-slate-200/60 p-5 text-base font-medium dark:border-darkmode-400"
                        >
                            <Lucide
                                icon="Receipt"
                                class="h-4 w-4 text-slate-400"
                            />
                            Ventas recientes
                        </div>
                        <div
                            class="divide-y divide-slate-100 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="o in recentOrders"
                                :key="o.id"
                                class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 px-5 py-3 text-sm"
                                :class="o.is_void ? 'opacity-60' : ''"
                            >
                                <div class="min-w-0 flex-1">
                                    <span class="text-slate-400"
                                        >#{{ o.id }}</span
                                    >
                                    <span
                                        class="ml-1"
                                        :class="o.is_void ? 'line-through' : ''"
                                        >{{ o.summary }}</span
                                    >
                                    <span
                                        v-if="o.room"
                                        class="ml-1.5 rounded-full bg-primary/10 px-1.5 py-0.5 text-xs text-primary"
                                        >Hab. {{ o.room }}</span
                                    >
                                    <span
                                        v-if="o.is_void"
                                        class="ml-1.5 rounded-full bg-danger/10 px-1.5 py-0.5 text-xs text-danger"
                                        >Cancelada</span
                                    >
                                    <p
                                        v-if="o.is_void && o.void_reason"
                                        class="mt-0.5 text-xs text-slate-400"
                                    >
                                        {{ o.void_reason }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <span
                                        class="font-medium"
                                        :class="o.is_void ? 'line-through' : ''"
                                        >{{ money(o.total) }}</span
                                    >
                                    <span class="text-xs text-slate-400">{{
                                        o.created_at
                                    }}</span>
                                    <button
                                        type="button"
                                        class="text-slate-400 transition hover:text-primary"
                                        title="Imprimir ticket"
                                        @click="openTicket(o.id)"
                                    >
                                        <Lucide
                                            icon="Printer"
                                            class="h-4 w-4"
                                        />
                                    </button>
                                    <button
                                        v-if="!o.is_void && !o.is_settled"
                                        type="button"
                                        class="text-slate-400 transition hover:text-danger"
                                        title="Cancelar venta"
                                        @click="askVoid(o.id)"
                                    >
                                        <Lucide icon="Ban" class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                            <div
                                v-if="!recentOrders.length"
                                class="px-5 py-8 text-center text-sm text-slate-500"
                            >
                                Sin ventas aún.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Carrito -->
                <div class="col-span-12 xl:col-span-5">
                    <div class="box box--stacked sticky top-24 flex flex-col">
                        <div
                            class="flex items-center justify-between border-b border-slate-200/60 p-5 dark:border-darkmode-400"
                        >
                            <h2
                                class="flex items-center gap-2 text-base font-medium"
                            >
                                <div
                                    class="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-primary"
                                >
                                    <Lucide
                                        icon="ShoppingCart"
                                        class="h-4 w-4"
                                    />
                                </div>
                                Cuenta
                                <span
                                    v-if="itemCount"
                                    class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                                    >{{ itemCount }}</span
                                >
                            </h2>
                            <button
                                v-if="cart.length"
                                type="button"
                                class="text-xs text-slate-400 hover:text-danger"
                                @click="clearCart"
                            >
                                Vaciar
                            </button>
                        </div>

                        <div class="p-5">
                            <div
                                v-if="cart.length"
                                class="divide-y divide-slate-100 dark:divide-darkmode-400"
                            >
                                <div
                                    v-for="line in cart"
                                    :key="line.product.id"
                                    class="flex items-center justify-between gap-2 py-3"
                                >
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate font-medium">
                                            {{ line.product.name }}
                                        </div>
                                        <div class="text-xs text-slate-400">
                                            {{
                                                money(
                                                    Number(line.product.price),
                                                )
                                            }}
                                            c/u
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button
                                            type="button"
                                            class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-300 text-slate-500 transition hover:border-primary hover:text-primary dark:border-darkmode-400"
                                            @click="decrease(line)"
                                        >
                                            <Lucide
                                                icon="Minus"
                                                class="h-3.5 w-3.5"
                                            />
                                        </button>
                                        <span
                                            class="w-6 text-center font-medium"
                                            >{{ line.qty }}</span
                                        >
                                        <button
                                            type="button"
                                            class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-300 text-slate-500 transition hover:border-primary hover:text-primary dark:border-darkmode-400"
                                            @click="line.qty += 1"
                                        >
                                            <Lucide
                                                icon="Plus"
                                                class="h-3.5 w-3.5"
                                            />
                                        </button>
                                        <span
                                            class="w-20 text-right font-medium"
                                            >{{
                                                money(
                                                    line.qty *
                                                        Number(
                                                            line.product.price,
                                                        ),
                                                )
                                            }}</span
                                        >
                                    </div>
                                </div>
                            </div>
                            <div
                                v-else
                                class="flex flex-col items-center gap-2 py-10 text-center text-slate-400"
                            >
                                <Lucide icon="ShoppingCart" class="h-8 w-8" />
                                <p class="text-sm">
                                    Toca productos para agregarlos a la cuenta.
                                </p>
                            </div>

                            <div
                                class="mt-3 border-t border-slate-200/60 pt-4 dark:border-darkmode-400"
                            >
                                <label
                                    class="mb-1.5 block text-sm text-slate-500"
                                    >Cargar a habitación (opcional)</label
                                >
                                <div class="relative">
                                    <Lucide
                                        icon="BedDouble"
                                        class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                                    />
                                    <FormSelect
                                        id="pos-stay"
                                        v-model="stayId"
                                        class="pl-9"
                                    >
                                        <option value="">
                                            Venta directa (sin cargar a
                                            habitación)
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
                            <div v-if="!stayId" class="mt-3">
                                <label
                                    class="mb-1.5 block text-sm text-slate-500"
                                    >Método de pago</label
                                >
                                <div class="grid grid-cols-3 gap-2">
                                    <button
                                        v-for="m in methods"
                                        :key="m.key"
                                        type="button"
                                        class="flex flex-col items-center gap-1 rounded-lg border py-2.5 text-xs font-medium transition"
                                        :class="
                                            paymentMethod === m.key
                                                ? 'border-primary bg-primary/10 text-primary'
                                                : 'border-slate-200/70 text-slate-500 hover:bg-slate-50 dark:border-darkmode-400'
                                        "
                                        @click="paymentMethod = m.key"
                                    >
                                        <Lucide
                                            :icon="m.icon"
                                            class="h-4 w-4"
                                        />
                                        {{ m.label }}
                                    </button>
                                </div>
                            </div>
                            <p
                                v-else
                                class="mt-3 flex items-center gap-2 rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning"
                            >
                                <Lucide icon="Info" class="h-4 w-4 shrink-0" />
                                Se cargará a la habitación y se cobrará en el
                                check-out.
                            </p>

                            <!-- Referencia del cobro (autorización de tarjeta
                                 o folio de transferencia) -->
                            <div
                                v-if="!stayId && paymentMethod !== 'cash'"
                                class="mt-3"
                            >
                                <label
                                    for="pos-reference"
                                    class="mb-1.5 block text-sm text-slate-500"
                                    >Referencia (opcional)</label
                                >
                                <FormInput
                                    id="pos-reference"
                                    v-model="paymentReference"
                                    type="text"
                                    maxlength="100"
                                    placeholder="Autorización o folio"
                                />
                            </div>

                            <!-- Descuento y propina -->
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label
                                        for="pos-discount"
                                        class="mb-1.5 block text-sm text-slate-500"
                                        >Descuento</label
                                    >
                                    <FormInput
                                        id="pos-discount"
                                        v-model="discount"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="0.00"
                                    />
                                </div>
                                <div>
                                    <label
                                        for="pos-tip"
                                        class="mb-1.5 block text-sm text-slate-500"
                                        >Propina</label
                                    >
                                    <FormInput
                                        id="pos-tip"
                                        v-model="tip"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="0.00"
                                    />
                                </div>
                            </div>
                            <div v-if="discountAmount > 0" class="mt-3">
                                <label
                                    for="pos-discount-reason"
                                    class="mb-1.5 block text-sm text-slate-500"
                                    >Motivo del descuento</label
                                >
                                <FormInput
                                    id="pos-discount-reason"
                                    v-model="discountReason"
                                    type="text"
                                    maxlength="100"
                                    placeholder="Cortesía, promoción, ajuste…"
                                />
                            </div>

                            <div
                                class="mt-4 rounded-lg bg-slate-50 px-4 py-3 dark:bg-darkmode-700"
                            >
                                <div
                                    v-if="discountAmount > 0 || tipAmount > 0"
                                    class="mb-2 space-y-1 border-b border-slate-200/70 pb-2 text-sm dark:border-darkmode-400"
                                >
                                    <div class="flex justify-between">
                                        <span class="text-slate-500"
                                            >Subtotal</span
                                        >
                                        <span>{{ money(subtotal) }}</span>
                                    </div>
                                    <div
                                        v-if="discountAmount > 0"
                                        class="flex justify-between text-success"
                                    >
                                        <span>Descuento</span>
                                        <span
                                            >-{{ money(discountAmount) }}</span
                                        >
                                    </div>
                                    <div
                                        v-if="tipAmount > 0"
                                        class="flex justify-between"
                                    >
                                        <span class="text-slate-500"
                                            >Propina</span
                                        >
                                        <span>{{ money(tipAmount) }}</span>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500">Total</span>
                                    <span class="text-xl font-medium">{{
                                        money(total)
                                    }}</span>
                                </div>
                            </div>

                            <p
                                v-if="error"
                                class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                            >
                                {{ error }}
                            </p>
                            <div
                                v-if="success"
                                class="mt-3 rounded-lg bg-success/10 px-3 py-2 text-sm text-success"
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
                                    <Lucide icon="Printer" class="h-4 w-4" />
                                    Imprimir ticket
                                </button>
                            </div>

                            <Button
                                class="mt-4 w-full rounded-[0.5rem] shadow-md shadow-primary/20"
                                variant="primary"
                                :disabled="saving || !cart.length"
                                @click="submit"
                            >
                                <Lucide
                                    icon="CreditCard"
                                    class="mr-2 h-4 w-4"
                                />
                                {{
                                    saving
                                        ? 'Registrando…'
                                        : `Cobrar ${money(total)}`
                                }}
                            </Button>
                        </div>
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
                            class="mx-auto mb-3 h-12 w-12 text-danger"
                        />
                        <h2 class="text-base font-medium">
                            ¿Cancelar la venta #{{ voidingId }}?
                        </h2>
                        <p class="mt-2 text-sm text-slate-500">
                            Lo vendido regresa al inventario y la venta deja de
                            contar en el corte de caja. Queda registrada como
                            cancelada, no se borra.
                        </p>
                    </div>

                    <div class="mt-4">
                        <label
                            for="pos-void-reason"
                            class="mb-1.5 block text-sm text-slate-500"
                            >Motivo (opcional)</label
                        >
                        <FormInput
                            id="pos-void-reason"
                            v-model="voidReason"
                            type="text"
                            maxlength="255"
                            placeholder="Se cobró de más, el cliente devolvió…"
                        />
                    </div>

                    <p
                        v-if="voidError"
                        class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                    >
                        {{ voidError }}
                    </p>

                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            class="min-h-11"
                            @click="voidingId = null"
                            >Volver</Button
                        >
                        <Button
                            variant="danger"
                            class="min-h-11"
                            :disabled="voidBusy"
                            @click="confirmVoid"
                            >{{
                                voidBusy ? 'Cancelando…' : 'Sí, cancelar'
                            }}</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
