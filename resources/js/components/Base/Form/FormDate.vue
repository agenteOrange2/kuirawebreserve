<script lang="ts">
export default {
    inheritAttrs: false,
};
</script>

<script setup lang="ts">
import Litepicker from 'litepicker';
import _ from 'lodash';
import { twMerge } from 'tailwind-merge';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    useAttrs,
    watch,
} from 'vue';
import Lucide from '@/components/Base/Lucide';

/**
 * Campo de fecha del panel: mismo Litepicker que el wizard público, pero en
 * popup y para UNA fecha. Sustituye a <input type="date">, que se ve
 * distinto en cada navegador (y en cada sistema operativo) y rompía la
 * simetría del theme.
 *
 * El contrato es el mismo que el del input nativo: v-model en 'YYYY-MM-DD'
 * (cadena vacía = sin fecha), así que las páginas no cambian su estado.
 * En pantalla se muestra DD/MM/YYYY, que es como lee la fecha la gente aquí,
 * y se puede teclear además de elegirla en el calendario.
 */
const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        /** Límites en 'YYYY-MM-DD' (los mismos min/max del input nativo). */
        min?: string | null;
        max?: string | null;
        disabled?: boolean;
        /** Muestra la equis para dejar el campo vacío. */
        clearable?: boolean;
        placeholder?: string;
        /** Clases para el input en sí (apariencia); `class` va al campo. */
        inputClass?: string;
    }>(),
    { clearable: true, placeholder: 'dd/mm/aaaa' },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'change', value: string): void;
}>();

const attrs = useAttrs();

const input = ref<HTMLInputElement>();
let picker: Litepicker | null = null;
// Litepicker escribe en el input y eso dispara su propio 'change': sin esta
// bandera, cada selección se procesaría dos veces.
let syncing = false;

const computedClass = computed(() =>
    twMerge([
        // Mismas clases que FormInput para que ambos campos sean idénticos;
        // pl-9 por el icono de calendario.
        'disabled:bg-slate-100 disabled:cursor-not-allowed dark:disabled:bg-darkmode-800/50 dark:disabled:border-transparent',
        'transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm rounded-md placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary/20 focus:border-primary/40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700/50 dark:placeholder:text-slate-500/80',
        'cursor-pointer pl-9',
        props.clearable && props.modelValue ? 'pr-8' : '',
        props.inputClass,
    ]),
);

// La clase que pasa la página dimensiona el campo completo (w-40, flex-1…),
// por eso va en el envoltorio: en el input dejaría la caja a todo lo ancho
// con el campo flotando dentro.
const wrapperClass = computed(() =>
    twMerge(['relative', typeof attrs.class === 'string' && attrs.class]),
);

/** 'YYYY-MM-DD' a 'DD/MM/YYYY' (lo que se ve en el campo). */
function toDisplay(iso?: string | null): string {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso ?? '');

    return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
}

/** 'DD/MM/YYYY' (o 'D-M-YYYY') a 'YYYY-MM-DD'; vacío si no cuadra. */
function toIso(text: string): string {
    const m = /^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$/.exec(text.trim());

    if (!m) return '';

    const [, d, mo, y] = m;
    const iso = `${y}-${mo.padStart(2, '0')}-${d.padStart(2, '0')}`;
    const probe = new Date(`${iso}T00:00`);

    // Descarta el 31 de febrero y compañía: Date lo corre al mes siguiente.
    return probe.getDate() === Number(d) && probe.getMonth() === Number(mo) - 1
        ? iso
        : '';
}

function toLocalDate(iso: string): Date {
    return new Date(`${iso}T00:00`);
}

function commit(iso: string) {
    if (iso === (props.modelValue ?? '')) return;

    emit('update:modelValue', iso);
    emit('change', iso);
}

/** Deja el campo mostrando exactamente lo que vale el modelo. */
function syncField() {
    if (!input.value) return;

    syncing = true;
    input.value.value = toDisplay(props.modelValue);
    syncing = false;
}

