<script setup lang="ts">
import axios from 'axios';
import { computed, inject, onMounted, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import type { CounterMethod } from '@/composables/useCounterMethods';
import { useCounterMethods } from '@/composables/useCounterMethods';
import Lucide from '@/components/Base/Lucide';
import { FloorPlanKey } from '../../context';
import { formatMoney } from '../../format';
import FolioActions from '../FolioActions.vue';

/**
 * Consumos y cobro de un cuarto en uso, sin salir del plano.
 *
 * **En un motel se cobra al entregar**: efectivo o terminal, ahí mismo. Por eso
 * el par "a la cuenta / cobrar ahora" solo existe donde el crédito de hotel
 * existe (hotel y "ambos", donde decide quien atiende).
 *
 * Dos columnas: a la izquierda qué se vende, a la derecha la cuenta. Cada una
 * con su propio scroll — antes todo iba en una sola columna y la caja de
 * "Consumido" terminaba encimada con la primera línea del carrito.
 *
 * El carrito vive SOLO en memoria: la página del POS guarda el suyo en
 * `kuira.pos.cart`, y compartir almacenamiento haría que una venta a medias en
 * el plano apareciera allá (o al revés) cargada a otra habitación.
 */
interface CatalogProduct {
    id: number;
    name: string;
    category: string | null;
    price: number;
    track_stock: boolean;
    stock_qty: number;
}

const ctx = inject(FloorPlanKey)!;

const room = computed(() => ctx.room.value!);
const {
    propertyId,
    chargeToRoomByDefault,
    roomCreditEnabled,
    onSold,
    onError,
    folio,
    busyAction,
    voidOrder,
} = ctx;

const products = ref<CatalogProduct[]>([]);
const categories = ref<string[]>([]);
const loading = ref(false);
const loaded = ref(false);

// El catálogo se pide una vez al abrir y se guarda en memoria, no en
// localStorage: el stock cambia y un precio viejo cobra de menos.
async function loadCatalog() {
    if (loaded.value || loading.value) {
        return;
    }

    loading.value = true;

    try {
        const { data } = await axios.get('/api/pos/catalog');
        products.value = data.products ?? [];
        categories.value = data.categories ?? [];
        loaded.value = true;
    } catch {
        onError('No se pudo cargar el catálogo de productos.');
    } finally {
        loading.value = false;
    }
}

const search = ref('');
const category = ref('');
const cart = ref<{ product_id: number; qty: number }[]>([]);
// Sin crédito de habitación (motel) esto NUNCA es verdadero: se cobra y ya.
const chargeToRoom = ref(
    roomCreditEnabled.value ? chargeToRoomByDefault.value : false,
);
// Formas de cobro que acepta la recepción (/ajustes/metodos-pago →
// Políticas): lo que el hotel no acepta ni se ofrece aquí.
const {
    methods: counterMethods,
    first: firstMethod,
    coerce: coerceMethod,
} = useCounterMethods();

const method = ref<CounterMethod>(firstMethod.value);
const reference = ref('');
const saving = ref(false);
const historyOpen = ref(false);

const stay = computed(() => room.value.active_stay);

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return products.value.filter((product) => {
        if (category.value && product.category !== category.value) {
            return false;
        }

        return !term || product.name.toLowerCase().includes(term);
    });
});

const lines = computed(() =>
    cart.value.map((line) => {
        const product = products.value.find((p) => p.id === line.product_id);

        return {
            ...line,
            name: product?.name ?? '—',
            price: product?.price ?? 0,
            total: (product?.price ?? 0) * line.qty,
        };
    }),
);

const total = computed(() =>
    lines.value.reduce((sum, line) => sum + line.total, 0),
);

/** Cuántos de este producto van en el carrito (para pintarlo en la lista). */
function qtyOf(productId: number): number {
    return cart.value.find((line) => line.product_id === productId)?.qty ?? 0;
}

function add(product: CatalogProduct) {
    const found = cart.value.find((line) => line.product_id === product.id);

    if (found) {
        found.qty += 1;
    } else {
        cart.value.push({ product_id: product.id, qty: 1 });
    }
}

function addOne(productId: number) {
    const found = cart.value.find((line) => line.product_id === productId);

    if (found) {
        found.qty += 1;
    }
}

