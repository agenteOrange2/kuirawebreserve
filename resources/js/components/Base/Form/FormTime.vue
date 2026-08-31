<script lang="ts">
export default {
    inheritAttrs: false,
};
</script>

<script setup lang="ts">
import _ from 'lodash';
import { twMerge } from 'tailwind-merge';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useAttrs,
    watch,
} from 'vue';
import Lucide from '@/components/Base/Lucide';

/**
 * Campo de hora del panel. Sustituye a <input type="time">, que cada
 * navegador dibuja a su manera (el "--:-- ---" del sistema) y rompía la
 * simetría del theme.
 *
 * Mismo contrato que el input nativo: v-model en 'HH:mm' (cadena vacía = sin
 * hora). Se puede teclear a la brava ("930", "9:30", "21", "9:30 pm") y
 * también elegir de la lista, que va de media en media hora o al paso que se
 * le pida.
 */
const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        disabled?: boolean;
        clearable?: boolean;
        placeholder?: string;
        /** Clases para el input en sí (apariencia); `class` va al campo. */
        inputClass?: string;
        /** Minutos entre opción y opción de la lista. */
        step?: number;
    }>(),
    { clearable: false, placeholder: 'hh:mm', step: 30 },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'change', value: string): void;
}>();

const attrs = useAttrs();

const root = ref<HTMLElement>();
const input = ref<HTMLInputElement>();
// La lista sale del <body> y ahí no llega .booking-dark (la piel oscura del
// wizard público es de ámbito), así que se copia a mano si el campo vive
// dentro de ella.
const darkSkin = ref(false);
const list = ref<HTMLElement>();
const open = ref(false);
// La lista se teletransporta al <body>: dentro de un modal el cuerpo tiene
// scroll propio y una lista 'absolute' se cortaría contra su borde.
const position = ref({ top: 0, left: 0, width: 0 });

const computedClass = computed(() =>
    twMerge([
        // Mismas clases que FormInput; pl-9 por el icono de reloj.
        'disabled:bg-slate-100 disabled:cursor-not-allowed dark:disabled:bg-darkmode-800/50 dark:disabled:border-transparent',
        'transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm rounded-md placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary/20 focus:border-primary/40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700/50 dark:placeholder:text-slate-500/80',
        'pl-9',
        props.clearable && props.modelValue ? 'pr-8' : '',
        props.inputClass,
    ]),
);

// Igual que en FormDate: la clase de la página dimensiona el campo entero.
const wrapperClass = computed(() =>
    twMerge(['relative', typeof attrs.class === 'string' && attrs.class]),
);

const options = computed(() => {
    const step = Math.max(1, props.step);
    const out: string[] = [];

    for (let m = 0; m < 24 * 60; m += step) {
        out.push(
            `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`,
        );
    }

    // Una hora fuera del paso (14:37 guardada de antes) tiene que seguir
    // apareciendo en la lista, o parecería que se perdió.
    const current = normalize(props.modelValue ?? '');

    if (current && !out.includes(current)) {
        out.push(current);
        out.sort();
    }

    return out;
});

/**
 * Interpreta lo que sea que hayan tecleado: '930', '0930', '9:3', '9',
 * '9:30 pm'. Devuelve 'HH:mm' o cadena vacía si no hay forma de leerlo.
 */
