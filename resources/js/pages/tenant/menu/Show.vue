<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormTextarea } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { WizardAppearance } from '@/composables/useWizardAppearance';
import { useWizardAppearance } from '@/composables/useWizardAppearance';

interface MenuProduct {
    id: number;
    name: string;
    category: string;
    price: number;
    photo_url: string | null;
}

const props = defineProps<{
    appearance: WizardAppearance;
    property: {
        name: string;
        logo_url: string | null;
        phone: string | null;
        currency: string;
    };
    products: MenuProduct[];
    // Del QR de la habitación: llega ya puesta.
    room: string | null;
    // hotel = puede cargarse a la habitación (se paga al final);
    // motel = siempre se paga al recibir.
    billingMode: 'hotel' | 'motel';
    deliveryMethods: Record<string, string>;
    // Horario de cocina: cerrada, la carta se ve pero no se pide.
    schedule: { open: boolean; from: string | null; to: string | null };
    etaMinutes: number | null;
}>();

const { isDark, rootStyle } = useWizardAppearance(props.appearance);

const money = (n: number) =>
    `$${n.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

// ── Carta agrupada por categoría, con filtro de pestañas ──
const categories = computed(() => [
    ...new Set(props.products.map((p) => p.category)),
]);
const activeCategory = ref<string | null>(null);

// Secciones tipo carta de restaurante: título de categoría + sus platillos.
const groupedProducts = computed(() => {
    const visible =
        activeCategory.value === null
            ? props.products
            : props.products.filter(
                  (p) => p.category === activeCategory.value,
              );

    return [...new Set(visible.map((p) => p.category))].map((category) => ({
        category,
        products: visible.filter((p) => p.category === category),
    }));
});

// ── Pedido (carrito) ──
const cart = reactive<Record<number, number>>({});
const cartCount = computed(() =>
    Object.values(cart).reduce((sum, qty) => sum + qty, 0),
);
const cartItems = computed(() =>
    Object.entries(cart)
        .map(([id, qty]) => ({
            product: props.products.find((p) => p.id === Number(id)),
            qty,
        }))
        .filter(
            (item): item is { product: MenuProduct; qty: number } =>
                item.product !== undefined && item.qty > 0,
        ),
);
const total = computed(() =>
    cartItems.value.reduce((sum, item) => sum + item.product.price * item.qty, 0),
);

function add(product: MenuProduct) {
    cart[product.id] = Math.min(20, (cart[product.id] ?? 0) + 1);
}

function remove(product: MenuProduct) {
    const next = (cart[product.id] ?? 0) - 1;
    if (next <= 0) {
        delete cart[product.id];
    } else {
        cart[product.id] = next;
    }
}

// ── Datos del huésped y envío ──
const form = reactive({
    guest_name: '',
    room: props.room ?? '',
    notes: '',
});
const checkout = ref(false);
const sending = ref(false);
const done = ref(false);
const error = ref<string | null>(null);

// Opciones de pago según el modo del negocio: en hotel el cargo a la
// habitación va primero (se paga al final); en motel solo al recibir.
interface PayOption {
    key: string;
    mode: 'room_charge' | 'on_delivery';
    method: string | null;
    label: string;
    hint: string;
    icon: 'BedDouble' | 'Banknote' | 'CreditCard';
}

const payOptions = computed<PayOption[]>(() => {
    const onDelivery: PayOption[] = Object.entries(props.deliveryMethods).map(
        ([method, label]) => ({
            key: method,
            mode: 'on_delivery',
            method,
            label,
            hint:
                method === 'card'
                    ? 'Llevamos la terminal a tu puerta.'
                    : 'Pagas en cuanto te lo entregamos.',
            icon: method === 'card' ? 'CreditCard' : 'Banknote',
        }),
    );

    if (props.billingMode === 'hotel') {
        return [
            {
                key: 'room_charge',
                mode: 'room_charge',
                method: null,
                label: 'Cargar a mi habitación',
                hint: 'Se suma a tu cuenta y lo pagas al hacer el check-out.',
                icon: 'BedDouble',
            },
            ...onDelivery,
        ];
    }

    return onDelivery;
});

const payChoice = ref<string>(
    props.billingMode === 'hotel' && props.room ? 'room_charge' : 'cash',
);
const chosenPay = computed(
    () =>
        payOptions.value.find((o) => o.key === payChoice.value) ??
        payOptions.value[0],
);

async function submit() {
    if (!form.guest_name.trim()) {
        error.value = 'Cuéntanos tu nombre para llevarte el pedido.';
        return;
    }
    if (chosenPay.value.mode === 'room_charge' && !form.room.trim()) {
        error.value =
            'Para cargarlo a tu habitación necesitamos el número de habitación.';
        return;
    }
    sending.value = true;
    error.value = null;
    try {
        await axios.post('/api/menu/solicitudes', {
            guest_name: form.guest_name.trim(),
            room: form.room.trim() || null,
            notes: form.notes.trim() || null,
            payment_mode: chosenPay.value.mode,
            payment_method: chosenPay.value.method,
            items: cartItems.value.map((item) => ({
                product_id: item.product.id,
                qty: item.qty,
            })),
        });
        done.value = true;
    } catch (e: any) {
        error.value =
            e.response?.data?.message ??
            'No se pudo enviar tu pedido. Intenta de nuevo en un momento.';
    } finally {
        sending.value = false;
    }
}

function startOver() {
    Object.keys(cart).forEach((k) => delete cart[Number(k)]);
    form.notes = '';
    checkout.value = false;
    done.value = false;
}
</script>

<template>
    <Head :title="`Menú · ${property.name}`" />
    <div
        class="flex min-h-screen bg-linear-to-b from-theme-1 to-theme-2 px-3 py-8 sm:px-8"
        :style="rootStyle"
    >
        <div class="mx-auto w-full max-w-3xl">
            <div class="mb-5 flex items-center gap-3 px-1 text-white">
                <img
                    v-if="property.logo_url"
                    :src="property.logo_url"
                    :alt="`Logo de ${property.name}`"
                    class="h-11 w-11 shrink-0 rounded-full bg-white object-contain p-1"
                />
                <div
                    v-else
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/10"
                >
                    <Lucide icon="UtensilsCrossed" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-lg font-medium">
                        {{ property.name }}
                    </div>
                    <div class="text-xs text-white/70">
                        Menú y servicios a tu habitación
                    </div>
                </div>
            </div>

            <div
                class="overflow-hidden rounded-2xl bg-white shadow-2xl"
                :class="isDark && 'booking-dark'"
            >
                <!-- Pedido enviado -->
                <div v-if="done" class="p-6 text-center sm:p-10">
                    <div
                        class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-success/10 text-success"
                    >
                        <Lucide icon="CircleCheck" class="h-7 w-7" />
                    </div>
                    <h1 class="mt-4 text-lg font-medium text-slate-800">
                        Recibimos tu pedido, {{ form.guest_name }}
                    </h1>
                    <p class="mt-1.5 text-sm text-slate-500">
                        El equipo ya fue avisado y te lo lleva
                        {{
                            etaMinutes
                                ? `en aproximadamente ${etaMinutes} minutos`
                                : 'en cuanto esté listo'
                        }}.
                        <template v-if="form.room">
                            Lo entregaremos en la habitación
                            {{ form.room }}.</template
                        >
                        <template v-if="chosenPay.mode === 'room_charge'">
                            El total se suma a tu cuenta y lo pagas al hacer el
                            check-out.</template
                        >
                        <template v-else>
                            {{ chosenPay.label }}: ten listo tu pago cuando
                            llegue el pedido.</template
                        >
                    </p>
                    <p
                        v-if="property.phone"
                        class="mt-3 text-xs text-slate-400"
                    >
                        ¿Cambios o dudas? Llama a recepción: {{ property.phone }}
                    </p>
                    <Button
                        variant="outline-secondary"
                        class="mt-6"
                        @click="startOver"
                    >
                        <Lucide icon="RotateCcw" class="mr-2 h-4 w-4" />
                        Pedir algo más
                    </Button>
                </div>

                <!-- Carta vacía (nada curado aún) -->
                <div
                    v-else-if="!products.length"
                    class="p-6 text-center sm:p-10"
                >
                    <div
                        class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 text-slate-400"
                    >
                        <Lucide icon="UtensilsCrossed" class="h-7 w-7" />
                    </div>
                    <h1 class="mt-4 text-lg font-medium text-slate-800">
                        El menú aún no está disponible
                    </h1>
                    <p class="mt-1.5 text-sm text-slate-500">
                        Vuelve a intentarlo más tarde o pregunta en recepción.
                    </p>
                </div>

                <!-- Confirmación del pedido -->
                <div v-else-if="checkout" class="p-5 sm:p-7">
                    <button
                        type="button"
                        class="flex items-center gap-1.5 text-sm text-slate-500 transition hover:text-slate-700"
                        @click="checkout = false"
                    >
                        <Lucide icon="ArrowLeft" class="h-4 w-4" />
                        Volver al menú
                    </button>

                    <h1 class="mt-4 text-lg font-medium text-slate-800">
                        Confirma tu pedido
                    </h1>
                    <p v-if="etaMinutes" class="mt-1 text-sm text-slate-500">
                        Te lo llevamos en aproximadamente
                        {{ etaMinutes }} minutos.
                    </p>

                    <div
                        class="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200"
                    >
                        <div
                            v-for="item in cartItems"
                            :key="item.product.id"
                            class="flex items-center gap-3 p-3.5"
                        >
                            <div class="min-w-0 flex-1">
                                <div
                                    class="truncate text-sm font-medium text-slate-700"
                                >
                                    {{ item.product.name }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ money(item.product.price) }} c/u
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50"
                                    title="Quitar uno"
                                    @click="remove(item.product)"
                                >
                                    <Lucide icon="Minus" class="h-3.5 w-3.5" />
                                </button>
                                <span
                                    class="w-6 text-center text-sm font-medium"
                                    >{{ item.qty }}</span
                                >
                                <button
                                    type="button"
                                    class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50"
                                    title="Agregar uno"
                                    @click="add(item.product)"
                                >
                                    <Lucide icon="Plus" class="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <div
                                class="w-20 text-right text-sm font-medium text-slate-700"
                            >
                                {{ money(item.product.price * item.qty) }}
                            </div>
                        </div>
                        <div
                            class="flex items-center justify-between p-3.5 text-sm"
                        >
                            <span class="text-slate-500"
                                >Total ({{ property.currency }})</span
                            >
                            <span class="text-base font-medium text-slate-800">{{
                                money(total)
                            }}</span>
                        </div>
                    </div>
                    <!-- Cómo pagar -->
                    <div class="mt-5">
                        <div class="text-sm font-medium text-slate-700">
                            ¿Cómo quieres pagar?
                        </div>
                        <div class="mt-2.5 space-y-2.5">
                            <label
                                v-for="option in payOptions"
                                :key="option.key"
                                class="flex cursor-pointer items-center gap-3.5 rounded-xl border p-3.5 transition"
                                :class="
                                    payChoice === option.key
                                        ? 'border-primary bg-primary/5'
                                        : 'border-slate-200 hover:border-slate-300'
                                "
                            >
                                <input
                                    v-model="payChoice"
                                    type="radio"
                                    name="pago"
                                    :value="option.key"
                                    class="sr-only"
                                />
                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                                    :class="
                                        payChoice === option.key
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-slate-100 text-slate-400'
                                    "
                                >
                                    <Lucide
                                        :icon="option.icon"
                                        class="h-5 w-5"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="text-sm font-medium text-slate-700"
                                    >
                                        {{ option.label }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ option.hint }}
                                    </div>
                                </div>
                                <div
                                    class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border"
                                    :class="
                                        payChoice === option.key
                                            ? 'border-primary bg-primary text-white'
                                            : 'border-slate-300'
                                    "
                                >
                                    <Lucide
                                        v-if="payChoice === option.key"
                                        icon="Check"
                                        class="h-3 w-3"
                                    />
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="mt-5 grid grid-cols-12 gap-4">
                        <div class="col-span-12 sm:col-span-7">
                            <label class="mb-1 block text-sm text-slate-600"
                                >Tu nombre</label
                            >
                            <FormInput
                                v-model="form.guest_name"
                                type="text"
                                maxlength="120"
                                placeholder="Para saber a quién llevárselo"
                            />
                        </div>
                        <div class="col-span-12 sm:col-span-5">
                            <label class="mb-1 block text-sm text-slate-600"
                                >Habitación
                                <span class="text-slate-400"
                                    >(si te hospedas aquí)</span
                                ></label
                            >
                            <FormInput
                                v-model="form.room"
                                type="text"
                                maxlength="40"
                                placeholder="Ej. 104"
                            />
                        </div>
                        <div class="col-span-12">
                            <label class="mb-1 block text-sm text-slate-600"
                                >¿Alguna indicación? (opcional)</label
                            >
                            <FormTextarea
                                v-model="form.notes"
                                rows="2"
                                maxlength="500"
                                placeholder="Sin hielo, salsa aparte, tocar despacio…"
                            />
                        </div>
                    </div>

                    <p
                        v-if="error"
                        class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                    >
                        {{ error }}
                    </p>

                    <Button
                        variant="primary"
                        class="mt-5 w-full shadow-md shadow-primary/20"
                        :disabled="sending || !cartItems.length"
                        @click="submit"
                    >
                        <Lucide
                            :icon="sending ? 'RefreshCw' : 'Send'"
                            class="mr-2 h-4 w-4"
                            :class="sending && 'animate-spin'"
                        />
                        {{
                            sending
                                ? 'Enviando…'
                                : `Enviar pedido · ${money(total)}`
                        }}
                    </Button>
                </div>

                <!-- Carta -->
                <div v-else class="p-5 sm:p-7">
                    <h1 class="text-xl font-medium text-slate-800 sm:text-2xl">
                        ¿Se te antoja algo?
                    </h1>
                    <div class="mt-2 h-1 w-12 rounded-full bg-primary"></div>
                    <p class="mt-3 text-sm text-slate-500">
                        Elige de nuestra carta y te lo llevamos hasta tu
                        puerta.
                        <template v-if="room">
                            Habitación {{ room }}.</template
                        >
                        <template v-if="etaMinutes && schedule.open">
                            Entrega aproximada:
                            {{ etaMinutes }} minutos.</template
                        >
                    </p>

                    <!-- Cocina cerrada: se puede ver, no pedir -->
                    <div
                        v-if="!schedule.open"
                        class="mt-4 flex items-start gap-2.5 rounded-xl bg-pending/10 px-4 py-3 text-sm text-pending"
                    >
                        <Lucide
                            icon="Clock"
                            class="mt-0.5 h-4 w-4 shrink-0"
                        />
                        <span>
                            La cocina atiende de {{ schedule.from }} a
                            {{ schedule.to }}. Puedes ver el menú y volver en
                            ese horario para pedir.
                        </span>
                    </div>

                    <!-- Categorías -->
                    <div
                        v-if="categories.length > 1"
                        class="mt-4 flex flex-wrap gap-2"
                    >
                        <button
                            type="button"
                            class="rounded-full px-3 py-1.5 text-xs font-medium transition"
                            :class="
                                activeCategory === null
                                    ? 'bg-primary text-white shadow-md shadow-primary/20'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                            "
                            @click="activeCategory = null"
                        >
                            Todo
                        </button>
                        <button
                            v-for="category in categories"
                            :key="category"
                            type="button"
                            class="rounded-full px-3 py-1.5 text-xs font-medium transition"
                            :class="
                                activeCategory === category
                                    ? 'bg-primary text-white shadow-md shadow-primary/20'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                            "
                            @click="activeCategory = category"
                        >
                            {{ category }}
                        </button>
                    </div>

                    <!-- La carta, por secciones: foto al frente, como carta
                         de hotel — sin ella la lista se siente de sistema -->
                    <div
                        v-for="group in groupedProducts"
                        :key="group.category"
                        class="mt-7 first:mt-5"
                    >
                        <div
                            v-if="categories.length > 1"
                            class="mb-3.5 flex items-center gap-3"
                        >
                            <span
                                class="text-xs font-medium tracking-[0.2em] text-primary uppercase"
                                >{{ group.category }}</span
                            >
                            <span
                                class="flex-1 border-t border-dashed border-slate-200"
                            ></span>
                        </div>
                        <div class="grid grid-cols-12 gap-3.5 sm:gap-4">
                            <div
                                v-for="product in group.products"
                                :key="product.id"
                                class="col-span-6 md:col-span-4"
                            >
                                <div
                                    class="flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white transition hover:border-primary/40 hover:shadow-lg hover:shadow-slate-200/60"
                                >
                                    <div
                                        class="relative h-28 w-full shrink-0 bg-primary/5 sm:h-32"
                                    >
                                        <img
                                            v-if="product.photo_url"
                                            :src="product.photo_url"
                                            :alt="product.name"
                                            class="h-full w-full object-cover"
                                            loading="lazy"
                                        />
                                        <div
                                            v-else
                                            class="flex h-full w-full items-center justify-center"
                                        >
                                            <Lucide
                                                icon="UtensilsCrossed"
                                                class="h-9 w-9 text-primary/25"
                                            />
                                        </div>
                                    </div>
                                    <div
                                        class="flex flex-1 flex-col gap-1 p-3 sm:p-3.5"
                                    >
                                        <div
                                            class="text-sm leading-snug font-medium text-slate-700"
                                        >
                                            {{ product.name }}
                                        </div>
                                        <div
                                            class="text-sm font-medium text-primary"
                                        >
                                            {{ money(product.price) }}
                                        </div>
                                        <div
                                            v-if="schedule.open"
                                            class="mt-auto pt-2"
                                        >
                                            <div
                                                v-if="cart[product.id]"
                                                class="flex items-center justify-between rounded-full bg-primary text-white shadow-md shadow-primary/20"
                                            >
                                                <button
                                                    type="button"
                                                    class="flex h-8 w-9 items-center justify-center rounded-l-full transition hover:bg-white/10"
                                                    title="Quitar uno"
                                                    @click="remove(product)"
                                                >
                                                    <Lucide
                                                        icon="Minus"
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                                <span
                                                    class="text-sm font-medium"
                                                    >{{
                                                        cart[product.id]
                                                    }}</span
                                                >
                                                <button
                                                    type="button"
                                                    class="flex h-8 w-9 items-center justify-center rounded-r-full transition hover:bg-white/10"
                                                    title="Agregar uno"
                                                    @click="add(product)"
                                                >
                                                    <Lucide
                                                        icon="Plus"
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                            </div>
                                            <button
                                                v-else
                                                type="button"
                                                class="flex h-8 w-full items-center justify-center gap-1.5 rounded-full bg-primary/10 text-xs font-medium text-primary transition hover:bg-primary hover:text-white"
                                                @click="add(product)"
                                            >
                                                <Lucide
                                                    icon="Plus"
                                                    class="h-3.5 w-3.5"
                                                />
                                                Agregar
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Barra del pedido -->
                    <div
                        v-if="cartCount && schedule.open"
                        class="sticky bottom-3 mt-6"
                    >
                        <Button
                            variant="primary"
                            class="w-full shadow-lg shadow-primary/30"
                            @click="checkout = true"
                        >
                            <Lucide
                                icon="ShoppingBag"
                                class="mr-2 h-4 w-4"
                            />
                            Ver mi pedido ({{ cartCount }}) ·
                            {{ money(total) }}
                        </Button>
                    </div>
                </div>
            </div>

            <p
                v-if="property.phone"
                class="mt-4 text-center text-xs text-white/60"
            >
                ¿Prefieres pedir por teléfono? Llama a recepción:
                {{ property.phone }}
            </p>
        </div>
    </div>
</template>
