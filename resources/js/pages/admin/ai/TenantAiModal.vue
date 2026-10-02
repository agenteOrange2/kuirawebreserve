<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect, FormSwitch } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { ProviderRow, TenantAiRow } from './types';
import { axiosMessage, effectiveLimit, usagePercent } from './types';

const props = defineProps<{
    tenant: TenantAiRow | null;
    providers: ProviderRow[];
}>();

const emit = defineEmits<{
    close: [];
    saved: [payload: Partial<TenantAiRow>];
}>();

const form = reactive({
    enabled: true,
    provider_id: '' as number | '',
    monthly_reply_limit: '' as number | string,
    byok_allowed: false,
    api_allowed: false,
});
const saving = ref(false);
const error = ref<string | null>(null);

watch(
    () => props.tenant?.id,
    () => {
        const t = props.tenant;
        if (!t) return;
        error.value = null;
        form.enabled = t.enabled;
        form.provider_id = t.provider_id ?? '';
        form.monthly_reply_limit = t.monthly_reply_limit ?? '';
        form.byok_allowed = t.byok_allowed;
        form.api_allowed = t.api_allowed;
    },
    { immediate: true },
);

// Una key asignada pero pausada: el guardián la salta y usa la cadena.
const assignedPaused = computed(() => {
    if (form.provider_id === '') return false;
    return (
        props.providers.find((p) => p.id === form.provider_id)?.active === false
    );
});

async function submit(): Promise<void> {
    if (!props.tenant) return;
    saving.value = true;
    error.value = null;
    const limit =
        form.monthly_reply_limit === '' || form.monthly_reply_limit === null
            ? null
            : Number(form.monthly_reply_limit);
    const payload = {
        enabled: form.enabled,
        platform_ai_provider_id:
            form.provider_id === '' ? null : form.provider_id,
        monthly_reply_limit: limit,
        byok_allowed: form.byok_allowed,
        api_allowed: form.api_allowed,
    };
    try {
        await axios.patch(
            route('admin.ai.tenants.update', props.tenant.id),
            payload,
        );
        emit('saved', {
            enabled: payload.enabled,
            provider_id: payload.platform_ai_provider_id,
            monthly_reply_limit: limit,
            byok_allowed: payload.byok_allowed,
            api_allowed: payload.api_allowed,
        });
    } catch (e) {
        error.value = axiosMessage(e, 'No se pudo guardar.');
    } finally {
        saving.value = false;
    }
}

const sectionLabel =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const fieldLabel = 'mb-1.5 block text-xs font-medium';
const toggleRow =
    'flex items-start justify-between gap-4 rounded-lg border border-slate-200/70 px-3.5 py-3 dark:border-darkmode-400';
</script>

