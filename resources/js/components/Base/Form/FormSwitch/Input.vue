<script lang="ts">
export default {
  inheritAttrs: false,
};

export interface InputProps extends /* @vue-ignore */ InputHTMLAttributes {
  modelValue?: InputHTMLAttributes["value"];
  type: "checkbox";
}
</script>

<script setup lang="ts">
import _ from "lodash";
import { twMerge } from "tailwind-merge";
import { computed, type InputHTMLAttributes, useAttrs } from "vue";
import FormCheck from "../FormCheck";

interface InputEmit {
  (e: "update:modelValue", value: string): void;
}

const props = defineProps<InputProps>();

const attrs = useAttrs();

const computedClass = computed(() =>
  twMerge([
    // Default. OJO Tailwind v4: las variantes se apilan de fuera hacia
    // dentro — es "checked:before:", nunca "before:checked:" (esa regla
    // genera un selector inválido y se descarta: el switch se veía como
    // un óvalo sólido sin perilla).
    "w-[38px] h-[24px] p-px rounded-full relative bg-slate-200 border-slate-200 dark:bg-darkmode-400 dark:border-darkmode-400",
    "before:content-[''] before:w-[20px] before:h-[20px] before:bg-white before:shadow-[1px_1px_3px_rgba(0,0,0,0.25)] before:transition-[margin-left] before:duration-200 before:ease-in-out before:absolute before:inset-y-0 before:my-auto before:ml-0 before:rounded-full dark:before:bg-darkmode-600",

    // On checked
    "checked:bg-primary checked:border-primary checked:bg-none",
    "checked:before:ml-[14px] checked:before:bg-white dark:checked:before:bg-white",

    typeof attrs.class === "string" && attrs.class,
  ])
);

const emit = defineEmits<InputEmit>();

const localValue = computed({
  get() {
    return props.modelValue;
  },
  set(newValue) {
    emit("update:modelValue", newValue);
  },
});
</script>

<template>
  <FormCheck.Input
    :type="props.type"
    :class="computedClass"
    v-bind="_.omit(attrs, 'class')"
    v-model="localValue"
  />
</template>