function remove(productId: number) {
    const found = cart.value.find((line) => line.product_id === productId);

    if (!found) {
        return;
    }

    found.qty -= 1;

    if (found.qty <= 0) {
        cart.value = cart.value.filter((line) => line.product_id !== productId);
    }
}

// Cambiar de cuarto vacía el carrito: cargarle al 104 lo que se armó para el
// 103 es el error caro de esta pantalla.
watch(
    () => room.value.id,
    () => {
        cart.value = [];
        historyOpen.value = false;
    },
);

async function submit() {
    if (!stay.value || cart.value.length === 0 || saving.value) {
        return;
    }

    saving.value = true;

    try {
        await axios.post('/api/orders', {
            property_id: propertyId,
            stay_id: stay.value.id,
            charge_to_room: chargeToRoom.value,
            payment_method: chargeToRoom.value
                ? null
                : coerceMethod(method.value),
            payment_reference:
                !chargeToRoom.value && reference.value ? reference.value : null,
            lines: cart.value,
        });

        onSold(
            chargeToRoom.value
                ? `${formatMoney(total.value)} a la cuenta de la ${room.value.number}`
                : `${formatMoney(total.value)} cobrados en la ${room.value.number}`,
        );
        cart.value = [];
        reference.value = '';
        // El stock se movió: el catálogo que quedó en memoria ya miente.
        loaded.value = false;
        void loadCatalog();
    } catch (error: any) {
        onError(
            error.response?.data?.message ?? 'No se pudo registrar el consumo.',
        );
    } finally {
        saving.value = false;
    }
}

/** Lo ya consumido en esta estancia, cobrado o a la cuenta. */
const consumed = computed(() => folio.value?.consumption ?? []);

async function cancelOrder(orderId: number) {
    // Cancelar devuelve mercancía y mueve el corte: se confirma, no se
    // deshace con un clic distraído.
    if (
        !window.confirm(
            '¿Cancelar esta venta y devolver la mercancía al inventario?',
        )
    ) {
        return;
    }

    await voidOrder(orderId);
}

// En un tab no hay "abrir la tarjeta": si hay alguien adentro, el catálogo se
// pide al entrar y el cajero teclea de una vez.
onMounted(() => {
    chargeToRoom.value = roomCreditEnabled.value
        ? chargeToRoomByDefault.value
        : false;

    if (stay.value) {
        void loadCatalog();
    }
});
</script>

