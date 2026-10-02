<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormTextarea } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { ProspectRow, ProspectStatus } from './types';
import { initials, statusMeta, statusOptions, whatsappHref } from './types';

const props = defineProps<{
    prospect: ProspectRow | null;
    mailConfigured: boolean;
    emailSettingsUrl: string;
    sending: boolean;
}>();

const emit = defineEmits<{
    close: [];
    saved: [];
    sendDocuments: [prospect: ProspectRow];
    whatsappSent: [prospect: ProspectRow];
    delete: [prospect: ProspectRow];
}>();

const form = useForm({
    status: 'new' as ProspectStatus,
    notes: '',
});

const messageExpanded = ref(false);

// Se reinicia solo al abrir otro prospecto: las recargas por enviar
// documentos traen el mismo id y no deben borrar lo que se va escribiendo.
watch(
    () => props.prospect?.id,
    () => {
        if (!props.prospect) {
            return;
        }
        form.clearErrors();
        form.status = props.prospect.status;
        form.notes = props.prospect.notes ?? '';
        form.defaults({ status: form.status, notes: form.notes });
        messageExpanded.value = false;
    },
    { immediate: true },
);

const sectionLabel =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const pill =
    'inline-flex max-w-full items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 transition dark:bg-darkmode-400 dark:text-slate-300';
const channelRow =
    'flex flex-col gap-2.5 px-3.5 py-3 sm:flex-row sm:items-center';

const interestLabels = computed(() => {
    if (!props.prospect) {
        return [];
    }
    return [
        ...(props.prospect.plan_label
            ? [`Plan ${props.prospect.plan_label}`]
            : []),
        ...props.prospect.services_labels,
    ];
});

const canWhatsappDocs = computed(
    () => !!props.prospect?.wa_phone && !!props.prospect?.wa_text,
);

