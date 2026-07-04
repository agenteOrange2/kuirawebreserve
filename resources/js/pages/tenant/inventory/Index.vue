<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormCheck, FormHelp, FormInput, FormLabel, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface RecipeItem { ingredient_id: number; ingredient?: string; unit?: string; quantity: number }
interface ProductRow {
    id: number; name: string; category: string | null; sku: string | null; type: string; unit: string;
    price: string; cost: number; margin: number; track_stock: boolean; stock_qty: number;
    reorder_point: number | null; active: boolean; low_stock: boolean; recipe: RecipeItem[];
}
interface IngredientRow { id: number; name: string; unit: string; stock_qty: number; reorder_point: number | null; cost: string; value: number; low_stock: boolean }
interface MovementRow { id: number; item: string; unit: string | null; type: string; type_label: string; qty: number; unit_cost: number | null; notes: string | null; by: string; at: string | null }

const props = defineProps<{
    property: { id: number; name: string };
    summary: { products_total: number; products_active: number; ingredients_total: number; low_stock: number; value_cost: number; value_price: number; potential_margin: number };
    categories: string[];
    products: ProductRow[];
    ingredients: IngredientRow[];
    movements: MovementRow[];
}>();

const money = (n: number | string) => '$' + new Intl.NumberFormat('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n) || 0);

const tab = ref<'productos' | 'insumos' | 'movimientos'>('productos');
const categoryFilter = ref<string>('');
const saving = ref(false);
const generalError = ref<string | null>(null);
const errors = reactive<Record<string, string>>({});

const commonCategories = ['Bebidas', 'Alimentos', 'Snacks', 'Minibar', 'Alcohol', 'Servicios', 'Amenidades'];
const categoryOptions = computed(() => Array.from(new Set([...props.categories, ...commonCategories])).sort());

const filteredProducts = computed(() =>
    categoryFilter.value ? props.products.filter((p) => p.category === categoryFilter.value) : props.products,
);

const lowStockItems = computed(() => [
    ...props.ingredients.filter((i) => i.low_stock).map((i) => ({ name: i.name, detail: `${i.stock_qty} ${i.unit}`, kind: 'ingredient' as const, id: i.id })),
    ...props.products.filter((p) => p.low_stock).map((p) => ({ name: p.name, detail: `${p.stock_qty} ${p.unit}`, kind: 'product' as const, id: p.id })),
]);

const movementMeta: Record<string, { label: string; icon: Icon; tone: string }> = {
    purchase: { label: 'Compra', icon: 'ArrowDownToLine', tone: 'bg-success/10 text-success' },
    sale: { label: 'Venta', icon: 'ShoppingCart', tone: 'bg-primary/10 text-primary' },
    waste: { label: 'Merma', icon: 'Trash2', tone: 'bg-danger/10 text-danger' },
    adjustment: { label: 'Ajuste', icon: 'Scale', tone: 'bg-warning/10 text-warning' },
};

function clearErrors() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    generalError.value = null;
}
function handleError(error: any) {
    const data = error.response?.data;
    if (data?.errors) Object.entries(data.errors).forEach(([key, msgs]) => (errors[key] = (msgs as string[])[0]));
    else generalError.value = data?.message ?? 'Ocurrió un error.';
}
async function mutate(fn: () => Promise<unknown>, onDone?: () => void) {
    saving.value = true;
    clearErrors();
    try {
        await fn();
        onDone?.();
        router.reload({ only: ['products', 'ingredients', 'summary', 'categories', 'movements'] });
    } catch (error) {
        handleError(error);
    } finally {
        saving.value = false;
    }
}

// ── Productos ────────────────────────────────────────────────
const showProduct = ref(false);
const editingProduct = ref<ProductRow | null>(null);
const productForm = reactive({
    name: '', category: '', sku: '', type: 'simple', unit: 'pieza',
    price: '' as string | number, cost: '' as string | number, track_stock: true,
    reorder_point: '' as string | number,
    recipe: [] as { ingredient_id: number | string; quantity: number | string }[],
});