<template>
    <div class="h-full">
        <p v-if="!stay" class="text-xs text-slate-500">
            La {{ room.number }} no tiene a nadie adentro: los consumos se
            cargan a una habitación en uso.
        </p>

        <div v-else class="grid gap-5 lg:grid-cols-12">
            <!-- Izquierda: qué se está vendiendo. -->
            <div class="flex min-h-0 flex-col lg:col-span-7">
                <div class="flex flex-wrap gap-2">
                    <FormInput
                        v-model="search"
                        type="search"
                        class="min-w-0 flex-1"
                        placeholder="Buscar producto"
                    />
                    <FormSelect
                        v-if="categories.length"
                        v-model="category"
                        class="w-full shrink-0 sm:w-44"
                    >
                        <option value="">Todas las categorías</option>
                        <option v-for="c in categories" :key="c" :value="c">
                            {{ c }}
                        </option>
                    </FormSelect>
                </div>

                <p v-if="loading" class="mt-4 text-xs text-slate-500">
                    Cargando catálogo…
                </p>

                <!-- Tarjetas y no renglones: se tocan con el dedo y el precio
                     se lee sin buscarlo. -->
                <div
                    v-else
                    class="mt-3 grid max-h-[46vh] grid-cols-1 gap-2 overflow-y-auto pr-1 sm:grid-cols-2"
                >
                    <button
                        v-for="product in filtered"
                        :key="product.id"
                        type="button"
                        class="flex flex-col gap-2 rounded-xl border p-3 text-left transition disabled:opacity-40"
                        :class="
                            qtyOf(product.id) > 0
                                ? 'border-primary bg-primary/5'
                                : 'border-slate-200/70 hover:border-primary/40 dark:border-darkmode-400'
                        "
                        :disabled="
                            product.track_stock && product.stock_qty <= 0
                        "
                        :title="
                            product.track_stock && product.stock_qty <= 0
                                ? 'Sin existencias'
                                : `Agregar ${product.name}`
                        "
                        @click="add(product)"
                    >
                        <!-- shrink-0: sin esto el nombre se aplasta contra
                             el borde cuando la rejilla iguala las alturas de
                             la fila y la tarjeta queda más corta que su
                             contenido. -->
                        <span
                            class="line-clamp-2 shrink-0 text-sm font-medium"
                            >{{ product.name }}</span
                        >
                        <span
                            class="mt-auto flex shrink-0 items-center justify-between"
                        >
                            <span class="text-sm font-semibold">{{
                                formatMoney(Number(product.price))
                            }}</span>
                            <span
                                v-if="qtyOf(product.id) > 0"
                                class="rounded-full bg-primary px-2 py-0.5 text-xs font-medium text-white"
                                >{{ qtyOf(product.id) }}</span
                            >
                            <span
                                v-else-if="
                                    product.track_stock &&
                                    product.stock_qty <= 3
                                "
                                class="text-xs text-pending"
                                >Quedan {{ product.stock_qty }}</span
                            >
                        </span>
                    </button>

                    <p
                        v-if="!filtered.length"
                        class="col-span-full rounded-xl border border-dashed border-slate-300/70 px-4 py-6 text-center text-xs text-slate-500 dark:border-darkmode-400"
                    >
                        Sin productos que coincidan.
                    </p>
                </div>
            </div>

            <!-- Derecha: la cuenta y la decisión de dinero. -->
            <div class="flex min-h-0 flex-col lg:col-span-5">
                <!-- Los dos números de la estancia, cada uno en su caja: antes
                     compartían renglón con la primera línea del carrito. -->
                <div class="grid grid-cols-2 gap-2">
                    <div
                        class="rounded-xl border border-slate-200/70 p-3 dark:border-darkmode-400"
                    >
                        <div class="text-xs text-slate-500">Consumido</div>
                        <div class="mt-0.5 font-semibold">
                            {{ formatMoney(Number(stay.consumos_total ?? 0)) }}
                        </div>
                    </div>
                    <div
                        class="rounded-xl border p-3"
                        :class="
                            Number(stay.balance_due ?? 0) > 0
                                ? 'border-pending/30 bg-pending/5'
                                : 'border-slate-200/70 dark:border-darkmode-400'
                        "
                    >
                        <div class="text-xs text-slate-500">
                            Por cobrar al salir
                        </div>
                        <div
                            class="mt-0.5 font-semibold"
                            :class="
                                Number(stay.balance_due ?? 0) > 0
                                    ? 'text-pending'
                                    : ''
                            "
                        >
                            {{ formatMoney(Number(stay.balance_due ?? 0)) }}
                        </div>
                    </div>
                </div>

                <!-- El carrito -->
                <div
                    class="mt-3 min-h-0 flex-1 overflow-y-auto rounded-xl border border-slate-200/70 p-3 dark:border-darkmode-400"
                >
                    <div v-if="lines.length" class="space-y-2">
                        <div
                            v-for="line in lines"
                            :key="line.product_id"
                            class="flex items-center gap-2"
                        >
                            <div class="flex shrink-0 items-center gap-1">
                                <button
                                    type="button"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 text-slate-500 transition hover:border-primary/40 hover:text-primary dark:border-darkmode-400"
                                    :title="`Quitar uno de ${line.name}`"
                                    @click="remove(line.product_id)"
                                >
                                    <Lucide icon="Minus" class="h-3.5 w-3.5" />
                                </button>
                                <span
                                    class="w-7 text-center text-sm font-semibold"
                                    >{{ line.qty }}</span
                                >
                                <button
                                    type="button"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 text-slate-500 transition hover:border-primary/40 hover:text-primary dark:border-darkmode-400"
                                    :title="`Agregar otro ${line.name}`"
                                    @click="addOne(line.product_id)"
                                >
                                    <Lucide icon="Plus" class="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm">
                                    {{ line.name }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ formatMoney(line.price) }} c/u
                                </div>
                            </div>
                            <span class="shrink-0 text-sm font-medium">{{
                                formatMoney(line.total)
                            }}</span>
                        </div>
                    </div>
                    <p v-else class="py-6 text-center text-xs text-slate-500">
                        Toca un producto de la lista para agregarlo.
                    </p>
                </div>

                <div
                    class="mt-3 flex items-baseline justify-between text-base font-semibold"
                >
                    <span>Total</span>
                    <span>{{ formatMoney(total) }}</span>
                </div>

                <!-- Quién paga y cuándo. En un motel esto no se pregunta: se
                     cobra al entregar, y por eso el par no existe ahí. -->
                <div
                    v-if="roomCreditEnabled"
                    class="mt-3 grid grid-cols-2 gap-2"
                >
                    <button
                        type="button"
                        class="min-h-11 rounded-lg border px-3 text-sm font-medium transition"
                        :class="
                            chargeToRoom
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-slate-200 text-slate-500 dark:border-darkmode-400'
                        "
                        title="Se suma a la cuenta y se cobra en la salida"
                        @click="chargeToRoom = true"
                    >
                        A la cuenta
                    </button>
                    <button
                        type="button"
                        class="min-h-11 rounded-lg border px-3 text-sm font-medium transition"
                        :class="
                            !chargeToRoom
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-slate-200 text-slate-500 dark:border-darkmode-400'
                        "
                        title="Entra al corte del turno ahora mismo"
                        @click="chargeToRoom = false"
                    >
                        Cobrar ahora
                    </button>
                </div>

                <div v-if="!chargeToRoom" class="mt-3 space-y-2">
                    <FormSelect v-model="method">
                        <option
                            v-for="m in counterMethods"
                            :key="m.key"
                            :value="m.key"
                        >
                            {{ m.label }}
                        </option>
                    </FormSelect>
                    <FormInput
                        v-if="method !== 'cash'"
                        v-model="reference"
                        type="text"
                        maxlength="100"
                        placeholder="Referencia o folio del voucher (opcional)"
                    />
                </div>

                <Button
                    variant="primary"
                    class="mt-3 min-h-12 w-full justify-center rounded-[0.5rem] text-base"
                    :disabled="!lines.length || saving"
                    @click="submit"
                >
                    {{
                        saving
                            ? 'Guardando…'
                            : chargeToRoom
                              ? `Cargar ${formatMoney(total)} a la cuenta`
                              : `Cobrar ${formatMoney(total)}`
                    }}
                </Button>

                <!-- Lo ya entregado, plegado: es consulta, no la tarea. -->
                <div
                    v-if="consumed.length"
                    class="mt-4 border-t border-slate-200/70 pt-3 dark:border-darkmode-400"
                >
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 text-xs font-medium text-slate-500 transition hover:text-primary"
                        @click="historyOpen = !historyOpen"
                    >
                        <Lucide
                            :icon="historyOpen ? 'ChevronUp' : 'ChevronDown'"
                            class="h-3.5 w-3.5"
                        />
                        Ya consumido ({{ consumed.length }})
                    </button>

                    <div v-if="historyOpen" class="mt-2 space-y-1.5">
                        <div
                            v-for="order in consumed"
                            :key="order.id"
                            class="flex items-start gap-2 text-sm"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="truncate">{{ order.summary }}</div>
                                <div class="text-xs text-slate-500">
                                    {{ order.created_at }} ·
                                    {{ order.method_label }}
                                    <span
                                        v-if="!order.settled"
                                        class="text-pending"
                                        title="Se cobra al registrar la salida"
                                        >· pendiente</span
                                    >
                                </div>
                            </div>
                            <span class="shrink-0">{{
                                formatMoney(order.total)
                            }}</span>
                            <button
                                v-if="order.can_void"
                                type="button"
                                class="shrink-0 text-slate-400 transition hover:text-primary disabled:opacity-40"
                                :disabled="busyAction === `order:${order.id}`"
                                title="Cancelar esta venta y devolver la mercancía al inventario"
                                @click="cancelOrder(order.id)"
                            >
                                <Lucide icon="Undo2" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <FolioActions
                    v-if="folio"
                    :folio="folio"
                    class="mt-4 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
                />
            </div>
        </div>
    </div>
</template>