function clear() {
    picker?.clearSelection();
    syncField();
    commit('');
}

// Teclado: cuando el navegador avisa que el usuario cambió el texto (al
// salir del campo) se interpreta lo escrito; si no es una fecha válida, se
// regresa a la que ya estaba en vez de quedarse en basura. Va en 'change' y
// no en 'blur' a propósito: elegir un día en el calendario también saca el
// foco del campo, y en ese momento Litepicker todavía no ha escrito la
// fecha — con 'blur' cada clic en el calendario se leía como "lo dejaron
// vacío" y borraba la selección. Y en fase de captura para ganarle al
// listener que Litepicker pone en el mismo evento: así el campo se corrige
// primero y él lee ya el texto bueno.
function onTyped() {
    if (!input.value) return;

    const typed = input.value.value.trim();

    if (typed === '') {
        clear();

        return;
    }

    const iso = toIso(typed);

    if (iso) {
        picker?.setDate(toLocalDate(iso));
        commit(iso);
    }

    syncField();
}

onMounted(() => {
    if (!input.value) return;

    syncField();

    // El popup se cuelga del <body>, fuera del alcance de la piel oscura del
    // wizard público (.booking-dark, que es de ámbito). Si el campo vive
    // dentro de ella, se le marca al calendario para que la siga.
    const darkSkin = input.value.closest('.booking-dark') !== null;

    picker = new Litepicker({
        element: input.value,
        singleMode: true,
        lang: 'es-MX',
        format: 'DD/MM/YYYY',
        autoApply: true,
        // Mes y año como listas: sin esto, capturar una fecha de nacimiento
        // obliga a pasar cuarenta años de mes en mes.
        dropdowns: {
            minYear: 1920,
            maxYear: new Date().getFullYear() + 5,
            months: true,
            years: true,
        },
        ...(props.modelValue
            ? { startDate: toLocalDate(props.modelValue) }
            : {}),
        ...(props.min ? { minDate: toLocalDate(props.min) } : {}),
        ...(props.max ? { maxDate: toLocalDate(props.max) } : {}),
        setup: (p: Litepicker) => {
            p.on('selected', (date) => {
                if (syncing) return;

                const picked = date.toJSDate();
                const iso = [
                    picked.getFullYear(),
                    String(picked.getMonth() + 1).padStart(2, '0'),
                    String(picked.getDate()).padStart(2, '0'),
                ].join('-');

                commit(iso);
                syncField();
            });
        },
    });

    if (darkSkin) {
        (picker as unknown as { ui?: HTMLElement }).ui?.classList.add(
            'litepicker--dark',
        );
    }
});

// El modelo manda: si la página lo cambia (reset de filtros, otro registro
// en el modal), el campo y el calendario se ponen al día.
watch(
    () => props.modelValue,
    (value) => {
        syncField();

        if (!picker) return;

        if (value) {
            picker.setDate(toLocalDate(value));
        } else {
            picker.clearSelection();
        }
    },
);

watch([() => props.min, () => props.max], ([min, max]) => {
    picker?.setOptions({
        minDate: min ? toLocalDate(min) : null,
        maxDate: max ? toLocalDate(max) : null,
    });
});

onBeforeUnmount(() => {
    picker?.destroy();
    picker = null;
});
</script>

<template>
    <div :class="wrapperClass">
        <Lucide
            icon="Calendar"
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
            @change.capture="onTyped"
        />
        <button
            v-if="clearable && modelValue && !disabled"
            type="button"
            title="Quitar fecha"
            class="absolute inset-y-0 right-0 z-10 my-auto mr-2 flex h-5 w-5 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-500 dark:hover:bg-darkmode-400"
            @mousedown.prevent
            @click="clear"
        >
            <Lucide icon="X" class="h-3.5 w-3.5" />
        </button>
    </div>
</template>
