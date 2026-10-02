<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect, FormSwitch } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { CatalogEntry, ProviderRow } from './types';
import { axiosMessage, providerTone } from './types';

const props = defineProps<{
    open: boolean;
    // null = alta; con fila = edición (el proveedor ya no cambia).
    provider: ProviderRow | null;
    catalog: CatalogEntry[];
}>();

const emit = defineEmits<{ close: []; saved: [] }>();

const form = reactive({
    provider: 'anthropic',
    model: '',
    api_key: '',
    active: true,
});
const saving = ref(false);
const error = ref<string | null>(null);
const showKey = ref(false);

const isEdit = computed(() => props.provider !== null);
const catalogFor = (key: string) => props.catalog.find((c) => c.key === key);

// Modelos sugeridos agrupados por nivel; "__custom" libera el campo manual.
const tierLabels: Record<string, string> = {
    new: 'Los más nuevos',
    mid: 'Intermedios',
    cheap: 'Económicos',
};
const modelChoice = ref('');
const modelGroups = computed(() => {
    const models = catalogFor(form.provider)?.models ?? [];
    return (['new', 'mid', 'cheap'] as const)
        .map((tier) => ({
            tier,
            label: tierLabels[tier],
            models: models.filter((m) => m.tier === tier),
        }))
        .filter((g) => g.models.length);
});

watch(modelChoice, (v) => {
    // "__custom" no toca el campo: conserva lo escrito (o lo guardado).
    if (v !== '__custom') form.model = v;
});

function firstModel(key: string): string {
    return catalogFor(key)?.models[0]?.id ?? '__custom';
}

function pickProvider(key: string): void {
    form.provider = key;
    modelChoice.value = firstModel(key);
    form.model = modelChoice.value === '__custom' ? '' : modelChoice.value;
}

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        error.value = null;
        showKey.value = false;
        form.api_key = '';
        if (props.provider) {
            form.provider = props.provider.provider;
            form.model = props.provider.model;
            form.active = props.provider.active;
            const known = catalogFor(props.provider.provider)?.models.some(
                (m) => m.id === props.provider!.model,
            );
            modelChoice.value = known ? props.provider.model : '__custom';
        } else {
            form.active = true;
            pickProvider(props.catalog[0]?.key ?? 'anthropic');
        }
    },
);

const canSave = computed(
    () =>
        !saving.value &&
        form.model.trim() !== '' &&
        (isEdit.value || form.api_key.trim() !== ''),
);

async function submit(): Promise<void> {
    if (!canSave.value) return;
    saving.value = true;
    error.value = null;
    try {
        if (props.provider) {
            await axios.patch(
                route('admin.ai.providers.update', props.provider.id),
                {
                    model: form.model.trim(),
                    api_key: form.api_key.trim() || null,
                    active: form.active,
                },
            );
        } else {
            await axios.post(route('admin.ai.providers.store'), {
                provider: form.provider,
                model: form.model.trim(),
                api_key: form.api_key.trim(),
                active: form.active,
            });
        }
        emit('saved');
    } catch (e) {
        error.value = axiosMessage(e, 'No se pudo guardar la key.');
    } finally {
        saving.value = false;
    }
}

const fieldLabel = 'mb-1.5 block text-xs font-medium';
const sectionLabel =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
</script>