function openProduct(product: ProductRow | null) {
    editingProduct.value = product;
    productForm.name = product?.name ?? '';
    productForm.category = product?.category ?? '';
    productForm.sku = product?.sku ?? '';
    productForm.type = product?.type ?? 'simple';
    productForm.unit = product?.unit ?? 'pieza';
    productForm.price = product?.price ?? '';
    productForm.cost = product ? String(product.cost) : '';
    productForm.track_stock = product?.track_stock ?? true;
    productForm.reorder_point = product?.reorder_point ?? '';
    productForm.recipe = product?.recipe.map((r) => ({ ingredient_id: r.ingredient_id, quantity: r.quantity })) ?? [];
    clearErrors();
    showProduct.value = true;
}
function addRecipeRow() {
    productForm.recipe.push({ ingredient_id: props.ingredients[0]?.id ?? '', quantity: 1 });
}
function submitProduct() {
    const payload: Record<string, unknown> = {
        name: productForm.name,
        category: productForm.category || null,
        sku: productForm.sku || null,
        type: productForm.type,
        unit: productForm.unit,
        price: productForm.price,
        cost: productForm.cost === '' ? 0 : productForm.cost,
        track_stock: productForm.type === 'simple' ? productForm.track_stock : false,
        reorder_point: productForm.reorder_point === '' ? null : productForm.reorder_point,
        recipe: productForm.type === 'composite' ? productForm.recipe : [],
    };
    mutate(
        () => editingProduct.value ? axios.patch(`/api/products/${editingProduct.value.id}`, payload) : axios.post('/api/products', { ...payload, property_id: props.property.id }),
        () => (showProduct.value = false),
    );
}

const deletingProduct = ref<ProductRow | null>(null);
function submitDeleteProduct() {
    if (!deletingProduct.value) return;
    mutate(() => axios.delete(`/api/products/${deletingProduct.value!.id}`), () => (deletingProduct.value = null));
}

// ── Insumos ──────────────────────────────────────────────────
const showIngredient = ref(false);
const editingIngredient = ref<IngredientRow | null>(null);
const ingredientForm = reactive({ name: '', unit: 'pieza', reorder_point: '' as string | number, cost: '' as string | number });

function openIngredient(ingredient: IngredientRow | null) {
    editingIngredient.value = ingredient;
    ingredientForm.name = ingredient?.name ?? '';
    ingredientForm.unit = ingredient?.unit ?? 'pieza';
    ingredientForm.reorder_point = ingredient?.reorder_point ?? '';
    ingredientForm.cost = ingredient?.cost ?? '';
    clearErrors();
    showIngredient.value = true;
}
function submitIngredient() {
    const payload: Record<string, unknown> = {
        name: ingredientForm.name, unit: ingredientForm.unit,
        reorder_point: ingredientForm.reorder_point === '' ? null : ingredientForm.reorder_point,
        cost: ingredientForm.cost === '' ? 0 : ingredientForm.cost,
    };
    mutate(
        () => editingIngredient.value ? axios.patch(`/api/ingredients/${editingIngredient.value.id}`, payload) : axios.post('/api/ingredients', { ...payload, property_id: props.property.id }),
        () => (showIngredient.value = false),
    );
}

// ── Movimientos de stock ─────────────────────────────────────
const movementTarget = ref<{ kind: 'product' | 'ingredient'; id: number; name: string; unit: string; stock_qty: number } | null>(null);
const movementForm = reactive({ type: 'purchase', qty: '' as string | number, unit_cost: '' as string | number, notes: '' });

function openMovement(kind: 'product' | 'ingredient', row: { id: number; name: string; unit: string; stock_qty: number }) {
    movementTarget.value = { kind, ...row };
    movementForm.type = 'purchase';
    movementForm.qty = '';
    movementForm.unit_cost = '';
    movementForm.notes = '';
    clearErrors();
}

const resultingStock = computed(() => {
    if (!movementTarget.value) return null;
    const raw = Number(movementForm.qty) || 0;
    const delta = movementForm.type === 'waste' ? -Math.abs(raw) : raw;
    return Math.round((movementTarget.value.stock_qty + delta) * 1000) / 1000;
});

function submitMovement() {
    if (!movementTarget.value) return;
    const base = movementTarget.value.kind === 'product' ? 'products' : 'ingredients';
    const rawQty = Number(movementForm.qty);
    const qty = movementForm.type === 'waste' ? -Math.abs(rawQty) : rawQty;
    mutate(
        () => axios.post(`/api/${base}/${movementTarget.value!.id}/movements`, {
            type: movementForm.type, qty,
            unit_cost: movementForm.unit_cost === '' ? null : movementForm.unit_cost,
            notes: movementForm.notes || null,
        }),
        () => (movementTarget.value = null),
    );
}

