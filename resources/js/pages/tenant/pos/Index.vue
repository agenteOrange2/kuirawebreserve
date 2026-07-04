<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
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
    recentOrders: { id: number; total: string; room: string | null; created_at: string; summary: string }[];
}>();

const cart = ref<CartLine[]>([]);
const stayId = ref<string | number>('');
const paymentMethod = ref<'cash' | 'card' | 'transfer'>('cash');
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

const money = (n: number) => '$' + new Intl.NumberFormat('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0);

const isOutOfStock = (p: PosProduct) => p.track_stock && p.type === 'simple' && p.stock_qty <= 0;

const filteredProducts = computed(() =>
    props.products.filter((p) => {
        const matchCat = !categoryFilter.value || p.category === categoryFilter.value;
        const matchSearch = !search.value || p.name.toLowerCase().includes(search.value.toLowerCase());
        return matchCat && matchSearch;
    }),
);

const total = computed(() => cart.value.reduce((sum, line) => sum + line.qty * Number(line.product.price), 0));
const itemCount = computed(() => cart.value.reduce((sum, line) => sum + line.qty, 0));

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
function clearCart() {
    cart.value = [];
    stayId.value = '';
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
            lines: cart.value.map((l) => ({ product_id: l.product.id, qty: l.qty })),
        });
        success.value = `Venta #${data.id} registrada · ${money(Number(data.total))}`;
        cart.value = [];
        stayId.value = '';
        router.reload({ only: ['products', 'recentOrders', 'activeStays'] });
    } catch (e: any) {
        error.value = e.response?.data?.message ?? 'No se pudo registrar la venta.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RazeLayout title="POS">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Punto de venta</h1>
                    <p class="text-sm text-slate-500">{{ property.name }}</p>
                </div>
                <Button as="a" :href="route('tenant.inventory')" variant="outline-secondary" class="rounded-[0.5rem] bg-white">
                    <Lucide icon="Package" class="mr-2 h-4 w-4 stroke-[1.3]" /> Inventario
                </Button>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-6">
                <!-- Productos -->
                <div class="col-span-12 xl:col-span-7">
                    <!-- Buscador + categorías -->
                    <div class="box box--stacked flex flex-col gap-3 p-3">
                        <div class="relative">
                            <Lucide icon="Search" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                            <FormInput v-model="search" type="text" placeholder="Buscar producto…" class="pl-9" />
                        </div>
                        <div v-if="categories.length" class="flex flex-wrap gap-1.5">
                            <button class="rounded-full px-3 py-1 text-xs font-medium transition" :class="!categoryFilter ? 'bg-primary text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-darkmode-400'" @click="categoryFilter = ''">Todo</button>
                            <button v-for="c in categories" :key="c" class="rounded-full px-3 py-1 text-xs font-medium transition" :class="categoryFilter === c ? 'bg-primary text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-darkmode-400'" @click="categoryFilter = c">{{ c }}</button>
                        </div>
                    </div>

                    <!-- Grid de productos -->
                    <div v-if="filteredProducts.length" class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <button
                            v-for="p in filteredProducts"
                            :key="p.id"
                            class="box box--stacked flex flex-col p-4 text-left transition hover:-translate-y-0.5 hover:border-primary/40"
                            :disabled="isOutOfStock(p)"
                            :class="{ 'opacity-40': isOutOfStock(p) }"
                            @click="add(p)"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="truncate font-medium">{{ p.name }}</div>
                                <span v-if="p.type === 'composite'" title="Compuesto (receta)"><Lucide icon="ChefHat" class="h-4 w-4 shrink-0 text-pending" /></span>
                            </div>
                            <div v-if="p.category" class="mt-0.5 text-xs text-slate-400">{{ p.category }}</div>
                            <div class="mt-auto flex items-end justify-between pt-3">
                                <span class="text-lg font-medium text-primary">{{ money(Number(p.price)) }}</span>
                                <span
                                    v-if="p.type === 'simple' && p.track_stock"
                                    class="rounded-full px-1.5 py-0.5 text-xs"
                                    :class="p.stock_qty <= 0 ? 'bg-danger/10 text-danger' : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'"
                                >
                                    {{ p.stock_qty <= 0 ? 'Agotado' : `${p.stock_qty} disp.` }}
                                </span>
                            </div>
                        </button>
                    </div>
                    <div v-else class="mt-4 box box--stacked flex flex-col items-center gap-3 py-12 text-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"><Lucide icon="Package" class="h-6 w-6" /></div>
                        <p class="text-sm text-slate-500">{{ search || categoryFilter ? 'Sin productos que coincidan.' : 'Sin productos activos.' }}</p>
                        <Button v-if="!search && !categoryFilter" as="a" :href="route('tenant.inventory')" variant="outline-primary" size="sm" class="rounded-[0.5rem]">Ir a Inventario</Button>
                    </div>

                    <!-- Ventas recientes -->
                    <div class="mt-6 box box--stacked">
                        <div class="flex items-center gap-2 border-b border-slate-200/60 p-5 text-base font-medium dark:border-darkmode-400">
                            <Lucide icon="Receipt" class="h-4 w-4 text-slate-400" /> Ventas recientes
                        </div>
                        <div class="divide-y divide-slate-100 dark:divide-darkmode-400">
                            <div v-for="o in recentOrders" :key="o.id" class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                                <div class="min-w-0">
                                    <span class="text-slate-400">#{{ o.id }}</span>
                                    <span class="ml-1">{{ o.summary }}</span>
                                    <span v-if="o.room" class="ml-1.5 rounded-full bg-primary/10 px-1.5 py-0.5 text-xs text-primary">Hab. {{ o.room }}</span>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <span class="font-medium">${{ o.total }}</span>
                                    <span class="text-xs text-slate-400">{{ o.created_at }}</span>
                                </div>
                            </div>
                            <div v-if="!recentOrders.length" class="px-5 py-8 text-center text-sm text-slate-500">Sin ventas aún.</div>
                        </div>
                    </div>
                </div>

                <!-- Carrito -->
                <div class="col-span-12 xl:col-span-5">
                    <div class="box box--stacked sticky top-24 flex flex-col">
                        <div class="flex items-center justify-between border-b border-slate-200/60 p-5 dark:border-darkmode-400">
                            <h2 class="flex items-center gap-2 text-base font-medium">
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-primary"><Lucide icon="ShoppingCart" class="h-4 w-4" /></div>
                                Cuenta
                                <span v-if="itemCount" class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">{{ itemCount }}</span>
                            </h2>
                            <button v-if="cart.length" type="button" class="text-xs text-slate-400 hover:text-danger" @click="clearCart">Vaciar</button>
                        </div>

                        <div class="p-5">
                            <div v-if="cart.length" class="divide-y divide-slate-100 dark:divide-darkmode-400">
                                <div v-for="line in cart" :key="line.product.id" class="flex items-center justify-between gap-2 py-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate font-medium">{{ line.product.name }}</div>
                                        <div class="text-xs text-slate-400">{{ money(Number(line.product.price)) }} c/u</div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="button" class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-300 text-slate-500 transition hover:border-primary hover:text-primary dark:border-darkmode-400" @click="decrease(line)"><Lucide icon="Minus" class="h-3.5 w-3.5" /></button>
                                        <span class="w-6 text-center font-medium">{{ line.qty }}</span>
                                        <button type="button" class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-300 text-slate-500 transition hover:border-primary hover:text-primary dark:border-darkmode-400" @click="line.qty += 1"><Lucide icon="Plus" class="h-3.5 w-3.5" /></button>
                                        <span class="w-20 text-right font-medium">{{ money(line.qty * Number(line.product.price)) }}</span>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="flex flex-col items-center gap-2 py-10 text-center text-slate-400">
                                <Lucide icon="ShoppingCart" class="h-8 w-8" />
                                <p class="text-sm">Toca productos para agregarlos a la cuenta.</p>
                            </div>

                            <div class="mt-3 border-t border-slate-200/60 pt-4 dark:border-darkmode-400">
                                <label class="mb-1.5 block text-sm text-slate-500">Cargar a habitación (opcional)</label>
                                <div class="relative">
                                    <Lucide icon="BedDouble" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormSelect id="pos-stay" v-model="stayId" class="pl-9">
                                        <option value="">Venta directa (sin cargar a habitación)</option>
                                        <option v-for="s in activeStays" :key="s.id" :value="s.id">{{ s.label }}</option>
                                    </FormSelect>
                                </div>
                            </div>

                            <!-- Método de pago (solo venta directa) -->
                            <div v-if="!stayId" class="mt-3">
                                <label class="mb-1.5 block text-sm text-slate-500">Método de pago</label>
                                <div class="grid grid-cols-3 gap-2">
                                    <button
                                        v-for="m in methods"
                                        :key="m.key"
                                        type="button"
                                        class="flex flex-col items-center gap-1 rounded-lg border py-2.5 text-xs font-medium transition"
                                        :class="paymentMethod === m.key ? 'border-primary bg-primary/10 text-primary' : 'border-slate-200/70 text-slate-500 hover:bg-slate-50 dark:border-darkmode-400'"
                                        @click="paymentMethod = m.key"
                                    >
                                        <Lucide :icon="m.icon" class="h-4 w-4" /> {{ m.label }}
                                    </button>
                                </div>
                            </div>
                            <p v-else class="mt-3 flex items-center gap-2 rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning">
                                <Lucide icon="Info" class="h-4 w-4 shrink-0" /> Se cargará a la habitación y se cobrará en el check-out.
                            </p>

                            <div class="mt-4 flex items-center justify-between rounded-lg bg-slate-50 px-4 py-3 dark:bg-darkmode-700">
                                <span class="text-slate-500">Total</span>
                                <span class="text-xl font-medium">{{ money(total) }}</span>
                            </div>

                            <p v-if="error" class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ error }}</p>
                            <p v-if="success" class="mt-3 flex items-center gap-2 rounded-lg bg-success/10 px-3 py-2 text-sm text-success"><Lucide icon="CircleCheck" class="h-4 w-4" /> {{ success }}</p>

                            <Button class="mt-4 w-full rounded-[0.5rem] shadow-md shadow-primary/20" variant="primary" :disabled="saving || !cart.length" @click="submit">
                                <Lucide icon="CreditCard" class="mr-2 h-4 w-4" /> {{ saving ? 'Registrando…' : `Cobrar ${money(total)}` }}
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