<template>
    <Dialog :open="open" size="lg" @close="emit('close')">
        <Dialog.Panel class="sm:w-[94vw] lg:w-[620px]">
            <form
                class="flex max-h-[calc(100dvh-6rem)] flex-col"
                @submit.prevent="submit"
            >
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border"
                        :class="
                            providerTone[form.provider] ??
                            'border-primary/10 bg-primary/10 text-primary'
                        "
                    >
                        <Lucide icon="KeyRound" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <Dialog.Title
                            class="block border-0 p-0 text-base font-medium"
                        >
                            {{
                                provider
                                    ? `Editar ${provider.label}`
                                    : 'Nueva key maestra'
                            }}
                        </Dialog.Title>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Con esta llave contestan los bots de los hoteles. Se
                            guarda cifrada.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                        title="Cerrar"
                        @click="emit('close')"
                    >
                        <Lucide icon="X" class="h-4 w-4" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4">
                    <section v-if="!provider">
                        <h3 :class="sectionLabel">Proveedor</h3>
                        <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <button
                                v-for="c in catalog"
                                :key="c.key"
                                type="button"
                                class="flex min-w-0 items-center gap-2.5 rounded-lg border px-3 py-2.5 text-left transition"
                                :class="
                                    form.provider === c.key
                                        ? 'border-primary/40 bg-primary/5'
                                        : 'border-slate-200/70 hover:bg-slate-50 dark:border-darkmode-400 dark:hover:bg-darkmode-400/30'
                                "
                                @click="pickProvider(c.key)"
                            >
                                <span
                                    class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full border"
                                    :class="
                                        form.provider === c.key
                                            ? 'border-primary'
                                            : 'border-slate-300'
                                    "
                                >
                                    <span
                                        v-if="form.provider === c.key"
                                        class="h-2 w-2 rounded-full bg-primary"
                                    />
                                </span>
                                <span class="min-w-0">
                                    <span
                                        class="block truncate text-xs font-medium"
                                        >{{ c.label }}</span
                                    >
                                    <span
                                        class="block truncate font-mono text-[11px] text-slate-400"
                                        >{{
                                            c.models[0]?.id ??
                                            c.placeholder_model
                                        }}</span
                                    >
                                </span>
                            </button>
                        </div>
                    </section>

                    <section>
                        <h3 :class="sectionLabel">Modelo y llave</h3>
                        <div class="mt-2 space-y-4">
                            <label class="block">
                                <span :class="fieldLabel">Modelo</span>
                                <FormSelect
                                    v-model="modelChoice"
                                    class="h-9 font-mono text-xs"
                                >
                                    <optgroup
                                        v-for="g in modelGroups"
                                        :key="g.tier"
                                        :label="g.label"
                                    >
                                        <option
                                            v-for="m in g.models"
                                            :key="m.id"
                                            :value="m.id"
                                        >
                                            {{ m.id }}
                                        </option>
                                    </optgroup>
                                    <option value="__custom">
                                        Otro (escribir a mano)
                                    </option>
                                </FormSelect>
                            </label>
                            <div
                                v-if="modelChoice === '__custom'"
                                class="relative"
                            >
                                <Lucide
                                    icon="Cpu"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                />
                                <FormInput
                                    v-model="form.model"
                                    type="text"
                                    class="h-9 pl-9 font-mono text-xs"
                                    :placeholder="
                                        catalogFor(form.provider)
                                            ?.placeholder_model
                                    "
                                />
                            </div>
                            <label class="block">
                                <span :class="fieldLabel">API key</span>
                                <div class="relative">
                                    <Lucide
                                        icon="KeyRound"
                                        class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                    />
                                    <FormInput
                                        v-model="form.api_key"
                                        :type="showKey ? 'text' : 'password'"
                                        class="h-9 pr-10 pl-9 font-mono text-xs"
                                        :placeholder="
                                            provider
                                                ? `Vacía = conservar ${provider.masked_key}`
                                                : catalogFor(form.provider)
                                                      ?.key_hint
                                        "
                                        autocomplete="off"
                                    />
                                    <button
                                        type="button"
                                        class="absolute inset-y-0 right-0 z-10 my-auto mr-1 flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:text-primary"
                                        :title="showKey ? 'Ocultar' : 'Mostrar'"
                                        @click="showKey = !showKey"
                                    >
                                        <Lucide
                                            :icon="showKey ? 'EyeOff' : 'Eye'"
                                            class="h-4 w-4"
                                        />
                                    </button>
                                </div>
                            </label>
                        </div>
                    </section>

                    <p
                        v-if="error"
                        class="rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                    >
                        {{ error }}
                    </p>
                </div>

                <div
                    class="flex items-center gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <FormSwitch class="flex items-center gap-2">
                        <FormSwitch.Input
                            v-model="form.active"
                            type="checkbox"
                        />
                        <span class="text-xs text-slate-500">{{
                            form.active ? 'Activa' : 'Pausada'
                        }}</span>
                    </FormSwitch>
                    <div class="ml-auto flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            @click="emit('close')"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="!canSave"
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                            {{ saving ? 'Guardando...' : 'Guardar' }}
                        </Button>
                    </div>
                </div>
            </form>
        </Dialog.Panel>
    </Dialog>
</template>