function normalize(text: string): string {
    const raw = text.trim().toLowerCase().replace(/\s+/g, '');

    if (raw === '') return '';

    const pm = /p\.?m\.?$/.test(raw);
    const am = /a\.?m\.?$/.test(raw);
    const body = raw.replace(/[ap]\.?m\.?$/, '');

    let h: number;
    let m: number;

    const withColon = /^(\d{1,2})[:.](\d{1,2})$/.exec(body);
    const digits = /^(\d{1,4})$/.exec(body);

    if (withColon) {
        h = Number(withColon[1]);
        m = Number(withColon[2]);
    } else if (digits) {
        const d = digits[1];
        if (d.length <= 2) {
            h = Number(d);
            m = 0;
        } else {
            h = Number(d.slice(0, d.length - 2));
            m = Number(d.slice(-2));
        }
    } else {
        return '';
    }

    if (pm && h < 12) h += 12;
    if (am && h === 12) h = 0;

    if (h > 23 || m > 59) return '';

    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

function syncField() {
    if (input.value) input.value.value = props.modelValue ?? '';
}

function commit(value: string) {
    if (value === (props.modelValue ?? '')) return;

    emit('update:modelValue', value);
    emit('change', value);
}

function pick(value: string) {
    commit(value);
    open.value = false;
    nextTick(syncField);
}

function clear() {
    commit('');
    nextTick(syncField);
}

function onTyped() {
    if (!input.value) return;

    const value = normalize(input.value.value);

    commit(value);
    syncField();
}

function place() {
    if (!input.value) return;

    const box = input.value.getBoundingClientRect();
    const below = window.innerHeight - box.bottom;
    const height = Math.min(224, options.value.length * 32 + 8);

    position.value = {
        // Si abajo no cabe, la lista sale hacia arriba.
        top: below < height + 8 ? box.top - height - 4 : box.bottom + 4,
        left: box.left,
        width: box.width,
    };
}

function onFocus() {
    if (props.disabled) return;

    place();
    open.value = true;
    // La lista arranca en la hora que ya tiene el campo, no en la medianoche.
    nextTick(() => {
        list.value
            ?.querySelector('[data-selected="true"]')
            ?.scrollIntoView({ block: 'center' });
    });
}

function onOutside(event: MouseEvent) {
    const target = event.target as Node;

    if (root.value?.contains(target) || list.value?.contains(target)) return;

    open.value = false;
}

// Con la lista colgada del <body>, si la página (o el modal) se desplaza hay
// que reacomodarla o quedaría flotando lejos del campo.
function onViewportChange() {
    if (open.value) place();
}

onMounted(() => {
    syncField();
    darkSkin.value = input.value?.closest('.booking-dark') !== null;
    document.addEventListener('mousedown', onOutside);
    window.addEventListener('scroll', onViewportChange, true);
    window.addEventListener('resize', onViewportChange);
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onOutside);
    window.removeEventListener('scroll', onViewportChange, true);
    window.removeEventListener('resize', onViewportChange);
});

watch(() => props.modelValue, syncField);
</script>

<template>
    <div ref="root" :class="wrapperClass">
        <Lucide
            icon="Clock"
            class="pointer-events-none absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
        />
        <input
            ref="input"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            :class="computedClass"
            :disabled="disabled"
            :placeholder="placeholder"
            v-bind="_.omit(attrs, 'class')"
            @focus="onFocus"
            @change="onTyped"
            @keydown.enter.prevent="
                onTyped();
                open = false;
            "
            @keydown.esc="open = false"
        />
        <button
            v-if="clearable && modelValue && !disabled"
            type="button"
            title="Quitar hora"
            class="absolute inset-y-0 right-0 z-10 my-auto mr-2 flex h-5 w-5 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-500 dark:hover:bg-darkmode-400"
            @mousedown.prevent
            @click="clear"
        >
            <Lucide icon="X" class="h-3.5 w-3.5" />
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="list"
                class="fixed z-[9999] max-h-56 overflow-y-auto rounded-md border py-1 shadow-lg"
                :class="
                    darkSkin
                        ? 'border-darkmode-400 bg-darkmode-600'
                        : 'border-slate-200 bg-white dark:border-darkmode-400 dark:bg-darkmode-600'
                "
                :style="{
                    top: `${position.top}px`,
                    left: `${position.left}px`,
                    width: `${position.width}px`,
                }"
            >
                <button
                    v-for="option in options"
                    :key="option"
                    type="button"
                    :data-selected="option === modelValue"
                    class="block w-full px-3 py-1.5 text-left text-sm transition"
                    :class="[
                        darkSkin
                            ? 'text-slate-300 hover:bg-darkmode-400'
                            : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400',
                        option === modelValue &&
                            'bg-primary/10 font-medium text-primary',
                    ]"
                    @mousedown.prevent
                    @click="pick(option)"
                >
                    {{ option }}
                </button>
            </div>
        </Teleport>
    </div>
</template>