const iconInput = 'absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400';
const cellClass = 'box shadow-[5px_3px_5px_#00000005] first:border-l last:border-r first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] rounded-l-none rounded-r-none border-x-0 dark:bg-darkmode-600';

const tabs = computed(() => [
    { key: 'productos', label: 'Productos', icon: 'Package' as Icon, count: props.products.length },
    { key: 'insumos', label: 'Insumos', icon: 'Wheat' as Icon, count: props.ingredients.length },
    { key: 'movimientos', label: 'Movimientos', icon: 'ArrowRightLeft' as Icon, count: props.movements.length },
]);
</script>

<template>
    <RazeLayout title="Inventario">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Inventario</h1>
                    <p class="text-sm text-slate-500">{{ property.name }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline-primary" class="rounded-[0.5rem] bg-white" @click="openIngredient(null)">
                        <Lucide icon="Wheat" class="mr-2 h-4 w-4 stroke-[1.3]" /> Insumo
                    </Button>
                    <Button variant="primary" class="rounded-[0.5rem] shadow-md shadow-primary/20" @click="openProduct(null)">
                        <Lucide icon="Plus" class="mr-2 h-4 w-4 stroke-[1.3]" /> Producto
                    </Button>
                </div>
            </div>

            <!-- KPIs -->
            <div class="mt-5 grid grid-cols-12 gap-5">
                <div class="col-span-6 p-5 sm:col-span-4 xl:col-span-3 box box--stacked">
                    <div class="flex items-center justify-between">
                        <div class="flex h-11 w-11 items-center justify-center rounded-full border border-primary/10 bg-primary/10"><Lucide icon="Wallet" class="h-5 w-5 text-primary" /></div>
                        <div class="text-2xl font-medium">{{ money(summary.value_cost) }}</div>
                    </div>
                    <div class="mt-4 text-sm font-medium">Valor del inventario</div>
                    <div class="mt-1 text-xs text-slate-500">A costo · vende por {{ money(summary.value_price) }}</div>
                </div>
                <div class="col-span-6 p-5 sm:col-span-4 xl:col-span-3 box box--stacked">
                    <div class="flex items-center justify-between">
                        <div class="flex h-11 w-11 items-center justify-center rounded-full border border-success/10 bg-success/10"><Lucide icon="TrendingUp" class="h-5 w-5 text-success" /></div>
                        <div class="text-2xl font-medium text-success">{{ money(summary.potential_margin) }}</div>
                    </div>
                    <div class="mt-4 text-sm font-medium">Margen potencial</div>
                    <div class="mt-1 text-xs text-slate-500">Si se vende todo el stock</div>
                </div>
                <div class="col-span-6 p-5 sm:col-span-4 xl:col-span-3 box box--stacked">
                    <div class="flex items-center justify-between">
                        <div class="flex h-11 w-11 items-center justify-center rounded-full border border-info/10 bg-info/10"><Lucide icon="Package" class="h-5 w-5 text-info" /></div>
                        <div class="text-2xl font-medium">{{ summary.products_active }}</div>
                    </div>
                    <div class="mt-4 text-sm font-medium">Productos activos</div>
                    <div class="mt-1 text-xs text-slate-500">{{ summary.ingredients_total }} insumos registrados</div>
                </div>
                <div class="col-span-6 p-5 sm:col-span-4 xl:col-span-3 box box--stacked" :class="summary.low_stock ? 'ring-1 ring-danger/30' : ''">
                    <div class="flex items-center justify-between">
                        <div class="flex h-11 w-11 items-center justify-center rounded-full border" :class="summary.low_stock ? 'border-danger/10 bg-danger/10' : 'border-slate-200 bg-slate-100 dark:bg-darkmode-400'">
                            <Lucide icon="TriangleAlert" class="h-5 w-5" :class="summary.low_stock ? 'text-danger' : 'text-slate-400'" />
                        </div>
                        <div class="text-2xl font-medium" :class="summary.low_stock ? 'text-danger' : ''">{{ summary.low_stock }}</div>
                    </div>
                    <div class="mt-4 text-sm font-medium">Stock bajo</div>
                    <div class="mt-1 text-xs text-slate-500">Ítems en o bajo su punto de reorden</div>
                </div>
            </div>

            <!-- Alerta de stock bajo -->
            <div v-if="lowStockItems.length" class="mt-5 box box--stacked border-l-4 border-l-danger p-4">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <Lucide icon="TriangleAlert" class="h-5 w-5 shrink-0 text-danger" />
                    <span class="font-medium">Reabastecer pronto:</span>
                    <span v-for="it in lowStockItems" :key="`${it.kind}-${it.id}`" class="rounded-full bg-danger/10 px-2 py-0.5 text-xs text-danger">
                        {{ it.name }} · {{ it.detail }}
                    </span>
                </div>
            </div>

            <div v-if="generalError" class="mt-4 rounded-lg bg-danger/10 px-4 py-3 text-sm text-danger">{{ generalError }}</div>

            <!-- Tabs -->
            <div class="mt-5 inline-flex flex-wrap gap-1 rounded-[0.7rem] border border-slate-200/80 bg-slate-100/70 p-1 dark:border-darkmode-400 dark:bg-darkmode-700">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    class="flex items-center gap-2 rounded-[0.5rem] px-4 py-2 text-sm font-medium transition"
                    :class="tab === t.key ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'"
                    @click="tab = t.key as typeof tab"
                >
                    <Lucide :icon="t.icon" class="h-4 w-4" /> {{ t.label }}
                    <span class="rounded-full px-1.5 py-0.5 text-xs leading-none" :class="tab === t.key ? 'bg-primary/10 text-primary' : 'bg-slate-200/80 text-slate-500 dark:bg-darkmode-400'">{{ t.count }}</span>
                </button>
            </div>

            <!-- ============ Productos ============ -->
            <div v-show="tab === 'productos'" class="mt-4">
                <div v-if="categories.length" class="mb-3 flex flex-wrap items-center gap-2">
                    <button class="rounded-full px-3 py-1 text-xs font-medium transition" :class="!categoryFilter ? 'bg-primary text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-darkmode-400'" @click="categoryFilter = ''">Todas</button>
                    <button v-for="c in categories" :key="c" class="rounded-full px-3 py-1 text-xs font-medium transition" :class="categoryFilter === c ? 'bg-primary text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-darkmode-400'" @click="categoryFilter = c">{{ c }}</button>
                </div>
                <div class="overflow-auto lg:overflow-visible">
                    <Table v-if="filteredProducts.length" class="border-separate border-spacing-y-[8px]">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="border-b-0 !bg-transparent">Producto</Table.Th>
                                <Table.Th class="border-b-0 !bg-transparent">Tipo</Table.Th>
                                <Table.Th class="border-b-0 !bg-transparent text-right">Precio</Table.Th>
                                <Table.Th class="border-b-0 !bg-transparent text-right">Costo / Margen</Table.Th>
                                <Table.Th class="border-b-0 !bg-transparent text-right">Stock</Table.Th>
                                <Table.Th class="border-b-0 !bg-transparent text-right">Acciones</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="p in filteredProducts" :key="p.id">
                                <Table.Td :class="cellClass">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium" :class="{ 'text-slate-400 line-through': !p.active }">{{ p.name }}</span>
                                        <span v-if="p.category" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400">{{ p.category }}</span>
                                    </div>
                                    <span v-if="p.type === 'composite'" class="mt-0.5 block text-xs text-slate-500">{{ p.recipe.map((r) => `${r.quantity} ${r.ingredient}`).join(' + ') }}</span>
                                </Table.Td>
                                <Table.Td :class="cellClass">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="p.type === 'composite' ? 'bg-pending/10 text-pending' : 'bg-info/10 text-info'">{{ p.type === 'composite' ? 'Compuesto' : 'Simple' }}</span>
                                </Table.Td>
                                <Table.Td :class="cellClass" class="text-right font-medium">${{ p.price }}</Table.Td>
                                <Table.Td :class="cellClass" class="text-right text-sm">
                                    <span class="text-slate-500">${{ p.cost.toFixed(2) }}</span>
                                    <span class="ml-1 rounded-full px-1.5 py-0.5 text-xs" :class="p.margin >= 0 ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'">{{ money(p.margin) }}</span>
                                </Table.Td>
                                <Table.Td :class="cellClass" class="text-right">
                                    <template v-if="p.type === 'simple' && p.track_stock">
                                        <span :class="p.low_stock ? 'font-medium text-danger' : ''">{{ p.stock_qty }} {{ p.unit }}</span>
                                    </template>
                                    <span v-else class="text-slate-400">—</span>
                                </Table.Td>
                                <Table.Td :class="cellClass" class="text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button v-if="p.type === 'simple' && p.track_stock" type="button" title="Movimiento de stock" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-success/10 hover:text-success" @click="openMovement('product', p)"><Lucide icon="PackagePlus" class="h-4 w-4" /></button>
                                        <button type="button" title="Editar" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary" @click="openProduct(p)"><Lucide icon="Pencil" class="h-4 w-4" /></button>
                                        <button type="button" title="Eliminar" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger" @click="deletingProduct = p"><Lucide icon="Trash2" class="h-4 w-4" /></button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div v-else class="box box--stacked flex flex-col items-center gap-3 py-12 text-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-info/10 text-info"><Lucide icon="Package" class="h-6 w-6" /></div>
                        <p class="text-sm text-slate-500">{{ categoryFilter ? 'Sin productos en esta categoría.' : 'Sin productos. Ej: Coca (simple) o Hamburguesa (compuesto con receta).' }}</p>
                        <Button variant="outline-primary" size="sm" class="rounded-[0.5rem]" @click="openProduct(null)"><Lucide icon="Plus" class="mr-1.5 h-4 w-4" /> Nuevo producto</Button>
                    </div>
                </div>
            </div>

            <!-- ============ Insumos ============ -->
            <div v-show="tab === 'insumos'" class="mt-4">
                <div class="overflow-auto lg:overflow-visible">
                    <Table v-if="ingredients.length" class="border-separate border-spacing-y-[8px]">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="border-b-0 !bg-transparent">Insumo</Table.Th>
                                <Table.Th class="border-b-0 !bg-transparent text-right">Stock</Table.Th>
                                <Table.Th class="border-b-0 !bg-transparent text-right">Reorden</Table.Th>
                                <Table.Th class="border-b-0 !bg-transparent text-right">Costo</Table.Th>
                                <Table.Th class="border-b-0 !bg-transparent text-right">Valor</Table.Th>
                                <Table.Th class="border-b-0 !bg-transparent text-right">Acciones</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="i in ingredients" :key="i.id">
                                <Table.Td :class="cellClass" class="font-medium">{{ i.name }}</Table.Td>
                                <Table.Td :class="cellClass" class="text-right"><span :class="i.low_stock ? 'font-medium text-danger' : ''">{{ i.stock_qty }} {{ i.unit }}</span></Table.Td>
                                <Table.Td :class="cellClass" class="text-right text-slate-500">{{ i.reorder_point ?? '—' }}</Table.Td>
                                <Table.Td :class="cellClass" class="text-right text-slate-500">${{ i.cost }}</Table.Td>
                                <Table.Td :class="cellClass" class="text-right font-medium">{{ money(i.value) }}</Table.Td>
                                <Table.Td :class="cellClass" class="text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button type="button" title="Movimiento de stock" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-success/10 hover:text-success" @click="openMovement('ingredient', i)"><Lucide icon="PackagePlus" class="h-4 w-4" /></button>
                                        <button type="button" title="Editar" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary" @click="openIngredient(i)"><Lucide icon="Pencil" class="h-4 w-4" /></button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div v-else class="box box--stacked flex flex-col items-center gap-3 py-12 text-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-warning/10 text-warning"><Lucide icon="Wheat" class="h-6 w-6" /></div>
                        <p class="text-sm text-slate-500">Sin insumos. Ej: pan, carne, mayonesa… (se descuentan solos al vender productos con receta).</p>
                        <Button variant="outline-primary" size="sm" class="rounded-[0.5rem]" @click="openIngredient(null)"><Lucide icon="Plus" class="mr-1.5 h-4 w-4" /> Nuevo insumo</Button>
                    </div>
                </div>
            </div>

            <!-- ============ Movimientos ============ -->
            <div v-show="tab === 'movimientos'" class="mt-4">
                <div class="box box--stacked overflow-auto p-5 lg:overflow-visible">
                    <div v-if="movements.length" class="flow-root">
                        <ul class="-mb-4">
                            <li v-for="(m, i) in movements" :key="m.id" class="relative pb-4">
                                <span v-if="i !== movements.length - 1" class="absolute left-[19px] top-10 h-full w-px bg-slate-200 dark:bg-darkmode-400" />
                                <div class="flex items-start gap-3">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :class="movementMeta[m.type]?.tone ?? 'bg-slate-100 text-slate-500'">
                                        <Lucide :icon="movementMeta[m.type]?.icon ?? 'Circle'" class="h-4 w-4" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-x-2 text-sm">
                                            <span class="font-medium">{{ m.item }}</span>
                                            <span class="rounded-full px-2 py-0.5 text-xs" :class="movementMeta[m.type]?.tone ?? 'bg-slate-100 text-slate-500'">{{ m.type_label }}</span>
                                            <span class="font-medium" :class="m.qty >= 0 ? 'text-success' : 'text-danger'">{{ m.qty >= 0 ? '+' : '' }}{{ m.qty }} {{ m.unit }}</span>
                                            <span v-if="m.unit_cost !== null" class="text-xs text-slate-400">@ {{ money(m.unit_cost) }}</span>
                                        </div>
                                        <div class="text-xs text-slate-400">{{ m.by }} · {{ m.at }}<span v-if="m.notes"> · {{ m.notes }}</span></div>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <div v-else class="flex flex-col items-center gap-3 py-12 text-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"><Lucide icon="ArrowRightLeft" class="h-6 w-6" /></div>
                        <p class="text-sm text-slate-500">Sin movimientos aún. Compras, mermas, ajustes y ventas aparecerán aquí.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal producto -->
        <Dialog size="lg" :open="showProduct" @close="showProduct = false">
            <Dialog.Panel>
                <form class="flex max-h-[85vh] flex-col" @submit.prevent="submitProduct">
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"><Lucide :icon="editingProduct ? 'Pencil' : 'Package'" class="h-5 w-5" /></div>
                        <div class="min-w-0 flex-1"><h2 class="text-base font-medium">{{ editingProduct ? 'Editar producto' : 'Nuevo producto' }}</h2><p class="mt-0.5 text-xs text-slate-500">Se venden en el POS</p></div>
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400" @click="showProduct = false"><Lucide icon="X" class="h-5 w-5" /></button>
                    </div>
                    <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                        <div class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
                            <div>
                                <FormLabel htmlFor="p-name">Nombre</FormLabel>
                                <div class="relative"><Lucide icon="Package" :class="iconInput" /><FormInput id="p-name" v-model="productForm.name" type="text" class="pl-9" placeholder="Hamburguesa" /></div>
                                <FormHelp v-if="errors.name" class="text-danger">{{ errors.name }}</FormHelp>
                            </div>
                            <div>
                                <FormLabel htmlFor="p-category">Categoría</FormLabel>
                                <div class="relative"><Lucide icon="Tag" :class="iconInput" /><FormInput id="p-category" v-model="productForm.category" type="text" list="cat-options" class="pl-9" placeholder="Alimentos" /></div>
                                <datalist id="cat-options"><option v-for="c in categoryOptions" :key="c" :value="c" /></datalist>
                            </div>
                            <div>
                                <FormLabel htmlFor="p-type">Tipo</FormLabel>
                                <FormSelect id="p-type" v-model="productForm.type"><option value="simple">Simple (stock propio)</option><option value="composite">Compuesto (receta de insumos)</option></FormSelect>
                            </div>
                            <div>
                                <FormLabel htmlFor="p-unit">Unidad</FormLabel>
                                <FormInput id="p-unit" v-model="productForm.unit" type="text" placeholder="pieza / lt / kg" />
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-3">
                            <div>
                                <FormLabel htmlFor="p-price">Precio de venta</FormLabel>
                                <div class="relative"><Lucide icon="DollarSign" :class="iconInput" /><FormInput id="p-price" v-model="productForm.price" type="number" step="0.01" min="0" class="pl-9" /></div>
                                <FormHelp v-if="errors.price" class="text-danger">{{ errors.price }}</FormHelp>
                            </div>
                            <div v-if="productForm.type === 'simple'">
                                <FormLabel htmlFor="p-cost">Costo</FormLabel>
                                <div class="relative"><Lucide icon="DollarSign" :class="iconInput" /><FormInput id="p-cost" v-model="productForm.cost" type="number" step="0.01" min="0" class="pl-9" /></div>
                            </div>
                            <div v-if="productForm.type === 'simple'">
                                <FormLabel htmlFor="p-reorder">Punto de reorden</FormLabel>
                                <div class="relative"><Lucide icon="TriangleAlert" :class="iconInput" /><FormInput id="p-reorder" v-model="productForm.reorder_point" type="number" step="0.001" min="0" class="pl-9" placeholder="—" /></div>
                            </div>
                        </div>
                        <div v-if="productForm.type === 'simple'" class="rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400">
                            <FormCheck><FormCheck.Input id="p-track" v-model="productForm.track_stock" type="checkbox" /><FormCheck.Label htmlFor="p-track">Controlar existencias de este producto</FormCheck.Label></FormCheck>
                        </div>

                        <template v-if="productForm.type === 'composite'">
                            <div class="flex items-center justify-between border-t border-slate-200/60 pt-4 dark:border-darkmode-400">
                                <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400"><Lucide icon="ChefHat" class="h-3.5 w-3.5" /> Receta (se descuenta de insumos)</div>
                                <Button type="button" variant="outline-primary" size="sm" class="rounded-[0.5rem]" :disabled="!ingredients.length" @click="addRecipeRow"><Lucide icon="Plus" class="h-3.5 w-3.5" /></Button>
                            </div>
                            <p v-if="!ingredients.length" class="rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning">Primero crea insumos para armar la receta.</p>
                            <div class="space-y-2">
                                <div v-for="(row, idx) in productForm.recipe" :key="idx" class="flex items-center gap-2">
                                    <FormSelect v-model="row.ingredient_id" class="flex-1"><option v-for="i in ingredients" :key="i.id" :value="i.id">{{ i.name }} ({{ i.unit }})</option></FormSelect>
                                    <FormInput v-model="row.quantity" type="number" step="0.001" min="0.001" class="w-24 text-center" />
                                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 transition hover:bg-danger/10 hover:text-danger" @click="productForm.recipe.splice(idx, 1)"><Lucide icon="Trash2" class="h-4 w-4" /></button>
                                </div>
                            </div>
                        </template>

                        <p v-if="generalError" class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ generalError }}</p>
                    </div>
                    <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <Button type="button" variant="outline-secondary" @click="showProduct = false">Cancelar</Button>
                        <Button type="submit" variant="primary" class="shadow-md shadow-primary/20" :disabled="saving"><Lucide icon="Check" class="mr-2 h-4 w-4" /> {{ saving ? 'Guardando…' : 'Guardar' }}</Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal insumo -->
        <Dialog size="lg" :open="showIngredient" @close="showIngredient = false">
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="submitIngredient">
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-warning/10 text-warning"><Lucide :icon="editingIngredient ? 'Pencil' : 'Wheat'" class="h-5 w-5" /></div>
                        <div class="min-w-0 flex-1"><h2 class="text-base font-medium">{{ editingIngredient ? 'Editar insumo' : 'Nuevo insumo' }}</h2><p class="mt-0.5 text-xs text-slate-500">Materia prima para recetas</p></div>
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400" @click="showIngredient = false"><Lucide icon="X" class="h-5 w-5" /></button>
                    </div>
                    <div class="space-y-4 px-6 py-5">
                        <div class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
                            <div>
                                <FormLabel htmlFor="i-name">Nombre</FormLabel>
                                <div class="relative"><Lucide icon="Wheat" :class="iconInput" /><FormInput id="i-name" v-model="ingredientForm.name" type="text" class="pl-9" placeholder="Pan" /></div>
                                <FormHelp v-if="errors.name" class="text-danger">{{ errors.name }}</FormHelp>
                            </div>
                            <div>
                                <FormLabel htmlFor="i-unit">Unidad</FormLabel>
                                <div class="relative"><Lucide icon="Ruler" :class="iconInput" /><FormInput id="i-unit" v-model="ingredientForm.unit" type="text" class="pl-9" placeholder="pieza / kg / lt" /></div>
                            </div>
                            <div>
                                <FormLabel htmlFor="i-cost">Costo unitario</FormLabel>
                                <div class="relative"><Lucide icon="DollarSign" :class="iconInput" /><FormInput id="i-cost" v-model="ingredientForm.cost" type="number" step="0.01" min="0" class="pl-9" /></div>
                            </div>
                            <div>
                                <FormLabel htmlFor="i-reorder">Punto de reorden</FormLabel>
                                <div class="relative"><Lucide icon="TriangleAlert" :class="iconInput" /><FormInput id="i-reorder" v-model="ingredientForm.reorder_point" type="number" step="0.001" min="0" class="pl-9" placeholder="—" /></div>
                            </div>
                        </div>
                        <p v-if="generalError" class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ generalError }}</p>
                    </div>
                    <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <Button type="button" variant="outline-secondary" @click="showIngredient = false">Cancelar</Button>
                        <Button type="submit" variant="primary" class="shadow-md shadow-primary/20" :disabled="saving"><Lucide icon="Check" class="mr-2 h-4 w-4" /> {{ saving ? 'Guardando…' : 'Guardar' }}</Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal movimiento -->
        <Dialog size="lg" :open="movementTarget !== null" @close="movementTarget = null">
            <Dialog.Panel>
                <form v-if="movementTarget" class="flex flex-col" @submit.prevent="submitMovement">
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"><Lucide icon="PackagePlus" class="h-5 w-5" /></div>
                        <div class="min-w-0 flex-1"><h2 class="text-base font-medium">Movimiento de stock</h2><p class="mt-0.5 text-xs text-slate-500">{{ movementTarget.name }} · {{ movementTarget.stock_qty }} {{ movementTarget.unit }} en existencia</p></div>
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400" @click="movementTarget = null"><Lucide icon="X" class="h-5 w-5" /></button>
                    </div>
                    <div class="space-y-4 px-6 py-5">
                        <div>
                            <FormLabel htmlFor="m-type">Tipo de movimiento</FormLabel>
                            <FormSelect id="m-type" v-model="movementForm.type"><option value="purchase">Compra (entrada)</option><option value="waste">Merma (salida)</option><option value="adjustment">Ajuste (+/−)</option></FormSelect>
                        </div>
                        <div class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
                            <div>
                                <FormLabel htmlFor="m-qty">Cantidad ({{ movementTarget.unit }})</FormLabel>
                                <FormInput id="m-qty" v-model="movementForm.qty" type="number" step="0.001" :placeholder="movementForm.type === 'adjustment' ? 'usa − para restar' : ''" />
                                <FormHelp v-if="errors.qty" class="text-danger">{{ errors.qty }}</FormHelp>
                            </div>
                            <div v-if="movementForm.type === 'purchase'">
                                <FormLabel htmlFor="m-cost">Costo unitario</FormLabel>
                                <div class="relative"><Lucide icon="DollarSign" :class="iconInput" /><FormInput id="m-cost" v-model="movementForm.unit_cost" type="number" step="0.01" min="0" class="pl-9" /></div>
                            </div>
                        </div>
                        <div v-if="movementForm.qty !== ''" class="flex items-center justify-between rounded-lg bg-slate-50 px-4 py-3 text-sm dark:bg-darkmode-700">
                            <span class="text-slate-500">Existencia resultante</span>
                            <span class="font-medium" :class="resultingStock !== null && resultingStock < 0 ? 'text-danger' : ''">{{ movementTarget.stock_qty }} → {{ resultingStock }} {{ movementTarget.unit }}</span>
                        </div>
                        <div>
                            <FormLabel htmlFor="m-notes">Notas (opcional)</FormLabel>
                            <FormInput id="m-notes" v-model="movementForm.notes" type="text" placeholder="Proveedor, factura, motivo…" />
                        </div>
                        <p v-if="generalError" class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ generalError }}</p>
                    </div>
                    <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <Button type="button" variant="outline-secondary" @click="movementTarget = null">Cancelar</Button>
                        <Button type="submit" variant="primary" class="shadow-md shadow-primary/20" :disabled="saving"><Lucide icon="Check" class="mr-2 h-4 w-4" /> {{ saving ? 'Registrando…' : 'Registrar' }}</Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal eliminar producto -->
        <Dialog :open="deletingProduct !== null" @close="deletingProduct = null">
            <Dialog.Panel>
                <div v-if="deletingProduct" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"><Lucide icon="Trash2" class="h-5 w-5" /></div>
                        <div><h2 class="text-base font-medium">¿Eliminar {{ deletingProduct.name }}?</h2><p class="mt-0.5 text-sm text-slate-500">Si tiene ventas o movimientos, solo se desactivará (se conserva el historial).</p></div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button variant="outline-secondary" @click="deletingProduct = null">Cancelar</Button>
                        <Button variant="danger" :disabled="saving" @click="submitDeleteProduct"><Lucide icon="Trash2" class="mr-2 h-4 w-4" /> Sí, eliminar</Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