function save(): void {
    if (!props.prospect) {
        return;
    }
    form.patch(route('admin.prospects.update', props.prospect.id), {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
}
</script>

<template>
    <Dialog :open="prospect !== null" size="lg" @close="emit('close')">
        <Dialog.Panel class="sm:w-[94vw] lg:w-[760px]">
            <form
                v-if="prospect"
                class="flex max-h-[calc(100dvh-6rem)] flex-col"
                @submit.prevent="save"
            >
                <!-- Cabecera fija -->
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                    >
                        {{ initials(prospect.name) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <Dialog.Title
                                class="block truncate border-0 p-0 text-base font-medium"
                                >{{ prospect.hotel_name }}</Dialog.Title
                            >
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="statusMeta[prospect.status].class"
                            >
                                <span
                                    class="h-1.5 w-1.5 rounded-full"
                                    :class="statusMeta[prospect.status].dot"
                                />
                                {{ statusMeta[prospect.status].label }}
                            </span>
                        </div>
                        <p class="mt-0.5 truncate text-xs text-slate-500">
                            {{ prospect.name }} · {{ prospect.source_label }} ·
                            {{ prospect.created_at }}
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

                <!-- Cuerpo scrolleable -->
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4">
                    <section>
                        <h3 :class="sectionLabel">Contacto</h3>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <a
                                :href="`mailto:${prospect.email}`"
                                :class="pill"
                                class="hover:bg-primary/10 hover:text-primary"
                            >
                                <Lucide
                                    icon="Mail"
                                    class="h-3.5 w-3.5 shrink-0"
                                />
                                <span class="truncate">{{
                                    prospect.email
                                }}</span>
                            </a>
                            <a
                                :href="`tel:${prospect.phone}`"
                                :class="pill"
                                class="hover:bg-primary/10 hover:text-primary"
                            >
                                <Lucide icon="Phone" class="h-3.5 w-3.5" />
                                {{ prospect.phone }}
                            </a>
                            <a
                                v-if="prospect.wa_phone && prospect.wa_greeting"
                                :href="
                                    whatsappHref(
                                        prospect.wa_phone,
                                        prospect.wa_greeting,
                                    )
                                "
                                target="_blank"
                                rel="noopener"
                                :class="pill"
                                class="hover:bg-success/10 hover:text-success"
                                title="Abrir conversación con un saludo; no marca documentos enviados"
                            >
                                <Lucide
                                    icon="MessageCircle"
                                    class="h-3.5 w-3.5"
                                    :class="
                                        prospect.has_whatsapp
                                            ? 'text-success'
                                            : ''
                                    "
                                />
                                {{
                                    prospect.has_whatsapp
                                        ? 'Escribir por WhatsApp'
                                        : 'WhatsApp (sin confirmar)'
                                }}
                            </a>
                            <span :class="pill">
                                <Lucide icon="BedDouble" class="h-3.5 w-3.5" />
                                {{
                                    prospect.rooms
                                        ? `${prospect.rooms} habitaciones`
                                        : 'Tamaño sin indicar'
                                }}
                            </span>
                        </div>
                    </section>

                    <section>
                        <h3 :class="sectionLabel">Interés</h3>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <span
                                v-for="label in interestLabels"
                                :key="label"
                                class="rounded-full bg-primary/10 px-2.5 py-1 text-[11px] font-medium text-primary"
                                >{{ label }}</span
                            >
                            <span
                                v-if="!interestLabels.length"
                                class="text-xs text-slate-400"
                                >Sin especificar</span
                            >
                        </div>
                        <div
                            v-if="prospect.message"
                            class="mt-2.5 rounded-lg bg-slate-50 px-3.5 py-3 dark:bg-darkmode-500"
                        >
                            <div :class="sectionLabel">Lo que nos contó</div>
                            <p
                                class="mt-1 text-xs leading-5 whitespace-pre-line text-slate-600 dark:text-slate-300"
                                :class="messageExpanded ? '' : 'line-clamp-3'"
                            >
                                {{ prospect.message }}
                            </p>
                            <button
                                v-if="prospect.message.length > 180"
                                type="button"
                                class="mt-1 text-xs font-medium text-primary hover:underline"
                                @click="messageExpanded = !messageExpanded"
                            >
                                {{
                                    messageExpanded
                                        ? 'Ver menos'
                                        : 'Ver mensaje completo'
                                }}
                            </button>
                        </div>
                    </section>

                    <section>
                        <div class="flex items-center gap-2">
                            <h3 :class="sectionLabel">Documentos</h3>
                            <span class="text-[11px] text-slate-400">
                                ·
                                {{
                                    prospect.docs_available === 1
                                        ? '1 le corresponde'
                                        : `${prospect.docs_available} le corresponden`
                                }}
                            </span>
                            <Link
                                :href="route('admin.prospects.documents')"
                                class="ml-auto text-xs font-medium text-primary hover:underline"
                                >Administrar</Link
                            >
                        </div>
                        <div
                            class="mt-2 divide-y divide-slate-200/60 rounded-lg border border-slate-200/80 dark:divide-darkmode-400 dark:border-darkmode-400"
                        >
                            <div :class="channelRow">
                                <div
                                    class="flex min-w-0 flex-1 items-center gap-2.5"
                                >
                                    <span
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                        :class="
                                            prospect.docs_email_sent_at
                                                ? 'bg-success/10 text-success'
                                                : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                                        "
                                    >
                                        <Lucide icon="Mail" class="h-4 w-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium">
                                            Por correo
                                        </div>
                                        <div
                                            class="text-xs"
                                            :class="
                                                prospect.docs_email_sent_at
                                                    ? 'text-success'
                                                    : 'text-slate-400'
                                            "
                                        >
                                            {{
                                                prospect.docs_email_sent_at
                                                    ? `Enviados el ${prospect.docs_email_sent_at}`
                                                    : !prospect.docs_available
                                                      ? 'Sin documentos para sus servicios'
                                                      : 'Sin enviar'
                                            }}
                                        </div>
                                    </div>
                                </div>
                                <Link
                                    v-if="!mailConfigured"
                                    :href="emailSettingsUrl"
                                    class="text-xs font-medium text-primary hover:underline"
                                    >Configurar correo</Link
                                >
                                <Button
                                    v-else
                                    type="button"
                                    variant="outline-secondary"
                                    class="h-8 rounded-[0.5rem] text-xs"
                                    :disabled="
                                        sending || !prospect.docs_available
                                    "
                                    @click="emit('sendDocuments', prospect)"
                                >
                                    <Lucide
                                        icon="Send"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    {{
                                        sending
                                            ? 'Enviando...'
                                            : prospect.docs_email_sent_at
                                              ? 'Reenviar'
                                              : 'Enviar'
                                    }}
                                </Button>
                            </div>
                            <div :class="channelRow">
                                <div
                                    class="flex min-w-0 flex-1 items-center gap-2.5"
                                >
                                    <span
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                        :class="
                                            prospect.docs_whatsapp_sent_at
                                                ? 'bg-success/10 text-success'
                                                : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                                        "
                                    >
                                        <Lucide
                                            icon="MessageCircle"
                                            class="h-4 w-4"
                                        />
                                    </span>
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium">
                                            Por WhatsApp
                                        </div>
                                        <div
                                            class="text-xs"
                                            :class="
                                                prospect.docs_whatsapp_sent_at
                                                    ? 'text-success'
                                                    : 'text-slate-400'
                                            "
                                        >
                                            {{
                                                prospect.docs_whatsapp_sent_at
                                                    ? `Enviados el ${prospect.docs_whatsapp_sent_at}`
                                                    : !prospect.wa_phone
                                                      ? 'Sin teléfono válido'
                                                      : !prospect.docs_available
                                                        ? 'Sin documentos para sus servicios'
                                                        : 'Sin enviar · se abre WhatsApp con los enlaces'
                                            }}
                                        </div>
                                    </div>
                                </div>
                                <Button
                                    v-if="canWhatsappDocs"
                                    :as="'a'"
                                    :href="
                                        whatsappHref(
                                            prospect.wa_phone!,
                                            prospect.wa_text,
                                        )
                                    "
                                    target="_blank"
                                    rel="noopener"
                                    variant="outline-secondary"
                                    class="h-8 rounded-[0.5rem] text-xs"
                                    @click="emit('whatsappSent', prospect)"
                                >
                                    <Lucide
                                        icon="MessageCircle"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    {{
                                        prospect.docs_whatsapp_sent_at
                                            ? 'Reenviar'
                                            : 'Enviar'
                                    }}
                                </Button>
                            </div>
                        </div>
                    </section>

                    <section>
                        <h3 :class="sectionLabel">Seguimiento</h3>
                        <div
                            class="mt-2 grid grid-cols-2 gap-1.5 sm:grid-cols-5"
                        >
                            <button
                                v-for="status in statusOptions"
                                :key="status.value"
                                type="button"
                                class="flex h-9 items-center justify-center gap-1.5 rounded-[0.5rem] border text-xs font-medium transition"
                                :class="
                                    form.status === status.value
                                        ? [
                                              statusMeta[status.value].class,
                                              'border-current',
                                          ]
                                        : 'border-slate-200 text-slate-500 hover:border-slate-300 dark:border-darkmode-400'
                                "
                                @click="form.status = status.value"
                            >
                                <Lucide
                                    :icon="statusMeta[status.value].icon as any"
                                    class="h-3.5 w-3.5"
                                />
                                {{ status.label }}
                            </button>
                        </div>
                        <p
                            v-if="form.errors.status"
                            class="mt-1 text-xs text-danger"
                        >
                            {{ form.errors.status }}
                        </p>
                        <FormTextarea
                            v-model="form.notes"
                            rows="4"
                            class="mt-3 text-xs"
                            placeholder="Acuerdos, próximo contacto, objeciones o lo que haga falta recordar..."
                        />
                        <p
                            v-if="form.errors.notes"
                            class="mt-1 text-xs text-danger"
                        >
                            {{ form.errors.notes }}
                        </p>
                        <div
                            class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-400"
                        >
                            <span class="inline-flex items-center gap-1"
                                ><Lucide icon="CalendarClock" class="h-3 w-3" />
                                Recibido {{ prospect.created_at }}</span
                            >
                            <span class="inline-flex items-center gap-1"
                                ><Lucide icon="PhoneCall" class="h-3 w-3" />
                                {{
                                    prospect.contacted_at
                                        ? `Primer contacto ${prospect.contacted_at}`
                                        : 'Sin contacto registrado'
                                }}</span
                            >
                        </div>
                    </section>

                    <section>
                        <h3 :class="sectionLabel">Historial</h3>
                        <ol class="mt-2 space-y-2.5">
                            <li
                                v-for="item in prospect.history"
                                :key="item.id"
                                class="flex gap-2.5"
                            >
                                <span
                                    class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-darkmode-400"
                                >
                                    <Lucide
                                        :icon="item.icon as any"
                                        class="h-3 w-3"
                                    />
                                </span>
                                <div class="min-w-0 text-xs">
                                    <div
                                        class="text-slate-700 dark:text-slate-300"
                                    >
                                        {{ item.label }}
                                        <span
                                            v-if="item.details.length"
                                            class="text-slate-500"
                                            >·
                                            {{ item.details.join(' · ') }}</span
                                        >
                                    </div>
                                    <div
                                        class="text-[11px] text-slate-400"
                                        :title="item.at ?? undefined"
                                    >
                                        {{ item.user ?? 'Alguien del equipo' }}
                                        ·
                                        {{ item.ago }}
                                    </div>
                                </div>
                            </li>
                            <li class="flex gap-2.5">
                                <span
                                    class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-info/10 text-info"
                                >
                                    <Lucide icon="Inbox" class="h-3 w-3" />
                                </span>
                                <div class="min-w-0 text-xs">
                                    <div
                                        class="text-slate-700 dark:text-slate-300"
                                    >
                                        Llegó desde {{ prospect.source_label }}
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        {{ prospect.created_at }} ·
                                        {{ prospect.created_ago }}
                                    </div>
                                </div>
                            </li>
                        </ol>
                    </section>
                </div>

                <!-- Pie fijo -->
                <div
                    class="flex items-center gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <button
                        type="button"
                        class="inline-flex h-9 items-center gap-1.5 rounded-[0.5rem] px-3 text-xs font-medium text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                        @click="emit('delete', prospect)"
                    >
                        <Lucide icon="Trash2" class="h-3.5 w-3.5" />
                        Eliminar
                    </button>
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
                            :disabled="form.processing || !form.isDirty"
                        >
                            <Lucide icon="Save" class="mr-1.5 h-3.5 w-3.5" />
                            {{ form.processing ? 'Guardando...' : 'Guardar' }}
                        </Button>
                    </div>
                </div>
            </form>
        </Dialog.Panel>
    </Dialog>
</template>