<template>
    <Dialog :open="tenant !== null" size="lg" @close="emit('close')">
        <Dialog.Panel class="sm:w-[94vw] lg:w-[620px]">
            <form
                v-if="tenant"
                class="flex max-h-[calc(100dvh-6rem)] flex-col"
                @submit.prevent="submit"
            >
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Bot" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <Dialog.Title
                            class="block truncate border-0 p-0 text-base font-medium"
                            >{{ tenant.name }}</Dialog.Title
                        >
                        <p class="mt-0.5 truncate text-xs text-slate-500">
                            Plan {{ tenant.plan_label }} ·
                            {{ tenant.used_replies }} de
                            {{ effectiveLimit(tenant) ?? 'sin límite' }}
                            respuestas este mes
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
                    <section>
                        <h3 :class="sectionLabel">Asistente</h3>
                        <label :class="toggleRow" class="mt-2 cursor-pointer">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium">
                                    Bot encendido
                                </span>
                                <span class="block text-xs text-slate-500">
                                    Apagado, el bot no contesta en ningún canal
                                    del hotel; los mensajes quedan en la bandeja
                                    para el personal.
                                </span>
                            </span>
                            <FormSwitch class="shrink-0">
                                <FormSwitch.Input
                                    v-model="form.enabled"
                                    type="checkbox"
                                />
                            </FormSwitch>
                        </label>
                    </section>

                    <section>
                        <h3 :class="sectionLabel">Proveedor y cuota</h3>
                        <div class="mt-2 grid grid-cols-12 gap-4">
                            <label class="col-span-12 sm:col-span-7">
                                <span :class="fieldLabel">Key que usa</span>
                                <FormSelect
                                    v-model="form.provider_id"
                                    class="h-9 text-xs"
                                >
                                    <option value="">
                                        Automático (la cadena en orden)
                                    </option>
                                    <option
                                        v-for="p in providers"
                                        :key="p.id"
                                        :value="p.id"
                                    >
                                        {{ p.label }} · {{ p.model
                                        }}{{ p.active ? '' : ' (pausada)' }}
                                    </option>
                                </FormSelect>
                                <span
                                    v-if="assignedPaused"
                                    class="mt-1 block text-[11px] text-warning"
                                >
                                    Esa key está pausada: mientras siga así, el
                                    hotel usa la cadena automática.
                                </span>
                            </label>
                            <label class="col-span-12 sm:col-span-5">
                                <span :class="fieldLabel"
                                    >Respuestas por mes</span
                                >
                                <FormInput
                                    v-model="form.monthly_reply_limit"
                                    type="number"
                                    min="0"
                                    class="h-9 text-xs"
                                    :placeholder="
                                        tenant.default_limit === null
                                            ? 'Sin límite'
                                            : String(tenant.default_limit)
                                    "
                                />
                            </label>
                        </div>
                        <p class="mt-2 text-[11px] text-slate-400">
                            Vacío = la que le toca:
                            {{
                                tenant.default_limit === null
                                    ? 'sin límite'
                                    : `${tenant.default_limit} (plan${tenant.ai_from_addon ? ' y servicio adicional' : ''})`
                            }}. Con la key asignada caída, el guardián prueba
                            las demás activas.
                        </p>
                        <div
                            class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                        >
                            <div
                                class="h-full rounded-full"
                                :class="
                                    usagePercent(tenant) >= 90
                                        ? 'bg-danger'
                                        : usagePercent(tenant) >= 75
                                          ? 'bg-warning'
                                          : 'bg-primary/70'
                                "
                                :style="{ width: `${usagePercent(tenant)}%` }"
                            />
                        </div>
                    </section>

                    <section>
                        <h3 :class="sectionLabel">Permisos del hotel</h3>
                        <div class="mt-2 space-y-2">
                            <label :class="toggleRow" class="cursor-pointer">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium"
                                        >Llaves propias (BYOK)</span
                                    >
                                    <span class="block text-xs text-slate-500"
                                        >El hotel captura sus propias keys en su
                                        panel; lo que contesten con ellas no
                                        consume la cuota.</span
                                    >
                                </span>
                                <FormSwitch class="shrink-0">
                                    <FormSwitch.Input
                                        v-model="form.byok_allowed"
                                        type="checkbox"
                                    />
                                </FormSwitch>
                            </label>
                            <label :class="toggleRow" class="cursor-pointer">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium"
                                        >Agent API</span
                                    >
                                    <span class="block text-xs text-slate-500"
                                        >Ve tokens y el playground de
                                        integraciones en su panel.</span
                                    >
                                </span>
                                <FormSwitch class="shrink-0">
                                    <FormSwitch.Input
                                        v-model="form.api_allowed"
                                        type="checkbox"
                                    />
                                </FormSwitch>
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
                    <Link
                        :href="route('admin.tenants.assistant', tenant.id)"
                        class="inline-flex h-9 items-center gap-1.5 rounded-[0.5rem] px-2 text-xs font-medium text-primary hover:underline"
                    >
                        <Lucide icon="ExternalLink" class="h-3.5 w-3.5" />
                        Instrucciones y prompt
                    </Link>
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
                            :disabled="saving"
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
